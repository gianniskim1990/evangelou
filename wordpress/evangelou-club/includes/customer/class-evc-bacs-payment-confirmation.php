<?php
defined('ABSPATH') || exit;

/** Result of one manager confirmation attempt (no personal data, no raw identifiers). */
final class EVC_Bacs_Confirmation_Result {
    /** Refused before anything was written (authorisation, input, order or membership checks). */
    const REFUSED = 'refused';

    /** @var string refused | EVC_Record_Outcome::* */
    public $outcome;
    /** @var string|null */
    public $reason;
    /** @var EVC_Funded_Period|null the recorded Athens period (new or already recorded) */
    public $period;
    /** @var bool was the attempt written to the Club audit trail? */
    public $audited = false;

    public function __construct(string $outcome, ?string $reason, ?EVC_Funded_Period $period) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->period = $period;
    }

    public static function refused(string $reason): self {
        return new self(self::REFUSED, $reason, null);
    }

    public function funded(): bool {
        return in_array($this->outcome, array(EVC_Record_Outcome::NEWLY_RECORDED, EVC_Record_Outcome::ALREADY_RECORDED), true) && $this->period !== null;
    }
}

/**
 * STAGE-03: the trusted BACS (bank transfer) payment-confirmation boundary.
 *
 * A WooCommerce order status is NEVER the proof. The proof is an explicit
 * MANAGER statement, from the bank statement, that this exact transfer was
 * received: the bank's transfer reference, the amount and the value date. The
 * boundary then records ONE money movement through the existing
 * EVC_Provenance_Recorder (approved Athens rules D1/D2/D5, idempotent,
 * compare-and-set store), bound to:
 *   - exactly one WooCommerce order   (the movement's only order anchor);
 *   - exactly one WordPress customer  (the order's customer);
 *   - exactly one PMPro level         (the configured Club level);
 *   - exactly one PMPro row           (the customer's single ACTIVE Club row,
 *                                      issued by PMPro WooCommerce on completion);
 *   - exactly one paid period         (computed by the recorder, never here).
 *
 * Identity: movement id = keyed HMAC of the normalised BANK REFERENCE, so the
 * same transfer can never fund a second order (anchor conflict) and an order
 * can never be funded by a second transfer (the order anchor is unique).
 * Retries of the same confirmation reuse the recorded verification instant
 * and are "already recorded"; nothing is ever written twice.
 *
 * Who may confirm (OPEN owner decision): only a user holding
 * CAPABILITY, which NO role is given by this plugin; the shared Club staff
 * account is refused even if it were given it, and nobody may confirm their
 * own order. This class is constructed by NO shipped code, route, admin page
 * or hook (the packager forbids it), so the operation is INACCESSIBLE until
 * the owner decides who holds the capability and where the action lives.
 *
 * Writes only to the Club provenance store (and the Club audit trail). It
 * never changes the WooCommerce order or the PMPro membership.
 */
final class EVC_Bacs_Payment_Confirmation {
    const CAPABILITY = 'evc_confirm_bank_payment';
    /** Bank transfer reference after normalisation: letters/digits only. */
    const REFERENCE_PATTERN = '/^[A-Z0-9]{6,64}$/D';
    const AMOUNT_MINOR = 2000;
    const ENVIRONMENT = 'live';
    const BUSINESS_TIMEZONE = 'Europe/Athens';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Provenance_Recorder */
    private $recorder;
    /** @var EVC_Provenance_Store */
    private $store;
    /** @var callable(): DateTimeImmutable */
    private $now;
    /** @var EVC_Audit_Log|null */
    private $audit;
    /** @var EVC_Club_Db|null */
    private $audit_db;

    public function __construct(EVC_Commerce_Source_Config $config, EVC_Provenance_Store $store, callable $now, ?EVC_Audit_Log $audit = null, ?EVC_Club_Db $audit_db = null) {
        $this->config = $config;
        $this->store = $store;
        $this->recorder = new EVC_Provenance_Recorder(
            $store,
            new EVC_Pmpro_Mapper_Config(array($config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX),
            self::ENVIRONMENT,
            self::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY
        );
        $this->now = $now;
        $this->audit = $audit;
        $this->audit_db = $audit_db;
    }

    /**
     * @param array{order_id?:mixed,bank_reference?:mixed,amount_minor?:mixed,currency?:mixed,received_on?:mixed} $input
     */
    public function confirm(int $actor_user_id, array $input): EVC_Bacs_Confirmation_Result {
        $result = $this->decide($actor_user_id, $input, $movement_id, $order_ref);
        $this->audit($actor_user_id, $result, $movement_id, $order_ref);
        return $result;
    }

    private function decide(int $actor_user_id, array $input, ?string &$movement_id, ?string &$order_ref): EVC_Bacs_Confirmation_Result {
        $movement_id = null;
        $order_ref = null;
        $actor = get_userdata($actor_user_id);
        if (!$actor instanceof WP_User || !user_can($actor, self::CAPABILITY) || EVC_Staff_Role::has_staff_role($actor)) {
            return EVC_Bacs_Confirmation_Result::refused('not_authorised');
        }

        $order_id = $input['order_id'] ?? null;
        $reference = self::normalise_reference($input['bank_reference'] ?? null);
        $received_on = $input['received_on'] ?? null;
        if (!is_int($order_id) || $order_id < 1 || $reference === null) {
            return EVC_Bacs_Confirmation_Result::refused('invalid_request');
        }
        if (($input['amount_minor'] ?? null) !== self::AMOUNT_MINOR || ($input['currency'] ?? null) !== EVC_Commerce_Source_Config::CURRENCY) {
            return EVC_Bacs_Confirmation_Result::refused('amount_not_20_eur');
        }
        if (!is_string($received_on) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $received_on)) {
            return EVC_Bacs_Confirmation_Result::refused('invalid_request');
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund) {
            return EVC_Bacs_Confirmation_Result::refused('order_not_found');
        }
        $order_ref = $this->config->ref('wc_order', (string) $order->get_id());
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id < 1 || get_userdata($customer_id) === false) {
            return EVC_Bacs_Confirmation_Result::refused('order_without_customer_account');
        }
        if ($customer_id === $actor_user_id) {
            return EVC_Bacs_Confirmation_Result::refused('own_order');
        }
        $problem = $this->order_problem($order, $received_on);
        if ($problem !== null) {
            return EVC_Bacs_Confirmation_Result::refused($problem);
        }
        $row_id = $this->single_active_row($customer_id);
        if (!is_int($row_id)) {
            return EVC_Bacs_Confirmation_Result::refused($row_id);
        }

