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
 *      failures   at most 10 per 5 minutes per client (owner decision D16) and 30 per 5 minutes per IPv6 /48 in
 *                 aggregate (round 4, B1), then 429 until the window ends;
 *      successes  at most EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR (default 10, owner decision D17) per client,
 *                 EVC_CLUB_JOIN_MAX_PER_NETWORK_HOUR (default 30) per IPv6 /48 and EVC_CLUB_JOIN_MAX_PER_HOUR
 *                 (default 100) in total per hour, reserved BEFORE the account is created ("try_later" when hit;
 *                 the total cap also logs one PII-free line per hour and shows an admin notice);
 *    plus a honeypot field; no CAPTCHA service. The failure rows are bounded: a failure row is deleted once its own
 *    5-minute window is over, and at ROW_CEILING failure rows no NEW failure row is written (existing ones still
 *    count) and the shop is told (log line + admin notice, once an hour). Account creation is NEVER refused because
 *    of the ceiling (round 4, B1): the ceiling must not become a way to close sign-up.
 *  - Nothing about the person is logged; FluentCRM is never called (owner decision D12).
 */
final class EVC_Onboarding_Registration {
    const MAX_FAILURES = 10;
    const WINDOW_SECONDS = 300;
    const SUCCESS_WINDOW_SECONDS = 3600;
    /** Owner decision D17 (2026-11-06): 10 new accounts per client and hour (was 3). */
    const DEFAULT_MAX_PER_CLIENT_HOUR = 10;
    /** Coordinator decision (round 4, N1): mobile carriers share a /48 among unrelated customers. */
    const DEFAULT_MAX_PER_NETWORK_HOUR = 30;
    /** Failures per IPv6 /48 per 5 minutes, in aggregate (round 4, B1): bounds the rows one /48 can write. */
    const MAX_NETWORK_FAILURES = 30;
    const DEFAULT_MAX_PER_HOUR = 100;
    const PER_CLIENT_CONSTANT = 'EVC_CLUB_JOIN_MAX_PER_CLIENT_HOUR';
    const NETWORK_CONSTANT = 'EVC_CLUB_JOIN_MAX_PER_NETWORK_HOUR';
    const GLOBAL_CONSTANT = 'EVC_CLUB_JOIN_MAX_PER_HOUR';
    /** At this many failure rows (f_ and m_): no NEW failure row until expired rows are deleted. Sign-up stays open. */
    const ROW_CEILING = 5000;
    /** When the total cap was last hit (a time only; the admin notice reads it). */
    const ALERT_OPTION = 'evc_club_join_cap_alert';
    /** When the failure rows last reached the ceiling (a time only; the admin notice reads it). */
    const ROWS_ALERT_OPTION = 'evc_club_join_rows_alert';
    /** Counter rows in wp_options: evc_join_rl_<f|s|n|g|a>_<key> (failures, successes per client / per /48 / total, alert), value "count|window_start" (autoload off). */
    const COUNTER_PREFIX = 'evc_join_rl_';
    /**
     * The next counter value, in SQL (placeholders: the window's earliest start, the fresh value "1|now"): a fresh
     * window once the stored start is at or before now - window, else count + 1 with the same start.
     */
    const NEXT_VALUE_SQL = "IF(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d, %s, "
        . "CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1, '|', SUBSTRING_INDEX(option_value, '|', -1)))";
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
    /** @var int */
    private $row_ceiling;

