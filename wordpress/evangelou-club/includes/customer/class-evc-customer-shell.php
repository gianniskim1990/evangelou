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
 *   customer ...................... 200 page + bootstrap JSON (REST base + wp_rest nonce)
 * Every response is private/no-store with no-referrer, nosniff, DENY framing
 * and a strict self-only CSP.
 */
final class EVC_Customer_Shell {
    const QUERY_VAR = 'evc_club_member';
    const SLUG = 'club-member';
    const REWRITE_STATE_OPTION = 'evc_club_member_rewrite_state';
    const REWRITE_STATE_ON = 'on-v1';
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
        if ((string) get_query_var(self::QUERY_VAR) !== '1') {
            return;
        }
        $response = self::respond(wp_get_current_user(), self::production_assets());
        self::emit($response);
        exit;
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
            'nonce' => wp_create_nonce('wp_rest'),
            'loginUrl' => wp_login_url(self::shell_url()),
            'logoutUrl' => wp_logout_url(self::shell_url()),
            'appVersion' => EVC_CLUB_VERSION,
        );
        return array(
            'status' => 200,
            'headers' => self::headers(EVC_Rest_Security::SHELL_CSP),
            'body' => self::render_shell($config, $resolved),
            'location' => null,
        );
    }

    /** JSON safe inside <script type="application/json">: <, >, &, ' and " are \u-escaped. */
    public static function encode_config(array $config): string {
        return (string) wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** @param array{scripts:string[],preloads:string[],styles:string[]} $assets */
    private static function render_shell(array $config, array $assets): string {
        $head = '';
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
