<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05C: the customer's own Club QR (owner decisions D6 / D7, 2026-11-04).
 *
 * Registered (EVC_Customer_Plugin::register_routes(), the ONLY construction site, packager-enforced)
 * only while EVC_CLUB_CUSTOMER_ENABLED === true AND EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1'.
 * Every call re-checks, in this order, before anything is read (no Club DB connection before step 4):
 *   1. EVC_CLUB_CUSTOMER_ENABLED === true;  2. EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1';
 *   3. the Club DB constants (EVC_Db_Config);  4. the Club DB connects;  5. schema MAX(version) >= 2.
 * Any failure → 'unavailable' (the route answers 503 {"result":"unavailable"}).
 *
 * Rules:
 *  - Only a WordPress user with an `evc_members` row (created by STAGE-05B after a recorded payment)
 *    has a QR. A QR is identity only: it never grants a coffee (the redeem route re-checks).
 *  - D6: no age limit; a QR changes ONLY when the customer asks (replace = true) or staff revoke it.
 *    issue() without replace when a QR is already active answers 'qr_exists' and changes nothing.
 *  - Only the SHA-256 digest is stored, so the plaintext is returned ONLY by issue() (first QR or
 *    replacement). The customer app keeps it on the device (D6/D7: a screenshot works; one coffee per
 *    Athens day limits any loss). status() never returns a token, only the active QR's issue time,
 *    so the app can tell that its stored QR is still the current one.
 *  - The member is ALWAYS the caller's own (EVC_Qr_Issuance_Requester::member_self from the server
 *    session; the controller passes get_current_user_id()).
 * Logs: a fixed reason / stage plus an exception class only; never a token, digest, id or name.
 * The constructor parameters are TEST seams; the shipped wiring passes none (packager).
 */
final class EVC_Customer_Qr_Backend {
    const PROVENANCE = 'club_db_v1';
    const MIN_SCHEMA_VERSION = 2;

    const REASON_ENVIRONMENT = 'environment_unreadable';
    const REASON_CUSTOMER = 'customer_flag_off';
    const REASON_PROVENANCE = 'payment_provenance_not_recorded';
    const REASON_DB_CONFIG = 'club_db_not_configured';
    const REASON_CONNECT = 'club_db_unavailable';
    const REASON_SCHEMA = 'club_db_schema_too_old';
    const REASON_FAILED = 'qr_failed';

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

    public function __construct(?callable $environment = null, ?callable $connect = null, ?EVC_Clock_Source $clock = null, ?callable $log = null) {
        $this->environment = $environment ?? array(__CLASS__, 'constants');
        $this->connect = $connect ?? function (EVC_Db_Config $config) {
            return EVC_Club_Db::connect($config);
        };
        $this->clock = $clock ?? new EVC_System_Clock();
        $this->log = $log ?? function (string $line) {
            error_log($line);
        };
    }

    /** Route registration gate (with the customer flag checked by the plugin before it). */
    public static function provenance_recorded(): bool {
        return defined('EVC_CLUB_PAYMENT_PROVENANCE') && constant('EVC_CLUB_PAYMENT_PROVENANCE') === self::PROVENANCE;
    }

    /** @return array{customer:mixed,provenance:mixed,db:?EVC_Db_Config} */
    public static function constants(): array {
        return array(
            'customer' => defined(EVC_Customer_Feature::CONSTANT) ? constant(EVC_Customer_Feature::CONSTANT) : null,
            'provenance' => defined('EVC_CLUB_PAYMENT_PROVENANCE') ? constant('EVC_CLUB_PAYMENT_PROVENANCE') : null,
            'db' => EVC_Db_Config::from_constants(),
        );
    }

    public function last_reason(): ?string {
        return $this->last_reason;
    }

