<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 wiring (owner decision D26 part 2), behind EVC_CLUB_PUSH_ENABLED (default OFF, and it also needs
 * EVC_CLUB_CUSTOMER_ENABLED): the ONLY place that constructs EVC_Push_Backend, EVC_Push_Sender, EVC_Push_Campaign,
 * EVC_Push_Admin_Page, EVC_Push_Auto and EVC_Rest_Me_Push_Controller (packager-enforced).
 *
 * OFF: boot() returns before anything is hooked, scheduled or constructed (no route: /me/push is a 404, no menu, no cron).
 * ON (single-site WordPress only): GET / POST / DELETE /me/push, the administrator page «Ειδοποιήσεις» (and its notice on
 * the Club pages, round 15), the batch
 * WP-Cron hook of a running send and, each only with its own constant, the two automatic notifications.
 * Missing or invalid VAPID keys do not stop the wiring: every request checks them again and answers «unavailable»
 * (fail closed), so fixing wp-config.php needs no other step.
 */
final class EVC_Push_Plugin {
    /** @var array{backend:EVC_Push_Backend,sender:EVC_Push_Sender,campaign:EVC_Push_Campaign,page:EVC_Push_Admin_Page,auto:EVC_Push_Auto,controller:EVC_Rest_Me_Push_Controller}|null */
    private static $parts;

    /** @param array{environment?:callable,connect?:callable,clock?:EVC_Clock_Source,log?:callable,http?:callable,time?:callable,membership?:callable,timer?:callable,redirect?:callable}|mixed $seams TEST seams */
    public static function boot($seams = null): void {
        if (!EVC_Push_Feature::enabled()) {
            return;
        }
        if (is_multisite()) {
            error_log('Evangelou Club: push notifications are not available on WordPress multisite.');
            return;
        }
        try {
            self::$parts = self::build(is_array($seams) ? $seams : array());
        } catch (Throwable $e) {
            self::$parts = null;
            error_log('Evangelou Club: push notifications stayed inert (' . get_class($e) . ' while wiring).');
            return;
        }
        add_action('rest_api_init', array(self::$parts['controller'], 'register_routes'), 10, 0);
        add_action('admin_menu', array(self::$parts['page'], 'add_menu'), 20);
        // Round 15 (L1): the Club pages say when a send is stuck without a named lock or was stopped for that reason.
        add_action('admin_notices', array(self::$parts['page'], 'club_notice'));
        add_action(EVC_Push_Campaign::CRON_HOOK, array(self::$parts['campaign'], 'cron'), 10, 1);
        self::$parts['auto']->register();
    }

    /** @return array{backend:EVC_Push_Backend,sender:EVC_Push_Sender,campaign:EVC_Push_Campaign,page:EVC_Push_Admin_Page,auto:EVC_Push_Auto,controller:EVC_Rest_Me_Push_Controller} */
    public static function build(array $seams = array()): array {
        return self::wire($seams);
    }

    private static function wire(array $seams): array {
        $backend = new EVC_Push_Backend($seams['environment'] ?? null, $seams['connect'] ?? null, $seams['clock'] ?? null, $seams['log'] ?? null);
        $sender = new EVC_Push_Sender($seams['http'] ?? null, $seams['time'] ?? null);
        $campaign = new EVC_Push_Campaign($backend, $sender, $seams['membership'] ?? null, $seams['timer'] ?? null);
        return array(
            'backend' => $backend,
            'sender' => $sender,
            'campaign' => $campaign,
            'page' => new EVC_Push_Admin_Page($campaign, $seams['redirect'] ?? null),
            'auto' => new EVC_Push_Auto($backend, $sender, $seams['membership'] ?? null, $seams['timer'] ?? null),
            'controller' => new EVC_Rest_Me_Push_Controller($backend),
        );
    }

    /** The wired parts (null while off or after a wiring failure). */
    public static function parts(): ?array {
        return self::$parts;
    }

    /** Plugin deactivation: no push event stays scheduled. */
    public static function deactivate(): void {
        foreach (array(EVC_Push_Auto::EXPIRY_HOOK, EVC_Push_Auto::ACTIVATED_HOOK, EVC_Push_Campaign::CRON_HOOK) as $hook) {
            wp_unschedule_hook($hook);
        }
    }
}
