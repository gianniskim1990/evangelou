<?php
defined('ABSPATH') || exit;
/**
 * STAGE-03D wiring, behind EVC_CLUB_WC_COMPLETION_ENABLED (default OFF):
 * the ONLY place that constructs EVC_Wc_Completion_Confirmer, its recovery
 * service, and the intent store / recovery inspector / issuance guard they use
 * (the packager enforces it).
 *
 * OFF: boot() returns before anything is hooked, scheduled, constructed or
 * connected. ON, and only with the commerce source (EVC_CLUB_MEMBERSHIP_SOURCE
 * ...) and the Club database (EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1' +
 * EVC_CLUB_DB_*) configured: the completion / cancellation / refund hooks, and
 * the recovery step on WP-Cron every 15 minutes. The Club database is opened
 * lazily, only when a Club order event needs it.
 */
final class EVC_Wc_Completion_Plugin {
    const CRON_HOOK = 'evc_club_wc_completion_recovery';
    const CRON_SCHEDULE = 'evc_club_quarter_hour';
    const CRON_INTERVAL = 900;

    /** @var EVC_Wc_Completion_Recovery|null */
    private static $recovery;

    public static function boot(): void {
        if (!EVC_Wc_Completion_Feature::enabled()) {
            return;
        }
        $parts = self::build(EVC_Commerce_Source_Config::from_constants(), EVC_Db_Config::from_constants(), function () {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        });
        if ($parts === null) {
            return; // not configured: nothing hooked (the add-on behaves as shipped)
        }
        $parts['confirmer']->register();
        self::$recovery = $parts['recovery'];
        add_filter('cron_schedules', array(__CLASS__, 'schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_recovery'));
        add_action('init', array(__CLASS__, 'schedule'));
    }

    /**
     * The confirmer and its recovery service over the configured stores; null when the commerce
     * source or the Club database is not configured.
     *
     * @param callable(): DateTimeImmutable $now
     * @param callable(): EVC_Provenance_Store|null $open_store default: the configured Club database
     * @return array{confirmer:EVC_Wc_Completion_Confirmer,recovery:EVC_Wc_Completion_Recovery,markers:EVC_Wc_Completion_Markers,intents:EVC_Wc_Bacs_Intent_Store,guard:EVC_Bacs_Issuance_Guard}|null
     */
    public static function build(?EVC_Commerce_Source_Config $config, ?EVC_Db_Config $db, callable $now, ?callable $open_store = null, int $lock_wait_seconds = EVC_Wc_Completion_Confirmer::LOCK_WAIT_SECONDS): ?array {
        if ($config === null || $db === null || !defined('EVC_CLUB_PAYMENT_PROVENANCE') || constant('EVC_CLUB_PAYMENT_PROVENANCE') !== 'club_db_v1') {
            return null;
        }
        $environment = EVC_Bacs_Payment_Confirmation::ENVIRONMENT;
        $intents = new EVC_Wc_Bacs_Intent_Store($config->intent_codec(), $environment);
        $markers = new EVC_Wc_Completion_Markers($config->completion_marker_codec(), $environment);
        $guard = new EVC_Bacs_Issuance_Guard();
        $open_store = $open_store ?? function () use ($db, $environment) {
            return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
                return EVC_Club_Db::connect($db);
            }, $environment);
        };
        $inspector_for = function (EVC_Provenance_Store $store) use ($config, $intents) {
            return new EVC_Bacs_Recovery_Inspector($config, $store, $intents, array('EVC_Wc_Completion_Confirmer', 'may_attest'), EVC_Payment_Evidence::KIND_WC_ORDER);
        };
        $audit_db = null;
        $open_audit_db = function () use ($db, &$audit_db) {
            if ($audit_db === null) {
                $audit_db = EVC_Club_Db::connect($db);
            }
            return $audit_db;
        };
        $confirmer = new EVC_Wc_Completion_Confirmer($config, $open_store, $inspector_for, $intents, $markers, $guard, $GLOBALS['wpdb'], $now, new EVC_Db_Audit_Log(), $open_audit_db, $lock_wait_seconds);
        return array(
            'confirmer' => $confirmer,
            'recovery' => new EVC_Wc_Completion_Recovery($confirmer, $markers),
            'markers' => $markers,
            'intents' => $intents,
            'guard' => $guard,
        );
    }

    /** @param array<string,array<string,mixed>> $schedules */
    public static function schedules($schedules): array {
        $schedules = is_array($schedules) ? $schedules : array();
        $schedules[self::CRON_SCHEDULE] = array('interval' => self::CRON_INTERVAL, 'display' => 'Evangelou Club: every 15 minutes');
        return $schedules;
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function run_recovery(): void {
        try {
            if (self::$recovery !== null) {
                self::$recovery->run();
            }
        } catch (Throwable $e) {
            // Retried on the next run; nothing is ever guessed.
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
