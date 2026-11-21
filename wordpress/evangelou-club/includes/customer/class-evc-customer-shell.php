<?php
defined('ABSPATH') || exit;
/**
 * Protected same-origin customer app at /club-member/ (STAGE-01), the same
 * pattern as the staff /club-admin/ shell (EVC_Staff_Shell).
 *
 * Routing: a single rewrite rule ^club-member/?$ -> index.php?evc_club_member=1,
 * added ONLY while EVC_CLUB_CUSTOMER_ENABLED === true and no existing page
 * owns the path; flushed only when that desired state changes. With plain
 * permalinks (no rewrite rules, e.g. the current staging site) the page is
 * served at ?evc_club_member=1 instead; shell_url() follows the setting.
 *
 * Authorization (a navigation carries no X-WP-Nonce: cookie session +
 * EVC_Customer_Auth::check_customer):
 *   flag off ...................... 404, no bootstrap
 *   not signed in ................. 302 to wp-login.php (native e-mail/password login)
 *   staff / privileged account .... 403, no bootstrap
 *   assets missing or inconsistent  503, no bootstrap
 *   customer ...................... 200 page + bootstrap JSON (REST base + wp_rest nonce; the join URL only while
 *                                   EVC_CLUB_ONBOARDING_ENABLED is on, STAGE-08 round 10; the service worker's URL
 *                                   and scope only with pretty permalinks, round 11; the push public key only while
 *                                   EVC_CLUB_PUSH_ENABLED is on with usable VAPID keys, round 12)
 *
 * STAGE-08 round 11 (owner decision D26 part 1, EVC_Customer_Pwa): the same query variable also serves the web app
 * manifest (=manifest; pretty /club-member/manifest.webmanifest) and the service worker (=sw; ONLY at the pretty
 * /club-member/sw.js). Both are public (no personal data, no nonce) and exist only while the flag is on.
 * Every response is private/no-store with no-referrer, nosniff, DENY framing
 * and a strict self-only CSP.
 */
final class EVC_Customer_Shell {
    const QUERY_VAR = 'evc_club_member';
    const SLUG = 'club-member';
    const REWRITE_STATE_OPTION = 'evc_club_member_rewrite_state';
    // Round 11: v2 adds the manifest and service worker rules (one flush when an existing site updates).
    const REWRITE_STATE_ON = 'on-v2';
    const REWRITE_STATE_OFF = 'off';
    const CONFIG_ELEMENT_ID = 'evc-customer-config';
    const ASSET_ENTRY = 'customer.html';
    const ERROR_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public static function register_hooks(): void {
        add_filter('query_vars', array(__CLASS__, 'filter_query_vars'));
        add_action('init', array(__CLASS__, 'sync_rewrite'), 20);
        add_action('template_redirect', array(__CLASS__, 'serve'), 0);
    }

    public static function filter_query_vars($vars) {
        if (EVC_Customer_Feature::enabled()) {
            $vars[] = self::QUERY_VAR;
        }
        return $vars;
    }

    public static function has_path_conflict(): bool {
        return get_page_by_path(self::SLUG, OBJECT, array('page', 'post')) instanceof WP_Post;
    }

    public static function desired_rewrite_state(): string {
        return (EVC_Customer_Feature::enabled() && !self::has_path_conflict()) ? self::REWRITE_STATE_ON : self::REWRITE_STATE_OFF;
    }

