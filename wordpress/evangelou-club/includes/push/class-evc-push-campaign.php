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
 * cursor (last subscription id) is saved after every message. step() is run by the administrator's open page (one POST
 * per batch) and by WP-Cron (CRON_HOOK, rescheduled while the job runs), whichever comes first.
 * 404 / 410 from a push service revokes the subscription ('gone'); other failures only count.
 *
 * Rate limit: at most MAX_PER_DAY started sends in any 24 hours and at least MIN_INTERVAL seconds between two.
 * Audit (option AUDIT_OPTION, newest AUDIT_KEEP entries): who (user id and login), when, audience (+ days), the planned
 * count and the result counts. NEVER an endpoint, a key, a member, a name, an e-mail address or the message text.
 */
final class EVC_Push_Campaign {
    const JOB_OPTION = 'evc_club_push_job';
    const AUDIT_OPTION = 'evc_club_push_audit';
    const AUDIT_KEEP = 100;
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

    const OK = 'ok';
    const BUSY = 'busy';
    const RATE_LIMITED = 'rate_limited';
    const INVALID = 'invalid';
    const UNAVAILABLE = 'unavailable';
    const NONE = 'none';

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

    /** @return array<int,array<string,mixed>> newest first */
    public static function audit(): array {
        $audit = get_option(self::AUDIT_OPTION, array());
        return is_array($audit) ? array_values($audit) : array();
    }

    /** Seconds until another send may start (0 = now), from the audit of started sends. */
    public static function wait_seconds(int $now): int {
        $starts = array();
        foreach (self::audit() as $entry) {
            if (is_array($entry) && ($entry['event'] ?? '') === 'started' && isset($entry['at'])) {
                $starts[] = (int) $entry['at'];
            }
        }
        rsort($starts);
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
        $lock = $this->lock();
        if ($lock === false) {
            return array('result' => self::BUSY);
        }
        try {
            $now = time();
            $job = self::job();
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
            update_option(self::JOB_OPTION, $job, false);
            $this->record('started', $job);
            $this->schedule($job);
            return array('result' => self::OK);
        } finally {
            $this->unlock($lock);
        }
    }

    /** «Διακοπή αποστολής»: the running job stops after the message in flight. @return string OK | NONE */
    public function cancel(WP_User $user): string {
        $lock = $this->lock(5);
        try {
            $job = self::job();
            if ($job === null || ($job['state'] ?? '') !== 'running') {
                return self::NONE;
            }
            $job['cancelled_by'] = (int) $user->ID;
            $this->finish($job, 'cancelled');
            return self::OK;
        } finally {
            if ($lock !== false) {
                $this->unlock($lock);
            }
        }
    }

    /**
     * One batch of the running job (from the page or WP-Cron). Never two at a time (database lock; a second caller
     * returns at once). @return string the job state after the batch ('running', 'done', 'stopped', 'none', 'busy')
     */
    public function step(): string {
        $lock = $this->lock();
        if ($lock === false) {
            return self::BUSY;
        }
        try {
            $job = self::job();
            if ($job === null || ($job['state'] ?? '') !== 'running') {
                return self::NONE;
            }
            $sending = $this->backend->sending();
            if ($sending === null) {
                $job['reason'] = (string) $this->backend->last_reason();
                $this->finish($job, 'stopped');
                return 'stopped';
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
                $job['cursor'] = $row['id'];
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
                $job['touched'] = time();
                update_option(self::JOB_OPTION, $job, false);
            }
            if (count($rows) < self::BATCH_SIZE && (int) $job['cursor'] === ($rows === array() ? (int) $job['cursor'] : end($rows)['id'])) {
                $this->finish($job, 'done');
                return 'done';
            }
            $job['touched'] = time();
            update_option(self::JOB_OPTION, $job, false);
            $this->schedule($job);
            return 'running';
        } finally {
            $this->unlock($lock);
        }
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

    private function finish(array $job, string $state): void {
        $job['state'] = $state;
        $job['finished'] = time();
        $job['touched'] = time();
        // The text is no longer needed once the job ended.
        $job['title'] = '';
        $job['body'] = '';
        update_option(self::JOB_OPTION, $job, false);
        $this->record($state, $job);
        wp_clear_scheduled_hook(self::CRON_HOOK, array((string) $job['id']));
    }

    /** One audit entry: who, when, audience and counts only. */
    private function record(string $event, array $job): void {
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
        self::append_audit($entry);
    }

    /** Adds one entry (newest first, AUDIT_KEEP kept). Callers pass counts and labels only. */
    public static function append_audit(array $entry): void {
        $audit = self::audit();
        array_unshift($audit, $entry);
        update_option(self::AUDIT_OPTION, array_slice($audit, 0, self::AUDIT_KEEP), false);
    }

    private function schedule(array $job): void {
        $args = array((string) $job['id']);
        if (!wp_next_scheduled(self::CRON_HOOK, $args)) {
            wp_schedule_single_event(time(), self::CRON_HOOK, $args);
        }
    }

    /** WP-Cron: one batch (the argument only names the job; the stored job is what runs). */
    public function cron($job_id = null): void {
        $this->step();
    }

    /** A MySQL named lock on the WordPress database, per site; null = the server has no named locks (then unlocked). */
    private function lock(int $timeout = self::LOCK_TIMEOUT) {
        global $wpdb;
        $held = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name(), $timeout));
        if ($held === null) {
            return null;
        }
        return (string) $held === '1' ? true : false;
    }

    private function unlock($lock): void {
        global $wpdb;
        if ($lock === true) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
        }
    }

    public static function lock_name(): string {
        global $wpdb;
        return 'evc_club_push_' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix), 0, 16);
    }
}