        $movement_id = $this->config->ref('bacs_transfer', $reference);
        try {
            $existing = $this->store->movement($movement_id);
        } catch (Throwable $e) {
            return new EVC_Bacs_Confirmation_Result(EVC_Record_Outcome::UNAVAILABLE, 'store_unavailable', null);
        }
        $movement = new EVC_Verified_Movement(
            $movement_id,
            self::ENVIRONMENT,
            $customer_id,
            $this->config->level_id,
            // A retry keeps what was recorded; the recorder still checks every fact against it.
            $existing !== null ? $existing->pmpro_row_ref() : $this->config->ref('pmpro_membership_row', (string) $row_id),
            $existing !== null ? $existing->verified_at_utc() : $this->whole_second_now(),
            self::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY,
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $order_ref)),
            EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN,
            EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION,
            EVC_Verified_Movement::STATE_CONFIRMED
        );
        $outcome = $this->recorder->record($movement);
        $period = $outcome->period();
        return new EVC_Bacs_Confirmation_Result($outcome->outcome(), $outcome->reason(), $period);
    }

    /** Upper-case letters and digits only (spaces, dashes, dots and slashes dropped); null if unusable. */
    public static function normalise_reference($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $compact = strtoupper(preg_replace('/[\s\-\.\/]+/u', '', $value));
        return preg_match(self::REFERENCE_PATTERN, $compact) ? $compact : null;
    }

    /** The order this confirmation may fund, or the reason it may not. */
    private function order_problem(WC_Order $order, string $received_on): ?string {
        if ((string) $order->get_status() !== 'completed') {
            // The shop issues the PMPro membership on completion; the Club records the money after.
            return 'order_not_completed';
        }
        if ((string) $order->get_payment_method() !== EVC_Commerce_Source_Config::PAYMENT_METHOD) {
            return 'order_not_bank_transfer';
        }
        if ((string) $order->get_currency() !== EVC_Commerce_Source_Config::CURRENCY || wc_format_decimal((string) $order->get_total(), 2) !== EVC_Commerce_Source_Config::PRICE) {
            return 'order_total_not_20_eur';
        }
        $items = $order->get_items('line_item');
        if (count($items) !== 1) {
            return 'order_not_a_single_club_item';
        }
        $item = reset($items);
        if (!$item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== $this->config->product_id || (int) $item->get_quantity() !== 1) {
            return 'order_not_a_single_club_item';
        }
        $zone = new DateTimeZone(self::BUSINESS_TIMEZONE);
        $received = DateTimeImmutable::createFromFormat('!Y-m-d', $received_on, $zone);
        if ($received === false || $received->format('Y-m-d') !== $received_on) {
            return 'invalid_received_date';
        }
        $created = $order->get_date_created();
        $today = (($this->now)())->setTimezone($zone)->format('Y-m-d');
        if ($created === null || $received_on < $created->setTimezone($zone)->format('Y-m-d') || $received_on > $today) {
            return 'received_date_outside_order_window';
        }
        return null;
    }

    /** @return int|string the only ACTIVE Club PMPro row id of the customer, or the refusal reason */
    private function single_active_row(int $customer_id) {
        global $wpdb;
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND membership_id = %d AND status = 'active'",
            $customer_id,
            $this->config->level_id
        ));
        if (!is_array($ids) || $wpdb->last_error !== '') {
            return 'membership_unreadable';
        }
        if (count($ids) === 0) {
            return 'membership_not_issued';
        }
        return count($ids) === 1 ? (int) $ids[0] : 'multiple_active_memberships';
    }

    private function whole_second_now(): DateTimeImmutable {
        $now = (($this->now)())->setTimezone(new DateTimeZone('UTC'));
        return new DateTimeImmutable($now->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    /** Every attempt is audited (actor, outcome, opaque references only). An audit failure never changes the result. */
    private function audit(int $actor_user_id, EVC_Bacs_Confirmation_Result $result, ?string $movement_id, ?string $order_ref): void {
        if ($this->audit === null || $this->audit_db === null) {
            return;
        }
        try {
            $this->audit->record($this->audit_db, array(
                'event_type' => 'bacs_payment_confirmation',
                'outcome' => $result->outcome,
                'member_id' => null,
                'redemption_id' => null,
                'request_id' => null,
                'staff_wp_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
                'occurred_at_utc' => (($this->now)())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'details' => array_filter(array(
                    'reason' => $result->reason,
                    'movement' => $movement_id,
                    'order' => $order_ref,
                    'period_end_utc' => $result->period ? $result->period->end_utc()->format('Y-m-d H:i:s') : null,
                ), function ($v) {
                    return $v !== null;
                }),
            ));
            $result->audited = true;
        } catch (Throwable $e) {
            $result->audited = false;
        }
    }
}
