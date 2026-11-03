<?php
defined('ABSPATH') || exit;

/**
 * STAGE-05B: what kind of WordPress account a user is, for Club member identities
 * (UI-04F rule: staff, administrators and every privileged account are never
 * Club members).
 *
 * CUSTOMER only for an existing, non-super-admin user whose roles are all in
 * CUSTOMER_ROLES (WooCommerce `customer`, WordPress `subscriber`), who does not
 * hold the shared Club staff role and holds none of PRIVILEGED_CAPABILITIES.
 * Anything else that exists is RESTRICTED (fail closed: such a user never gets a
 * member row, whatever they paid). A user that does not exist is MISSING.
 */
final class EVC_Wp_Club_Account_Directory implements EVC_Wp_Account_Directory {
    const CUSTOMER_ROLES = array('customer', 'subscriber');
    const PRIVILEGED_CAPABILITIES = array('edit_posts', 'edit_shop_orders', 'manage_woocommerce', 'manage_options', 'list_users', 'edit_users', 'evc_confirm_bank_payment', 'evc_manage_club');

    public function account_kind(int $wp_user_id): string {
        $user = $wp_user_id > 0 ? get_userdata($wp_user_id) : false;
        if (!$user instanceof WP_User || !$user->exists()) {
            return self::MISSING;
        }
        if (is_super_admin($wp_user_id) || EVC_Staff_Role::has_staff_role($user)) {
            return self::RESTRICTED;
        }
        $roles = array_values((array) $user->roles);
        if ($roles === array()) {
            return self::RESTRICTED;
        }
        foreach ($roles as $role) {
            if (!in_array($role, self::CUSTOMER_ROLES, true)) {
                return self::RESTRICTED;
            }
        }
        foreach (self::PRIVILEGED_CAPABILITIES as $cap) {
            if (user_can($user, $cap)) {
                return self::RESTRICTED;
            }
        }
        return self::CUSTOMER;
    }
}

/**
 * STAGE-05B: the paying customer's Club member row (`evc_members`, migration 001)
 * after a payment confirmation the Club has RECORDED.
 *
 * Exists only behind EVC_CLUB_WC_COMPLETION_ENABLED: EVC_Wc_Completion_Plugin::wire()
 * is the only place that constructs it (packager rule; the constructor also refuses
 * while the constant is off) and hands it to the WooCommerce-completion confirmer and
 * its WP-Cron recovery. The manager BACS paths (EVC_Bacs_Payment_Confirmation /
 * _Finalizer / _Issuer) accept it as an optional argument but are themselves never
 * constructed by shipped code.
 *
 * What it does — and does not do:
 *  - It runs the reviewed UI-04F EVC_Member_Sync_Service (constructed ONLY in
 *    service() below) over the SQL identity store, the WordPress account directory
 *    and the SAME membership source the staff redeem route uses (PMPro rows + the
 *    Club's own recorded payment provenance). An identity is created only for an
 *    ordinary customer account with a verified, currently eligible membership; an
 *    existing row is never replaced, disabled or re-enabled.
 *  - A member row grants nothing by itself: eligibility for a coffee is decided at
 *    every redemption from the recorded provenance (D1/D2/D5, Option 2 unchanged).
 *  - Exactly one row per WordPress user, by the database's unique keys (never
 *    check-then-insert): two confirmations, retries, the recovery run and a
 *    confirmation at the same time all end with one row.
 *  - It runs only AFTER the confirmation is final and outside its member lock;
 *    every Throwable is caught here: a failure never changes, undoes or delays the
 *    recorded payment confirmation.
 *  - Recovery: reconcile() (on every WP-Cron recovery run) re-tries every customer
 *    whose LATEST recorded paid period has not ended and who has no member row yet
 *    (newest payments first, bounded). The next confirmation of the same customer
 *    re-tries too.
 *  - Audit: one `club_member_sync` event per confirmation-triggered attempt, and per
 *    reconcile attempt that created a row (opaque member row id, or the numeric
 *    WordPress user id when there is no row; never names, e-mail addresses, phones,
 *    payment data or exception texts). Logs: a fixed reason and an exception class.
 *  - No QR token is ever issued (D8 open); nothing is sent to FluentCRM.
 */
final class EVC_Club_Member_Sync implements EVC_Clock_Source {
    const EVENT = 'club_member_sync';
    const RECONCILE_BATCH = 20;
    const TRIGGER_CONFIRMATION = 'confirmation';
    const TRIGGER_RECONCILE = 'reconcile';
    /** The Club database could not be opened, or something unexpected was thrown here. */
    const UNAVAILABLE = 'unavailable';
    /** Results after which the connection is dropped and re-opened on the next attempt. */
    const RECONNECT_AFTER = array(EVC_Member_Sync_Result::SERVER_ERROR, EVC_Member_Sync_Result::OUTCOME_UNKNOWN, self::UNAVAILABLE);

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var callable(): EVC_Club_Db */
    private $open_db;
    /** @var callable(): DateTimeImmutable */
    private $now;
    /** @var callable(string): void */
    private $log;
    /** @var EVC_Club_Db|null opened lazily, once (again after a failure) */
    private $db;
    /** @var array<int,string> */
    private $last_reconcile = array();

    /**
     * @param callable(): EVC_Club_Db $open_db a connection of its own to the Club database
     * @param callable(): DateTimeImmutable $now
     * @param callable(string): void|null $log TEST seam; default: error_log
     */
    public function __construct(EVC_Commerce_Source_Config $config, callable $open_db, callable $now, ?callable $log = null) {
        EVC_Wc_Completion_Feature::assert_constructible(__CLASS__);
        $this->config = $config;
        $this->open_db = $open_db;
        $this->now = $now;
        $this->log = $log ?? function (string $line) {
            error_log($line);
        };
    }

