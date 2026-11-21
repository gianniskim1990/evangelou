<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the gated Club database side of member push notifications, shared by
 * /me/push (EVC_Rest_Me_Push_Controller), the administrator's sending page and the automatic notifications.
 * Constructed only by EVC_Push_Plugin::wire() behind EVC_CLUB_PUSH_ENABLED (packager-enforced).
 *
 * Every call re-checks, in this order, before anything is read (no Club DB connection before step 5):
 *   1. EVC_CLUB_PUSH_ENABLED === true and EVC_CLUB_CUSTOMER_ENABLED === true;
 *   2. EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1' (members exist only then);
 *   3. usable VAPID keys (EVC_Push_Vapid::keys_problem() === null: fail closed, the values are never logged);
 *   4. the Club DB constants;  5. the Club DB connects;  6. schema MAX(version) >= 5 (migration 005).
 * Any failure -> 'unavailable' (the route answers 503 {"result":"unavailable"}; the member app hides the control).
 * Below 5 nothing is written and nothing fails loudly: push simply does not exist yet.
 *
 * The member is ALWAYS the caller's own (the controller passes get_current_user_id()). Logs carry a fixed reason and an
 * exception class only: never an endpoint, key, member id or name.
 * The constructor parameters are TEST seams; the shipped wiring passes none.
 */
final class EVC_Push_Backend {
    const PROVENANCE = 'club_db_v1';
    const LOCK_WAIT_SECONDS = 5;

    const REASON_ENVIRONMENT = 'environment_unreadable';
    const REASON_FLAG = 'push_flag_off';
    const REASON_PROVENANCE = 'payment_provenance_not_recorded';
    const REASON_VAPID = 'vapid_keys_unusable';
    const REASON_DB_CONFIG = 'club_db_not_configured';
    const REASON_CONNECT = 'club_db_unavailable';
    const REASON_SCHEMA = 'club_db_schema_below_5';
    const REASON_FAILED = 'push_failed';

    /** @var callable(): array */
    private $environment;
    /** @var callable(EVC_Db_Config): EVC_Club_Db */
    private $connect;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable(string): void */
    private $log;
    /** @var string|null */
    private $last_reason = null;
    /** @var array{public:string,private:string,subject:string}|null the keys of the current open() (never logged) */
    private $keys = null;

