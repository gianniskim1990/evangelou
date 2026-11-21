<?php
defined('ABSPATH') || exit;
/**
 * Round 9 (owner decision D24): the REAL backend of the prepared-redemption route (coffee + preparation +
 * staff note), behind the SAME gates as the v1 redeem route: the staff flag and
 * EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1'. Constructed only in EVC_Staff_Plugin::register_routes(),
 * inside `if (EVC_Club_Redemption_Feature::enabled()) { ... }` after the staff gate (packager rule set
 * preparedProblemsFor); without the opt-in the route does not exist at all.
 *
 * Builds the UI-03D.A EVC_Prepared_Redemption_Service LAZILY, once per request, inside service(), from:
 *   store       EVC_Sql_Prep_Redemption_Store (migration 004) on the separate Club DB;
 *   catalogue   EVC_Club_Prep_Catalogue_Authority (the approved D24 document + its pinned digest);
 *   membership  EXACTLY the v1 route's source (recorded_membership(): WooCommerce / PMPro read-only with the
 *               Club's own recorded payment provenance, no fallback);
 *   clock       EVC_System_Clock (read once per redemption by the service).
 *
 * service() returns null unless every piece is there, in this order (no Club DB connection before step 6):
 *   1. the staff flag;  2. EVC_CLUB_REDEMPTION_SOURCE;  3. the commerce source;  4. EVC_CLUB_PAYMENT_PROVENANCE;
 *   5. the Club DB constants;  6. the Club DB connects;  7. schema MAX(version) >= 4 (migration 004).
 * last_reason() says why. REASON_SCHEMA is the graceful case: migration 004 is not applied yet, so the
 * till keeps the v1 flow (coffee type only) and the route answers 409 prepared_unavailable.
 *
 * The v1 route stays as it is (older tablets): both routes write ONE ledger with the same unique keys, so
 * one free coffee per member per Athens day holds across them.
 *
 * Logs: a fixed reason / stage plus an exception class / SQLSTATE / driver code, never a token, id, name,
 * note or message. The constructor parameters are TEST seams; the shipped wiring passes none (packager).
 */
final class EVC_Club_Prepared_Redemption_Backend {
    const MIN_SCHEMA_VERSION = EVC_Coffee_History::PREPARED_SCHEMA_VERSION;
    const PAYMENT_PROVENANCE = 'club_db_v1';

    const REASON_ENVIRONMENT = 'environment_unreadable';
    const REASON_STAFF = 'staff_flag_off';
    const REASON_REDEMPTION = 'redemption_source_off';
    const REASON_COMMERCE = 'commerce_source_not_configured';
    const REASON_PROVENANCE = 'payment_provenance_not_recorded';
    const REASON_DB_CONFIG = 'club_db_not_configured';
    const REASON_CONNECT = 'club_db_unavailable';
    const REASON_SCHEMA = 'club_db_schema_below_4';
    const REASON_BUILD = 'build_failed';

    /** @var callable(): array */
    private $environment;
    /** @var callable(EVC_Db_Config): EVC_Club_Db */
    private $connect;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable(string): void */
    private $log;
    /** @var callable(EVC_Commerce_Source_Config, EVC_Club_Db): EVC_Membership_Adapter */
    private $membership;
    /** @var string|null */
    private $last_reason = null;

    public function __construct(?callable $environment = null, ?callable $connect = null, ?EVC_Clock_Source $clock = null, ?callable $log = null, ?callable $membership = null) {
        $this->environment = $environment ?? array(__CLASS__, 'constants');
        $this->connect = $connect ?? function (EVC_Db_Config $config) {
            return EVC_Club_Db::connect($config);
        };
        $this->clock = $clock ?? new EVC_System_Clock();
        $this->log = $log ?? function (string $line) {
            error_log($line);
        };
        $this->membership = $membership ?? array(__CLASS__, 'recorded_membership');
    }

