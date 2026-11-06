<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07 pages, served from the customer app bundle (customer-app/, mode "join" or "poster"), the same way as
 * the /club-member/ shell. Constructed only by EVC_Onboarding_Plugin::wire() (behind EVC_CLUB_ONBOARDING_ENABLED).
 *
 *   /club/join/          (plain permalinks: /?evc_club_join=1)       PUBLIC: the join flow
 *   /club/join/poster/   (plain permalinks: /?evc_club_join=poster)  shop staff / shop managers / administrators
 *
 * Join: anyone. The bootstrap says which step the visitor is on (register | terms | offer | member | not_customer
 * | unavailable), computed on the server; every URL in it is built here (never from the request), so there is no
 * open redirect, and a ?redirect_to= / ?next= in the address is simply ignored.
 * Poster: a big QR that encodes ONLY the public join URL plus the fixed marker src=store (no secret, no personal
 * data). Signed out -> the WordPress login (returning to the poster); any other account -> 403.
 * Every response is private/no-store, noindex, no-referrer, nosniff, DENY framing and a self-only CSP.
 */
final class EVC_Onboarding_Shell {
    const QUERY_VAR = 'evc_club_join';
    const SLUG = 'club/join';
    const POSTER = 'poster';
    const STORE_MARKER = 'store';
    const CONFIG_ELEMENT_ID = 'evc-customer-config';
    const ERROR_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    /** Who may open the tablet poster: the shared Club staff account, shop managers, administrators. */
    const POSTER_CAPABILITIES = array('manage_options', 'manage_woocommerce');

    /** @var EVC_Onboarding_Handoff */
    private $handoff;
    /** @var EVC_Staff_Assets */
    private $assets;

