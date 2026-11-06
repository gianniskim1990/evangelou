<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07 wiring, behind EVC_CLUB_ONBOARDING_ENABLED (+ EVC_CLUB_CUSTOMER_ENABLED), default OFF: the ONLY place
 * that constructs EVC_Onboarding_Registration, EVC_Onboarding_Handoff, EVC_Onboarding_Shell, EVC_Rest_Join_Controller
 * and EVC_Onboarding_Checkout_Guard (the packager enforces it).
 *
 * OFF: boot() returns before anything is hooked, registered or constructed (no query variable, no rewrite rule, no
 * route: the site is unchanged). ON: the join page and the tablet poster (query variable + pretty-permalink rules,
 * flushed once when they first appear), the three join routes, and the Club rules on the WooCommerce cart and checkout
 * (EVC_Onboarding_Checkout_Guard: bought alone, no second order, bank transfer only: owner decision D15). Nothing is
 * scheduled, nothing touches FluentCRM (owner decision D12), no new payment code (the existing WooCommerce checkout
 * and the STAGE-03D confirmation).
 */
final class EVC_Onboarding_Plugin {
    const REWRITE_STATE_OPTION = 'evc_club_join_rewrite_state';
    const REWRITE_STATE = 'on-v1';

    /** @var array{shell:EVC_Onboarding_Shell,controller:EVC_Rest_Join_Controller,registration:EVC_Onboarding_Registration,handoff:EVC_Onboarding_Handoff,guard:EVC_Onboarding_Checkout_Guard}|null */
    private static $parts;

    /**
     * @param array{membership?:EVC_Membership_Adapter,clock?:EVC_Clock_Source,now?:callable,assets?:EVC_Staff_Assets,cart?:callable}|mixed $seams
     *        TEST seams (WordPress passes '' from plugins_loaded; anything but an array is ignored)
     */
    public static function boot($seams = null): void {
        if (!EVC_Onboarding_Feature::enabled()) {
            return;
        }
        $seams = is_array($seams) ? $seams : array();
        try {
            self::$parts = self::build($seams['membership'] ?? null, $seams['clock'] ?? null, $seams['now'] ?? null, $seams['assets'] ?? null, $seams['cart'] ?? null);
        } catch (Throwable $e) {
            self::$parts = null;
            error_log('Evangelou Club: the onboarding stayed inert (' . get_class($e) . ' while wiring).');
            return;
        }
        add_filter('query_vars', array('EVC_Onboarding_Shell', 'filter_query_vars'));
        add_action('init', array(__CLASS__, 'rewrite'), 20);
        add_action('template_redirect', array(__CLASS__, 'serve'), 0);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        self::$parts['guard']->register();
    }

    /** @return array{shell:EVC_Onboarding_Shell,controller:EVC_Rest_Join_Controller,registration:EVC_Onboarding_Registration,handoff:EVC_Onboarding_Handoff,guard:EVC_Onboarding_Checkout_Guard} */
    public static function build(?EVC_Membership_Adapter $membership = null, ?EVC_Clock_Source $clock = null, ?callable $now = null, ?EVC_Staff_Assets $assets = null, ?callable $cart = null): array {
        return self::wire($membership ?? EVC_Customer_Plugin::membership_adapter(), $clock ?? new EVC_System_Clock(), $now, $assets ?? EVC_Onboarding_Shell::production_assets(), $cart);
    }

    private static function wire(EVC_Membership_Adapter $membership, EVC_Clock_Source $clock, ?callable $now, EVC_Staff_Assets $assets, ?callable $cart): array {
        $handoff = new EVC_Onboarding_Handoff(EVC_Commerce_Source_Config::from_constants(), $membership, $clock);
        $registration = new EVC_Onboarding_Registration($now);
        return array(
            'shell' => new EVC_Onboarding_Shell($handoff, $assets),
            'controller' => new EVC_Rest_Join_Controller($registration, $handoff),
            'registration' => $registration,
            'handoff' => $handoff,
            'guard' => new EVC_Onboarding_Checkout_Guard($handoff, $cart),
        );
    }

    /** The wired parts (null while off or after a wiring failure). */
    public static function parts(): ?array {
        return self::$parts;
    }

    /** Pretty-permalink rules, flushed ONLY the first time they appear (a stored state, as the /club-member/ shell). */
    public static function rewrite(): void {
        EVC_Onboarding_Shell::add_rewrite_rules();
        if (get_option(self::REWRITE_STATE_OPTION, null) !== self::REWRITE_STATE) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_STATE_OPTION, self::REWRITE_STATE, true);
        }
    }

    public static function serve(): void {
        if (self::$parts !== null) {
            self::$parts['shell']->serve();
        }
    }

    public static function register_routes(): void {
        if (self::$parts !== null) {
            self::$parts['controller']->register_routes();
        }
    }

    /** Plugin deactivation: the rules go with the next flush; the state is forgotten so they are flushed again. */
    public static function deactivate(): void {
        delete_option(self::REWRITE_STATE_OPTION);
    }
}
