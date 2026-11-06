<?php
defined('ABSPATH') || exit;
/**
 * STAGE-06 wiring, behind EVC_CLUB_FLUENTCRM_SYNC_ENABLED (default OFF): the ONLY place that
 * constructs EVC_Fluentcrm_Api_Gateway and EVC_Fluentcrm_Tag_Sync (the packager enforces it).
 *
 * OFF: boot() returns before anything is hooked, scheduled, constructed or connected.
 * ON: an administrator notice whenever the sync is held back (tags not configured / not verified,
 * FluentCRM missing, membership source not configured) and the Tools page «Evangelou Club —
 * FluentCRM» with counts only. Only when the tags are configured AND the membership source is:
 *  - the existing lifecycle events (WooCommerce order completed = BACS confirmation, on-hold /
 *    pending, cancelled / failed / refunded, a refund, and PMPro level changes incl. expiry) only
 *    NOTE the customer; the sync runs at the end of the request (shutdown), after the payment,
 *    membership or redemption work is done, never inside it, and its failure is swallowed;
 *  - a daily, idempotent reconcile on WP-Cron: lock-protected, in batches, bounded per run, with
 *    a follow-up run when more members remain.
 * Nothing is logged but class names; nothing is stored but counts.
 */
final class EVC_Fluentcrm_Plugin {
    const CRON_HOOK = 'evc_club_fluentcrm_reconcile';
    const CONTINUE_HOOK = 'evc_club_fluentcrm_reconcile_more';
    const STATUS_OPTION = 'evc_club_fluentcrm_status';
    const CURSOR_OPTION = 'evc_club_fluentcrm_cursor';
    const LOCK_OPTION = 'evc_club_fluentcrm_lock';
    const LOG_TRANSIENT = 'evc_club_fluentcrm_logged';
    const RETRY_OPTION = 'evc_club_fluentcrm_retries';
    /** Round 3: after a failing run, at most this many follow-up runs (5, 10, 20 minutes), then the next daily run. */
    const MAX_RETRIES = 3;
    const LOCK_TTL = 900;
    const BATCH = 50;
    const MAX_PER_RUN = 500;
    const MAX_PER_REQUEST = 20;
    const CONTINUE_DELAY = 300;
    const EVENT_PRIORITY = 100;
    const ORDER_STATUSES = array('completed', 'processing', 'on-hold', 'pending', 'cancelled', 'failed', 'refunded');
    const PAGE_SLUG = 'evc-club-fluentcrm';
    const PAGE_TITLE = 'Evangelou Club — FluentCRM';
    const CAPABILITY = 'manage_options';
    const PROBLEM_SOURCE = 'membership_source_not_configured';
    const PROBLEM_WIRING = 'wiring_failed';

    /** @var EVC_Fluentcrm_Tag_Sync|null */
    private static $sync;
    /** @var string|null why nothing is written (null = configured; verify() may still hold it back) */
    private static $problem;
    /** @var callable(int, int): int[] */
    private static $candidates;
    /** @var array<int,true> */
    private static $queue = array();

    /**
     * @param array{membership?:EVC_Membership_Adapter,candidates?:callable,clock?:EVC_Clock_Source}|mixed $seams TEST seams
     *        (WordPress passes '' from plugins_loaded; anything but an array is ignored)
     */
    public static function boot($seams = null): void {
        if (!EVC_Fluentcrm_Feature::enabled()) {
            return;
        }
        $seams = is_array($seams) ? $seams : array();
        self::$sync = null;
        self::$problem = null;
        self::$queue = array();
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        try {
            $parts = self::build($seams['membership'] ?? null, $seams['candidates'] ?? null, $seams['clock'] ?? null);
        } catch (Throwable $e) {
            $parts = array('sync' => null, 'problem' => self::PROBLEM_WIRING, 'candidates' => null);
            self::log($e);
        }
        self::$sync = $parts['sync'];
        self::$problem = $parts['problem'];
        self::$candidates = $parts['candidates'];
        if (self::$sync === null) {
            return; // held back: the notice says why; nothing is hooked or scheduled
        }
        foreach (self::ORDER_STATUSES as $status) {
            add_action('woocommerce_order_status_' . $status, array(__CLASS__, 'note_order'), self::EVENT_PRIORITY, 1);
        }
        add_action('woocommerce_order_refunded', array(__CLASS__, 'note_order'), self::EVENT_PRIORITY, 1);
        add_action('pmpro_after_change_membership_level', array(__CLASS__, 'note_level_change'), self::EVENT_PRIORITY, 2);
        add_action('shutdown', array(__CLASS__, 'flush'), self::EVENT_PRIORITY, 0);
        add_action(self::CRON_HOOK, array(__CLASS__, 'reconcile_daily'));
        add_action(self::CONTINUE_HOOK, array(__CLASS__, 'reconcile'));
        add_action('init', array(__CLASS__, 'schedule'));
    }