    /**
     * @param callable(): int|null $now TEST seam (seconds)
     * @param int|null $row_ceiling TEST seam (default ROW_CEILING)
     */
    public function __construct(?callable $now = null, ?int $row_ceiling = null) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->now = $now ?? function (): int {
            return time();
        };
        $this->row_ceiling = $row_ceiling !== null && $row_ceiling > 0 ? $row_ceiling : self::ROW_CEILING;
    }

    public static function max_per_client_hour(): int {
        return EVC_Onboarding_Rules::cap(defined(self::PER_CLIENT_CONSTANT) ? constant(self::PER_CLIENT_CONSTANT) : null, self::DEFAULT_MAX_PER_CLIENT_HOUR);
    }

    public static function max_per_network_hour(): int {
        return EVC_Onboarding_Rules::cap(defined(self::NETWORK_CONSTANT) ? constant(self::NETWORK_CONSTANT) : null, self::DEFAULT_MAX_PER_NETWORK_HOUR);
    }

    public static function max_per_hour(): int {
        return EVC_Onboarding_Rules::cap(defined(self::GLOBAL_CONSTANT) ? constant(self::GLOBAL_CONSTANT) : null, self::DEFAULT_MAX_PER_HOUR);
    }

    /** The client key of an address (HMAC with a derived, domain-separated secret). */
    public static function key_of(string $ip): string {
        return EVC_Onboarding_Rules::client_key($ip, EVC_Onboarding_Rules::derive_secret(wp_salt('auth')));
    }

    /** The key of the client's IPv6 /48 ('' for IPv4). */
    public static function network_key_of(string $ip): string {
        return EVC_Onboarding_Rules::network_key($ip, EVC_Onboarding_Rules::derive_secret(wp_salt('auth')));
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
        $network = self::network_key_of($ip);
        if (EVC_Onboarding_Rules::blocked($this->read('f', $key), $now, self::WINDOW_SECONDS, self::MAX_FAILURES)
            || ($network !== '' && EVC_Onboarding_Rules::blocked($this->read('m', $network), $now, self::WINDOW_SECONDS, self::MAX_NETWORK_FAILURES))) {
            return array('outcome' => self::RATE_LIMITED);
        }
        if (is_user_logged_in()) {
            return array('outcome' => self::SIGNED_IN);
        }
        $honeypot = $input[self::HONEYPOT] ?? '';
        if (!is_string($honeypot) || $honeypot !== '') {
            $this->count_failure_of($key, $network, $now);
            return array('outcome' => self::NOT_COMPLETED);
        }
        $errors = EVC_Onboarding_Rules::validate($input);
        if ($errors !== array()) {
            $this->count_failure_of($key, $network, $now);
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
            $this->count_failure_of($key, $network, $now);
            return array('outcome' => self::CHECK_ACCOUNT);
        }
        if (get_role(self::ROLE) === null) {
            return array('outcome' => self::UNAVAILABLE);
        }
        // Success caps: a slot is RESERVED atomically before the account exists (a burst cannot overshoot the cap).
        // The failure-row ceiling is deliberately NOT consulted here (round 4, B1).
        if ($this->bump('s', $key, self::SUCCESS_WINDOW_SECONDS, $now) > self::max_per_client_hour()
            || ($network !== '' && $this->bump('n', $network, self::SUCCESS_WINDOW_SECONDS, $now) > self::max_per_network_hour())) {
            return array('outcome' => self::TRY_LATER);
        }
        if ($this->bump('g', 'all', self::SUCCESS_WINDOW_SECONDS, $now) > self::max_per_hour()) {
            // The total cap stays BLOCKING (a lever for denial of service, documented): the shop is told once an hour.
            $this->alert_total_cap($now);
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
            $this->count_failure_of($key, $network, $now);
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

    /** The successes counted for this client's IPv6 /48 in the current hour (0 for IPv4). */
    public function network_successes(string $ip): int {
        $network = self::network_key_of($ip);
        return $network === '' ? 0 : $this->current('n', $network, self::SUCCESS_WINDOW_SECONDS);
    }

    /** The failures counted for this client's IPv6 /48 right now (0 for IPv4). */
    public function network_failures(string $ip): int {
        $network = self::network_key_of($ip);
        return $network === '' ? 0 : $this->current('m', $network, self::WINDOW_SECONDS);
    }

    /** The admin notices of the last 24 hours ('' when none; no personal data). */
    public static function admin_notice_html(int $now): string {
        $html = '';
        $at = self::recent_alert(self::ALERT_OPTION, $now);
        if ($at !== null) {
            $html .= self::notice(sprintf(
                'Evangelou Club: Συμπληρώθηκε το ωριαίο όριο νέων λογαριασμών από το QR του καταστήματος (%1$d ανά ώρα) στις %2$s. Οι νέες εγγραφές περιμένουν μέχρι να περάσει η ώρα. Αν πρόκειται για πραγματική κίνηση και όχι για επίθεση, ο τεχνικός μπορεί να αυξήσει τη ρύθμιση %3$s.',
                self::max_per_hour(),
                wp_date('d/m/Y H:i', $at),
                self::GLOBAL_CONSTANT
            ));
        }
        $at = self::recent_alert(self::ROWS_ALERT_OPTION, $now);
        if ($at !== null) {
            $html .= self::notice(sprintf(
                'Evangelou Club: Στις %1$s καταγράφηκαν πάρα πολλές αποτυχημένες προσπάθειες εγγραφής από το QR (πιθανή επίθεση). Οι εγγραφές συνεχίζουν κανονικά· νέες αποτυχίες δεν καταγράφονται μέχρι να λήξουν οι παλιές (5 λεπτά). Αν επαναλαμβάνεται, ο τεχνικός μπορεί να ελέγξει την κίνηση ή να κλείσει προσωρινά τη ρύθμιση EVC_CLUB_ONBOARDING_ENABLED.',
                wp_date('d/m/Y H:i', $at)
            ));
        }
        return $html;
    }

    private static function recent_alert(string $option, int $now): ?int {
        $at = (int) get_option($option, 0);
        return $at <= 0 || $now - $at > DAY_IN_SECONDS || $at > $now + 60 ? null : $at;
    }

    private static function notice(string $text): string {
        return '<div class="notice notice-warning"><p>' . esc_html($text) . '</p></div>';
    }

    /** admin_notices: shown to administrators only. */
    public static function admin_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo self::admin_notice_html(time()); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
    }

    /** One PII-free log line and the admin notice, at most once per hour (the first event of the 'a' window). */
    private function alert_total_cap(int $now): void {
        if ($this->bump('a', 'total-cap', self::SUCCESS_WINDOW_SECONDS, $now) !== 1) {
            return;
        }
        error_log('Evangelou Club: the hourly limit of new Club accounts (' . self::GLOBAL_CONSTANT . ') was reached; new sign-ups wait until the hour ends.');
        update_option(self::ALERT_OPTION, $now, false);
    }

    /** One PII-free log line and the admin notice, at most once per hour, when the failure rows reach the ceiling. */
    private function alert_rows_full(int $now): void {
        if ($this->bump('a', 'rows-full', self::SUCCESS_WINDOW_SECONDS, $now) !== 1) {
            return;
        }
        error_log('Evangelou Club: the failed QR sign-up attempts reached the row ceiling; new failures are not recorded until old ones expire (sign-up stays open).');
        update_option(self::ROWS_ALERT_OPTION, $now, false);
    }

    /**
     * A failure of this client (and of its IPv6 /48). At the row ceiling only EXISTING rows are counted (one atomic
     * UPDATE each): no new row is written, so a flood from many addresses cannot grow the options table without bound.
     */
    private function count_failure_of(string $key, string $network, int $now): void {
        $full = $this->failure_rows_full($now);
        if ($full) {
            $this->alert_rows_full($now);
        }
        $this->count_in('f', $key, $now, $full);
        if ($network !== '') {
            $this->count_in('m', $network, $now, $full);
        }
    }

    private function count_in(string $bucket, string $key, int $now, bool $existing_only): void {
        if (!$existing_only) {
            $this->bump($bucket, $key, self::WINDOW_SECONDS, $now);
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = " . self::NEXT_VALUE_SQL . ' WHERE option_name = %s',
            $now - self::WINDOW_SECONDS,
            '1|' . $now,
            self::COUNTER_PREFIX . $bucket . '_' . $key
        ));
    }

    /** Whether the failure rows reached the ceiling (a bounded count on the option_name index; expired ones deleted first). */
    private function failure_rows_full(int $now): bool {
        if ($this->count_failure_rows() < $this->row_ceiling) {
            return false;
        }
        $this->cleanup($now);
        return $this->count_failure_rows() >= $this->row_ceiling;
    }

    private function count_failure_rows(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT %d) AS evc_rows",
            $wpdb->esc_like(self::COUNTER_PREFIX . 'f_') . '%',
            $wpdb->esc_like(self::COUNTER_PREFIX . 'm_') . '%',
            $this->row_ceiling
        ));
    }

    /**
     * Removes expired rows (only a count and a time each): failure rows (f_, m_) as soon as their own 5-minute window
     * is over (round 4, B1), every other row two hours after its window started. A deadlock or lock wait here is
     * ignored and not logged: the rows are simply removed by a later cleanup.
     */
    private function cleanup(int $now): void {
        global $wpdb;
        $was = $wpdb->suppress_errors(true);
        foreach (array('f_', 'm_') as $bucket) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d", $wpdb->esc_like(self::COUNTER_PREFIX . $bucket) . '%', $now - self::WINDOW_SECONDS));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + %d < %d", $wpdb->esc_like(self::COUNTER_PREFIX) . '%', 2 * self::SUCCESS_WINDOW_SECONDS, $now));
        $wpdb->suppress_errors($was);
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
            . 'ON DUPLICATE KEY UPDATE option_value = ' . self::NEXT_VALUE_SQL,
            $name,
            $fresh,
            $now - $window,
            $fresh
        ));
        if (random_int(1, 50) === 1) {
            $this->cleanup($now); // expired rows are removed now and then
        }
        $state = $this->read($bucket, $key);
        return $state === null ? PHP_INT_MAX : $state[0];
    }
}
