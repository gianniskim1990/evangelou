<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05A: the REAL backend of the staff redeem route (v1), behind EVC_CLUB_REDEMPTION_SOURCE ===
 * 'club_db_v1' (EVC_Club_Redemption_Feature; default OFF, then EVC_Staff_Plugin::register_routes()
 * keeps the always-unavailable backend and nothing here is even constructed).
 *
 * Builds EVC_Redemption_Service LAZILY, once per redeem request, inside service() (never at boot,
 * never at route registration), from shipped pieces only:
 *   ledger      EVC_Redemption_Store + EVC_Db_Audit_Log on the separate Club DB (migration 001);
 *   membership  EVC_Pmpro_Membership_Adapter over EVC_Wp_Commerce_Pmpro_Reader with the Club's OWN
 *               recorded payment provenance (EVC_Store_Club_Payment_Provenance over
 *               EVC_Sql_Provenance_Store, migration 002) — never the "unrecorded" fallback;
 *   coffee      the approved catalogue (EVC_Approved_Coffee_Catalogue_Source);
 *   clock       EVC_System_Clock.
 *
 * service() returns null (the controller answers 503 server_error, nothing granted) unless ALL hold,
 * checked in this order (no Club DB connection is opened before the last step):
 *   1. staff flag EVC_CLUB_STAFF_ENABLED === true;
 *   2. EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1';
 *   3. the commerce source is configured (EVC_Commerce_Source_Config, exact types);
 *   4. EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1' (exact; no fallback to unrecorded provenance);
 *   5. the Club DB constants (EVC_Db_Config) are present and valid;
 *   6. the coffee catalogue is non-null and non-empty;
 *   7. the Club DB connects;
 *   8. its schema has migration 002 (evc_schema_migrations MAX(version) >= 2).
 * Any throw while building is caught: null. Logs carry only a fixed reason and an exception class /
 * SQLSTATE / driver code, never a host, user, password, DB name, message or personal data.
 *
 * The constructor parameters after the catalogue are TEST seams; the shipped wiring passes only the
 * catalogue (enforced by the packager, app/scripts/php-guards.mjs REDEMPTION rules).
 */
final class EVC_Club_Redemption_Backend implements EVC_Redemption_Backend {
    const PAYMENT_PROVENANCE = 'club_db_v1';
    const MIN_SCHEMA_VERSION = 2;

    const REASON_ENVIRONMENT = 'environment_unreadable';
    const REASON_STAFF = 'staff_flag_off';
    const REASON_REDEMPTION = 'redemption_source_off';
    const REASON_COMMERCE = 'commerce_source_not_configured';
    const REASON_PROVENANCE = 'payment_provenance_not_recorded';
    const REASON_DB_CONFIG = 'club_db_not_configured';
    const REASON_CATALOGUE = 'coffee_catalogue_unavailable';
    const REASON_CONNECT = 'club_db_unavailable';
    const REASON_SCHEMA = 'club_db_schema_too_old';
    const REASON_BUILD = 'build_failed';

    /** @var EVC_Redemption_Catalogue_Source */
    private $catalogue;
    /** @var callable(): array */
    private $environment;
    /** @var callable(EVC_Db_Config): EVC_Club_Db */
    private $connect;
    /** @var EVC_Clock_Source|null */
    private $clock;
    /** @var callable(string): void */
    private $log;
    /** @var string|null */
    private $last_reason = null;

    /**
     * @param callable(): array|null $environment TEST seam; default: self::constants()
     * @param callable(EVC_Db_Config): EVC_Club_Db|null $connect TEST seam; default: EVC_Club_Db::connect
     * @param EVC_Clock_Source|null $clock TEST seam; default: EVC_System_Clock
     * @param callable(string): void|null $log TEST seam; default: error_log
     */
    public function __construct(EVC_Redemption_Catalogue_Source $catalogue, ?callable $environment = null, ?callable $connect = null, ?EVC_Clock_Source $clock = null, ?callable $log = null) {
        $this->catalogue = $catalogue;
        $this->environment = $environment ?? array(__CLASS__, 'constants');
        $this->connect = $connect ?? function (EVC_Db_Config $config) {
            return EVC_Club_Db::connect($config);
        };
        $this->clock = $clock;
        $this->log = $log ?? function (string $line) {
            error_log($line);
        };
    }

    /**
     * The server configuration (wp-config.php constants), read at call time. A missing constant is
     * null. The commerce and Club DB configs are parsed by their own exact parsers.
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

    /** Why the last service() call returned null (null after a success). For tests and the log. */
    public function last_reason(): ?string {
        return $this->last_reason;
    }

    public function service(): ?EVC_Redemption_Service {
        $this->last_reason = null;
        try {
            return $this->build();
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_BUILD, $e);
        }
    }

    private function build(): ?EVC_Redemption_Service {
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
        $db_config = $env['db'] ?? null;
        if (!$db_config instanceof EVC_Db_Config) {
            return $this->refuse(self::REASON_DB_CONFIG);
        }
        $catalog = $this->catalogue->catalogue();
        if (!$catalog instanceof EVC_Coffee_Catalog || $catalog->codes() === array()) {
            return $this->refuse(self::REASON_CATALOGUE);
        }
        try {
            $db = ($this->connect)($db_config);
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_CONNECT, $e);
        }
        if (!$db instanceof EVC_Club_Db) {
            return $this->refuse(self::REASON_CONNECT);
        }
        try {
            $row = $db->fetch_one('SELECT MAX(version) AS v FROM evc_schema_migrations');
        } catch (Throwable $e) {
            return $this->refuse(self::REASON_SCHEMA, $e);
        }
        if ($row === null || $row['v'] === null || (int) $row['v'] < self::MIN_SCHEMA_VERSION) {
            return $this->refuse(self::REASON_SCHEMA);
        }

        $connect = $this->connect;
        $provenance = new EVC_Store_Club_Payment_Provenance(function () use ($db, $connect, $db_config) {
            return new EVC_Sql_Provenance_Store($db, function () use ($connect, $db_config) {
                return $connect($db_config);
            }, EVC_Bacs_Payment_Confirmation::ENVIRONMENT);
        }, $commerce);
        $membership = new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($commerce, $provenance), $commerce->mapper());
        $log = $this->log;
        return new EVC_Redemption_Service(
            new EVC_Redemption_Store($db),
            new EVC_Db_Audit_Log(),
            $membership,
            $catalog,
            $this->clock ?? new EVC_System_Clock(),
            function (array $d) use ($log) {
                // EVC_Redemption_Service passes only the stage, the exception class and DB codes.
                $log(sprintf(
                    'Evangelou Club: staff redemption error (stage=%s, exception=%s, sqlstate=%s, code=%s).',
                    self::safe_token($d['stage'] ?? null),
                    self::safe_token($d['exception'] ?? null),
                    self::safe_token($d['sql_state'] ?? null),
                    self::safe_token($d['driver_code'] ?? null)
                ));
            }
        );
    }

    /** @return null always */
    private function refuse(string $reason, ?Throwable $e = null) {
        $this->last_reason = $reason;
        try {
            ($this->log)('Evangelou Club: staff redemption unavailable (' . $reason . ($e === null ? '' : ', ' . self::safe_token(get_class($e))) . ').');
        } catch (Throwable $ignored) {
            // Logging must never turn a refusal into anything else.
        }
        return null;
    }

    /** Only [A-Za-z0-9_] (class names, SQLSTATE, codes, fixed stages); anything else is dropped. */
    private static function safe_token($value): string {
        if ($value === null) {
            return '-';
        }
        $s = preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
        return $s === '' ? '-' : substr($s, 0, 64);
    }
}