    /**
     * @return array{state:string,issued_at_utc?:string}
     *   state: unavailable | not_member | member_disabled | none | active (+ issued_at_utc)
     */
    public function status(int $wp_user_id): array {
        $db = $this->open();
        if ($db === null) {
            return array('state' => 'unavailable');
        }
        try {
            $store = EVC_Club_Qr_Services::store($db);
            $member = $store->find_member_by_wp_user($wp_user_id);
            if ($member === null) {
                return array('state' => 'not_member');
            }
            if ($member['status'] !== 'enabled') {
                return array('state' => 'member_disabled');
            }
            $hash = $store->active_token_hash($member['id']);
            $token = $hash === null ? null : $store->find_token_by_hash($hash);
            if ($token === null || $token['status'] !== 'active') {
                return array('state' => 'none');
            }
            return array('state' => 'active', 'issued_at_utc' => $token['issued_at_utc']);
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('state' => 'unavailable');
        }
    }

    /**
     * @return array{result:string,token?:string,issued_at_utc?:string}
     *   result: issued (+ token, issued_at_utc) | unavailable | not_member | member_disabled | qr_exists
     *           | concurrent_change | outcome_unknown
     */
    public function issue(int $wp_user_id, bool $replace): array {
        $db = $this->open();
        if ($db === null) {
            return array('result' => 'unavailable');
        }
        try {
            $store = EVC_Club_Qr_Services::store($db);
            $member = $store->find_member_by_wp_user($wp_user_id);
            if ($member === null) {
                return array('result' => 'not_member');
            }
            if ($member['status'] !== 'enabled') {
                return array('result' => 'member_disabled');
            }
            if (!$replace && $store->active_token_hash($member['id']) !== null) {
                return array('result' => 'qr_exists');
            }
            $log = $this->log;
            $result = EVC_Club_Qr_Services::token_service($db, $this->clock, function (array $d) use ($log) {
                $log('Evangelou Club: customer QR error (stage=' . self::safe($d['stage'] ?? null) . ', exception=' . self::safe($d['exception'] ?? null) . ').');
            }, !$replace)->issue(EVC_Qr_Issuance_Requester::member_self($wp_user_id), $member['member_public_id']);
            switch ($result->kind()) {
                case EVC_Qr_Token_Result::ISSUED:
                    return array('result' => 'issued', 'token' => (string) $result->token(), 'issued_at_utc' => (string) $result->issued_at_utc());
                case EVC_Qr_Token_Result::CONCURRENT_CHANGE:
                    // Another device of the same customer wrote first (without replace: a QR now exists).
                    return array('result' => 'concurrent_change');
                case EVC_Qr_Token_Result::OUTCOME_UNKNOWN:
                    return array('result' => 'outcome_unknown');
                case EVC_Qr_Token_Result::MEMBER_DISABLED:
                    return array('result' => 'member_disabled');
                default:
                    // forbidden / member_not_found / invalid_request cannot happen for the caller's own
                    // row read just above; server_error and anything else: unavailable.
                    return array('result' => 'unavailable');
            }
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('result' => 'unavailable');
        }
    }

    /** The gated, prepared Club DB connection for this request, or null (reason logged). */
    private function open(): ?EVC_Club_Db {
        $this->last_reason = null;
        try {
            $env = ($this->environment)();
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_ENVIRONMENT, $e);
        }
        if (!is_array($env)) {
            return $this->refuse(self::REASON_ENVIRONMENT);
        }
        if (!EVC_Customer_Feature::is_enabled_value($env['customer'] ?? null)) {
            return $this->refuse(self::REASON_CUSTOMER);
        }
        if (($env['provenance'] ?? null) !== self::PROVENANCE) {
            return $this->refuse(self::REASON_PROVENANCE);
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
            EVC_Club_Qr_Services::prepare_connection($db);
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_CONNECT, $e);
        }
        try {
            $row = $db->fetch_one('SELECT MAX(version) AS v FROM evc_schema_migrations');
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_SCHEMA, $e);
        }
        if ($row === null || $row['v'] === null || (int) $row['v'] < self::MIN_SCHEMA_VERSION) {
            return $this->refuse(self::REASON_SCHEMA);
        }
        return $db;
    }

    /** @return null always */
    private function refuse(string $reason, ?Throwable $e = null) {
        $this->last_reason = $reason;
        try {
            ($this->log)('Evangelou Club: customer QR unavailable (' . $reason . ($e === null ? '' : ', ' . self::safe(get_class($e))) . ').');
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