    /** Adds the rule when wanted; flushes ONLY when the desired state changed. */
    public static function sync_rewrite(): void {
        $state = self::desired_rewrite_state();
        if ($state === self::REWRITE_STATE_ON) {
            add_rewrite_rule('^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
            foreach (EVC_Customer_Pwa::REWRITES as $regex => $page) {
                add_rewrite_rule('^' . self::SLUG . $regex, 'index.php?' . self::QUERY_VAR . '=' . $page, 'top');
            }
        }
        $current = get_option(self::REWRITE_STATE_OPTION, null);
        if ($current === $state) {
            return;
        }
        if (!($current === null && $state === self::REWRITE_STATE_OFF)) {
            flush_rewrite_rules(false);
        }
        update_option(self::REWRITE_STATE_OPTION, $state, true);
    }

    public static function deactivate(): void {
        delete_option(self::REWRITE_STATE_OPTION);
        flush_rewrite_rules(false);
    }

    public static function serve(): void {
        $page = (string) get_query_var(self::QUERY_VAR);
        if ($page === '1') {
            $response = self::respond(wp_get_current_user(), self::production_assets());
        } elseif ($page === EVC_Customer_Pwa::MANIFEST || $page === EVC_Customer_Pwa::WORKER) {
            $path = wp_parse_url(isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH);
            $response = self::respond_pwa($page, is_string($path) ? $path : '', self::production_assets(), plugins_url('customer-app', EVC_CLUB_FILE), EVC_CLUB_PATH . 'customer-app/pwa/' . EVC_Customer_Pwa::WORKER_FILE);
        } else {
            return;
        }
        self::emit($response);
        exit;
    }

    /**
     * Round 11: the manifest and the service worker (EVC_Customer_Pwa); 404 while the flag is off.
     * @return array{status:int,headers:array<string,string>,body:string,location:?string}
     */
    public static function respond_pwa(string $page, string $request_path, EVC_Staff_Assets $assets, string $asset_url, string $worker_file): array {
        if (!EVC_Customer_Feature::enabled()) {
            return EVC_Customer_Pwa::not_found();
        }
        if ($page === EVC_Customer_Pwa::MANIFEST) {
            return EVC_Customer_Pwa::manifest_response(EVC_Customer_Pwa::icon_base());
        }
        if ($page === EVC_Customer_Pwa::WORKER) {
            return EVC_Customer_Pwa::worker_response($request_path, $assets, $asset_url, $worker_file);
        }
        return EVC_Customer_Pwa::not_found();
    }

    public static function production_assets(): EVC_Staff_Assets {
        return new EVC_Staff_Assets(EVC_CLUB_PATH . 'customer-app', plugins_url('customer-app', EVC_CLUB_FILE), self::ASSET_ENTRY);
    }

    /** Pretty permalinks: /club-member/; plain permalinks: /?evc_club_member=1. */
    public static function shell_url(): string {
        if ((string) get_option('permalink_structure') === '') {
            return add_query_arg(self::QUERY_VAR, '1', home_url('/'));
        }
        return home_url('/' . self::SLUG . '/');
    }

    /**
     * Pure decision + rendering (testable without exit).
     * @return array{status:int,headers:array<string,string>,body:string,location:?string}
     */
    public static function respond(WP_User $user, EVC_Staff_Assets $assets): array {
        if (!EVC_Customer_Feature::enabled()) {
            return self::error_page(404, 'Η σελίδα δεν βρέθηκε.');
        }
        if (!$user->exists()) {
            return self::redirect(wp_login_url(self::shell_url()));
        }
        if (EVC_Customer_Auth::check_customer($user) instanceof WP_Error) {
            return self::error_page(403, 'Αυτός ο λογαριασμός δεν είναι λογαριασμός μέλους του Club.');
        }
        $resolved = $assets->resolve();
        if ($resolved === null) {
            return self::error_page(503, 'Η εφαρμογή του Club δεν είναι διαθέσιμη αυτή τη στιγμή.');
        }
        $config = array(
            'restBase' => untrailingslashit(rest_url(EVC_Customer_Rest_Security::NAMESPACE_V1)),
            'homePath' => EVC_Rest_Security::home_path(),
            'nonce' => wp_create_nonce('wp_rest'),
            'loginUrl' => wp_login_url(self::shell_url()),
            'logoutUrl' => wp_logout_url(self::shell_url()),
        );
        // STAGE-08 round 10: the in-store join flow (/club/join/ or ?evc_club_join=1) is where a signed-in customer
        // without a membership joins and pays. Its URL is sent ONLY while that flow exists
        // (EVC_CLUB_ONBOARDING_ENABLED); otherwise the page carries no join link at all.
        if (EVC_Onboarding_Feature::enabled()) {
            $config['joinUrl'] = EVC_Onboarding_Shell::join_url();
        }
        // Round 11 (D26): the home-screen app's service worker, only with pretty permalinks (EVC_Customer_Pwa).
        $config = array_merge($config, EVC_Customer_Pwa::config());
        // Round 12 (D26 part 2): the VAPID public key while push is on and its keys are usable (EVC_Push_Feature::ready()).
        $config = array_merge($config, EVC_Push_Feature::page_config());
        $config['appVersion'] = EVC_CLUB_VERSION;
        return array(
            'status' => 200,
            'headers' => self::headers(EVC_Rest_Security::SHELL_CSP),
            'body' => self::render_shell($config, $resolved, EVC_Customer_Pwa::head_tags(EVC_Customer_Pwa::icon_base())),
            'location' => null,
        );
    }

    /** JSON safe inside <script type="application/json">: <, >, &, ' and " are \u-escaped. */
    public static function encode_config(array $config): string {
        return (string) wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** @param array{scripts:string[],preloads:string[],styles:string[]} $assets */
    private static function render_shell(array $config, array $assets, string $pwa_head = ''): string {
        $head = $pwa_head;
        foreach ($assets['styles'] as $href) {
            $head .= '<link rel="stylesheet" href="' . esc_url($href) . '">' . "\n";
        }
        foreach ($assets['preloads'] as $href) {
            $head .= '<link rel="modulepreload" href="' . esc_url($href) . '">' . "\n";
        }
        foreach ($assets['scripts'] as $src) {
            $head .= '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
        }
        return "<!doctype html>\n<html lang=\"el\">\n<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"robots\" content=\"noindex, nofollow\">\n"
            . "<meta name=\"referrer\" content=\"no-referrer\">\n"
            . "<title>Evangelou Club</title>\n"
            . $head
            . "</head>\n<body>\n<div id=\"root\"></div>\n"
            . '<script type="application/json" id="' . self::CONFIG_ELEMENT_ID . '">' . self::encode_config($config) . "</script>\n"
            . "</body>\n</html>\n";
    }

    private static function error_page(int $status, string $message): array {
        $body = "<!doctype html>\n<html lang=\"el\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex, nofollow\">"
            . '<title>Evangelou Club</title></head><body><p>' . esc_html($message) . "</p></body></html>\n";
        return array('status' => $status, 'headers' => self::headers(self::ERROR_CSP), 'body' => $body, 'location' => null);
    }

    private static function redirect(string $location): array {
        return array('status' => 302, 'headers' => self::headers(self::ERROR_CSP), 'body' => '', 'location' => $location);
    }

    /** @return array<string,string> */
    private static function headers(string $csp): array {
        $headers = EVC_Rest_Security::security_headers();
        $headers['Content-Security-Policy'] = $csp;
        $headers['X-Robots-Tag'] = 'noindex, nofollow';
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return $headers;
    }

    private static function emit(array $response): void {
        status_header($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($response['location'] !== null) {
            wp_safe_redirect($response['location'], 302);
            return;
        }
        echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
    }
}
