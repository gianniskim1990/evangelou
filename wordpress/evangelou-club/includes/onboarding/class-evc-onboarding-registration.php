<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07: creating a customer's WordPress account from the public join page. Constructed only by
 * EVC_Onboarding_Plugin::wire() (behind EVC_CLUB_ONBOARDING_ENABLED); the ONLY shipped file that creates a user
 * or sets an auth cookie (packager-enforced).
 *
 *  - WordPress core APIs only: wp_insert_user() with the WooCommerce "customer" role, then the customer is
 *    signed in (wp_set_auth_cookie). No e-mail is sent by this step (wp_insert_user sends none). WordPress's own
 *    "Anyone can register" setting is deliberately NOT consulted: this is a separate, flag-gated sign-up of Club
 *    customers only (docs/store-onboarding.md).
 *  - An e-mail that already has an account is NEVER attached to, signed into or changed: the answer is the same
 *    "check_account" as for every other reason the e-mail cannot be used.
 *  - Abuse protection, all counters ATOMIC in the WordPress options table (one INSERT … ON DUPLICATE KEY UPDATE per
 *    event; no transient read-modify-write), keyed by an HMAC of the client address (IPv6 by its /64), never the
 *    address itself:
 *      failures   at most 10 per 5 minutes per client (owner decision D16), then 429 until the window ends;
 *      successes  at most EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR (default 3) per client and EVC_CLUB_JOIN_MAX_PER_HOUR
 *                 (default 30) in total per hour, reserved BEFORE the account is created ("try_later" when hit);
 *    plus a honeypot field; no CAPTCHA service.
 *  - Nothing about the person is logged; FluentCRM is never called (owner decision D12).
 */
final class EVC_Onboarding_Registration {
    const MAX_FAILURES = 10;
    const WINDOW_SECONDS = 300;
    const SUCCESS_WINDOW_SECONDS = 3600;
    const DEFAULT_MAX_PER_CLIENT_HOUR = 3;
    const DEFAULT_MAX_PER_HOUR = 30;
    const PER_CLIENT_CONSTANT = 'EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR';
    const GLOBAL_CONSTANT = 'EVC_CLUB_JOIN_MAX_PER_HOUR';
    /** Counter rows in wp_options: evc_join_rl_<f|s|g>_<key>, value "count|window_start" (autoload off). */
    const COUNTER_PREFIX = 'evc_join_rl_';
    /** Not an autofill-friendly name (browsers fill "website", "url", "company" …); autocomplete="off" in the form. */
    const HONEYPOT = 'evc_extra';
    const ROLE = 'customer';

    const OK = 'ok';
    const INVALID = 'invalid';
    const CHECK_ACCOUNT = 'check_account';
    const NOT_COMPLETED = 'not_completed';
    const RATE_LIMITED = 'rate_limited';
    const TRY_LATER = 'try_later';
    const SIGNED_IN = 'signed_in';
    const UNAVAILABLE = 'unavailable';

    /** @var callable(): int */
    private $now;

