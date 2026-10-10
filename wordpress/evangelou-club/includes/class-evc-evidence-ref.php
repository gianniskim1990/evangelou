<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Keyed opaque references for the future trusted PMPro/WooCommerce reader.
 *
 * HMAC-SHA256 over "<namespace>\0<raw identifier>" with a caller-supplied
 * secret. No secret exists in this code base: the reader must inject one from
 * a trusted configuration boundary (not the database it reads, not Git). An
 * unkeyed hash of an order number would be guessable and is NOT equivalent.
 */
final class EVC_Evidence_Ref {
    const MIN_SECRET_BYTES = 32;
    const NAMESPACE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    public static function hmac(string $secret, string $namespace, string $raw_identifier): string {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException('Evidence secret too short.');
        }
        if (!preg_match(self::NAMESPACE_PATTERN, $namespace)) {
            throw new InvalidArgumentException('Invalid evidence namespace.');
        }
        if ($raw_identifier === '') {
            throw new InvalidArgumentException('Empty identifier.');
        }
        return hash_hmac('sha256', $namespace . "\0" . $raw_identifier, $secret);
    }
}
