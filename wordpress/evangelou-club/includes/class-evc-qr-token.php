<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * QR tokens: `evc_` + 48 lower-case hex digits. Only SHA-256 token digests are stored in the dedicated
 * Club database; the staff lookup finds a member by that digest.
 *
 * Owner decision D22 (2026-11-19): a member's QR is the SAME on every device and browser; opening the
 * card never changes it. The customer route therefore derives the member's token on the server
 * (derive()): HMAC-SHA256 under the dedicated server secret EVC_CLUB_QR_SECRET (wp-config.php, at least
 * 32 bytes, never equal to EVC_CLUB_LOOKUP_KEY), over the member's public id and the member's generation
 * (the number of QR rows the member has in `evc_qr_tokens`). The same member and generation always give
 * the same token, so the server can hand it out again to that member on any device; the database still
 * holds only its digest, so the till lookup is unchanged. A new code exists only when the generation
 * changes, i.e. after an administrator's revocation (or, once, when an older random token is replaced).
 * mint() stays for the reviewed UI-04C service, whose administrator path still uses it.
 */
final class EVC_Qr_Token {
    /** wp-config.php constant of the derivation secret (D22). */
    const SECRET_CONSTANT = 'EVC_CLUB_QR_SECRET';
    const MIN_SECRET_BYTES = 32;
    /** Domain separation: this HMAC input is used for nothing else. */
    const DERIVATION_CONTEXT = 'evc-club-member-qr|v1|';

    public static function mint() {
        return 'evc_' . bin2hex(random_bytes(24));
    }

    public static function is_valid_format($token) {
        return is_string($token) && (bool) preg_match('/^evc_[0-9a-f]{48}$/D', $token);
    }

    public static function digest($token) {
        if (!self::is_valid_format($token)) {
            throw new InvalidArgumentException('Invalid QR token format.');
        }
        return hash('sha256', $token);
    }

    /**
     * Round 8 (NIT-1): a secret needs at least this many DIFFERENT bytes. 64 random hex digits practically
     * always hold 14-16; a value of one repeated character (or a short repeated pattern) is refused.
     */
    const MIN_DISTINCT_BYTES = 8;

    /**
     * A usable derivation secret: a string of at least 32 bytes that is NOT the lookup-proof secret (one
     * leaked key must not give both). Anything else fails closed (the customer QR route answers 503).
     * Round 8 (NIT-1): an obviously non-random value fails closed too: the placeholder copied from the docs
     * (it contains «...»), or a value with fewer than MIN_DISTINCT_BYTES different bytes (e.g. 32 × «k»).
     *
     * @param mixed $secret
     * @param mixed $lookup_secret the value of EVC_CLUB_LOOKUP_KEY, or null when it is not defined
     */
    public static function is_derivation_secret($secret, $lookup_secret = null): bool {
        if (!is_string($secret) || strlen($secret) < self::MIN_SECRET_BYTES) {
            return false;
        }
        if (strpos($secret, '...') !== false || count(count_chars($secret, 1)) < self::MIN_DISTINCT_BYTES) {
            return false;
        }
        return !(is_string($lookup_secret) && hash_equals($lookup_secret, $secret));
    }

    /**
     * The member's token for one generation (>= 1). Pure and deterministic; the result is never stored or
     * logged, only its digest is.
     */
    public static function derive(string $secret, string $member_public_id, int $generation): string {
        if (!self::is_derivation_secret($secret) || !EVC_Member_Id::is_valid_format($member_public_id) || $generation < 1) {
            throw new InvalidArgumentException('Invalid QR token derivation.');
        }
        $mac = hash_hmac('sha256', self::DERIVATION_CONTEXT . $member_public_id . '|' . $generation, $secret);
        return 'evc_' . substr($mac, 0, 48);
    }
}
