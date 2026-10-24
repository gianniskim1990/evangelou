<?php
defined('ABSPATH') || exit;

/**
 * STAGE-03B.2: READ-ONLY gathering of every fact EVC_Bacs_Recovery_Policy
 * needs — the signed intent, the WooCommerce order, the customer's PMPro rows
 * and the Club record (recorded movement / period / corrections / order-anchor
 * owner, or a FRESH advisory plan) — read anew for each decision.
 *
 * It writes nothing (no order, PMPro, Club or intent change), executes no
 * recovery step and is not reachable: no route, hook, page or role; nothing
 * in the plugin constructs it (the packager forbids it).
 */
final class EVC_Bacs_Recovery_Inspector {
    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Provenance_Store */
    private $store;
    /** @var EVC_Wc_Bacs_Intent_Store */
    private $intents;
    /** @var EVC_Provenance_Recorder */
    private $recorder;
    /** @var EVC_Bacs_Recovery_Policy */
    private $policy;

    public function __construct(EVC_Commerce_Source_Config $config, EVC_Provenance_Store $store, EVC_Wc_Bacs_Intent_Store $intents) {
        $this->config = $config;
        $this->store = $store;
        $this->intents = $intents;
        $this->recorder = new EVC_Provenance_Recorder(
            $store,
            new EVC_Pmpro_Mapper_Config(array($config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX),
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY
        );
        $this->policy = new EVC_Bacs_Recovery_Policy();
    }

    public function inspect(int $actor_user_id, int $order_id): EVC_Bacs_Recovery_Decision {
        return $this->policy->decide($this->facts($actor_user_id, $order_id));
    }

    public function facts(int $actor_user_id, int $order_id): EVC_Bacs_Recovery_Facts {
        $intent = $this->intents->read($order_id);
        $order = $this->order_facts($order_id);
        $customer = $order === null ? 0 : $order->customer_id;
        $rows = $customer > 0 ? $this->pmpro_rows($customer) : null;

        $club_readable = true;
        $movement = null;
        $period = null;
        $corrections = array();
        $owner = null;
        $plan = null;
        if ($intent->outcome === EVC_Bacs_Intent_Read::VALID) {
            $i = $intent->intent;
            try {
                $movement = $this->store->movement($i->movement_id());
                $period = $movement === null ? null : $this->store->period_for_movement($i->movement_id());
                $corrections = $this->store->corrections_for($i->movement_id());
                $owner = $this->store->movement_for_alias((new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $i->order_anchor()))->key());
                if ($movement === null) {
                    $plan = $this->recorder->plan(self::prospective($i));
                }
            } catch (Throwable $e) {
                $club_readable = false;
            }
        }
        return new EVC_Bacs_Recovery_Facts(
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            $this->config->level_id,
            $actor_user_id,
            self::authorized($actor_user_id),
            $intent,
            $order,
            $rows,
            $club_readable,
            $movement,
            $period,
            $corrections,
            $owner,
            $plan
        );
    }

    /** The payment the intent describes, as a prospective (row-less, unconfirmed) payment. */
    public static function prospective(EVC_Bacs_Intent $i): EVC_Prospective_Payment {
        return new EVC_Prospective_Payment(
            $i->movement_id(),
            $i->environment(),
            $i->customer_id(),
            $i->level_id(),
            $i->verified_at_utc(),
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY,
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $i->order_anchor())),
            EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN,
            EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION
        );
    }

    /** Same rule as the confirmation boundary: the capability, never the shared staff account. */
    private static function authorized(int $actor_user_id): bool {
        $actor = $actor_user_id > 0 ? get_userdata($actor_user_id) : false;
        return $actor instanceof WP_User
            && user_can($actor, EVC_Bacs_Payment_Confirmation::CAPABILITY)
            && !EVC_Staff_Role::has_staff_role($actor);
    }

    private function order_facts(int $order_id): ?EVC_Bacs_Order_Facts {
        try {
            $order = $order_id > 0 ? wc_get_order($order_id) : false;
            if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund) {
                return null;
            }
            $items = array_values($order->get_items('line_item'));
            $single = count($items) === 1
                && (int) $items[0]->get_product_id() === $this->config->product_id
                && (int) $items[0]->get_quantity() === 1;
            return new EVC_Bacs_Order_Facts(
                (int) $order->get_id(),
                (int) $order->get_customer_id(),
                (string) $order->get_status(),
                (string) $order->get_payment_method(),
                (string) $order->get_currency(),
                (string) wc_format_decimal($order->get_total(), 2),
                $single
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return EVC_Pmpro_Membership_Row[]|null */
    private function pmpro_rows(int $wp_user_id): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, membership_id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d ORDER BY id ASC", $wp_user_id),
            ARRAY_A
        );
        if (!is_array($rows) || $wpdb->last_error !== '') {
            return null;
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = new EVC_Pmpro_Membership_Row(
                $this->config->ref('pmpro_membership_row', (string) $r['id']),
                (int) $r['membership_id'],
                (string) $r['status'],
                $r['startdate'] === null ? null : (string) $r['startdate'],
                $r['enddate'] === null ? null : (string) $r['enddate'],
                false
            );
        }
        return $out;
    }
}
