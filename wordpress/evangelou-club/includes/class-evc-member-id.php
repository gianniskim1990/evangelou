<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Stable public opaque member identifier ("mem_" + 32 lowercase hex chars).
 * Minted once per Club member and never derived from the WordPress user ID,
 * phone or e-mail. Clients must treat it as an opaque string.
 */
final class EVC_Member_Id {
    public static function mint(): string {
        return 'mem_' . bin2hex(random_bytes(16));
    }

    public static function is_valid_format($value): bool {
        return is_string($value) && (bool) preg_match('/^mem_[0-9a-f]{32}$/D', $value);
    }
}
