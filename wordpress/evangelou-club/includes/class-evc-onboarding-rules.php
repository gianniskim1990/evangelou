<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-07 in-store QR onboarding: the pure rules (definitions only; used by the onboarding classes behind
 * EVC_CLUB_ONBOARDING_ENABLED). The same rules as the approved CUSTOMER-01 registration form
 * (app/src/customerPreview/registration.ts, mirrored by app/src/customer/joinRules.ts): full name of at least two
 * words, a Greek mobile (canonical +3069xxxxxxxx), a REQUIRED e-mail (owner decision 2026-10-23), plus a password.
 * Every value is checked again here; the browser checks are only a convenience.
 */
final class EVC_Onboarding_Rules {
    const NAME_MAX = 80;
    const EMAIL_MAX = 254;
    const PASSWORD_MIN = 10;
    const PASSWORD_MAX = 128;
    /** The Club terms the customer accepts (bump when the text changes: everyone is asked again). */
    const TERMS_VERSION = 'club-terms-v1';

    const NAME_MISSING = 'name_missing';
    const NAME_ONE_WORD = 'name_one_word';
    const NAME_CHARS = 'name_chars';
    const NAME_LONG = 'name_long';
    const PHONE_MISSING = 'phone_missing';
    const PHONE_INVALID = 'phone_invalid';
    const EMAIL_MISSING = 'email_missing';
    const EMAIL_INVALID = 'email_invalid';
    const PASSWORD_SHORT = 'password_short';
    const PASSWORD_LONG = 'password_long';
    const PASSWORD_WEAK = 'password_weak';

    /** A few passwords people really choose; anything this predictable is refused. */
    const COMMON_PASSWORDS = array('1234567890', '0123456789', 'qwertyuiop', 'password12', 'password123', 'evangelou1', 'evangelou123', 'kafes12345', 'coffee1234', 'asdfghjkl1', '1111111111', 'abcdefghij', 'abc1234567');

    /** Trimmed, inner whitespace collapsed. */
    public static function normalise_name(string $raw): string {
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
    }

    /** "69x xxx xxxx", "+30 69…", "0030 69…" (spaces, dots, dashes, brackets ignored) -> "+3069xxxxxxxx", else null. */
    public static function canonical_greek_mobile(string $raw): ?string {
        $compact = (string) preg_replace('/[\s().-]/u', '', $raw);
        $national = (string) preg_replace('/^(?:\+30|0030)/', '', $compact);
        return preg_match('/^69\d{8}$/D', $national) === 1 ? '+30' . $national : null;
    }

    /** Trimmed and lower-cased; null when not a plausible address (the same shape as the browser form). */
    public static function normalise_email(string $raw): ?string {
        $email = strtolower(trim($raw));
        if ($email === '' || strlen($email) > self::EMAIL_MAX || preg_match('/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/D', $email) !== 1) {
            return null;
        }
        return $email;
    }

    /**
     * Field errors (codes only) for a registration; an empty array = valid.
     *
     * @param array<string,mixed> $input name, phone, email, password (anything else is ignored)
     * @return array<string,string>
     */
    public static function validate(array $input): array {
        $errors = array();
        $name = self::normalise_name(self::text($input['name'] ?? null));
        if ($name === '') {
            $errors['name'] = self::NAME_MISSING;
        } elseif (self::length($name) > self::NAME_MAX) {
            $errors['name'] = self::NAME_LONG;
        } elseif (preg_match("/^\\p{L}[\\p{L}\\p{M}' .-]*$/u", $name) !== 1) {
            $errors['name'] = self::NAME_CHARS;
        } elseif (strpos($name, ' ') === false) {
            $errors['name'] = self::NAME_ONE_WORD;
        }
        $phone = self::text($input['phone'] ?? null);
        if (trim($phone) === '') {
            $errors['phone'] = self::PHONE_MISSING;
        } elseif (self::canonical_greek_mobile($phone) === null) {
            $errors['phone'] = self::PHONE_INVALID;
        }
        $email_raw = self::text($input['email'] ?? null);
        $email = self::normalise_email($email_raw);
        if (trim($email_raw) === '') {
            $errors['email'] = self::EMAIL_MISSING;
        } elseif ($email === null) {
            $errors['email'] = self::EMAIL_INVALID;
        }
        $password = $input['password'] ?? null;
        $problem = self::password_problem(is_string($password) ? $password : '', (string) $email, (string) self::canonical_greek_mobile($phone));
        if ($problem !== null) {
            $errors['password'] = $problem;
        }
        return $errors;
    }

