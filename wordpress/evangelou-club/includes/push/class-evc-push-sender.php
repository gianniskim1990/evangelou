<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): delivers ONE notification to ONE stored subscription, straight from this
 * server to the browser's push service (RFC 8030), no third-party sender. The ONLY shipped file that makes an outgoing
 * HTTP request for push (packager-enforced). Constructed only by EVC_Push_Plugin::wire().
 *
 *   1. the stored endpoint is checked AGAIN with EVC_Push_Endpoint::problem() (https, allowlisted push host): anything
 *      else is never called (SSRF);
 *   2. the payload is encrypted for that subscription (EVC_Push_Crypto, RFC 8291 aes128gcm) and signed for that push
 *      service origin (EVC_Push_Vapid, RFC 8292);
 *   3. POST with TTL (24 h), Urgency normal, Content-Encoding aes128gcm; no redirect is followed; TLS verified; a short
 *      timeout so a batch stays inside its time budget.
 * Result: 'sent' (2xx) | 'gone' (404 / 410: the subscription no longer exists; the caller revokes it) | 'failed'
 * (anything else, incl. a network error, 413, 429 or a VAPID refusal) | 'refused' (the stored endpoint or keys no longer
 * pass the checks: never sent). Nothing about the endpoint, the keys or the push service's answer body is logged.
 */
final class EVC_Push_Sender {
    const TTL_SECONDS = 86400;
    const TIMEOUT_SECONDS = 5;
    const SENT = 'sent';
    const GONE = 'gone';
    const FAILED = 'failed';
    const REFUSED = 'refused';

    /** @var callable(string, array, string): int */
    private $http;
    /** @var callable(): int */
    private $time;

    /**
     * @param callable(string $url, array<string,string> $headers, string $body): int|null $http TEST seam: returns the HTTP
     *        status, throws on a network error (default: wp_remote_post without redirects)
     * @param callable(): int|null $time TEST seam (default: time())
     */
    public function __construct(?callable $http = null, ?callable $time = null) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->http = $http ?? array(__CLASS__, 'post');
        $this->time = $time ?? 'time';
    }

    /**
     * @param array{endpoint:string,p256dh:string,auth:string} $subscription
     * @param array{public:string,private:string,subject:string} $keys
     */
    public function send(array $subscription, string $payload, array $keys): string {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        if (EVC_Push_Endpoint::problem($endpoint) !== null) {
            return self::REFUSED;
        }
        $p256dh = EVC_Push_Endpoint::p256dh($subscription['p256dh'] ?? null);
        $secret = EVC_Push_Endpoint::auth($subscription['auth'] ?? null);
        if ($p256dh === null || $secret === null) {
            return self::REFUSED;
        }
        try {
            $body = EVC_Push_Crypto::encrypt($payload, $p256dh, $secret);
            $authorization = EVC_Push_Vapid::authorization(EVC_Push_Endpoint::audience($endpoint), (int) ($this->time)(), $keys['public'], $keys['private'], $keys['subject']);
        } catch (Throwable $e) {
            return self::REFUSED;
        }
        $headers = array(
            'TTL' => (string) self::TTL_SECONDS,
            'Urgency' => 'normal',
            'Content-Encoding' => 'aes128gcm',
            'Content-Type' => 'application/octet-stream',
            'Authorization' => $authorization,
        );
        try {
            $status = (int) ($this->http)($endpoint, $headers, $body);
        } catch (Throwable $e) {
            return self::FAILED;
        }
        if ($status >= 200 && $status < 300) {
            return self::SENT;
        }
        return $status === 404 || $status === 410 ? self::GONE : self::FAILED;
    }

    /** The real request: no redirects, TLS verified, unsafe (private / local) addresses refused by WordPress too. */
    public static function post(string $url, array $headers, string $body): int {
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'blocking' => true,
            'user-agent' => 'Evangelou Club',
        ));
        if (is_wp_error($response)) {
            throw new RuntimeException('Push request failed.');
        }
        return (int) wp_remote_retrieve_response_code($response);
    }
}