    /**
     * EXACTLY the v1 redeem route's membership source (STAGE-05A): WooCommerce / PMPro read-only, with the
     * Club's own recorded payment provenance on the same Club DB connection (no fallback). Reading only.
     */
    public static function recorded_membership(EVC_Commerce_Source_Config $commerce, EVC_Club_Db $db): EVC_Membership_Adapter {
        $provenance = new EVC_Store_Club_Payment_Provenance(function () use ($db) {
            return new EVC_Sql_Provenance_Store($db, function () {
                throw new LogicException('A prepared redemption never writes payment provenance.');
            }, EVC_Bacs_Payment_Confirmation::ENVIRONMENT);
        }, $commerce);
        return new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($commerce, $provenance), $commerce->mapper());
    }

    /**
     * The server configuration (wp-config.php constants), read at call time: the same pieces as the v1 route.
     *
     * @return array{staff:mixed,redemption:mixed,provenance:mixed,commerce:?EVC_Commerce_Source_Config,db:?EVC_Db_Config}
     */
    public static function constants(): array {
        return array(
            'staff' => defined(EVC_Staff_Feature::CONSTANT) ? constant(EVC_Staff_Feature::CONSTANT) : null,
            'redemption' => defined(EVC_Club_Redemption_Feature::CONSTANT) ? constant(EVC_Club_Redemption_Feature::CONSTANT) : null,
            'provenance' => defined('EVC_CLUB_PAYMENT_PROVENANCE') ? constant('EVC_CLUB_PAYMENT_PROVENANCE') : null,
            'commerce' => EVC_Commerce_Source_Config::from_constants(),
            'db' => EVC_Db_Config::from_constants(),
        );
    }

    public function last_reason(): ?string {
        return $this->last_reason;
    }

    /** The authoritative server clock (also used for the reply's server_business_date). */
    public function clock(): EVC_Clock_Source {
        return $this->clock;
    }

    public function service(): ?EVC_Prepared_Redemption_Service {
        $this->last_reason = null;
        try {
            return $this->build();
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_BUILD, $e);
        }
    }

    private function build(): ?EVC_Prepared_Redemption_Service {
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
        if (($env['provenance'] ?? null) !== self::PAYMENT_PROVENANCE) {
            return $this->refuse(self::REASON_PROVENANCE);
        }
        $config = $env['db'] ?? null;
        if (!$config instanceof EVC_Db_Config) {
            return $this->refuse(self::REASON_DB_CONFIG);
        }
        try {
            $db = ($this->connect)($config);
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_CONNECT, $e);
        }
        if (!$db instanceof EVC_Club_Db) {
            return $this->refuse(self::REASON_CONNECT);
        }
        try {
            $row = $db->fetch_one('SELECT MAX(version) AS v FROM evc_schema_migrations');
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_CONNECT, $e);
        }
        if ($row === null || $row['v'] === null || (int) $row['v'] < self::MIN_SCHEMA_VERSION) {
            return $this->refuse(self::REASON_SCHEMA);
        }
        $log = $this->log;
        return new EVC_Prepared_Redemption_Service(
            new EVC_Sql_Prep_Redemption_Store($db),
            new EVC_Club_Prep_Catalogue_Authority(),
            ($this->membership)($commerce, $db),
            $this->clock,
            null,
            function (array $d) use ($log) {
                // The service passes only the stage, the exception class and DB codes.
                $log(sprintf(
                    'Evangelou Club: prepared redemption error (stage=%s, exception=%s, sqlstate=%s, code=%s).',
                    self::safe($d['stage'] ?? null),
                    self::safe($d['exception'] ?? null),
                    self::safe($d['sql_state'] ?? null),
                    self::safe($d['driver_code'] ?? null)
                ));
            }
        );
    }

    /** @return null always */
    private function refuse(string $reason, ?Throwable $e = null) {
        $this->last_reason = $reason;
        try {
            ($this->log)('Evangelou Club: prepared redemption unavailable (' . $reason . ($e === null ? '' : ', ' . self::safe(get_class($e))) . ').');
        } catch (Throwable $ignored) {
            // Logging must never turn a refusal into anything else.
        }
        return null;
    }

    private static function safe($value): string {
        if ($value === null) {
            return '-';
        }
        $s = preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
        return $s === '' ? '-' : substr($s, 0, 64);
    }
}
