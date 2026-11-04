<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05C: staff QR scan → member + first name + status (owner decision D8), the failed-scan limit
 * (D9, migration 003) and the administrator QR revocation (D6), on real memberships.
 *
 * Constructed ONLY in EVC_Staff_Plugin::register_routes(), after the staff gate, inside
 * `if (EVC_Club_Redemption_Feature::enabled()) { ... }` (packager rule set qrProblemsFor): the routes
 * do not even exist without EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1'. Every call re-checks, in this
 * order, before anything is read (no Club DB connection before step 7):
 *   1. EVC_CLUB_STAFF_ENABLED === true;  2. EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1';
 *   3. the commerce source (EVC_Commerce_Source_Config);  4. EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1';
 *   5. the Club DB constants;  6. EVC_CLUB_LOOKUP_KEY (>= 32 bytes, the lookup-proof secret);
 *   7. the Club DB connects;  8. schema MAX(version) >= 3 (the failure counters).
 * Any failure → 'unavailable' (503). The membership source is EXACTLY the STAGE-05A redeem route's
 * (Club-recorded provenance, no fallback), so "active" here means what the redeem route will accept
 * at this instant; the redeem route still re-checks everything when the coffee is recorded.
 *
 * Lookup answers (identity is the reviewed UI-04C EVC_Member_Lookup_Service):
 *   found      member_id, first_name (WordPress first name, else WooCommerce billing first name, else
 *              null; at most 40 characters; never a surname, phone, e-mail or any other data) and
 *              status: active | used_today | inactive (frozen, expired, unverified, pending, none …)
 *   invalid_qr the scanned text is not a Club QR at all (counted as a failure)
 *   not_found  ONE answer for unknown / revoked / replaced / disabled-member tokens: it never reveals
 *              whether a token ever existed (counted as a failure)
 *   rate_limited · forbidden · unavailable
 * Logs: a fixed reason / stage plus an exception class only; never a token, digest, name or id.
 * The constructor parameters are TEST seams; the shipped wiring passes none (packager).
 */
final class EVC_Club_Lookup_Backend {
    const PROVENANCE = 'club_db_v1';
    const MIN_SCHEMA_VERSION = 3;
    const FIRST_NAME_MAX = 40;

    const REASON_ENVIRONMENT = 'environment_unreadable';
    const REASON_STAFF = 'staff_flag_off';
    const REASON_REDEMPTION = 'redemption_source_off';
    const REASON_COMMERCE = 'commerce_source_not_configured';
    const REASON_PROVENANCE = 'payment_provenance_not_recorded';
    const REASON_DB_CONFIG = 'club_db_not_configured';
    const REASON_KEY = 'lookup_key_missing';
    const REASON_CONNECT = 'club_db_unavailable';
    const REASON_SCHEMA = 'club_db_schema_too_old';
    const REASON_FAILED = 'lookup_failed';

    /** @var callable(): array */
    private $environment;
    /** @var callable(EVC_Db_Config): EVC_Club_Db */
    private $connect;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable(string): void */
    private $log;
    /** @var callable(int): ?string */
    private $first_name;
    /** @var callable(EVC_Commerce_Source_Config, EVC_Club_Db): EVC_Membership_Adapter */
    private $membership;
    /** @var string|null */
    private $last_reason = null;

    public function __construct(?callable $environment = null, ?callable $connect = null, ?EVC_Clock_Source $clock = null, ?callable $log = null, ?callable $first_name = null, ?callable $membership = null) {
        $this->environment = $environment ?? array(__CLASS__, 'constants');
        $this->connect = $connect ?? function (EVC_Db_Config $config) {
            return EVC_Club_Db::connect($config);
        };
        $this->clock = $clock ?? new EVC_System_Clock();
        $this->log = $log ?? function (string $line) {
            error_log($line);
        };
        $this->first_name = $first_name ?? array(__CLASS__, 'wordpress_first_name');
        $this->membership = $membership ?? array(__CLASS__, 'recorded_membership');
    }

