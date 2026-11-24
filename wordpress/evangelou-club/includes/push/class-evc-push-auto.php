<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the two AUTOMATIC member notifications, each behind its own constant
 * (both default OFF, both also need EVC_CLUB_PUSH_ENABLED; EVC_Push_Feature). Constructed only by EVC_Push_Plugin::wire().
 *
 *   EVC_CLUB_PUSH_AUTO_ACTIVATED  «Η συνδρομή σας ενεργοποιήθηκε»: when the shop marks an order «Completed», a WP-Cron
 *                                 event for that customer is queued (the shop's request is never slowed by sending);
 *                                 the event sends only if the membership is ACTIVE then, once per paid period (user
 *                                 meta ACTIVATED_META = the period's end).
 *   EVC_CLUB_PUSH_AUTO_EXPIRY     «Η συνδρομή σας λήγει σε 3 ημέρες»: once a day at 10:00 Athens (a single WP-Cron event,
 *                                 scheduled again for the next day's 10:00, so the hour follows summer time), to every
 *                                 subscribed member whose ACTIVE period ends on the Athens day three days ahead; once
 *                                 per member per period (user meta EXPIRY_META = the period's end, written before
 *                                 sending: at most once, never twice). Members are read in batches under a time budget;
 *                                 a long list continues one minute later from where it stopped (option RUN_OPTION).
 * Both use the same gated backend, sender and 404 / 410 revocation as the administrator's page; each run leaves one
 * audit entry with counts only.
 *
 * Round 13 (N-b): both run ONLY while holding their own named lock (EVC_Push_Campaign::auto_lock_name()), so two WP-Cron
 * runners at once (WP-Cron can start the same event twice under load) can never both read «not sent yet» for a member
 * and both send: the second one answers 'busy' (and comes back a minute later if nothing else is queued). Under the
 * lock the «once per period» markers and the run state are read straight from the database, never from a cache.
 * GET_LOCK answering NULL or failing = nothing is sent ('no_lock', fail closed).
 *
 * Round 14:
 *   - No lock for «ενεργοποιήθηκε»: the event comes back NO_LOCK_RETRY_SECONDS (5 minutes) later, at most
 *     NO_LOCK_TRIES times (the try number travels as the event's second argument); after the last try nothing is sent
 *     for that order (fail closed, never twice). The member still sees the active card.
 *   - No lock for «λήγει σε 3 ημέρες»: that day's run is skipped and the next run is the next day's 10:00 (as before).
 *     Members whose period ends on the skipped day's target day get no 3-day notice that period: the next day's run looks
 *     three days ahead of ITS day. Accepted (fail closed: a database without named locks sends nothing at all, and the
 *     readiness / admin pages say why); documented in docs/member-push.md.
 *   - Before sending to a member each run checks that its database connection still holds the lock (a silent reconnect
 *     drops it): if not, it stops at once ('lost_lock') and sends nothing more; the markers keep it «at most once».
 *   - Audit entries go to their own store (EVC_Push_Campaign::AUTO_AUDIT_OPTION, written only under this lock), so an
 *     automatic entry and an administrator's entry written at the same moment never overwrite each other.
 *
 * Round 15 (review of round 14):
 *   - «ενεργοποιήθηκε» refused as BUSY keeps its try number: the retry event is array(user, attempt) (it was array(user),
 *     which restarted the count). A LOST_LOCK (the connection lost the lock before the marker was written, so nothing was
 *     sent) is retried exactly like a missing lock: NO_LOCK_RETRY_SECONDS later with attempt + 1, at most NO_LOCK_TRIES
 *     tries in all; it is no longer dropped. The «once per period» marker keeps every retry «at most once».
 *   - The expiry run state (RUN_OPTION) is written only by save_run(): this connection must still hold the automatic lock
 *     (checked right before the write) and the write is a compare-and-replace over the value this run read or last
 *     wrote (EVC_Push_Campaign::replace_option()), so a runner that lost the lock never overwrites the position or the
 *     counts another runner saved. A refused write stops the run ('lost_lock', retried a minute later).
 */
final class EVC_Push_Auto {
    const ACTIVATED_HOOK = 'evc_club_push_activated';
    const EXPIRY_HOOK = 'evc_club_push_expiry';
    const ACTIVATED_META = 'evc_club_push_activated_for';
    const EXPIRY_META = 'evc_club_push_expiry_for';
    const RUN_OPTION = 'evc_club_push_expiry_run';
    const ORDER_PRIORITY = 50;
    const EXPIRY_HOUR = 10;
    const DAYS_AHEAD = 3;
    const BATCH = 100;
    const TIME_BUDGET = 20;
    const ZONE = 'Europe/Athens';
    /** Round 14: «ενεργοποιήθηκε» without a named lock comes back 5 minutes later, at most this many times. */
    const NO_LOCK_TRIES = 6;
    const NO_LOCK_RETRY_SECONDS = 300;
    const ACTIVATED_TITLE = 'Η συνδρομή σας ενεργοποιήθηκε';
    const ACTIVATED_BODY = 'Η κάρτα μέλους σας είναι έτοιμη: ο καφές της ημέρας σας περιμένει στο κατάστημα.';
    const EXPIRY_TITLE = 'Η συνδρομή σας λήγει σε 3 ημέρες';
    const EXPIRY_BODY = 'Ανανεώστε την από την κάρτα μέλους σας, για να συνεχίσετε να παίρνετε τον καφέ της ημέρας.';

    /** @var EVC_Push_Backend */
    private $backend;
    /** @var EVC_Push_Sender */
    private $sender;
    /** @var callable(int, DateTimeImmutable): array */
    private $membership;
    /** @var callable(): float */
    private $timer;

    public function __construct(EVC_Push_Backend $backend, EVC_Push_Sender $sender, ?callable $membership = null, ?callable $timer = null) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->backend = $backend;
        $this->sender = $sender;
        $this->membership = $membership ?? array('EVC_Push_Campaign', 'membership_of');
        $this->timer = $timer ?? function (): float {
            return microtime(true);
        };
    }

    /** Hooks only what is switched on; a switched-off one also loses an event left from before. */
    public function register(): void {
        if (EVC_Push_Feature::auto_activated()) {
            add_action('woocommerce_order_status_completed', array($this, 'on_completed'), self::ORDER_PRIORITY, 1);
            add_action(self::ACTIVATED_HOOK, array($this, 'activated'), 10, 2);
        }
        if (EVC_Push_Feature::auto_expiry()) {
            add_action(self::EXPIRY_HOOK, array($this, 'expiry'), 10, 0);
            add_action('init', array($this, 'schedule_expiry'), 30);
        } elseif (wp_next_scheduled(self::EXPIRY_HOOK)) {
            wp_clear_scheduled_hook(self::EXPIRY_HOOK);
        }
    }

    /** The next 10:00 in Athens strictly after $now (a Unix time). */
    public static function next_run(int $now): int {
        $zone = new DateTimeZone(self::ZONE);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
        $target = $local->setTime(self::EXPIRY_HOUR, 0, 0);
        if ($target->getTimestamp() <= $now) {
            $target = $local->modify('+1 day')->setTime(self::EXPIRY_HOUR, 0, 0);
        }
        return $target->getTimestamp();
    }

    public function schedule_expiry(): void {
        if (!wp_next_scheduled(self::EXPIRY_HOOK)) {
            wp_schedule_single_event(self::next_run(time()), self::EXPIRY_HOOK);
        }
    }

    /** woocommerce_order_status_completed: queue the check for that customer (nothing is sent in the shop's request). */
    public function on_completed($order_id): void {
        try {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
            $user = $order ? (int) $order->get_customer_id() : 0;
            if ($user > 0 && !wp_next_scheduled(self::ACTIVATED_HOOK, array($user))) {
                wp_schedule_single_event(time(), self::ACTIVATED_HOOK, array($user));
            }
        } catch (Throwable $e) {
            // never disturbs the order
        }
    }

    /**
     * WP-Cron: «Η συνδρομή σας ενεργοποιήθηκε», only for an active membership, once per paid period.
     * @param int|string $attempt round 14: the how-many-th try after a missing named lock (0 = the first event)
     * @return string what happened
     */
    public function activated($wp_user_id, $attempt = 0): string {
        $wp_user_id = (int) $wp_user_id;
        $attempt = max(0, (int) $attempt);
        $lock = EVC_Push_Campaign::acquire_lock(EVC_Push_Campaign::auto_lock_name());
        if ($lock === null) {
            $this->retry_activated($wp_user_id, $attempt);
            return 'no_lock';
        }
        if ($lock !== true) {
            // Round 15 (L3): the try number travels on (a busy lock is not a new first try).
            if ($wp_user_id > 0 && !wp_next_scheduled(self::ACTIVATED_HOOK, array($wp_user_id, $attempt))) {
                wp_schedule_single_event(time() + 60, self::ACTIVATED_HOOK, array($wp_user_id, $attempt));
            }
            return 'busy';
        }
        try {
            $result = $this->activated_locked($wp_user_id);
        } finally {
            EVC_Push_Campaign::release_lock(EVC_Push_Campaign::auto_lock_name());
        }
        if ($result === 'lost_lock') {
            // Round 15 (L3): nothing was sent (the marker comes after the check): retried like a missing lock.
            $this->retry_activated($wp_user_id, $attempt);
        }
        return $result;
    }

    /** Round 14 / 15: never lose the notification to a passing refusal: try again later, a bounded number of times. */
    private function retry_activated(int $wp_user_id, int $attempt): void {
        $next = array($wp_user_id, $attempt + 1);
        if ($wp_user_id > 0 && $attempt + 1 < self::NO_LOCK_TRIES && !wp_next_scheduled(self::ACTIVATED_HOOK, $next)) {
            wp_schedule_single_event(time() + self::NO_LOCK_RETRY_SECONDS, self::ACTIVATED_HOOK, $next);
        }
    }

    /** activated() under the automatic notifications' lock. */
    private function activated_locked(int $wp_user_id): string {
        $now = EVC_Clock::to_utc($this->backend->clock()->now());
        $m = $this->read($wp_user_id, $now);
        if ($m === null || $m['state'] !== EVC_Customer_Membership::ACTIVE || !is_string($m['paid_until_utc'])) {
            return 'not_active';
        }
        if (self::fresh_marker($wp_user_id, self::ACTIVATED_META) === $m['paid_until_utc']) {
            return 'already';
        }
        $sending = $this->backend->sending();
        if ($sending === null) {
            return 'unavailable';
        }
        if (!EVC_Push_Campaign::holds_lock(EVC_Push_Campaign::auto_lock_name())) {
            return 'lost_lock';
        }
        update_user_meta($wp_user_id, self::ACTIVATED_META, $m['paid_until_utc']);
        $counts = $this->send_member($sending, $wp_user_id, EVC_Push_Message::payload(self::ACTIVATED_TITLE, self::ACTIVATED_BODY));
        $this->audit('auto_activated', $counts);
        return 'sent';
    }

    /** WP-Cron, 10:00 Athens: «Η συνδρομή σας λήγει σε 3 ημέρες». @return string 'done' | 'continued' | 'unavailable' | 'busy' | 'no_lock' | 'lost_lock' */
    public function expiry(): string {
        $lock = EVC_Push_Campaign::acquire_lock(EVC_Push_Campaign::auto_lock_name());
        if ($lock === null) {
            $this->schedule_next();
            return 'no_lock';
        }
        if ($lock !== true) {
            if (!wp_next_scheduled(self::EXPIRY_HOOK)) {
                wp_schedule_single_event(time() + 60, self::EXPIRY_HOOK);
            }
            return 'busy';
        }
        try {
            return $this->expiry_locked();
        } finally {
            EVC_Push_Campaign::release_lock(EVC_Push_Campaign::auto_lock_name());
        }
    }

    /** expiry() under the automatic notifications' lock. */
    private function expiry_locked(): string {
        $now = EVC_Clock::to_utc($this->backend->clock()->now());
        $today = $now->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
        $target = $now->setTimezone(new DateTimeZone(self::ZONE))->modify('+' . self::DAYS_AHEAD . ' days')->format('Y-m-d');
        $raw = EVC_Push_Campaign::fresh_raw(self::RUN_OPTION);
        $run = $raw === null ? null : maybe_unserialize($raw);
        $run = is_array($run) && ($run['date'] ?? '') === $today ? $run : array('date' => $today, 'after' => 0, 'done' => false, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'members' => 0);
        if (!empty($run['done'])) {
            $this->schedule_next();
            return 'done';
        }
        $sending = $this->backend->sending();
        if ($sending === null) {
            $this->schedule_next();
            return 'unavailable';
        }
        $payload = EVC_Push_Message::payload(self::EXPIRY_TITLE, self::EXPIRY_BODY);
        $started = ($this->timer)();
        $finished = false;
        while (!$finished) {
            $members = $sending['store']->subscribed_members((int) $run['after'], self::BATCH);
            if ($members === array()) {
                $finished = true;
                break;
            }
            foreach ($members as $member_id => $wp_user_id) {
                if (($this->timer)() - $started >= self::TIME_BUDGET) {
                    break 2;
                }
                if (!EVC_Push_Campaign::holds_lock(EVC_Push_Campaign::auto_lock_name())) {
                    // Round 14: a reconnect dropped the lock: stop now; the next run continues from the saved position.
                    return $this->expiry_lost();
                }
                $run['after'] = $member_id;
                $m = $this->read($wp_user_id, $now);
                if ($m !== null && $m['state'] === EVC_Customer_Membership::ACTIVE && is_string($m['paid_until_utc'])
                    && self::athens_day($m['paid_until_utc']) === $target
                    && self::fresh_marker($wp_user_id, self::EXPIRY_META) !== $m['paid_until_utc']) {
                    update_user_meta($wp_user_id, self::EXPIRY_META, $m['paid_until_utc']);
                    $counts = $this->send_member($sending, $wp_user_id, $payload);
                    $run['members']++;
                    foreach (array('sent', 'failed', 'removed') as $k) {
                        $run[$k] += $counts[$k];
                    }
                }
                // Round 15: written back only by the lock holder, over the value this run last saw.
                if (!$this->save_run($run, $raw)) {
                    return $this->expiry_lost();
                }
            }
            if (count($members) < self::BATCH) {
                $finished = true;
            }
        }
        if (!$finished) {
            if (!$this->save_run($run, $raw)) {
                return $this->expiry_lost();
            }
            wp_schedule_single_event(time() + 60, self::EXPIRY_HOOK);
            return 'continued';
        }
        $run['done'] = true;
        if (!$this->save_run($run, $raw)) {
            return $this->expiry_lost();
        }
        $this->audit('auto_expiry', $run);
        $this->schedule_next();
        return 'done';
    }

    /**
     * Round 15: the run state written only while this connection holds the automatic lock, and only over the value this
     * run read or last wrote ($raw, updated on success). @return bool false = nothing written
     */
    private function save_run(array $run, ?string &$raw): bool {
        if (!EVC_Push_Campaign::holds_lock(EVC_Push_Campaign::auto_lock_name())) {
            return false;
        }
        $new = maybe_serialize($run);
        if (!EVC_Push_Campaign::replace_option(self::RUN_OPTION, $raw, $new)) {
            return false;
        }
        $raw = $new;
        return true;
    }

    /** The run stopped without the lock (or its write was refused): it comes back a minute later from the saved position. */
    private function expiry_lost(): string {
        if (!wp_next_scheduled(self::EXPIRY_HOOK)) {
            wp_schedule_single_event(time() + 60, self::EXPIRY_HOOK);
        }
        return 'lost_lock';
    }

    private function schedule_next(): void {
        wp_clear_scheduled_hook(self::EXPIRY_HOOK);
        wp_schedule_single_event(self::next_run(time()), self::EXPIRY_HOOK);
    }

    /** A «sent for this period» marker as the database holds it now (another runner may have written it). */
    private static function fresh_marker(int $wp_user_id, string $key): string {
        wp_cache_delete($wp_user_id, 'user_meta');
        return (string) get_user_meta($wp_user_id, $key, true);
    }

    public static function athens_day(string $instant): string {
        $at = strtotime($instant);
        return $at === false ? '' : (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
    }

    /** @return array{state:string,paid_until_utc:?string}|null null = could not be read (nothing is sent then) */
    private function read(int $wp_user_id, DateTimeImmutable $now): ?array {
        if ($wp_user_id <= 0) {
            return null;
        }
        try {
            $m = ($this->membership)($wp_user_id, $now);
        } catch (Throwable $e) {
            return null;
        }
        return is_array($m) && isset($m['state']) ? array('state' => (string) $m['state'], 'paid_until_utc' => $m['paid_until_utc'] ?? null) : null;
    }

    /** Every active subscription of that member (an enabled member only). @return array{sent:int,failed:int,removed:int} */
    private function send_member(array $sending, int $wp_user_id, string $payload): array {
        $counts = array('sent' => 0, 'failed' => 0, 'removed' => 0);
        try {
            $member = $sending['store']->member_for_wp_user($wp_user_id);
            if ($member === null || $member['status'] !== 'enabled') {
                return $counts;
            }
            foreach ($sending['store']->batch(0, 20, $member['id']) as $row) {
                $outcome = $this->sender->send($row, $payload, $sending['keys']);
                $at = $this->backend->now_utc();
                if ($outcome === EVC_Push_Sender::SENT) {
                    $sending['store']->record_success($row['id'], $at);
                    $counts['sent']++;
                } elseif ($outcome === EVC_Push_Sender::GONE) {
                    $sending['store']->revoke_gone($row['id'], $at);
                    $counts['removed']++;
                } else {
                    $sending['store']->record_failure($row['id'], $at);
                    $counts['failed']++;
                }
            }
        } catch (Throwable $e) {
            $counts['failed']++;
        }
        return $counts;
    }

    private function audit(string $event, array $counts): void {
        EVC_Push_Campaign::append_audit(array(
            'event' => $event,
            'at' => time(),
            'job' => '',
            'user_id' => 0,
            'user_login' => '',
            'audience' => $event === 'auto_expiry' ? 'expiring' : 'active',
            'days' => $event === 'auto_expiry' ? self::DAYS_AHEAD : 0,
            'planned' => (int) ($counts['members'] ?? 1),
            'sent' => (int) $counts['sent'],
            'failed' => (int) $counts['failed'],
            'removed' => (int) $counts['removed'],
            'skipped' => 0,
        ), EVC_Push_Campaign::AUTO_AUDIT_OPTION);
    }
}