    public function __construct(?callable $environment = null, ?callable $connect = null, ?EVC_Clock_Source $clock = null, ?callable $log = null) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->environment = $environment ?? array(__CLASS__, 'constants');
        $this->connect = $connect ?? function (EVC_Db_Config $config) {
            return EVC_Club_Db::connect($config);
        };
        $this->clock = $clock ?? new EVC_System_Clock();
        $this->log = $log ?? function (string $line) {
            error_log($line);
        };
    }

    /** @return array{push:mixed,customer:mixed,provenance:mixed,db:?EVC_Db_Config,vapid:array} */
    public static function constants(): array {
        return array(
            'push' => defined(EVC_Push_Feature::CONSTANT) ? constant(EVC_Push_Feature::CONSTANT) : null,
            'customer' => defined(EVC_Customer_Feature::CONSTANT) ? constant(EVC_Customer_Feature::CONSTANT) : null,
            'provenance' => defined('EVC_CLUB_PAYMENT_PROVENANCE') ? constant('EVC_CLUB_PAYMENT_PROVENANCE') : null,
            'db' => EVC_Db_Config::from_constants(),
            'vapid' => EVC_Push_Vapid::constants(),
        );
    }

    public function last_reason(): ?string {
        return $this->last_reason;
    }

    public function clock(): EVC_Clock_Source {
        return $this->clock;
    }

    public function now_utc(): string {
        return EVC_Clock::to_utc($this->clock->now())->format('Y-m-d H:i:s.u');
    }

    /** @return array{state:string} state: ready | not_member | member_disabled | unavailable */
    public function status(int $wp_user_id): array {
        $db = $this->open();
        $this->keys = null;
        if ($db === null) {
            return array('state' => 'unavailable');
        }
        try {
            $member = self::member($db, $wp_user_id);
            if ($member === null) {
                return array('state' => 'not_member');
            }
            return array('state' => $member['status'] === 'enabled' ? 'ready' : 'member_disabled');
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('state' => 'unavailable');
        }
    }

    /**
     * The member's explicit consent: store (or refresh) this browser's subscription. The caller validated the values.
     * @return array{result:string} result: created | refreshed | renewed | taken | not_member | member_disabled | unavailable
     */
    public function subscribe(int $wp_user_id, string $endpoint, string $p256dh, string $auth, string $agent): array {
        $db = $this->open();
        $this->keys = null;
        if ($db === null) {
            return array('result' => 'unavailable');
        }
        try {
            $member = self::member($db, $wp_user_id);
            if ($member === null) {
                return array('result' => 'not_member');
            }
            if ($member['status'] !== 'enabled') {
                return array('result' => 'member_disabled');
            }
            $service = (string) EVC_Push_Endpoint::service($endpoint);
            return array('result' => (new EVC_Sql_Push_Subscription_Store($db))->subscribe($member['id'], $endpoint, $p256dh, $auth, $service, $agent, $this->now_utc()));
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('result' => 'unavailable');
        }
    }

    /**
     * «Διακοπή ειδοποιήσεων» (or signing out): only this member's row of that endpoint is revoked.
     * @return array{result:string} result: stopped | not_member | unavailable
     */
    public function unsubscribe(int $wp_user_id, string $endpoint): array {
        $db = $this->open(false);
        $this->keys = null;
        if ($db === null) {
            return array('result' => 'unavailable');
        }
        try {
            $member = self::member($db, $wp_user_id);
            if ($member === null) {
                return array('result' => 'not_member');
            }
            (new EVC_Sql_Push_Subscription_Store($db))->unsubscribe($member['id'], $endpoint, $this->now_utc());
            return array('result' => 'stopped');
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('result' => 'unavailable');
        }
    }

    /**
     * For sending: the store on a gated connection and the VAPID keys of this request, or null (reason in last_reason()).
     * @return array{store:EVC_Sql_Push_Subscription_Store,keys:array{public:string,private:string,subject:string}}|null
     */
    public function sending(): ?array {
        $db = $this->open();
        if ($db === null) {
            return null;
        }
        $keys = $this->keys;
        $this->keys = null;
        return array('store' => new EVC_Sql_Push_Subscription_Store($db), 'keys' => $keys);
    }

    /** @return array{id:int,status:string}|null */
    private static function member(EVC_Club_Db $db, int $wp_user_id): ?array {
        if ($wp_user_id <= 0) {
            return null;
        }
        $row = $db->fetch_one('SELECT id, status FROM evc_members WHERE wp_user_id = ?', array($wp_user_id));
        return $row === null ? null : array('id' => (int) $row['id'], 'status' => (string) $row['status']);
    }

    /**
     * The gated connection for this request, or null (reason logged). $needs_keys: stopping notifications works even
     * when the keys were removed (a member can always stop them), subscribing and sending need them.
     */
    private function open(bool $needs_keys = true): ?EVC_Club_Db {
        $this->last_reason = null;
        $this->keys = null;
        try {
            $env = ($this->environment)();
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_ENVIRONMENT, $e);
        }
        if (!is_array($env)) {
            return $this->refuse(self::REASON_ENVIRONMENT);
        }
        if (!EVC_Push_Feature::is_enabled_value($env['push'] ?? null) || !EVC_Customer_Feature::is_enabled_value($env['customer'] ?? null)) {
            return $this->refuse(self::REASON_FLAG);
        }
        if (($env['provenance'] ?? null) !== self::PROVENANCE) {
            return $this->refuse(self::REASON_PROVENANCE);
        }
        $vapid = is_array($env['vapid'] ?? null) ? $env['vapid'] : array();
        $keys_ok = EVC_Push_Vapid::keys_problem($vapid['public'] ?? null, $vapid['private'] ?? null, $vapid['subject'] ?? null) === null;
        if ($needs_keys && !$keys_ok) {
            return $this->refuse(self::REASON_VAPID);
        }
        $config = $env['db'] ?? null;
        if (!$config instanceof EVC_Db_Config) {
            return $this->refuse(self::REASON_DB_CONFIG);
        }
        try {
            $db = ($this->connect)($config);
            if (!$db instanceof EVC_Club_Db) {
                return $this->refuse(self::REASON_CONNECT);
            }
            $db->exec_raw('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_CONNECT, $e);
        }
        try {
            $row = $db->fetch_one('SELECT MAX(version) AS v FROM evc_schema_migrations');
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_SCHEMA, $e);
        }
        if ($row === null || $row['v'] === null || (int) $row['v'] < EVC_Push_Feature::SCHEMA_VERSION) {
            return $this->refuse(self::REASON_SCHEMA);
        }
        if ($keys_ok) {
            $this->keys = array('public' => (string) $vapid['public'], 'private' => (string) $vapid['private'], 'subject' => (string) $vapid['subject']);
        }
        return $db;
    }

    /** @return null always */
    private function refuse(string $reason, ?Throwable $e = null) {
        $this->last_reason = $reason;
        try {
            ($this->log)('Evangelou Club: push unavailable (' . $reason . ($e === null ? '' : ', ' . self::safe(get_class($e))) . ').');
        } catch (Throwable $ignored) {
            // never changes the answer
        }
        return null;
    }

    private static function safe($value): string {
        $s = preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
        return $s === '' ? '-' : substr($s, 0, 64);
    }
}
