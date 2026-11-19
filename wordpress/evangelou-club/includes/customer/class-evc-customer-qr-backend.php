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
 * Every call ALSO needs (step 3b, before any connection) a valid EVC_CLUB_QR_SECRET: a string of at least
 * 32 bytes that differs from EVC_CLUB_LOOKUP_KEY (EVC_Qr_Token::is_derivation_secret); without it the route
 * answers 'unavailable' (fail closed, REASON_QR_SECRET).
 *
 * Rules:
 *  - Only a WordPress user with an `evc_members` row (created by STAGE-05B after a recorded payment)
 *    has a QR. A QR is identity only: it never grants a coffee (the redeem route re-checks).
 *  - Owner decision D22 (2026-11-19, supersedes the client side of D21): the member's QR is the SAME on
 *    every device and browser and opening the card never changes it. issue() returns the member's stable
 *    token (EVC_Qr_Token::derive over the member's public id and generation) every time it is asked:
 *      active row == the stable token of the current generation  -> that token, nothing written;
 *      no active row (first QR, or after an administrator's revocation) -> the token of the NEXT
 *        generation is stored (a revocation therefore always gives a new code);
 *      an active row that is not the stable token (a random token issued before D22, e.g. on staging, or
 *        one derived under an earlier secret) -> replaced ONCE by the stable token of the next
 *        generation; from then on the same token comes back on every device.
 *    The customer can no longer replace the QR: `replace` is accepted for older cached apps and ignored.
 *    Only an administrator's revocation (EVC_Club_Lookup_Backend::revoke_for_wp_user) starts a new code.
 *  - D6 (no age limit) and D7 (a screenshot works) are unchanged. Only the SHA-256 digest is stored; the
 *    plaintext is derived again for the signed-in member only, and returned ONLY by issue() (POST, no-store).
 *    status() never returns a token, only the active QR's issue time.
 *  - The member is ALWAYS the caller's own (the controller passes get_current_user_id(); the member row is
 *    read by that WordPress user id), so one member can never receive another member's token.
 * Logs: a fixed reason / stage plus an exception class only; never a token, digest, id or name.
 * The constructor parameters are TEST seams; the shipped wiring passes none (packager).
 */
final class EVC_Customer_Qr_Backend {
    const PROVENANCE = 'club_db_v1';
    const MIN_SCHEMA_VERSION = 2;
    /** issue(): how often a lost race with another device of the same member is read again. */
    const STABLE_TRIES = 2;

    const REASON_ENVIRONMENT = 'environment_unreadable';
    const REASON_CUSTOMER = 'customer_flag_off';
    const REASON_PROVENANCE = 'payment_provenance_not_recorded';
    const REASON_DB_CONFIG = 'club_db_not_configured';
    const REASON_QR_SECRET = 'qr_secret_missing';
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
    /** @var string|null the derivation secret of the current request (set by open(); never logged) */
    private $secret = null;

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

    /** @return array{customer:mixed,provenance:mixed,db:?EVC_Db_Config,qr_secret:mixed,lookup_key:mixed} */
    public static function constants(): array {
        return array(
            'customer' => defined(EVC_Customer_Feature::CONSTANT) ? constant(EVC_Customer_Feature::CONSTANT) : null,
            'provenance' => defined('EVC_CLUB_PAYMENT_PROVENANCE') ? constant('EVC_CLUB_PAYMENT_PROVENANCE') : null,
            'db' => EVC_Db_Config::from_constants(),
            'qr_secret' => defined(EVC_Qr_Token::SECRET_CONSTANT) ? constant(EVC_Qr_Token::SECRET_CONSTANT) : null,
            'lookup_key' => defined(EVC_Club_Qr_Services::LOOKUP_KEY_CONSTANT) ? constant(EVC_Club_Qr_Services::LOOKUP_KEY_CONSTANT) : null,
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
     * D22: the signed-in member's stable QR (see the class comment). There is no `replace`: a customer can
     * never revoke or change the QR.
     *
     * @return array{result:string,token?:string,issued_at_utc?:string}
     *   result: issued (+ token, issued_at_utc) | unavailable | not_member | member_disabled
     *           | concurrent_change | outcome_unknown
     */
    public function issue(int $wp_user_id): array {
        $db = $this->open();
        if ($db === null) {
            return array('result' => 'unavailable');
        }
        $secret = (string) $this->secret;
        $this->secret = null;
        try {
            $store = EVC_Club_Qr_Services::store($db);
            $member = $store->find_member_by_wp_user($wp_user_id);
            if ($member === null) {
                return array('result' => 'not_member');
            }
            if ($member['status'] !== 'enabled') {
                return array('result' => 'member_disabled');
            }
            // Two tries: another device of the same member may store the same stable token at the same moment.
            for ($try = 0; $try < self::STABLE_TRIES; $try++) {
                $active = $store->active_token_hash($member['id']);
                $generation = $store->generation($member['id']);
                if ($active !== null && $generation >= 1) {
                    $token = EVC_Qr_Token::derive($secret, $member['member_public_id'], $generation);
                    if (hash_equals($active, EVC_Qr_Token::digest($token))) {
                        $row = $store->find_token_by_hash($active);
                        if ($row !== null && $row['status'] === 'active' && $row['member_id'] === $member['id']) {
                            return array('result' => 'issued', 'token' => $token, 'issued_at_utc' => $row['issued_at_utc']);
                        }
                    }
                }
                // None yet (first QR / after a revocation) or not the stable one (before D22): the next generation.
                $token = EVC_Qr_Token::derive($secret, $member['member_public_id'], $generation + 1);
                $now = EVC_Clock::to_utc($this->clock->now())->format('Y-m-d H:i:s.u');
                try {
                    $store->rotate($member['id'], $active, EVC_Qr_Token::digest($token), $now);
                    return array('result' => 'issued', 'token' => $token, 'issued_at_utc' => $now);
                } catch (EVC_Qr_Rotation_Conflict $e) {
                    continue;
                } catch (EVC_Db_Duplicate_Key_Exception $e) {
                    continue;
                } catch (EVC_Db_Commit_Unknown_Exception $e) {
                    // Asking again is safe: the next call finds the stored stable token or stores it.
                    $this->refuse(self::REASON_FAILED, $e);
                    return array('result' => 'outcome_unknown');
                }
            }
            return array('result' => 'concurrent_change');
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('result' => 'unavailable');
        }
    }

    /** The gated, prepared Club DB connection for this request, or null (reason logged). */
    private function open(): ?EVC_Club_Db {
        $this->last_reason = null;
        $this->secret = null;
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
        // D22: no derivation secret, no QR (fail closed, before any connection).
        if (!EVC_Qr_Token::is_derivation_secret($env['qr_secret'] ?? null, $env['lookup_key'] ?? null)) {
            return $this->refuse(self::REASON_QR_SECRET);
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
        $this->secret = $env['qr_secret'];
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
