<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): which push subscriptions the server accepts, and what it keeps about them.
 * Pure: no WordPress, no network.
 *
 * The endpoint is a URL the BROWSER received from its push service; the server will later POST to it. To stop the
 * subscription route from becoming a way to make this server call arbitrary addresses (SSRF), only these are accepted:
 *   - https only, port 443 (written or not), no user name / password, no fragment, printable ASCII only, at most
 *     MAX_LENGTH characters, a non-empty path;
 *   - the host is EXACTLY one of the browsers' push services (lower case):
 *       fcm.googleapis.com                  Chrome, Android, Samsung Internet, Opera (Firebase Cloud Messaging)
 *       web.push.apple.com                  Safari on iPhone / iPad (installed web app) and on the Mac
 *       updates.push.services.mozilla.com   Firefox
 *       <one label>.notify.windows.com      Microsoft Edge on Windows (Windows Push Notification Services)
 *     never an IP address, never another host, never a sub-domain other than that one label for Windows.
 * The sender checks the stored endpoint again with the same rule before every request and never follows a redirect.
 *
 * Kept about a subscription (migration 005): the endpoint (needed to send) and its SHA-256 (for uniqueness), the two
 * keys, and two coarse labels only: the push service and the browser family (agent_family(); never the User-Agent text).
 */
final class EVC_Push_Endpoint {
    const MAX_LENGTH = 1024;
    const HOSTS = array(
        'fcm.googleapis.com' => 'fcm',
        'web.push.apple.com' => 'apple',
        'updates.push.services.mozilla.com' => 'mozilla',
    );
    /** Windows Push Notification Services: exactly one DNS label in front of this suffix. */
    const WINDOWS_SUFFIX = '.notify.windows.com';
    const SERVICES = array('fcm', 'apple', 'mozilla', 'windows');
    const AGENTS = array('chrome', 'safari', 'firefox', 'edge', 'samsung', 'other');

    const PROBLEM_TYPE = 'endpoint_not_text';
    const PROBLEM_LENGTH = 'endpoint_length';
    const PROBLEM_CHARACTERS = 'endpoint_characters';
    const PROBLEM_FORM = 'endpoint_not_https_url';
    const PROBLEM_HOST = 'endpoint_host_not_allowed';

    /** Null when the endpoint may be stored and called; otherwise a problem code. */
    public static function problem($url): ?string {
        if (!is_string($url)) {
            return self::PROBLEM_TYPE;
        }
        $length = strlen($url);
        if ($length < 12 || $length > self::MAX_LENGTH) {
            return self::PROBLEM_LENGTH;
        }
        // Printable ASCII only: no space, control or non-ASCII byte; no fragment, no backslash.
        if (preg_match('/^[\x21-\x7E]+$/D', $url) !== 1 || strpbrk($url, "#\\") !== false) {
            return self::PROBLEM_CHARACTERS;
        }
        // https://host[:443]/path — the host part cannot hold '@', ':' (other than :443) or '/'.
        if (preg_match('#^https://([a-z0-9.-]+)(?::443)?(/[^?]*(?:\?.*)?)$#D', $url, $m) !== 1 || strlen($m[2]) < 2) {
            return self::PROBLEM_FORM;
        }
        return self::service_of_host($m[1]) === null ? self::PROBLEM_HOST : null;
    }

    /** The push service of an ACCEPTED endpoint ('fcm', 'apple', 'mozilla', 'windows'), or null. */
    public static function service($url): ?string {
        if (self::problem($url) !== null) {
            return null;
        }
        preg_match('#^https://([a-z0-9.-]+)#', (string) $url, $m);
        return self::service_of_host($m[1]);
    }

    private static function service_of_host(string $host): ?string {
        if (isset(self::HOSTS[$host])) {
            return self::HOSTS[$host];
        }
        $suffix = strlen(self::WINDOWS_SUFFIX);
        if (strlen($host) > $suffix && substr($host, -$suffix) === self::WINDOWS_SUFFIX
            && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', substr($host, 0, -$suffix)) === 1) {
            return 'windows';
        }
        return null;
    }

    /** The "aud" claim of the VAPID token: the origin of the push resource (RFC 8292 section 2). */
    public static function audience(string $url): string {
        preg_match('#^https://([a-z0-9.-]+)#', $url, $m);
        return 'https://' . ($m[1] ?? '');
    }

    /** SHA-256 hex of the endpoint (the unique key of evc_push_subscriptions). */
    public static function hash(string $url): string {
        return hash('sha256', $url);
    }

    /** The subscription's p256dh key (base64url of a P-256 point) as raw bytes, or null. */
    public static function p256dh($value): ?string {
        $raw = is_string($value) && strlen($value) <= 100 ? EVC_Push_Crypto::b64u_decode($value) : null;
        return $raw !== null && EVC_Push_Crypto::is_public_key($raw) ? $raw : null;
    }

    /** The subscription's auth secret (base64url of 16 bytes) as raw bytes, or null. */
    public static function auth($value): ?string {
        $raw = is_string($value) && strlen($value) <= 32 ? EVC_Push_Crypto::b64u_decode($value) : null;
        return $raw !== null && strlen($raw) === EVC_Push_Crypto::AUTH_LENGTH ? $raw : null;
    }

    /** A coarse browser family from the User-Agent header; the header itself is never stored. */
    public static function agent_family($user_agent): string {
        $ua = is_string($user_agent) ? $user_agent : '';
        if (strpos($ua, 'Edg/') !== false || strpos($ua, 'EdgA/') !== false || strpos($ua, 'EdgiOS/') !== false) {
            return 'edge';
        }
        if (strpos($ua, 'SamsungBrowser/') !== false) {
            return 'samsung';
        }
        if (strpos($ua, 'Firefox/') !== false || strpos($ua, 'FxiOS/') !== false) {
            return 'firefox';
        }
        if (strpos($ua, 'Chrome/') !== false || strpos($ua, 'CriOS/') !== false) {
            return 'chrome';
        }
        if (strpos($ua, 'Safari/') !== false && preg_match('/\b(iPhone|iPad|iPod|Macintosh)\b/', $ua) === 1) {
            return 'safari';
        }
        return 'other';
    }
}
