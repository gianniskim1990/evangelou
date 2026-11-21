<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): one notification sent by an administrator to an audience of members, in
 * batches, so a long list never times out. Constructed only by EVC_Push_Plugin::wire() (packager-enforced).
 *
 * Audiences (each member counted by the Club's own membership reading, EVC_Customer_Membership over the configured
 * membership adapter, at the moment each batch runs):
 *   all        every active subscription of an enabled member
 *   active     members whose membership is active now
 *   expiring   active members whose paid period ends within the next N days (1..30)
 *
 * Flow: preview() counts the recipients (nothing is sent) -> start() after the administrator's confirmation creates the
 * ONE job (option JOB_OPTION; refused while another runs) -> step() sends one batch: at most BATCH_SIZE subscriptions
 * and at most TIME_BUDGET seconds, under a database lock so the page and WP-Cron never send the same batch twice; the
 * cursor (last subscription id) is saved before every message (round 15; it was after). step() is run by the
 * administrator's open page (one POST per batch) and by WP-Cron (CRON_HOOK, rescheduled while the job runs), whichever
 * comes first.
 * 404 / 410 from a push service revokes the subscription ('gone'); other failures only count.
 *
 * Locks (round 13): a job is only ever changed by the holder of the WordPress-database named lock (lock_name()).
 *   - GET_LOCK answering NULL or failing = no lock = NOTHING is sent (NO_LOCK, fail closed; never unlocked).
 *   - GET_LOCK is re-entrant on one MySQL connection; this class is not: a request that already holds a lock is refused
 *     it again (so a nested call behaves like a second runner).
 * «Διακοπή αποστολής» (cancel(), round 13 M1): the request is first written as its own row (CANCEL_OPTION = the job id,
 *   one INSERT … ON DUPLICATE KEY UPDATE, never through the job option that the running batch rewrites). If the lock is
 *   free, cancel() ends the job itself, under the lock. If a batch holds it, cancel() answers CANCEL_PENDING and that
 *   batch reads the request again straight from the database (not the object cache) before EVERY message and ends the
 *   job as 'cancelled'. So 'cancelled' is only ever written under the lock, after which no batch runs: the audit never
 *   shows 'cancelled' followed by 'done'.
 *
 * Round 14:
 *   - A running job always has its next WP-Cron event queued. step() queues it AFTER releasing the lock (keep_alive(),
 *     also when a batch throws), and cron() queues a retry RETRY_SECONDS later when the lock is busy or missing (the
 *     event that WP-Cron just took off the queue is gone; the page that holds the lock may have looked before it was).
 *   - Before every message, and before the job is written at the end of a batch, the batch checks that its own MySQL
 *     connection still holds the lock (IS_USED_LOCK = CONNECTION_ID; wpdb reconnects silently after "server has gone
 *     away", and a reconnect drops the lock). If not, it stops at once (LOST_LOCK): nothing more is sent or written; the
 *     cursor saved after the last message lets the next lock holder continue without sending anything twice.
 *   - cancel() arriving just as the job finishes answers DONE_BEFORE_CANCEL and clears its stop request.
 *
 * Round 15 (review of round 14):
 *   - M1: a runner that lost the lock can never bring a finished job back. EVERY write of the job goes through
 *     save_running_job(): (1) this request's connection must still hold the lock (holds_lock(), checked right before the
 *     write, also after each message and inside finish()), and (2) the write is conditional: the job is read again
 *     straight from the database and replaced in ONE statement (UPDATE … WHERE option_value = the value just read) only
 *     if it is still the same job id AND still 'running'. The cursor is moved past a row and saved BEFORE that row's
 *     message goes, so each row is sent at most once (a runner that loses the lock mid-message loses at most that row's
 *     counts, never sends a row twice). A refused write ends the batch at once (LOST_LOCK, or NONE when the job ended).
 *   - L1: a database that answers GET_LOCK with NULL forever cannot keep a job «running» for ever: each WP-Cron batch that
 *     got no named lock counts (NO_LOCK_OPTION, '<job id>:<count>'); at NO_LOCK_TRIES the job is written 'stopped' with
 *     reason 'no_lock' through the same conditional write without the lock (it can only end that same running job),
 *     the text is cleared and an audit entry is written to STOP_AUDIT_OPTION (written only by compare-and-replace).
 *     cancel() without any named lock ends the job the same way. The Club pages show a notice while a job is stuck or
 *     was stopped for this reason (EVC_Push_Admin_Page::club_notice()).
 *   - L2: LOST_LOCK is handled like BUSY: the next WP-Cron batch comes RETRY_SECONDS later, not at once.
 *
 * Rate limit: at most MAX_PER_DAY started sends in any 24 hours and at least MIN_INTERVAL seconds between two, counted
 * from their own small store (STARTS_OPTION, the newest STARTS_KEEP start times, written under the lock), not from the
 * audit (automatic notifications add audit entries and could push the starts out of its AUDIT_KEEP entries). Round 14:
 * while STARTS_OPTION does not exist yet (a site upgraded from round 12), it is seeded from the 'started' entries of the
 * administrators' audit, so the daily cap survives the upgrade; the first start() stores it.
 * Audit: who (user id and login), when, audience (+ days), the planned count and the result counts. NEVER an endpoint, a
 * key, a member, a name, an e-mail address or the message text. Round 14: two stores, so that two writers can never
 * overwrite each other's entry (the campaign holds its lock, the automatic notifications hold theirs, and the two run at
 * the same time): AUDIT_OPTION is written only under the campaign lock, AUTO_AUDIT_OPTION only under the automatic
 * notifications' lock, each re-read straight from the database (fresh_option) right before it is written. audit() merges
 * the two, newest first. Round 15: a third store, STOP_AUDIT_OPTION, holds the lock-free stops (compare-and-replace only).
 */