    /**
     * EXACTLY the STAGE-05A redeem route's membership source: WooCommerce / PMPro read-only, with the
     * Club's own recorded payment provenance on the same Club DB connection (no fallback).
     */
    public static function recorded_membership(EVC_Commerce_Source_Config $commerce, EVC_Club_Db $db): EVC_Membership_Adapter {
        $provenance = new EVC_Store_Club_Payment_Provenance(function () use ($db) {
            return new EVC_Sql_Provenance_Store($db, function () {
                throw new LogicException('The QR lookup never writes payment provenance.');
            }, EVC_Bacs_Payment_Confirmation::ENVIRONMENT);
        }, $commerce);
        return new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($commerce, $provenance), $commerce->mapper());
    }

    /** @return array{staff:mixed,redemption:mixed,provenance:mixed,commerce:?EVC_Commerce_Source_Config,db:?EVC_Db_Config,lookup_key:?string} */
    public static function constants(): array {
        return array(
            'staff' => defined(EVC_Staff_Feature::CONSTANT) ? constant(EVC_Staff_Feature::CONSTANT) : null,
            'redemption' => defined(EVC_Club_Redemption_Feature::CONSTANT) ? constant(EVC_Club_Redemption_Feature::CONSTANT) : null,
            'provenance' => defined('EVC_CLUB_PAYMENT_PROVENANCE') ? constant('EVC_CLUB_PAYMENT_PROVENANCE') : null,
            'commerce' => EVC_Commerce_Source_Config::from_constants(),
            'db' => EVC_Db_Config::from_constants(),
            'lookup_key' => EVC_Club_Qr_Services::lookup_secret_from_constants(),
        );
    }

    /**
     * D8 «μικρό όνομα»: the FIRST WORD of the first name as the customer entered it (WordPress, else
     * WooCommerce billing), at most 40 characters; null when there is none.
     */
    public static function wordpress_first_name(int $wp_user_id): ?string {
        foreach (array('first_name', 'billing_first_name') as $key) {
            $name = trim((string) get_user_meta($wp_user_id, $key, true));
            if ($name !== '') {
                $words = preg_split('/\s+/u', $name);
                $first = is_array($words) && $words[0] !== '' ? $words[0] : $name;
                return function_exists('mb_substr') ? mb_substr($first, 0, self::FIRST_NAME_MAX) : substr($first, 0, self::FIRST_NAME_MAX);
            }
        }
        return null;
    }

    public function last_reason(): ?string {
        return $this->last_reason;
    }

    /**
     * @param mixed $token the scanned text
     * @return array{result:string,member_id?:string,first_name?:?string,status?:string}
     */
    public function lookup(EVC_Staff_Actor $actor, $token): array {
        $open = $this->open();
        if ($open === null) {
            return array('result' => 'unavailable');
        }
        list($db, $commerce, $secret) = $open;
        try {
            $found = EVC_Club_Qr_Services::lookup_service($db, $secret, $this->clock, $this->reporter('member_lookup'))->by_qr($actor, $token);
            switch ($found->kind()) {
                case EVC_Member_Lookup_Result::FOUND:
                    break;
                case EVC_Member_Lookup_Result::NOT_FOUND:
                    return array('result' => EVC_Qr_Token::is_valid_format($token) ? 'not_found' : 'invalid_qr');
                case EVC_Member_Lookup_Result::RATE_LIMITED:
                    return array('result' => 'rate_limited');
                case EVC_Member_Lookup_Result::FORBIDDEN:
                    return array('result' => 'forbidden');
                default:
                    return array('result' => 'unavailable');
            }
            $public_id = (string) $found->member()['member_public_id'];
            $member = EVC_Club_Qr_Services::store($db)->find_member_by_public_id($public_id);
            if ($member === null || $member['status'] !== 'enabled') {
                return array('result' => 'not_found');
            }
            return array(
                'result' => 'found',
                'member_id' => $public_id,
                'first_name' => ($this->first_name)($member['wp_user_id']),
                'status' => $this->status($db, $commerce, $member),
            );
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('result' => 'unavailable');
        }
    }

    /**
     * D6: an administrator (evc_manage_club, never the shared tablet account) retires a customer's QR,
     * e.g. after a lost phone; the customer's app then gets a new one at its next open.
     *
     * @return array{result:string} revoked | nothing_to_revoke | not_a_member | forbidden | concurrent_change | unavailable
     */
    public function revoke_for_wp_user(EVC_Staff_Actor $admin, int $wp_user_id): array {
        $open = $this->open();
        if ($open === null) {
            return array('result' => 'unavailable');
        }
        $db = $open[0];
        try {
            $member = EVC_Club_Qr_Services::store($db)->find_member_by_wp_user($wp_user_id);
            if ($member === null) {
                return array('result' => 'not_a_member');
            }
            $result = EVC_Club_Qr_Services::token_service($db, $this->clock, $this->reporter('qr_token'), false)
                ->revoke(EVC_Qr_Issuance_Requester::administrator($admin), $member['member_public_id']);
            $map = array(
                EVC_Qr_Token_Result::REVOKED => 'revoked',
                EVC_Qr_Token_Result::NOTHING_TO_REVOKE => 'nothing_to_revoke',
                EVC_Qr_Token_Result::FORBIDDEN => 'forbidden',
                EVC_Qr_Token_Result::CONCURRENT_CHANGE => 'concurrent_change',
            );
            return array('result' => $map[$result->kind()] ?? 'unavailable');
        } catch (Throwable $e) {
            $this->refuse(self::REASON_FAILED, $e);
            return array('result' => 'unavailable');
        }
    }

    /** active | used_today | inactive, decided at this instant from the redeem route's own sources. */
    private function status(EVC_Club_Db $db, EVC_Commerce_Source_Config $commerce, array $member): string {
        $now = EVC_Clock::to_utc($this->clock->now());
        $membership = ($this->membership)($commerce, $db);
        if ($membership->entitlement_for($member['wp_user_id'], $now)->denial_reason($now) !== null) {
            return 'inactive';
        }
        $today = (new EVC_Redemption_Store($db))->find_for_day($member['id'], EVC_Redemption_Service::BENEFIT_FREE_COFFEE, EVC_Clock::business_date($now));
        return $today === null ? 'active' : 'used_today';
    }

    /** @return array{0:EVC_Club_Db,1:EVC_Commerce_Source_Config,2:string}|null */
    private function open(): ?array {
        $this->last_reason = null;
        try {
            $env = ($this->environment)();
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_ENVIRONMENT, $e);
        }
        if (!is_array($env)) {
            return $this->refuse(self::REASON_ENVIRONMENT);
        }
        if (!EVC_Staff_Feature::is_enabled_value($env['staff'] ?? null)) {
            return $this->refuse(self::REASON_STAFF);
        }
        if (!EVC_Club_Redemption_Feature::is_enabled_value($env['redemption'] ?? null)) {
            return $this->refuse(self::REASON_REDEMPTION);
        }
        $commerce = $env['commerce'] ?? null;
        if (!$commerce instanceof EVC_Commerce_Source_Config) {
            return $this->refuse(self::REASON_COMMERCE);
        }
        if (($env['provenance'] ?? null) !== self::PROVENANCE) {
            return $this->refuse(self::REASON_PROVENANCE);
        }
        $config = $env['db'] ?? null;
        if (!$config instanceof EVC_Db_Config) {
            return $this->refuse(self::REASON_DB_CONFIG);
        }
        $secret = $env['lookup_key'] ?? null;
        if (!EVC_Club_Qr_Services::is_lookup_secret($secret)) {
            return $this->refuse(self::REASON_KEY);
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
        return array($db, $commerce, $secret);
    }

    private function reporter(string $label): callable {
        $log = $this->log;
        return function (array $d) use ($log, $label) {
            $log('Evangelou Club: staff QR error (' . $label . ', stage=' . self::safe($d['stage'] ?? null) . ', exception=' . self::safe($d['exception'] ?? null) . ').');
        };
    }

    /** @return null always */
    private function refuse(string $reason, ?Throwable $e = null) {
        $this->last_reason = $reason;
        try {
            ($this->log)('Evangelou Club: staff QR unavailable (' . $reason . ($e === null ? '' : ', ' . self::safe(get_class($e))) . ').');
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
