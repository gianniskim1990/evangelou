<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Mint opaque high-entropy QR tokens. Only SHA-256 token hashes are stored
 * in the dedicated Club database. QR lookup will also require staff auth.
 */
final class EVC_Qr_Token {
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
}