    public function now(): DateTimeImmutable {
        return EVC_Clock::to_utc(($this->now)());
    }

    /**
     * After a payment confirmation the Club RECORDED (or found already recorded) for this
     * customer. Never throws. @return string an EVC_Member_Sync_Result kind or self::UNAVAILABLE
     */
    public function after_payment(int $wp_user_id): string {
        return $this->sync_one($wp_user_id, 'woocommerce', 'payment_changed', self::TRIGGER_CONFIRMATION);
    }

    /**
     * Recovery: customers whose latest recorded paid period has not ended and who have no member
     * row yet, newest payments first (at most $limit, never more than RECONCILE_BATCH). Never throws.
     *
     * @return array<int,string> WordPress user id => result kind of this run
     */
    public function reconcile(int $limit = self::RECONCILE_BATCH): array {
        $this->last_reconcile = array();
        try {
            $limit = max(1, min(self::RECONCILE_BATCH, $limit));
            $rows = $this->db()->fetch_all(
                'SELECT h.wp_user_id FROM evc_pay_member_heads h'
                . ' JOIN evc_pay_periods p ON p.movement_id = h.latest_movement_id AND p.wp_user_id = h.wp_user_id'
                . ' LEFT JOIN evc_members m ON m.wp_user_id = h.wp_user_id'
                . ' WHERE m.id IS NULL AND p.end_utc > ?'
                . ' ORDER BY h.updated_at_utc DESC, h.wp_user_id ASC LIMIT ?',
                array($this->now()->format('Y-m-d H:i:s.u'), $limit)
            );
        } catch (Throwable $e) {
            $this->db = null;
            $this->log_line('reconcile_unavailable', get_class($e));
            return array();
        }
        foreach ($rows as $row) {
            $wp_user_id = filter_var($row['wp_user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($wp_user_id === false || $wp_user_id < 1) {
                continue;
            }
            $this->last_reconcile[$wp_user_id] = $this->sync_one($wp_user_id, 'scheduled_reconcile', 'periodic_check', self::TRIGGER_RECONCILE);
        }
        return $this->last_reconcile;
    }

    /** @return array<int,string> the results of the last reconcile() */
    public function last_reconcile(): array {
        return $this->last_reconcile;
    }

    private function sync_one(int $wp_user_id, string $source, string $kind, string $trigger): string {
        $result = null;
        try {
            $db = $this->db();
            $result = $this->service($db)->sync(EVC_Member_Sync_Signal::of($source, $kind, $wp_user_id));
            $outcome = $result->kind();
        } catch (Throwable $e) {
            $db = null;
            $outcome = self::UNAVAILABLE;
            $this->log_line('unavailable', get_class($e));
        }
        if ($db !== null && ($trigger === self::TRIGGER_CONFIRMATION || $outcome === EVC_Member_Sync_Result::PROVISIONED)) {
            $this->audit($db, $outcome, $result !== null ? $result->member_public_id() : null, $wp_user_id, $trigger);
        }
        if (in_array($outcome, self::RECONNECT_AFTER, true)) {
            $this->db = null;
        }
        return $outcome;
    }

    /** The ONLY construction site of EVC_Member_Sync_Service in shipped code (packager rule). */
    private function service(EVC_Club_Db $db): EVC_Member_Sync_Service {
        $config = $this->config;
        $open_db = $this->open_db;
        $provenance = new EVC_Store_Club_Payment_Provenance(function () use ($db, $open_db) {
            return new EVC_Sql_Provenance_Store($db, $open_db, EVC_Bacs_Payment_Confirmation::ENVIRONMENT);
        }, $config);
        return new EVC_Member_Sync_Service(
            new EVC_Sql_Member_Identity_Store($db),
            new EVC_Wp_Club_Account_Directory(),
            new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($config, $provenance), $config->mapper()),
            $this,
            null,
            function (array $d) {
                $this->log_line((string) ($d['stage'] ?? 'member_sync'), (string) ($d['exception'] ?? ''));
            }
        );
    }

    private function db(): EVC_Club_Db {
        if ($this->db === null) {
            $db = ($this->open_db)();
            if (!$db instanceof EVC_Club_Db) {
                throw new UnexpectedValueException('No Club database connection.');
            }
            $this->db = $db;
        }
        return $this->db;
    }

    private function audit(EVC_Club_Db $db, string $outcome, ?string $public_id, int $wp_user_id, string $trigger): void {
        try {
            $member_id = null;
            if ($public_id !== null) {
                $row = $db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public_id));
                $member_id = $row !== null ? (int) $row['id'] : null;
            }
            $details = array('trigger' => $trigger);
            if ($member_id === null) {
                $details['customer'] = $wp_user_id;
            }
            (new EVC_Db_Audit_Log())->record($db, array(
                'event_type' => self::EVENT,
                'outcome' => $outcome,
                'member_id' => $member_id,
                'occurred_at_utc' => $this->now()->format('Y-m-d H:i:s.u'),
                'details' => $details,
            ));
        } catch (Throwable $e) {
            $this->log_line('audit_failed', get_class($e));
        }
    }

    /** A fixed reason and an exception class only ([A-Za-z0-9_]); never a message, host, user or id. */
    private function log_line(string $reason, string $exception): void {
        try {
            $clean = function (string $s): string {
                $s = (string) preg_replace('/[^A-Za-z0-9_]/', '', $s);
                return $s === '' ? '-' : substr($s, 0, 64);
            };
            ($this->log)('Evangelou Club: member sync ' . $clean($reason) . ' (' . $clean($exception) . ').');
        } catch (Throwable $ignored) {
            // Logging never changes anything.
        }
    }
}