final class EVC_Push_Campaign {
    const JOB_OPTION = 'evc_club_push_job';
    const AUDIT_OPTION = 'evc_club_push_audit';
    const AUDIT_KEEP = 100;
    /** Round 14: the automatic notifications' own audit store (written only under auto_lock_name()). */
    const AUTO_AUDIT_OPTION = 'evc_club_push_auto_audit';
    /** Round 13: the pending «Διακοπή αποστολής» request: '<job id>:<user id>'. */
    const CANCEL_OPTION = 'evc_club_push_cancel';
    /** Round 13 (N-c): Unix times of the newest started sends, newest first. */
    const STARTS_OPTION = 'evc_club_push_starts';
    const STARTS_KEEP = 10;
    const CRON_HOOK = 'evc_club_push_batch';
    const BATCH_SIZE = 25;
    const TIME_BUDGET = 15;
    const MIN_INTERVAL = 900;
    const MAX_PER_DAY = 4;
    const MAX_DAYS = 30;
    /** A running job that has not moved for this long is stopped (e.g. the site was restored meanwhile). */
    const STALE_SECONDS = 3600;
    const AUDIENCES = array('all', 'active', 'expiring');
    const LOCK_TIMEOUT = 0;
    /** Round 14: a WP-Cron batch that found the lock busy (or missing) comes back this many seconds later. */
    const RETRY_SECONDS = 60;
    /** Round 15 (L1): consecutive WP-Cron batches without a named lock for the running job: '<job id>:<count>'. */
    const NO_LOCK_OPTION = 'evc_club_push_no_lock';
    /** Round 15 (L1): after this many WP-Cron batches in a row without a named lock the job is stopped (about 10 minutes). */
    const NO_LOCK_TRIES = 10;
    /** Round 15 (L1): audit entries of the lock-free stops (written only by compare-and-replace, never under a lock). */
    const STOP_AUDIT_OPTION = 'evc_club_push_stop_audit';
    /** Round 15 (L1): the reason recorded on a job stopped because the database gave no named lock. */
    const REASON_NO_LOCK = 'no_lock';

    const OK = 'ok';
    const BUSY = 'busy';
    const RATE_LIMITED = 'rate_limited';
    const INVALID = 'invalid';
    const UNAVAILABLE = 'unavailable';
    const NONE = 'none';
    /** Round 13: the cancel request is recorded; the batch in flight stops before its next message. */
    const CANCEL_PENDING = 'cancel_pending';
    /** Round 13 (N-a): the database gave no named lock (NULL or an error): nothing is sent. */
    const NO_LOCK = 'no_lock';
    const CANCELLED = 'cancelled';
    /** Round 14: the stop arrived just after the job had ended by itself ('done' or 'stopped'). */
    const DONE_BEFORE_CANCEL = 'done_before_cancel';
    /** Round 14: the batch's database connection no longer holds the lock (a reconnect): it stopped, nothing more sent. */
    const LOST_LOCK = 'lost_lock';

    /** @var array<string,bool> named locks this request holds */
    private static $held = array();

    /** @var EVC_Push_Backend */
    private $backend;
    /** @var EVC_Push_Sender */
    private $sender;
    /** @var callable(int, DateTimeImmutable): array{state:string,paid_until_utc:?string} */
    private $membership;
    /** @var callable(): float */
    private $timer;