    /**
     * @param EVC_Membership_Adapter|null $membership TEST seam; default: the configured Club membership source
     * @param callable(int, int): int[]|null $candidates TEST seam; default: Club members by PMPro row or Club order
     * @param EVC_Clock_Source|null $clock TEST seam; default: the system clock
     * @return array{sync:?EVC_Fluentcrm_Tag_Sync,problem:?string,candidates:?callable}
     */
    public static function build(?EVC_Membership_Adapter $membership = null, ?callable $candidates = null, ?EVC_Clock_Source $clock = null): array {
        list($config, $problem) = EVC_Fluentcrm_Feature::tag_config();
        if ($config === null) {
            return array('sync' => null, 'problem' => $problem, 'candidates' => null);
        }
        $commerce = EVC_Commerce_Source_Config::from_constants();
        if ($membership === null) {
            if ($commerce === null || !defined('EVC_CLUB_PAYMENT_PROVENANCE') || constant('EVC_CLUB_PAYMENT_PROVENANCE') !== 'club_db_v1' || EVC_Db_Config::from_constants() === null) {
                return array('sync' => null, 'problem' => self::PROBLEM_SOURCE, 'candidates' => null);
            }
            $membership = new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($commerce, EVC_Customer_Plugin::payment_provenance($commerce)), $commerce->mapper());
        }
        if ($candidates === null) {
            if ($commerce === null) {
                return array('sync' => null, 'problem' => self::PROBLEM_SOURCE, 'candidates' => null);
            }
            $candidates = function (int $after, int $limit) use ($commerce): array {
                return self::club_members_after($commerce, $after, $limit);
            };
        }
        return self::wire($config, $membership, $candidates, $clock ?? new EVC_System_Clock());
    }

    private static function wire(EVC_Fluentcrm_Tag_Config $config, EVC_Membership_Adapter $membership, callable $candidates, EVC_Clock_Source $clock): array {
        $sync = new EVC_Fluentcrm_Tag_Sync($config, new EVC_Fluentcrm_Api_Gateway(), $membership, function (int $wp_user_id): ?string {
            $user = get_userdata($wp_user_id);
            return $user instanceof WP_User ? (string) $user->user_email : null;
        }, $clock);
        return array('sync' => $sync, 'problem' => null, 'candidates' => $candidates);
    }

    // ---- lifecycle events: note the customer only; sync at the end of the request --------------------------------

    /** @param int|mixed $order_id */
    public static function note_order($order_id): void {
        try {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
            if ($order instanceof WC_Order && !$order instanceof WC_Order_Refund) {
                self::note_user((int) $order->get_customer_id());
            }
        } catch (Throwable $e) {
            // Never in the way of the order: the daily reconcile catches up.
        }
    }

    /** pmpro_after_change_membership_level($level_id, $user_id): includes cancellation and expiry. */
    public static function note_level_change($level_id, $user_id = 0): void {
        self::note_user((int) $user_id);
    }

    public static function note_user(int $wp_user_id): void {
        if ($wp_user_id > 0 && count(self::$queue) < self::MAX_PER_REQUEST) {
            self::$queue[$wp_user_id] = true; // beyond the cap: left to the daily reconcile
        }
    }

    /** shutdown: sync the noted customers (fire-and-forget; never throws). */
    public static function flush(): void {
        $users = array_keys(self::$queue);
        self::$queue = array();
        if ($users === array() || self::$sync === null) {
            return;
        }
        try {
            if (self::$sync->verify() !== null) {
                self::log_problem(); // held back: no FluentCRM write at all
                return;
            }
            $counts = array();
            foreach ($users as $user) {
                $outcome = self::$sync->sync_user($user);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
            $status = self::status();
            $status['events'] = self::add_counts($status['events'], $counts);
            $status['events_at'] = time();
            update_option(self::STATUS_OPTION, $status, false);
            self::log_problem();
        } catch (Throwable $e) {
            self::log($e);
        }
    }

    // ---- daily reconcile: idempotent, locked, batched, bounded -------------------------------------------------

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK);
        }
    }

    /** The daily run: a fresh retry budget, then the reconcile. */
    public static function reconcile_daily(): int {
        delete_option(self::RETRY_OPTION);
        return self::reconcile();
    }

    /** @return int how many members were synced in this run (counts only). */
    public static function reconcile(): int {
        if (self::$sync === null || self::$candidates === null) {
            return 0;
        }
        if (self::$sync->verify() !== null) {
            self::log_problem(); // held back: no FluentCRM write, the cursor stays where it is
            return 0;
        }
        $lock = self::acquire_lock();
        if ($lock === null) {
            return 0;
        }
        $done = 0;
        $status = self::status();
        try {
            $cursor = max(0, (int) get_option(self::CURSOR_OPTION, 0));
            $finished = false;
            while ($done < self::MAX_PER_RUN) {
                $ids = (self::$candidates)($cursor, min(self::BATCH, self::MAX_PER_RUN - $done));
                $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : array()), function ($id) use ($cursor) {
                    return $id > $cursor;
                }));
                if ($ids === array()) {
                    $finished = true;
                    break;
                }
                sort($ids);
                $counts = array();
                foreach ($ids as $id) {
                    $outcome = self::$sync->sync_user($id);
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                    $cursor = $id;
                    $done++;
                }
                // Round 3: push the lock's end forward after every batch, so a long run is never taken over.
                // Not ours any more (another run took it): stop here, BEFORE writing the cursor (STAGE-07 round 2):
                // that run owns the cursor now; this batch is simply done again (the sync is idempotent).
                $lock = self::refresh_lock($lock);
                if ($lock === null) {
                    break;
                }
                $status['current'] = self::add_counts($status['current'], $counts);
                update_option(self::CURSOR_OPTION, $cursor, false);
            }
            if ($lock === null) {
                // Stopped cleanly (STAGE-07 nit): the lock is another run's now, so this run writes nothing more —
                // no status, no retry budget, no schedule, no release; that run owns the counts from here.
                return $done;
            }
            if ($finished) {
                $status['last'] = $status['current'];
                $status['last_finished_at'] = time();
                $status['current'] = array();
                update_option(self::CURSOR_OPTION, 0, false);
            } elseif (!wp_next_scheduled(self::CONTINUE_HOOK)) {
                wp_schedule_single_event(time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK);
            }
            update_option(self::STATUS_OPTION, $status, false);
            delete_option(self::RETRY_OPTION);
            self::log_problem();
        } catch (Throwable $e) {
            // Round 2: keep what was counted and come back from the saved cursor (round 3: bounded, with backoff).
            update_option(self::STATUS_OPTION, $status, false);
            self::schedule_retry();
            self::log($e);
        } finally {
            if ($lock !== null) {
                self::release_lock($lock);
            }
        }
        return $done;
    }

    /** At most MAX_RETRIES follow-up runs after failures, 5, 10 then 20 minutes later; then the next daily run. */
    private static function schedule_retry(): void {
        $n = max(0, (int) get_option(self::RETRY_OPTION, 0)) + 1;
        update_option(self::RETRY_OPTION, $n, false);
        if ($n > self::MAX_RETRIES || wp_next_scheduled(self::CONTINUE_HOOK)) {
            return;
        }
        wp_schedule_single_event(time() + self::CONTINUE_DELAY * (1 << ($n - 1)), self::CONTINUE_HOOK);
    }

    /** Compare-and-swap: a new end and token for OUR lock; null when it is not ours any more. */
    public static function refresh_lock(string $mine): ?string {
        global $wpdb;
        $next = (time() + self::LOCK_TTL) . '|' . bin2hex(random_bytes(16));
        $wpdb->last_error = '';
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $next, self::LOCK_OPTION, $mine));
        if ($wpdb->last_error !== '') {
            self::forget_cached_lock();
            // STAGE-07 nit: a database error is not a lost lock: the run fails, which schedules ONE bounded follow-up.
            throw new RuntimeException('The reconcile lock could not be refreshed.');
        }
        $ok = (int) $wpdb->rows_affected === 1;
        self::forget_cached_lock();
        return $ok ? $next : null;
    }

    /**
     * The reconcile lock, atomic in the database (round 2). The row holds "<until>|<random token>":
     *  - taken with INSERT IGNORE on the unique option_name: exactly one caller sees rows_affected === 1;
     *  - a stale lock (a crashed run, until <= now) is taken over with a conditional UPDATE … WHERE
     *    option_value = <the stale value read>, so of two callers racing for it only one wins;
     *  - released with DELETE … WHERE option_value = <our own value>: never someone else's lock.
     *
     * @param callable(): void|null $before_takeover TEST seam: runs between reading a stale lock and taking it over
     * @return string|null our lock value (pass it to release_lock()), or null when another run holds the lock
     */
    public static function acquire_lock(?callable $before_takeover = null): ?string {
        global $wpdb;
        $now = time();
        $mine = ($now + self::LOCK_TTL) . '|' . bin2hex(random_bytes(16));
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $mine));
        $won = (int) $wpdb->rows_affected === 1;
        if (!$won) {
            $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION));
            if (!is_string($old) || (int) strstr($old . '|', '|', true) > $now) {
                return null; // held by a live run (or released this instant: the next run will do it)
            }
            if ($before_takeover !== null) {
                $before_takeover();
            }
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $mine, self::LOCK_OPTION, $old));
            $won = (int) $wpdb->rows_affected === 1;
        }
        self::forget_cached_lock();
        return $won ? $mine : null;
    }

    /** Releases the lock only while it is still ours. */
    public static function release_lock(string $mine): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $mine));
        self::forget_cached_lock();
    }

    /** The lock row as stored (null = free), read past the options cache. */
    public static function lock_value(): ?string {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION));
        return is_string($v) ? $v : null;
    }

    private static function forget_cached_lock(): void {
        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * Club members after $after (ascending WordPress user IDs): a PMPro row on the Club level, or a
     * WooCommerce order with the Club product (whatever its status, so an unpaid BACS order counts).
     *
     * @return int[]
     */
    private static function club_members_after(EVC_Commerce_Source_Config $commerce, int $after, int $limit): array {
        global $wpdb;
        $items = $wpdb->prefix . 'woocommerce_order_items';
        $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $customers = 'SELECT o.customer_id AS u FROM ' . \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_orders() . " o INNER JOIN {$items} oi ON oi.order_id = o.id AND oi.order_item_type = 'line_item' INNER JOIN {$meta} im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id' AND im.meta_value = %s";
        } else {
            $customers = "SELECT CAST(pm.meta_value AS UNSIGNED) AS u FROM {$wpdb->postmeta} pm INNER JOIN {$items} oi ON oi.order_id = pm.post_id AND oi.order_item_type = 'line_item' INNER JOIN {$meta} im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id' AND im.meta_value = %s WHERE pm.meta_key = '_customer_user'";
        }
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT x.u FROM (SELECT user_id AS u FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d UNION {$customers}) x WHERE x.u > %d ORDER BY x.u ASC LIMIT %d",
            $commerce->level_id,
            (string) $commerce->product_id,
            $after,
            $limit
        ));
        if (!is_array($ids) || $wpdb->last_error !== '') {
            throw new RuntimeException('Club members could not be listed.');
        }
        return array_map('intval', $ids);
    }

    // ---- status (counts only) ------------------------------------------------------------------------------------

    /** @return array{current:array<string,int>,last:array<string,int>,events:array<string,int>,last_finished_at:int,events_at:int} */
    public static function status(): array {
        $raw = get_option(self::STATUS_OPTION, array());
        $raw = is_array($raw) ? $raw : array();
        $out = array();
        foreach (array('current', 'last', 'events') as $key) {
            $out[$key] = self::add_counts(array(), isset($raw[$key]) && is_array($raw[$key]) ? $raw[$key] : array());
        }
        $out['last_finished_at'] = isset($raw['last_finished_at']) ? (int) $raw['last_finished_at'] : 0;
        $out['events_at'] = isset($raw['events_at']) ? (int) $raw['events_at'] : 0;
        return $out;
    }

    /** Only known outcome codes with non-negative integer counts survive. */
    private static function add_counts(array $base, array $more): array {
        foreach ($more as $outcome => $n) {
            if (in_array($outcome, EVC_Fluentcrm_Tag_Sync::outcomes(), true) && is_int($n) && $n > 0) {
                $base[$outcome] = (isset($base[$outcome]) && is_int($base[$outcome]) ? $base[$outcome] : 0) + $n;
            }
        }
        return $base;
    }

    /** Why nothing is written right now, or null. Verifies the tags (read-only FluentCRM calls). */
    public static function problem(): ?string {
        if (self::$problem !== null) {
            return self::$problem;
        }
        return self::$sync === null ? self::PROBLEM_WIRING : self::$sync->verify();
    }

    // ---- administrator surfaces (counts and codes only) ---------------------------------------------------------

    public static function notice(): void {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $problem = self::problem();
        if ($problem === null) {
            return;
        }
        self::log_problem();
        echo '<div class="notice notice-error"><p>' . esc_html('Evangelou Club: ο συγχρονισμός ετικετών FluentCRM είναι σε αναμονή — δεν γίνεται καμία αλλαγή στο FluentCRM (κωδικός: ' . $problem . ').') . '</p></div>';
    }

    public static function add_menu(): void {
        add_management_page(self::PAGE_TITLE, self::PAGE_TITLE, self::CAPABILITY, self::PAGE_SLUG, array(__CLASS__, 'render'));
    }

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html('Δεν έχετε δικαίωμα για αυτή τη σελίδα.'), '', array('response' => 403));
        }
        $problem = self::problem();
        $status = self::status();
        echo '<div class="wrap"><h1>' . esc_html(self::PAGE_TITLE) . '</h1>';
        echo '<p>' . esc_html($problem === null ? 'Κατάσταση: ενεργός συγχρονισμός ετικετών.' : 'Κατάσταση: σε αναμονή (κωδικός: ' . $problem . '). Δεν γίνεται καμία αλλαγή.') . '</p>';
        $sections = array(
            'last' => 'Τελευταίος πλήρης έλεγχος' . ($status['last_finished_at'] > 0 ? ' (' . gmdate('Y-m-d H:i', $status['last_finished_at']) . ' UTC)' : ''),
            'current' => 'Έλεγχος σε εξέλιξη',
            'events' => 'Από συμβάντα παραγγελιών / συνδρομών (σύνολο)',
        );
        foreach ($sections as $key => $title) {
            echo '<h2>' . esc_html($title) . '</h2><table class="widefat striped"><tbody>';
            foreach (EVC_Fluentcrm_Tag_Sync::outcomes() as $outcome) {
                echo '<tr><td>' . esc_html($outcome) . '</td><td>' . esc_html((string) ($status[$key][$outcome] ?? 0)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p>' . esc_html('«no_contact»: μέλη χωρίς επαφή στο FluentCRM. Δεν δημιουργούνται επαφές αυτόματα.') . '</p></div>';
    }

    // ---- logging: class names only, at most once an hour --------------------------------------------------------

    private static function log_problem(): void {
        if (self::$sync !== null && self::$sync->verify() !== null) {
            self::log_once('EVC_Fluentcrm_Tag_Sync');
        }
    }

    private static function log(Throwable $e): void {
        self::log_once(get_class($e));
    }

    private static function log_once(string $class): void {
        if (get_transient(self::LOG_TRANSIENT) !== false) {
            return;
        }
        set_transient(self::LOG_TRANSIENT, 1, HOUR_IN_SECONDS);
        error_log('Evangelou Club: FluentCRM tag sync held back (' . $class . ').');
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CONTINUE_HOOK);
        delete_option(self::RETRY_OPTION);
    }
}
