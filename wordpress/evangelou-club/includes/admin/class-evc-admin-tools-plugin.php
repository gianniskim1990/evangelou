<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 wiring, behind EVC_CLUB_ADMIN_TOOLS_ENABLED (default OFF): the ONLY place that constructs
 * EVC_Staff_Account_Service, EVC_Staff_Account_Page, EVC_Readiness and EVC_Readiness_Page (packager-enforced).
 *
 * OFF: boot() returns before anything is hooked or constructed (no menu, no admin action, no login hook).
 * ON (single-site WordPress only): the «Evangelou Club» admin menu for administrators (the shared till account; the
 * read-only readiness page) and the wp_login hook that records a staff account's last sign-in time.
 */
final class EVC_Admin_Tools_Plugin {
    /** @var array{service:EVC_Staff_Account_Service,page:EVC_Staff_Account_Page,readiness:EVC_Readiness,readiness_page:EVC_Readiness_Page}|null */
    private static $parts;

    /** @param array{now?:callable,db_config?:callable,connect?:callable,server?:array,constants?:array,header?:callable}|mixed $seams TEST seams */
    public static function boot($seams = null): void {
        if (!EVC_Admin_Tools_Feature::enabled()) {
            return;
        }
        if (is_multisite()) {
            error_log('Evangelou Club: the Club admin pages are not available on WordPress multisite.');
            return;
        }
        $seams = is_array($seams) ? $seams : array();
        try {
            self::$parts = self::build($seams['now'] ?? null, $seams['db_config'] ?? null, $seams['connect'] ?? null, $seams['server'] ?? null, $seams['constants'] ?? null, $seams['header'] ?? null);
        } catch (Throwable $e) {
            self::$parts = null;
            error_log('Evangelou Club: the Club admin pages stayed inert (' . get_class($e) . ' while wiring).');
            return;
        }
        add_action('admin_menu', array(self::$parts['page'], 'add_menu'));
        add_action('admin_menu', array(self::$parts['readiness_page'], 'add_menu'));
        add_action('wp_login', array(self::$parts['service'], 'record_login'), 10, 2);
    }

    /** @return array{service:EVC_Staff_Account_Service,page:EVC_Staff_Account_Page,readiness:EVC_Readiness,readiness_page:EVC_Readiness_Page} */
    public static function build(?callable $now = null, ?callable $db_config = null, ?callable $connect = null, ?array $server = null, ?array $constants = null, ?callable $header = null): array {
        return self::wire($now, $db_config, $connect, $server, $constants, $header);
    }

    /** The readiness checks get NO service: they read the till account through the read-only EVC_Staff_Account_Reader. */
    private static function wire(?callable $now, ?callable $db_config, ?callable $connect, ?array $server, ?array $constants, ?callable $header): array {
        $service = new EVC_Staff_Account_Service($now);
        $readiness = new EVC_Readiness($db_config, $connect, $server, $constants);
        return array(
            'service' => $service,
            'page' => new EVC_Staff_Account_Page($service, $header),
            'readiness' => $readiness,
            'readiness_page' => new EVC_Readiness_Page($readiness),
        );
    }

    /** The wired parts (null while off or after a wiring failure). */
    public static function parts(): ?array {
        return self::$parts;
    }
}