    /** @param callable(): int|null $now TEST seam (seconds) */
    public function __construct(?callable $now = null) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->now = $now ?? function (): int {
            return time();
        };
    }

    public static function max_per_client_hour(): int {
        return EVC_Onboarding_Rules::cap(defined(self::PER_CLIENT_CONSTANT) ? constant(self::PER_CLIENT_CONSTANT) : null, self::DEFAULT_MAX_PER_CLIENT_HOUR);
    }

    public static function max_per_hour(): int {
        return EVC_Onboarding_Rules::cap(defined(self::GLOBAL_CONSTANT) ? constant(self::GLOBAL_CONSTANT) : null, self::DEFAULT_MAX_PER_HOUR);
    }

    /** The client key of an address (HMAC with a derived, domain-separated secret). */
    public static function key_of(string $ip): string {
        return EVC_Onboarding_Rules::client_key($ip, EVC_Onboarding_Rules::derive_secret(wp_salt('auth')));
    }

    /**
     * @param array<string,mixed> $input the JSON body
     * @return array{outcome:string,fields?:array<string,string>,user_id?:int}
     */
    public function register(array $input, string $ip): array {
        $key = self::key_of($ip);
        if ($key === '') {
            return array('outcome' => self::UNAVAILABLE);
        }
        $now = ($this->now)();
        if (EVC_Onboarding_Rules::blocked($this->read('f', $key), $now, self::WINDOW_SECONDS, self::MAX_FAILURES)) {
            return array('outcome' => self::RATE_LIMITED);
        }
        if (is_user_logged_in()) {
            return array('outcome' => self::SIGNED_IN);
        }
        $honeypot = $input[self::HONEYPOT] ?? '';
        if (!is_string($honeypot) || $honeypot !== '') {
            $this->bump('f', $key, self::WINDOW_SECONDS, $now);
            return array('outcome' => self::NOT_COMPLETED);
        }
        $errors = EVC_Onboarding_Rules::validate($input);
        if ($errors !== array()) {
            $this->bump('f', $key, self::WINDOW_SECONDS, $now);
            return array('outcome' => self::INVALID, 'fields' => $errors);
        }
        $password = (string) $input['password'];
        // Hash once here and once more below on the "check_account" path, as wp_insert_user() does on success, so both
        // paths do comparable work. The result itself is not used (wp_insert_user hashes again).
        $unused_hash = wp_hash_password($password);
        unset($unused_hash);
        $email = (string) EVC_Onboarding_Rules::normalise_email((string) $input['email']);
        if (email_exists($email) !== false || username_exists($email) !== false || !is_email($email)) {
            wp_hash_password($password);
            $this->bump('f', $key, self::WINDOW_SECONDS, $now);
            return array('outcome' => self::CHECK_ACCOUNT);
        }
        if (get_role(self::ROLE) === null) {
            return array('outcome' => self::UNAVAILABLE);
        }
        // Success caps: a slot is RESERVED atomically before the account exists (a burst cannot overshoot the cap).
        if ($this->bump('s', $key, self::SUCCESS_WINDOW_SECONDS, $now) > self::max_per_client_hour()
            || $this->bump('g', 'all', self::SUCCESS_WINDOW_SECONDS, $now) > self::max_per_hour()) {
            return array('outcome' => self::TRY_LATER);
        }
        list($first, $last) = EVC_Onboarding_Rules::split_name((string) $input['name']);
        $phone = (string) EVC_Onboarding_Rules::canonical_greek_mobile((string) $input['phone']);
        $user_id = wp_insert_user(array(
            'user_login' => 'club_' . bin2hex(random_bytes(6)),
            'user_pass' => $password,
            'user_email' => $email,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => EVC_Onboarding_Rules::normalise_name((string) $input['name']),
            'role' => self::ROLE,
            'show_admin_bar_front' => 'false',
        ));
        if (is_wp_error($user_id) || (int) $user_id < 1) {
            // e.g. the e-mail was taken a moment ago by a parallel request: the same answer as an existing account.
            $this->bump('f', $key, self::WINDOW_SECONDS, $now);
            return array('outcome' => self::CHECK_ACCOUNT);
        }
        $user_id = (int) $user_id;
        // WooCommerce billing details, so the checkout is prefilled with exactly what the customer typed. The phone is
        // UNVERIFIED (anyone can type any number): it must never become a phone directory or identify a member.
        update_user_meta($user_id, 'billing_first_name', $first);
        update_user_meta($user_id, 'billing_last_name', $last);
        update_user_meta($user_id, 'billing_email', $email);
        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'billing_country', 'GR');
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false, is_ssl());
        return array('outcome' => self::OK, 'user_id' => $user_id);
    }

    /** The failures counted for this client address right now (0 when none / expired). */
    public function failures(string $ip): int {
        return $this->current('f', self::key_of($ip), self::WINDOW_SECONDS);
    }

    /** The successes counted for this client address in the current hour. */
    public function successes(string $ip): int {
        return $this->current('s', self::key_of($ip), self::SUCCESS_WINDOW_SECONDS);
    }

    /** Counts a failure that was decided outside register() (another origin with a valid nonce). */
    public function count_failure(string $ip): void {
        $key = self::key_of($ip);
        if ($key !== '') {
            $this->bump('f', $key, self::WINDOW_SECONDS, ($this->now)());
        }
    }

    private function current(string $bucket, string $key, int $window): int {
        if ($key === '') {
            return 0;
        }
        $state = $this->read($bucket, $key);
        return $state === null || ($this->now)() - $state[1] >= $window ? 0 : $state[0];
    }

    /** @return array{0:int,1:int}|null */
    private function read(string $bucket, string $key): ?array {
        global $wpdb;
        return EVC_Onboarding_Rules::parse_counter($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::COUNTER_PREFIX . $bucket . '_' . $key)));
    }

    /**
     * ONE atomic statement: a new row "1|now", or +1 inside the window, or a fresh "1|now" once the window is over.
     * Returns the count after this event (a concurrent event may already be included: never under-counted).
     */
    private function bump(string $bucket, string $key, int $window, int $now): int {
        global $wpdb;
        $name = self::COUNTER_PREFIX . $bucket . '_' . $key;
        $fresh = '1|' . $now;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') "
            . "ON DUPLICATE KEY UPDATE option_value = IF(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + %d <= %d, %s, "
            . "CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1, '|', SUBSTRING_INDEX(option_value, '|', -1)))",
            $name,
            $fresh,
            $window,
            $now,
            $fresh
        ));
        if (random_int(1, 50) === 1) {
            // Expired rows are removed now and then (they hold only a count and a time, never an address).
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + %d < %d", $wpdb->esc_like(self::COUNTER_PREFIX) . '%', 2 * self::SUCCESS_WINDOW_SECONDS, $now));
        }
        $state = $this->read($bucket, $key);
        return $state === null ? PHP_INT_MAX : $state[0];
    }
}
