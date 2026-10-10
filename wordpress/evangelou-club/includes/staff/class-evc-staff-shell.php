<?php
defined('ABSPATH') || exit;

/**
 * Protected same-origin staff app at /club-admin/ (owner decision D1).
 *
 * Routing: a single rewrite rule ^club-admin/?$ -> index.php?evc_club_admin=1,
 * added ONLY while EVC_CLUB_STAFF_ENABLED === true and no existing page owns
 * the club-admin path. Rewrite rules are flushed only when that desired
 * state changes (stored in an option), never on every request.
 *
 * Authorization (a top-level navigation carries no X-WP-Nonce, so the shell
 * uses the WordPress cookie session and EVC_Staff_Auth::check_account()):
 *   flag off ............................... 404, no shell, no bootstrap
 *   not logged in .......................... 302 to wp-login.php
 *   wrong/extra role, direct caps, disabled  403, no bootstrap
 *   expired / idle / unmarked session ...... 302 to wp-login.php?reauth=1
 *   assets missing or inconsistent ......... 503, no bootstrap
 *   restricted staff, valid session ........ 200 shell + bootstrap JSON
 * Only the 200 response contains the wp_rest nonce. Every response is
 * private/no-store with no-referrer, nosniff, DENY framing and a strict CSP.
 */
final class EVC_Staff_Shell {
    const QUERY_VAR = 'evc_club_admin';
    const SLUG = 'club-admin';
    const REWRITE_STATE_OPTION = 'evc_club_admin_rewrite_state';
    const REWRITE_STATE_ON = 'on-v1';
    const REWRITE_STATE_OFF = 'off';
    const CONFIG_ELEMENT_ID = 'evc-staff-config';
    const IDLE_LOCK_SECONDS = 300; // owner-approved 5-minute tablet lock
    const ERROR_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public static function register_hooks(): void {
        add_filter('query_vars', array(__CLASS__, 'filter_query_vars'));
        add_action('init', array(__CLASS__, 'sync_rewrite'), 20);
        add_action('template_redirect', array(__CLASS__, 'serve'), 0);
    }

    public static function filter_query_vars($vars) {
        if (EVC_Staff_Feature::enabled()) {
            $vars[] = self::QUERY_VAR;
        }
        return $vars;
    }

    public static function has_path_conflict(): bool {
        return get_page_by_path(self::SLUG, OBJECT, array('page', 'post')) instanceof WP_Post;
    }

    public static function desired_rewrite_state(): string {
        return (EVC_Staff_Feature::enabled() && !self::has_path_conflict()) ? self::REWRITE_STATE_ON : self::REWRITE_STATE_OFF;
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
        // First run with the feature off: no rule was ever added, nothing to flush.
        if (!($current === null && $state === self::REWRITE_STATE_OFF)) {
            flush_rewrite_rules(false);
        }
        update_option(self::REWRITE_STATE_OPTION, $state, true);
    }

    /** Deactivation: remove our rule and forget the state. */
    public static function deactivate(): void {
        delete_option(self::REWRITE_STATE_OPTION);
        flush_rewrite_rules(false);
    }

    public static function serve(): void {
        if ((string) get_query_var(self::QUERY_VAR) !== '1') {
            return;
        }
        $response = self::respond(wp_get_current_user(), EVC_Staff_Assets::production());
        self::emit($response);
        exit;
    }

    public static function shell_url(): string {
        return home_url('/' . self::SLUG . '/');
    }

    /**
     * Pure decision + rendering (testable without exit).
     * @return array{status:int,headers:array<string,string>,body:string,location:?string}
     */
    public static function respond(WP_User $user, EVC_Staff_Assets $assets): array {
        if (!EVC_Staff_Feature::enabled()) {
            return self::error_page(404, 'Η σελίδα δεν βρέθηκε.');
        }
        if (!$user->exists()) {
            return self::redirect(wp_login_url(self::shell_url()));
        }
        $error = EVC_Staff_Auth::check_account($user, null, true);
        if ($error instanceof WP_Error) {
            $data = $error->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 403;
            if ($status === 401) {
                // Session expired/idle/invalid: it has been destroyed; force a real login.
                return self::redirect(wp_login_url(self::shell_url(), true));
            }
            return self::error_page(403, 'Δεν επιτρέπεται η πρόσβαση με αυτόν τον λογαριασμό.');
        }
        $resolved = $assets->resolve();
        if ($resolved === null) {
            return self::error_page(503, 'Η εφαρμογή προσωπικού δεν είναι διαθέσιμη αυτή τη στιγμή.');
        }
        $token = wp_get_session_token();
        $config = array(
            'restBase' => untrailingslashit(rest_url(EVC_Rest_Security::NAMESPACE_V1)),
            'nonce' => wp_create_nonce('wp_rest'),
            'loginUrl' => wp_login_url(self::shell_url(), true),
            'shellUrl' => self::shell_url(),
            'idleLockSeconds' => self::IDLE_LOCK_SECONDS,
            'sessionRef' => (is_string($token) && $token !== '') ? EVC_Staff_Session::session_ref($token) : '',
            'appVersion' => EVC_CLUB_VERSION,
            'features' => array(
                'qrLookup' => false,
                'phoneLookup' => false,
                'coffeeRedemption' => false,
                'history' => false,
            ),
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
            . "<title>Ευαγγέλου Club · Προσωπικό</title>\n"
            . $head
            . "</head>\n<body>\n<div id=\"root\"></div>\n"
            . '<script type="application/json" id="' . self::CONFIG_ELEMENT_ID . '">' . self::encode_config($config) . "</script>\n"
            . "</body>\n</html>\n";
    }

    private static function error_page(int $status, string $message): array {
        $body = "<!doctype html>\n<html lang=\"el\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex, nofollow\">"
            . '<title>Ευαγγέλου Club</title></head><body><p>' . esc_html($message) . "</p></body></html>\n";
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