    /**
     * @param callable|null $membership TEST seam: (wp_user_id, now) -> EVC_Customer_Membership::describe() shape
     * @param callable|null $timer      TEST seam: seconds as float (default microtime(true))
     */
    public function __construct(EVC_Push_Backend $backend, EVC_Push_Sender $sender, ?callable $membership = null, ?callable $timer = null) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->backend = $backend;
        $this->sender = $sender;
        $this->membership = $membership ?? array(__CLASS__, 'membership_of');
        $this->timer = $timer ?? function (): float {
            return microtime(true);
        };
    }

    /** The shipped membership reading: the same adapter and the same states as the member page. */
    public static function membership_of(int $wp_user_id, DateTimeImmutable $now): array {
        return EVC_Customer_Membership::describe(EVC_Customer_Plugin::membership_adapter()->entitlement_for($wp_user_id, $now), $now);
    }

    public static function valid_audience($audience, $days): bool {
        if (!in_array($audience, self::AUDIENCES, true)) {
            return false;
        }
        return $audience !== 'expiring' || (is_int($days) && $days >= 1 && $days <= self::MAX_DAYS);
    }

    /** Does this member belong to the audience now? A membership that cannot be read never does (fail closed). */
    public function in_audience(int $wp_user_id, string $audience, int $days, DateTimeImmutable $now): bool {
        if ($audience === 'all') {
            return true;
        }
        try {
            $m = ($this->membership)($wp_user_id, $now);
        } catch (Throwable $e) {
            return false;
        }
        if (!is_array($m) || ($m['state'] ?? null) !== EVC_Customer_Membership::ACTIVE) {
            return false;
        }
        if ($audience === 'active') {
            return true;
        }
        $until = isset($m['paid_until_utc']) && is_string($m['paid_until_utc']) ? strtotime($m['paid_until_utc']) : false;
        return $until !== false && $until > $now->getTimestamp() && $until <= $now->getTimestamp() + $days * 86400;
    }

    /**
     * The recipients now, before anything is sent.
     * @return array{result:string,members?:int,subscriptions?:int}
     */
    public function preview(string $audience, int $days): array {
        if (!self::valid_audience($audience, $audience === 'expiring' ? $days : 0)) {
            return array('result' => self::INVALID);
        }
        $sending = $this->backend->sending();
        if ($sending === null) {
            return array('result' => self::UNAVAILABLE);
        }
        $now = EVC_Clock::to_utc($this->backend->clock()->now());
        $members = array();
        $subscriptions = 0;
        $after = 0;
        do {
            $rows = $sending['store']->batch($after, 500);
            foreach ($rows as $row) {
                $after = $row['id'];
                if ($row['member_status'] !== 'enabled') {
                    continue;
                }
                if (!isset($members[$row['member_id']])) {
                    $members[$row['member_id']] = $this->in_audience($row['wp_user_id'], $audience, $days, $now);
                }
                if ($members[$row['member_id']]) {
                    $subscriptions++;
                }
            }
        } while (count($rows) === 500);
        return array('result' => self::OK, 'members' => count(array_filter($members)), 'subscriptions' => $subscriptions);
    }

    /** @return array<string,mixed>|null the current (or last) job, as stored */
    public static function job(): ?array {
        $job = get_option(self::JOB_OPTION, null);
        return is_array($job) ? $job : null;
    }

    /** The job as the database holds it now (another request may have changed it since this one cached it). */
    public static function fresh_job(): ?array {
        $job = self::fresh_option(self::JOB_OPTION);
        return is_array($job) ? $job : null;
    }

    /**
     * An option read straight from the database, never from the object cache (a persistent cache or this request's
     * own cache can hold a value another request has changed). null = no such option.
     */
    public static function fresh_option(string $name) {
        global $wpdb;
        wp_cache_delete($name, 'options');
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        return $raw === null ? null : maybe_unserialize($raw);
    }

    /** Is a «Διακοπή αποστολής» request recorded for this (running) job? For the administrator's page. */
    public static function cancel_pending_for(array $job): bool {
        return self::cancel_request_for($job) !== null;
    }

    /** @return int|null the user id of the recorded cancel request for this job (0 if unknown), null = none */
    private static function cancel_request_for(array $job): ?int {
        $flag = self::fresh_option(self::CANCEL_OPTION);
        if (!is_string($flag) || preg_match('/^([0-9a-f]{16}):([0-9]{1,20})$/D', $flag, $m) !== 1 || $m[1] !== (string) ($job['id'] ?? '')) {
            return null;
        }
        return (int) $m[2];
    }

    /** Records the cancel request in ONE statement (atomic upsert), outside the job option the batch rewrites. */
    private static function record_cancel_request(string $job_id, int $user_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            self::CANCEL_OPTION,
            $job_id . ':' . $user_id
        ));
        wp_cache_delete(self::CANCEL_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * @return array<int,array<string,mixed>> newest first: the stores merged (each keeps its own order; on equal times the
     *         lock-free stops first (a stop always ends a job started before it), then the administrators' store, then the
     *         automatic one), AUDIT_KEEP kept
     */
    public static function audit(): array {
        $stores = array(self::audit_store(self::STOP_AUDIT_OPTION), self::audit_store(self::AUDIT_OPTION), self::audit_store(self::AUTO_AUDIT_OPTION));
        $merged = array();
        while (count($merged) < self::AUDIT_KEEP) {
            $pick = null;
            foreach ($stores as $i => $store) {
                if ($store !== array() && ($pick === null || (int) ($store[0]['at'] ?? 0) > (int) ($stores[$pick][0]['at'] ?? 0))) {
                    $pick = $i;
                }
            }
            if ($pick === null) {
                break;
            }
            $merged[] = array_shift($stores[$pick]);
        }
        return $merged;
    }

    /** One audit store as the database holds it now (newest first). */
    private static function audit_store(string $store): array {
        $audit = self::fresh_option($store);
        return is_array($audit) ? array_values(array_filter($audit, 'is_array')) : array();
    }

    /** Unix times of the newest started sends (newest first), from their own store (never the audit). */
    public static function starts(): array {
        $stored = self::fresh_option(self::STARTS_OPTION);
        if ($stored === null) {
            // Round 14: no store yet (upgraded from round 12): the start times the administrators' audit still holds.
            $stored = array();
            foreach (self::audit_store(self::AUDIT_OPTION) as $entry) {
                if (($entry['event'] ?? '') === 'started' && isset($entry['at'])) {
                    $stored[] = $entry['at'];
                }
            }
        }
        $starts = array();
        foreach (is_array($stored) ? $stored : array() as $at) {
            if (is_int($at) || (is_string($at) && ctype_digit($at))) {
                $starts[] = (int) $at;
            }
        }
        rsort($starts);
        return array_slice($starts, 0, self::STARTS_KEEP);
    }

    /** Seconds until another send may start (0 = now), from the store of started sends. */
    public static function wait_seconds(int $now): int {
        $starts = self::starts();
        $wait = 0;
        if ($starts !== array() && $now - $starts[0] < self::MIN_INTERVAL) {
            $wait = self::MIN_INTERVAL - ($now - $starts[0]);
        }
        $recent = array_values(array_filter($starts, function ($at) use ($now) {
            return $now - $at < 86400;
        }));
        if (count($recent) >= self::MAX_PER_DAY) {
            $wait = max($wait, 86400 - ($now - $recent[self::MAX_PER_DAY - 1]));
        }
        return max(0, $wait);
    }

    /**
     * The administrator confirmed: create the job. The caller checked the capability and the nonce.
     * @return array{result:string,wait?:int}
     */
    public function start(WP_User $user, $title, $body, string $audience, int $days, int $planned): array {
        if (EVC_Push_Message::problem($title, $body) !== null || !self::valid_audience($audience, $audience === 'expiring' ? $days : 0)) {
            return array('result' => self::INVALID);
        }
        $lock = self::acquire_lock(self::lock_name());
        if ($lock === null) {
            return array('result' => self::NO_LOCK);
        }
        if ($lock !== true) {
            return array('result' => self::BUSY);
        }
        try {
            $now = time();
            $job = self::fresh_job();
            if ($job !== null && ($job['state'] ?? '') === 'running') {
                if ($now - (int) ($job['touched'] ?? 0) < self::STALE_SECONDS) {
                    return array('result' => self::BUSY);
                }
                $this->finish($job, 'stopped');
            }
            $wait = self::wait_seconds($now);
            if ($wait > 0) {
                return array('result' => self::RATE_LIMITED, 'wait' => $wait);
            }
            $job = array(
                'id' => bin2hex(random_bytes(8)),
                'state' => 'running',
                'title' => EVC_Push_Message::normalise($title),
                'body' => EVC_Push_Message::normalise($body),
                'audience' => $audience,
                'days' => $audience === 'expiring' ? $days : 0,
                'planned' => max(0, $planned),
                'user_id' => (int) $user->ID,
                'user_login' => (string) $user->user_login,
                'started' => $now,
                'touched' => $now,
                'cursor' => 0,
                'steps' => 0,
                'sent' => 0,
                'failed' => 0,
                'removed' => 0,
                'skipped' => 0,
            );
            // Round 15 (M1): the job is written only while this connection still holds the lock.
            if (!self::holds_lock(self::lock_name())) {
                return array('result' => self::LOST_LOCK);
            }
            update_option(self::JOB_OPTION, $job, false);
            delete_option(self::CANCEL_OPTION);
            delete_option(self::NO_LOCK_OPTION);
            update_option(self::STARTS_OPTION, array_slice(array_merge(array($now), self::starts()), 0, self::STARTS_KEEP), false);
            $this->record('started', $job);
            return array('result' => self::OK);
        } finally {
            self::release_lock(self::lock_name());
            $this->keep_alive();
        }
    }

    /**
     * «Διακοπή αποστολής». The request is recorded first; the job is ended here only while holding the lock.
     * @return string OK (stopped now, or another stop already did) | CANCEL_PENDING (a batch holds the lock: it stops before
     *                its next message) | DONE_BEFORE_CANCEL (the job ended by itself between the tap and the lock; round
     *                14) | NONE (no running job)
     * Round 15 (L1): with NO named lock at all (GET_LOCK NULL or failing) the job is ended here by the conditional write
     * (same job id, still running; no lock needed, it can only end that job), instead of waiting for a batch that may never
     * get a lock.
     */
    public function cancel(WP_User $user): string {
        $job = self::fresh_job();
        if ($job === null || ($job['state'] ?? '') !== 'running') {
            return self::NONE;
        }
        self::record_cancel_request((string) $job['id'], (int) $user->ID);
        $lock = self::acquire_lock(self::lock_name());
        if ($lock === null) {
            $job['cancelled_by'] = (int) $user->ID;
            if ($this->finish($job, self::CANCELLED, false)) {
                return self::OK;
            }
            $now = self::fresh_job();
            if ($now === null || ($now['state'] ?? '') === 'running') {
                return $now === null ? self::NONE : self::CANCEL_PENDING;
            }
            delete_option(self::CANCEL_OPTION);
            return ($now['state'] ?? '') === self::CANCELLED ? self::OK : self::DONE_BEFORE_CANCEL;
        }
        if ($lock !== true) {
            return self::CANCEL_PENDING;
        }
        try {
            $job = self::fresh_job();
            if ($job === null || ($job['state'] ?? '') !== 'running') {
                // Round 14: the job ended between the first read and the lock; the request just recorded is stale.
                delete_option(self::CANCEL_OPTION);
                if ($job === null) {
                    return self::NONE;
                }
                return ($job['state'] ?? '') === self::CANCELLED ? self::OK : self::DONE_BEFORE_CANCEL;
            }
            $job['cancelled_by'] = (int) $user->ID;
            $this->finish($job, self::CANCELLED);
            return self::OK;
        } finally {
            self::release_lock(self::lock_name());
        }
    }

    /**
     * Under the lock: a recorded cancel request for this job ends it now.
     * @return string|null null = no request; CANCELLED = ended now; else the refusal of the write (round 15: LOST_LOCK / NONE)
     */
    private function stop_requested(array $job): ?string {
        $by = self::cancel_request_for($job);
        if ($by === null) {
            return null;
        }
        $job['cancelled_by'] = $by;
        return $this->finish($job, self::CANCELLED) ? self::CANCELLED : self::refused();
    }

    /**
     * One batch of the running job (from the page or WP-Cron). Never two at a time (database lock; a second caller
     * returns at once). @return string the job state after the batch ('running', 'done', 'stopped', 'cancelled', 'none',
     * 'busy', 'no_lock': without a named lock nothing is sent; 'lost_lock': the connection lost the lock mid-batch)
     */
    public function step(): string {
        $lock = self::acquire_lock(self::lock_name());
        if ($lock === null) {
            return self::NO_LOCK;
        }
        if ($lock !== true) {
            return self::BUSY;
        }
        $result = null;
        try {
            $result = $this->step_locked();
            return $result;
        } finally {
            self::release_lock(self::lock_name());
            // Round 14: after the lock is released (and also when the batch threw), a job still running has its next event.
            // Round 15 (L2): after a lost lock it comes RETRY_SECONDS later, like a busy lock (not at once).
            $this->keep_alive($result === self::LOST_LOCK ? self::RETRY_SECONDS : 0);
        }
    }

    /** step() while holding the campaign lock. */
    private function step_locked(): string {
        // Round 15 (L1): a batch that got the lock ends a run of batches without one.
        delete_option(self::NO_LOCK_OPTION);
        $job = self::fresh_job();
        if ($job === null || ($job['state'] ?? '') !== 'running') {
            return self::NONE;
        }
        $stop = $this->stop_requested($job);
        if ($stop !== null) {
            return $stop;
        }
        $sending = $this->backend->sending();
        if ($sending === null) {
            $job['reason'] = (string) $this->backend->last_reason();
            return $this->finish($job, 'stopped') ? 'stopped' : self::refused();
        }
        $payload = EVC_Push_Message::payload($job['title'], $job['body']);
        $started = ($this->timer)();
        $now = EVC_Clock::to_utc($this->backend->clock()->now());
        $members = array();
        $job['steps'] = (int) $job['steps'] + 1;
        $rows = $sending['store']->batch((int) $job['cursor'], self::BATCH_SIZE);
        foreach ($rows as $row) {
            if (($this->timer)() - $started >= self::TIME_BUDGET) {
                break;
            }
            $stop = $this->stop_requested($job);
            if ($stop !== null) {
                return $stop;
            }
            // Round 15 (M1): the cursor moves past this row and is saved BEFORE its message goes: each row at most once.
            $job['cursor'] = $row['id'];
            $job['touched'] = time();
            if (!self::save_running_job($job)) {
                return self::refused();
            }
            if ($row['member_status'] !== 'enabled') {
                $job['skipped']++;
            } else {
                if (!isset($members[$row['member_id']])) {
                    $members[$row['member_id']] = $this->in_audience($row['wp_user_id'], $job['audience'], (int) $job['days'], $now);
                }
                if (!$members[$row['member_id']]) {
                    $job['skipped']++;
                } else {
                    $this->deliver($sending, $row, $payload, $job);
                }
            }
            // Round 15 (M1): the counts, again only by the lock holder and only onto the same, still running job. (Going on
            // after a refusal here would be refused at the next row or in finish() as well: this only stops sooner.)
            $job['touched'] = time();
            if (!self::save_running_job($job)) {
                return self::refused();
            }
        }
        if (count($rows) < self::BATCH_SIZE && (int) $job['cursor'] === ($rows === array() ? (int) $job['cursor'] : end($rows)['id'])) {
            return $this->finish($job, 'done') ? 'done' : self::refused();
        }
        $job['touched'] = time();
        return self::save_running_job($job) ? 'running' : self::refused();
    }

    /**
     * Round 15 (M1): the ONLY writer of a running job. Writes $job only if
     *   (1) $locked: this request's connection still holds the campaign lock (checked right before the write), and
     *   (2) the database still holds a RUNNING job with the same id: read straight from the database and replaced in one
     *       statement (UPDATE … WHERE option_value = the value just read), so nothing written in between is overwritten.
     * $locked = false only for the lock-free stops (no named lock at all, L1): they can only END that same running job.
     * @return bool false = nothing written (the lock is gone, or the job ended / was replaced meanwhile)
     */
    private static function save_running_job(array $job, bool $locked = true): bool {
        if ($locked && !self::holds_lock(self::lock_name())) {
            return false;
        }
        $raw = self::fresh_raw(self::JOB_OPTION);
        $fresh = $raw === null ? null : maybe_unserialize($raw);
        if (!is_array($fresh) || (string) ($fresh['id'] ?? '') !== (string) ($job['id'] ?? '')) {
            return false;
        }
        if (($fresh['state'] ?? '') !== 'running') {
            return false;
        }
        // A revision number: every write changes the stored value (a compare-and-replace never matches an older one).
        $job['rev'] = (int) ($fresh['rev'] ?? 0) + 1;
        return self::replace_option(self::JOB_OPTION, $raw, maybe_serialize($job));
    }

    /** Round 15: why a job write was refused: the lock is gone (LOST_LOCK) or the job is no longer this running one (NONE). */
    private static function refused(): string {
        return self::holds_lock(self::lock_name()) ? self::NONE : self::LOST_LOCK;
    }

    /** Round 15: an option's raw (serialized) value straight from the database; null = no such option. */
    public static function fresh_raw(string $name): ?string {
        global $wpdb;
        wp_cache_delete($name, 'options');
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        return $raw === null ? null : (string) $raw;
    }

    /**
     * Round 15: compare and replace in ONE statement: the option becomes $new only if it still holds exactly $expected
     * (null = only if it does not exist yet). The same value again counts as written. @return bool true = written
     */
    public static function replace_option(string $name, ?string $expected, string $new): bool {
        global $wpdb;
        if ($expected !== null && $expected === $new) {
            return true;
        }
        if ($expected === null) {
            $changed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $new));
        } else {
            $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $name, $expected));
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        return $changed === 1;
    }

    /** Sends one message and records the outcome on the subscription and in the job counters. */
    private function deliver(array $sending, array $row, string $payload, array &$job): void {
        $outcome = $this->sender->send($row, $payload, $sending['keys']);
        $at = $this->backend->now_utc();
        try {
            if ($outcome === EVC_Push_Sender::SENT) {
                $sending['store']->record_success($row['id'], $at);
                $job['sent']++;
            } elseif ($outcome === EVC_Push_Sender::GONE) {
                $sending['store']->revoke_gone($row['id'], $at);
                $job['removed']++;
            } else {
                $sending['store']->record_failure($row['id'], $at);
                $job['failed']++;
            }
        } catch (Throwable $e) {
            // The message went (or not); only its bookkeeping failed. Counted as it was sent.
            $outcome === EVC_Push_Sender::SENT ? $job['sent']++ : $job['failed']++;
        }
    }

    /**
     * Ends the job. Round 15 (M1): through save_running_job() (the lock still held, the same job still running), and only
     * a write that went through leaves its audit entry. $locked = false: the lock-free stops (L1), audited in
     * STOP_AUDIT_OPTION. @return bool false = nothing written, nothing audited
     */
    private function finish(array $job, string $state, bool $locked = true): bool {
        $job['state'] = $state;
        $job['finished'] = time();
        $job['touched'] = time();
        // The text is no longer needed once the job ended.
        $job['title'] = '';
        $job['body'] = '';
        if (!self::save_running_job($job, $locked)) {
            return false;
        }
        delete_option(self::CANCEL_OPTION);
        delete_option(self::NO_LOCK_OPTION);
        $this->record($state, $job, $locked ? self::AUDIT_OPTION : self::STOP_AUDIT_OPTION);
        wp_clear_scheduled_hook(self::CRON_HOOK, array((string) $job['id']));
        return true;
    }

    /** One audit entry: who, when, audience and counts only. */
    private function record(string $event, array $job, string $store = self::AUDIT_OPTION): void {
        $entry = array(
            'event' => $event,
            'at' => time(),
            'job' => (string) $job['id'],
            'user_id' => (int) $job['user_id'],
            'user_login' => (string) $job['user_login'],
            'audience' => (string) $job['audience'],
            'days' => (int) $job['days'],
            'planned' => (int) $job['planned'],
            'sent' => (int) $job['sent'],
            'failed' => (int) $job['failed'],
            'removed' => (int) $job['removed'],
            'skipped' => (int) $job['skipped'],
        );
        if (isset($job['reason'])) {
            $entry['reason'] = preg_replace('/[^a-z0-9_]/', '', (string) $job['reason']);
        }
        if ($store === self::STOP_AUDIT_OPTION) {
            self::append_stop_audit($entry);
            return;
        }
        self::append_audit($entry, self::AUDIT_OPTION);
    }

    /**
     * Round 15 (L1): one entry of a lock-free stop. Written only by compare-and-replace (re-read and retried a few times),
     * so two such writers never lose each other's entry; nothing else writes this store.
     */
    private static function append_stop_audit(array $entry): void {
        for ($try = 0; $try < 5; $try++) {
            $raw = self::fresh_raw(self::STOP_AUDIT_OPTION);
            $audit = $raw === null ? array() : maybe_unserialize($raw);
            $audit = is_array($audit) ? array_values(array_filter($audit, 'is_array')) : array();
            array_unshift($audit, $entry);
            if (self::replace_option(self::STOP_AUDIT_OPTION, $raw, maybe_serialize(array_slice($audit, 0, self::AUDIT_KEEP)))) {
                return;
            }
        }
    }

    /**
     * Adds one entry (newest first, AUDIT_KEEP kept) to one audit store. Callers pass counts and labels only, and hold
     * the store's lock: AUDIT_OPTION = the campaign lock, AUTO_AUDIT_OPTION = the automatic notifications' lock (round 14).
     * The store is re-read straight from the database here, inside that lock, so an entry another process wrote a
     * moment ago (its object cache never seen by this request) is kept.
     */
    public static function append_audit(array $entry, string $store = self::AUDIT_OPTION): void {
        $store = $store === self::AUTO_AUDIT_OPTION ? self::AUTO_AUDIT_OPTION : self::AUDIT_OPTION;
        $audit = self::audit_store($store);
        array_unshift($audit, $entry);
        update_option($store, array_slice($audit, 0, self::AUDIT_KEEP), false);
    }

    /** Queues the job's next batch now, unless one is queued already. */
    private function schedule(array $job, int $delay = 0): void {
        $args = array((string) $job['id']);
        if (!wp_next_scheduled(self::CRON_HOOK, $args)) {
            wp_schedule_single_event(time() + $delay, self::CRON_HOOK, $args);
        }
    }

    /** Round 14: run after the lock is released: a job still running as stored always has its next event queued. */
    private function keep_alive(int $delay = 0): void {
        $job = self::fresh_job();
        if ($job !== null && ($job['state'] ?? '') === 'running' && isset($job['id'])) {
            $this->schedule($job, $delay);
        }
    }

    /**
     * WP-Cron: one batch (the argument only names the job; the stored job is what runs). Round 14: WP-Cron took this
     * event off the queue before calling here, and the runner holding the lock may have looked for a queued event before
     * that; so a busy (or missing) lock queues a retry RETRY_SECONDS later: a running job is never left without an event.
     */
    public function cron($job_id = null): void {
        $result = $this->step();
        if ($result === self::NO_LOCK) {
            $this->no_lock_seen();
        }
        // Round 15 (L2): a lost lock is retried like a busy one.
        if ($result === self::BUSY || $result === self::NO_LOCK || $result === self::LOST_LOCK) {
            $this->keep_alive(self::RETRY_SECONDS);
        }
    }

    /**
     * Round 15 (L1): one more WP-Cron batch without a named lock for the running job. At NO_LOCK_TRIES in a row the job is
     * stopped (reason 'no_lock') by the lock-free conditional write: it only ends that same, still running job.
     */
    private function no_lock_seen(): void {
        $job = self::fresh_job();
        if ($job === null || ($job['state'] ?? '') !== 'running' || !isset($job['id'])) {
            return;
        }
        $count = self::no_lock_count($job) + 1;
        if ($count < self::NO_LOCK_TRIES) {
            self::upsert_raw(self::NO_LOCK_OPTION, (string) $job['id'] . ':' . $count);
            return;
        }
        $job['reason'] = self::REASON_NO_LOCK;
        $this->finish($job, 'stopped', false);
    }

    /** Round 15 (L1): WP-Cron batches in a row that got no named lock for this job (0 = none recorded). */
    public static function no_lock_count(array $job): int {
        $flag = self::fresh_option(self::NO_LOCK_OPTION);
        if (!is_string($flag) || preg_match('/^([0-9a-f]{16}):([0-9]{1,9})$/D', $flag, $m) !== 1 || $m[1] !== (string) ($job['id'] ?? '')) {
            return 0;
        }
        return (int) $m[2];
    }

    /** Round 15 (L1): is this job stuck without a named lock (running), or was it stopped for that reason? For the notice. */
    public static function no_lock_problem(?array $job): ?string {
        if ($job === null) {
            return null;
        }
        if (($job['state'] ?? '') === 'running' && self::no_lock_count($job) > 0) {
            return 'stuck';
        }
        return ($job['state'] ?? '') === 'stopped' && ($job['reason'] ?? '') === self::REASON_NO_LOCK ? 'stopped' : null;
    }

    /** One small option row written in ONE statement (atomic upsert), never through the object cache. */
    private static function upsert_raw(string $name, string $value): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $name,
            $value
        ));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * A MySQL named lock on the WordPress database (round 13: also used by EVC_Push_Auto).
     * @return bool|null true = held now; false = busy (another runner, or this request already holds it);
     *                   null = no named lock (GET_LOCK answered NULL or failed): the caller sends NOTHING (fail closed)
     */
    public static function acquire_lock(string $name, int $timeout = self::LOCK_TIMEOUT): ?bool {
        global $wpdb;
        if (isset(self::$held[$name])) {
            return false;
        }
        $held = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout));
        if ($held === null || (string) $wpdb->last_error !== '') {
            return null;
        }
        if ((string) $held !== '1') {
            return false;
        }
        self::$held[$name] = true;
        return true;
    }

    /**
     * Round 14: does this request's database connection still hold the named lock? wpdb reconnects silently when the
     * server went away, and the new connection holds nothing. false also on any error (the caller stops: fail closed).
     */
    public static function holds_lock(string $name): bool {
        global $wpdb;
        if (!isset(self::$held[$name])) {
            return false;
        }
        $mine = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name));
        return (string) $wpdb->last_error === '' && (string) $mine === '1';
    }

    /** Releases a lock this request holds (a lock it does not hold is left alone). */
    public static function release_lock(string $name): void {
        global $wpdb;
        if (isset(self::$held[$name])) {
            unset(self::$held[$name]);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private static function site_hash(): string {
        global $wpdb;
        return substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix), 0, 16);
    }

    public static function lock_name(): string {
        return 'evc_club_push_' . self::site_hash();
    }

    /** Round 13 (N-b): the automatic notifications' own lock (a running campaign does not hold them up). */
    public static function auto_lock_name(): string {
        return 'evc_club_push_auto_' . self::site_hash();
    }
}