    /**
     * Server password policy (WordPress itself enforces none server-side): 10-128 characters, not a known common
     * password, not only one repeated character, and not built from the e-mail's name or the mobile number.
     */
    public static function password_problem(string $password, string $email, string $phone): ?string {
        $length = self::length($password);
        if ($length < self::PASSWORD_MIN) {
            return self::PASSWORD_SHORT;
        }
        if ($length > self::PASSWORD_MAX) {
            return self::PASSWORD_LONG;
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($password, 'UTF-8') : strtolower($password);
        if (in_array($lower, self::COMMON_PASSWORDS, true) || preg_match('/^(.)\1*$/su', $password) === 1) {
            return self::PASSWORD_WEAK;
        }
        $local = (string) strstr($email . '@', '@', true);
        if (strlen($local) >= 4 && strpos($lower, strtolower($local)) !== false) {
            return self::PASSWORD_WEAK;
        }
        $digits = substr((string) preg_replace('/\D/', '', $phone), -8);
        if (strlen($digits) === 8 && strpos((string) preg_replace('/\D/', '', $password), $digits) !== false) {
            return self::PASSWORD_WEAK;
        }
        return null;
    }

    /** "Μαρία Παπαδοπούλου" -> ['Μαρία', 'Παπαδοπούλου']; the rest after the first word is the surname. */
    public static function split_name(string $name): array {
        $name = self::normalise_name($name);
        $space = strpos($name, ' ');
        return $space === false ? array($name, '') : array(substr($name, 0, $space), substr($name, $space + 1));
    }

    /**
     * The rate-limit key of a client address: an HMAC, so no IP address is ever stored. IPv4 addresses count one by
     * one; an IPv6 address counts by its /64 network (one home or phone gets a whole /64, so a client could otherwise
     * rotate through billions of addresses); an IPv4-mapped IPv6 address counts as its IPv4 address. Empty when the
     * address is missing or not an IP address (then the caller fails closed).
     */
    public static function client_key(string $ip, string $secret): string {
        $ip = trim($ip);
        if ($secret === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = (string) inet_pton($ip);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        $subject = strlen($packed) === 4 ? 'v4|' . bin2hex($packed) : 'v6|' . bin2hex(substr($packed, 0, 8));
        return substr(hash_hmac('sha256', 'evc-join|' . $subject, $secret), 0, 32);
    }

    /** The rate-limit secret: derived from a WordPress salt with its own label (never the salt itself). */
    public static function derive_secret(string $salt): string {
        return $salt === '' ? '' : hash_hmac('sha256', 'evangelou-club/join-rate-limit-key/v1', $salt);
    }

    /** A configured cap: a positive int up to 1000 (exact type), else the safe default. */
    public static function cap($value, int $default): int {
        return is_int($value) && $value >= 1 && $value <= 1000 ? $value : $default;
    }

    /** "count|window_start" as stored by the atomic counter -> [count, start], or null when unreadable. */
    public static function parse_counter($value): ?array {
        if (!is_string($value) || preg_match('/^(\d{1,9})\|(\d{1,12})$/D', $value, $m) !== 1) {
            return null;
        }
        return array((int) $m[1], (int) $m[2]);
    }

    /**
     * Failure window (D9 style): at most $max failures per $window seconds per client. $state is the stored
     * array(count, window_start) or null. Returns the state after one more failure.
     *
     * @param array{0:int,1:int}|null $state
     * @return array{0:int,1:int}
     */
    public static function add_failure(?array $state, int $now, int $window): array {
        if ($state === null || !isset($state[0], $state[1]) || $now - (int) $state[1] >= $window) {
            return array(1, $now);
        }
        return array((int) $state[0] + 1, (int) $state[1]);
    }

    /** @param array{0:int,1:int}|null $state */
    public static function blocked(?array $state, int $now, int $window, int $max): bool {
        return $state !== null && isset($state[0], $state[1]) && $now - (int) $state[1] < $window && (int) $state[0] >= $max;
    }

    private static function text($value): string {
        return is_string($value) ? $value : '';
    }

    private static function length(string $value): int {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