    public function __construct(EVC_Onboarding_Handoff $handoff, EVC_Staff_Assets $assets) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->handoff = $handoff;
        $this->assets = $assets;
    }

    public static function production_assets(): EVC_Staff_Assets {
        return EVC_Customer_Shell::production_assets();
    }

    public static function filter_query_vars($vars) {
        $vars = is_array($vars) ? $vars : array();
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /** Pretty permalinks only; with plain permalinks the query variable is used directly. No flush here. */
    public static function add_rewrite_rules(): void {
        add_rewrite_rule('^' . self::SLUG . '/' . self::POSTER . '/?$', 'index.php?' . self::QUERY_VAR . '=' . self::POSTER, 'top');
        add_rewrite_rule('^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    public static function join_url(bool $from_store = false): string {
        $url = (string) get_option('permalink_structure') === '' ? add_query_arg(self::QUERY_VAR, '1', home_url('/')) : home_url('/' . self::SLUG . '/');
        return $from_store ? add_query_arg('src', self::STORE_MARKER, $url) : $url;
    }

    public static function poster_url(): string {
        return (string) get_option('permalink_structure') === '' ? add_query_arg(self::QUERY_VAR, self::POSTER, home_url('/')) : home_url('/' . self::SLUG . '/' . self::POSTER . '/');
    }

    public function serve(): void {
        $response = $this->handle((string) get_query_var(self::QUERY_VAR), wp_get_current_user());
        if ($response === null) {
            return;
        }
        self::emit($response);
        exit;
    }

    /**
     * The response for this page, or null when the request is not ours. Round 2: every page of ours is kept out of
     * any page cache: DONOTCACHEPAGE for caching plugins, WordPress's own no-cache headers (wp_get_nocache_headers)
     * in every response, and nocache_headers() when it is sent.
     *
     * @return array{status:int,headers:array<string,string>,body:string,location:?string}|null
     */
    public function handle(string $page, WP_User $user): ?array {
        if ($page !== '1' && $page !== self::POSTER) {
            return null;
        }
        self::no_page_cache();
        return $page === self::POSTER ? $this->poster($user) : $this->join($user);
    }

    public static function no_page_cache(): void {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    /** @return array{status:int,headers:array<string,string>,body:string,location:?string} */
    public function join(WP_User $user): array {
        $resolved = $this->assets->resolve();
        if ($resolved === null) {
            return self::error_page(503, 'Η εγγραφή στο Club δεν είναι διαθέσιμη αυτή τη στιγμή.');
        }
        $join = self::join_url();
        $config = array(
            'mode' => 'join',
            'restBase' => untrailingslashit(rest_url(EVC_Customer_Rest_Security::NAMESPACE_V1)),
            'nonce' => wp_create_nonce('wp_rest'),
            'step' => $this->step($user),
            'joinUrl' => $join,
            'memberUrl' => EVC_Customer_Shell::shell_url(),
            'loginUrl' => wp_login_url($join),
            'logoutUrl' => wp_logout_url($join),
            'lostPasswordUrl' => wp_lostpassword_url($join),
            'privacyUrl' => self::same_site_url((string) get_privacy_policy_url()),
            'termsUrl' => self::same_site_url(defined('EVC_CLUB_TERMS_URL') && is_string(constant('EVC_CLUB_TERMS_URL')) ? (string) constant('EVC_CLUB_TERMS_URL') : ''),
            'appVersion' => EVC_CLUB_VERSION,
        );
        return array('status' => 200, 'headers' => self::headers(EVC_Rest_Security::SHELL_CSP), 'body' => self::render($config, $resolved), 'location' => null);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,location:?string} */
    public function poster(WP_User $user): array {
        if (!$user->exists()) {
            return array('status' => 302, 'headers' => self::headers(self::ERROR_CSP), 'body' => '', 'location' => wp_login_url(self::poster_url()));
        }
        if (!self::may_show_poster($user)) {
            return self::error_page(403, 'Η αφίσα εγγραφής είναι μόνο για το κατάστημα.');
        }
        $resolved = $this->assets->resolve();
        if ($resolved === null) {
            return self::error_page(503, 'Η αφίσα δεν είναι διαθέσιμη αυτή τη στιγμή.');
        }
        $config = array('mode' => 'poster', 'joinUrl' => self::join_url(true), 'appVersion' => EVC_CLUB_VERSION);
        return array('status' => 200, 'headers' => self::headers(EVC_Rest_Security::SHELL_CSP), 'body' => self::render($config, $resolved), 'location' => null);
    }

    public static function may_show_poster(WP_User $user): bool {
        if (!$user->exists()) {
            return false;
        }
        if (EVC_Staff_Role::has_staff_role($user)) {
            return true;
        }
        foreach (self::POSTER_CAPABILITIES as $capability) {
            if (user_can($user, $capability)) {
                return true;
            }
        }
        return false;
    }

    /** register | not_customer | member | terms | offer | unavailable */
    public function step(WP_User $user): string {
        if (!$user->exists()) {
            return 'register';
        }
        if (EVC_Customer_Auth::check_customer($user) !== null) {
            return 'not_customer';
        }
        $next = $this->handoff->next_step((int) $user->ID);
        if ($next === EVC_Onboarding_Handoff::NEXT_CHECKOUT) {
            return 'offer';
        }
        return $next; // member | terms | unavailable
    }

    /** A same-site absolute URL, or '' (a terms / privacy link must never lead elsewhere from this page). */
    public static function same_site_url(string $url): string {
        if ($url === '') {
            return '';
        }
        $site = wp_parse_url(home_url('/'));
        $parts = wp_parse_url($url);
        if (!is_array($site) || !is_array($parts) || !isset($parts['scheme'], $parts['host'], $site['host'])) {
            return '';
        }
        if (!in_array($parts['scheme'], array('http', 'https'), true) || strtolower($parts['host']) !== strtolower($site['host']) || ($parts['port'] ?? null) !== ($site['port'] ?? null)) {
            return '';
        }
        return $url;
    }

    /** JSON safe inside <script type="application/json">. */
    public static function encode_config(array $config): string {
        return (string) wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    /** @param array{scripts:string[],preloads:string[],styles:string[]} $assets */
    private static function render(array $config, array $assets): string {
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

    /** @return array<string,string> */
    private static function headers(string $csp): array {
        $headers = EVC_Rest_Security::security_headers();
        foreach (wp_get_nocache_headers() as $name => $value) {
            if (is_string($value)) {
                $headers[$name] = $value; // Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private; Expires
            }
        }
        $headers['Content-Security-Policy'] = $csp;
        $headers['X-Robots-Tag'] = 'noindex, nofollow';
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return $headers;
    }

    private static function emit(array $response): void {
        nocache_headers();
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
