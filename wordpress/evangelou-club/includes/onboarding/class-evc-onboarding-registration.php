<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07: creating a customer's WordPress account from the public join page. Constructed only by
 * EVC_Onboarding_Plugin::wire() (behind EVC_CLUB_ONBOARDING_ENABLED); the ONLY shipped file that creates a user
 * or sets an auth cookie (packager-enforced).
 *
 *  - WordPress core APIs only: wp_insert_user() with the WooCommerce "customer" role, then the customer is
 *    signed in (wp_set_auth_cookie). No e-mail is sent by this step (wp_insert_user sends none).
 *  - An e-mail that already has an account is NEVER attached to, signed into or revealed: the answer is the same
 *    "check_account" as for every other reason the e-mail cannot be used, after the same password hashing work.
 *  - Abuse protection (D9 style): at most 10 failures per 5 minutes per client address (an HMAC of REMOTE_ADDR,
 *    never the address), then 429 until the window ends; a honeypot field; no CAPTCHA service.
 *  - Nothing about the person is logged; FluentCRM is never called (owner decision D12).
 */
final class EVC_Onboarding_Registration {
    const MAX_FAILURES = 10;
    const WINDOW_SECONDS = 300;
    const FAILURE_TRANSIENT = 'evc_join_f_';
    const HONEYPOT = 'website';
    const ROLE = 'customer';

    const OK = 'ok';
    const INVALID = 'invalid';
    const CHECK_ACCOUNT = 'check_account';
    const NOT_COMPLETED = 'not_completed';
    const RATE_LIMITED = 'rate_limited';
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

    /**
     * @param array<string,mixed> $input the JSON body
     * @return array{outcome:string,fields?:array<string,string>,user_id?:int}
     */
    public function register(array $input, string $ip): array {
        $key = EVC_Onboarding_Rules::client_key($ip, wp_salt('nonce'));
        if ($key === '') {
            return array('outcome' => self::UNAVAILABLE);
        }
        $now = ($this->now)();
        $state = $this->state($key);
        if (EVC_Onboarding_Rules::blocked($state, $now, self::WINDOW_SECONDS, self::MAX_FAILURES)) {
            return array('outcome' => self::RATE_LIMITED);
        }
        if (is_user_logged_in()) {
            return array('outcome' => self::SIGNED_IN);
        }
        $honeypot = $input[self::HONEYPOT] ?? '';
        if (!is_string($honeypot) || $honeypot !== '') {
            $this->fail($key, $state, $now);
            return array('outcome' => self::NOT_COMPLETED);
        }
        $errors = EVC_Onboarding_Rules::validate($input);
        if ($errors !== array()) {
            $this->fail($key, $state, $now);
            return array('outcome' => self::INVALID, 'fields' => $errors);
        }
        $password = (string) $input['password'];
        // The same hashing work on every path that reaches here: the answer time does not tell whether the e-mail exists.
        $hash = wp_hash_password($password);
        $email = (string) EVC_Onboarding_Rules::normalise_email((string) $input['email']);
        if (email_exists($email) !== false || username_exists($email) !== false || !is_email($email)) {
            wp_hash_password($password); // the second hash wp_insert_user() would have done
            $this->fail($key, $state, $now);
            return array('outcome' => self::CHECK_ACCOUNT);
        }
        if (get_role(self::ROLE) === null) {
            return array('outcome' => self::UNAVAILABLE);
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
        unset($hash);
        if (is_wp_error($user_id) || (int) $user_id < 1) {
            // e.g. the e-mail was taken a moment ago by a parallel request: the same answer as an existing account.
            $this->fail($key, $state, $now);
            return array('outcome' => self::CHECK_ACCOUNT);
        }
        $user_id = (int) $user_id;
        // WooCommerce billing details, so the checkout is prefilled with exactly what the customer typed.
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
        $key = EVC_Onboarding_Rules::client_key($ip, wp_salt('nonce'));
        $state = $key === '' ? null : $this->state($key);
        if ($state === null || ($this->now)() - (int) $state[1] >= self::WINDOW_SECONDS) {
            return 0;
        }
        return (int) $state[0];
    }

    /** Counts a failure that was decided outside register() (a bad nonce or origin). */
    public function count_failure(string $ip): void {
        $key = EVC_Onboarding_Rules::client_key($ip, wp_salt('nonce'));
        if ($key !== '') {
            $this->fail($key, $this->state($key), ($this->now)());
        }
    }

    /** @return array{0:int,1:int}|null */
    private function state(string $key): ?array {
        $value = get_transient(self::FAILURE_TRANSIENT . $key);
        return is_array($value) && isset($value[0], $value[1]) ? array((int) $value[0], (int) $value[1]) : null;
    }

    /** @param array{0:int,1:int}|null $state */
    private function fail(string $key, ?array $state, int $now): void {
        set_transient(self::FAILURE_TRANSIENT . $key, EVC_Onboarding_Rules::add_failure($state, $now, self::WINDOW_SECONDS), self::WINDOW_SECONDS);
    }
}
