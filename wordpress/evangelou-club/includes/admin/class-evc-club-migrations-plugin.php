<?php
defined('ABSPATH') || exit;
/**
 * STAGE-04A wiring, behind EVC_CLUB_MIGRATIONS_ENABLED (default OFF): the ONLY place that
 * constructs EVC_Club_Migrations_Service, its admin page and CLI command, and EVC_Migrator
 * (the packager enforces it).
 *
 * OFF: boot() returns before anything is hooked, registered, constructed or connected.
 * ON: the Tools page for administrators (admin_menu) and, under WP-CLI only, the command
 * `wp evc-club migrations`. Nothing connects to the Club database until an administrator opens
 * the page or the technician runs the command; nothing migrates until apply is confirmed. The
 * plugin activation hook never migrates.
 */
final class EVC_Club_Migrations_Plugin {
    const CLI_COMMAND = 'evc-club migrations';

    public static function boot(): void {
        if (!EVC_Club_Migrations_Feature::enabled()) {
            return;
        }
        if (!self::supported_site(is_multisite())) {
            // Every subsite administrator holds manage_options: never offer one shared Club DB to them.
            error_log('Evangelou Club: the Club DB migrations page is not available on WordPress multisite.');
            return;
        }
        try {
            $parts = self::build();
            $parts['page']->register();
            if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
                WP_CLI::add_command(self::CLI_COMMAND, $parts['cli']);
            }
        } catch (Throwable $e) {
            // A throw while wiring must never fatal the site: stay inert.
            error_log('Evangelou Club: the Club DB migrations page stayed inert (' . get_class($e) . ' while wiring).');
        }
    }

    /** Single-site WordPress only (STAGE-04A review): on multisite nothing is offered at all. */
    public static function supported_site(bool $multisite): bool {
        return !$multisite;
    }

    /**
     * @param callable(): ?EVC_Db_Config|null $config default: the EVC_CLUB_DB_* constants
     * @param callable(EVC_Db_Config): EVC_Club_Db|null $connect default: EVC_Club_Db::connect
     * @param callable(string): void|null $log default: error_log
     * @param array{line:callable,success:callable,error:callable}|null $io default: WP_CLI output
     * @param array{name:string,host:string,prefix:string}|null $wordpress default: DB_NAME, DB_HOST, $wpdb->base_prefix
     * @param callable(): ?array|null $wordpress_server default: the WordPress connection's @@hostname / @@port / DATABASE()
     * @param string|null $directory default: the shipped database/ directory (tests point it elsewhere)
     * @return array{service:EVC_Club_Migrations_Service,page:EVC_Club_Migrations_Admin_Page,cli:EVC_Club_Migrations_Cli}
     */
    public static function build(?callable $config = null, ?callable $connect = null, ?callable $log = null, ?array $io = null, ?array $wordpress = null, ?callable $wordpress_server = null, ?string $directory = null): array {
        return self::wire($config, $connect, $log, $io, $wordpress, $wordpress_server, $directory);
    }

    private static function wire(?callable $config, ?callable $connect, ?callable $log, ?array $io, ?array $wordpress, ?callable $wordpress_server, ?string $directory): array {
        global $wpdb;
        $directory = $directory ?? dirname(__DIR__, 2) . '/database';
        $service = new EVC_Club_Migrations_Service(
            $config ?? function () {
                return EVC_Db_Config::from_constants();
            },
            $connect ?? function (EVC_Db_Config $db) {
                return EVC_Club_Db::connect($db);
            },
            function (EVC_Club_Db $db) use ($directory) {
                return new EVC_Migrator($db, $directory);
            },
            $wordpress ?? array(
                'name' => defined('DB_NAME') ? (string) DB_NAME : '',
                'host' => defined('DB_HOST') ? (string) DB_HOST : '',
                'prefix' => isset($wpdb->base_prefix) ? (string) $wpdb->base_prefix : (string) $wpdb->prefix,
            ),
            $wordpress_server ?? function () use ($wpdb) {
                $row = $wpdb->get_row('SELECT @@hostname AS h, @@port AS p, DATABASE() AS d', ARRAY_A);
                return is_array($row) ? $row : null;
            },
            new EVC_Db_Audit_Log(),
            $log ?? function (string $line) {
                error_log($line);
            },
            function () {
                return new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
        );
        $io = $io ?? array(
            'line' => function (string $text) {
                WP_CLI::line($text);
            },
            'success' => function (string $text) {
                WP_CLI::success($text);
            },
            'error' => function (string $text) {
                WP_CLI::error($text);
            },
        );
        return array(
            'service' => $service,
            'page' => new EVC_Club_Migrations_Admin_Page($service),
            'cli' => new EVC_Club_Migrations_Cli($service, $io, function () {
                return get_current_user_id();
            }),
        );
    }
}
