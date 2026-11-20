<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 11, owner decision D26 part 1: the member page as a home-screen app (no push: round 12).
 * Served only through EVC_Customer_Shell (so only while EVC_CLUB_CUSTOMER_ENABLED is true); the staff app and the
 * join pages never link any of it.
 *
 *   manifest      pretty: <home>/club-member/manifest.webmanifest   plain: <home>/?evc_club_member=manifest
 *                 application/manifest+json; start_url = the member page (/club-member/ or ?evc_club_member=1);
 *                 scope = the member page path (/club-member/; with plain permalinks it can only be the home path)
 *   icons         static PNG files of the package (customer-app/pwa/), made from the bundled logo
 *                 (app/scripts/generate-pwa-icons.mjs)
 *   worker        pretty permalinks ONLY: <home>/club-member/sw.js, scope <home>/club-member/. A worker's scope is
 *                 a path prefix; with plain permalinks the member page path is the home path, and a worker there
 *                 would control the whole site (the staff app, the shop, the checkout): none is served then (404),
 *                 and the page asks for none. The script lives under its scope, so no Service-Worker-Allowed
 *                 header is needed (the default maximum scope is the script's own directory). It is served ONLY at
 *                 that exact path: ?evc_club_member=sw at the site root is a 404 (it would allow a site-wide scope).
 *
 * Nothing here is personal: the manifest and the worker are the same for every visitor (no sign-in needed: a browser
 * fetches the manifest without cookies), they carry no nonce, and the worker never stores a page or a REST answer
 * (app/src/customer/pwa/swRules.ts).
 */
final class EVC_Customer_Pwa {
    const MANIFEST = 'manifest';
    const WORKER = 'sw';
    const MANIFEST_FILE = 'manifest.webmanifest';
    /** Pretty permalinks: the rewrite rules under /club-member/ (added by EVC_Customer_Shell::sync_rewrite()). */
    const REWRITES = array('/manifest\\.webmanifest$' => self::MANIFEST, '/sw\\.js$' => self::WORKER);
    const WORKER_FILE = 'sw.js';
    const NAME = 'Ευαγγέλου Club';
    const SHORT_NAME = 'Club';
    /** Design tokens (app/src/preview/preview.css): --roast, the member card's background. */
    const THEME_COLOR = '#1e1713';
    const BACKGROUND_COLOR = '#1e1713';
    const ICONS = array(
        array('file' => 'icon-192.png', 'sizes' => '192x192', 'purpose' => 'any'),
        array('file' => 'icon-512.png', 'sizes' => '512x512', 'purpose' => 'any'),
        array('file' => 'icon-maskable-512.png', 'sizes' => '512x512', 'purpose' => 'maskable'),
    );
    const APPLE_TOUCH_ICON = 'apple-touch-icon.png';
    /** The worker script's own CSP: it may fetch this site only. */
    const WORKER_CSP = "default-src 'none'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'";

    public static function pretty(): bool {
        return (string) get_option('permalink_structure') !== '';
    }

    /** The member page path with pretty permalinks, e.g. /club-member/ or /shop/club-member/ (browser-encoded). */
    public static function scope(): string {
        return EVC_Rest_Security::home_path() . EVC_Customer_Shell::SLUG . '/';
    }

    public static function manifest_url(): string {
        return self::pretty() ? home_url('/' . EVC_Customer_Shell::SLUG . '/' . self::MANIFEST_FILE) : add_query_arg(EVC_Customer_Shell::QUERY_VAR, self::MANIFEST, home_url('/'));
    }

    /** The worker's URL, or null with plain permalinks (no worker at all then). */
    public static function worker_url(): ?string {
        return self::pretty() ? home_url('/' . EVC_Customer_Shell::SLUG . '/' . self::WORKER_FILE) : null;
    }

    public static function icon_base(): string {
        return plugins_url('customer-app/pwa', EVC_CLUB_FILE);
    }

    /** The extra bootstrap keys of the member page: the worker only with pretty permalinks. */
    public static function config(): array {
        $worker = self::worker_url();
        return $worker === null ? array() : array('serviceWorkerUrl' => $worker, 'serviceWorkerScope' => self::scope());
    }

    /** <head> lines of the member page: manifest, theme colour, iOS home-screen tags. */
    public static function head_tags(string $icon_base): string {
        $icon_base = rtrim($icon_base, '/');
        return '<link rel="manifest" href="' . esc_url(self::manifest_url()) . '">' . "\n"
            . '<meta name="theme-color" content="' . self::THEME_COLOR . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . esc_url($icon_base . '/' . self::APPLE_TOUCH_ICON) . '">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="' . esc_attr(self::NAME) . '">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    }

    /** @return array<string,mixed> the manifest document */
    public static function manifest_document(string $icon_base): array {
        $icon_base = rtrim($icon_base, '/');
        $start = EVC_Customer_Shell::shell_url();
        $icons = array();
        foreach (self::ICONS as $icon) {
            $icons[] = array('src' => $icon_base . '/' . $icon['file'], 'sizes' => $icon['sizes'], 'type' => 'image/png', 'purpose' => $icon['purpose']);
        }
        return array(
            'id' => $start,
            'name' => self::NAME,
            'short_name' => self::SHORT_NAME,
            'lang' => 'el',
            'dir' => 'ltr',
            'start_url' => $start,
            // Plain permalinks: the member page is <home>/?evc_club_member=1, so its path (the scope) is the home path.
            'scope' => self::pretty() ? self::scope() : EVC_Rest_Security::home_path(),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => self::BACKGROUND_COLOR,
            'theme_color' => self::THEME_COLOR,
            'icons' => $icons,
        );
    }

    /** @return array{status:int,headers:array<string,string>,body:string,location:?string} */
    public static function manifest_response(string $icon_base): array {
        $headers = self::headers(EVC_Rest_Security::API_CSP);
        $headers['Content-Type'] = 'application/manifest+json; charset=utf-8';
        return array('status' => 200, 'headers' => $headers, 'body' => (string) wp_json_encode(self::manifest_document($icon_base), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'location' => null);
    }

    /**
     * The worker script: its configuration (the build's file list, the REST prefixes, the scope) in front of the
     * built script. 404 with plain permalinks, at any other path than its own, or without the build.
     *
     * @param string $request_path the path of this request as the browser sent it
     * @param string $script_file  customer-app/pwa/sw.js of the package
     * @return array{status:int,headers:array<string,string>,body:string,location:?string}
     */
    public static function worker_response(string $request_path, EVC_Staff_Assets $assets, string $asset_url, string $script_file): array {
        if (!self::pretty() || $request_path !== self::scope() . self::WORKER_FILE) {
            return self::not_found();
        }
        $resolved = $assets->resolve();
        $script = is_file($script_file) && is_readable($script_file) ? (string) file_get_contents($script_file) : '';
        if ($resolved === null || $script === '') {
            return self::not_found();
        }
        $precache = array_values(array_unique(array_merge($resolved['scripts'], $resolved['preloads'], $resolved['styles'])));
        $config = array(
            'version' => EVC_CLUB_VERSION . '-' . substr(hash('sha256', implode("\n", $precache) . "\n" . hash('sha256', $script)), 0, 16),
            'scope' => self::scope(),
            'assetBase' => rtrim($asset_url, '/') . '/assets/',
            'precache' => $precache,
            'restPrefixes' => self::rest_prefixes(),
            'offline' => array('scripts' => $resolved['scripts'], 'styles' => $resolved['styles'], 'appVersion' => EVC_CLUB_VERSION),
        );
        $headers = self::headers(self::WORKER_CSP);
        $headers['Content-Type'] = 'text/javascript; charset=utf-8';
        $body = 'self.__EVC_SW__ = ' . wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ";\n" . $script;
        return array('status' => 200, 'headers' => $headers, 'body' => $body, 'location' => null);
    }

    /** @return string[] the REST path prefixes (the site's, and the default /wp-json/ under the home path) */
    public static function rest_prefixes(): array {
        $prefixes = array();
        $path = wp_parse_url(rest_url(), PHP_URL_PATH);
        if (is_string($path) && $path !== '' && strpos($path, '?') === false) {
            $prefixes[] = rtrim($path, '/') . '/';
        }
        $prefixes[] = EVC_Rest_Security::home_path() . 'wp-json/';
        return array_values(array_unique($prefixes));
    }

    /** @return array{status:int,headers:array<string,string>,body:string,location:?string} */
    public static function not_found(): array {
        $headers = self::headers(EVC_Rest_Security::API_CSP);
        $headers['Content-Type'] = 'text/plain; charset=utf-8';
        return array('status' => 404, 'headers' => $headers, 'body' => '', 'location' => null);
    }

    /** @return array<string,string> */
    private static function headers(string $csp): array {
        $headers = EVC_Rest_Security::security_headers();
        $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate, max-age=0';
        $headers['Content-Security-Policy'] = $csp;
        $headers['X-Robots-Tag'] = 'noindex, nofollow';
        return $headers;
    }
}
