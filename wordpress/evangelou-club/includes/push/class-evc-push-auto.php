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
            add_action(self::ACTIVATED_HOOK, array($this, 'activated'), 10, 1);
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

    /** WP-Cron: «Η συνδρομή σας ενεργοποιήθηκε», only for an active membership, once per paid period. @return string what happened */
    public function activated($wp_user_id): string {
        $wp_user_id = (int) $wp_user_id;
        $now = EVC_Clock::to_utc($this->backend->clock()->now());
        $m = $this->read($wp_user_id, $now);
        if ($m === null || $m['state'] !== EVC_Customer_Membership::ACTIVE || !is_string($m['paid_until_utc'])) {
            return 'not_active';
        }
        if ((string) get_user_meta($wp_user_id, self::ACTIVATED_META, true) === $m['paid_until_utc']) {
            return 'already';
        }
        $sending = $this->backend->sending();
        if ($sending === null) {
            return 'unavailable';
        }
        update_user_meta($wp_user_id, self::ACTIVATED_META, $m['paid_until_utc']);
        $counts = $this->send_member($sending, $wp_user_id, EVC_Push_Message::payload(self::ACTIVATED_TITLE, self::ACTIVATED_BODY));
        $this->audit('auto_activated', $counts);
        return 'sent';
    }

    /** WP-Cron, 10:00 Athens: «Η συνδρομή σας λήγει σε 3 ημέρες». @return string 'done' | 'continued' | 'unavailable' */
    public function expiry(): string {
        $now = EVC_Clock::to_utc($this->backend->clock()->now());
        $today = $now->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
        $target = $now->setTimezone(new DateTimeZone(self::ZONE))->modify('+' . self::DAYS_AHEAD . ' days')->format('Y-m-d');
        $run = get_option(self::RUN_OPTION, array());
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
                $run['after'] = $member_id;
                $m = $this->read($wp_user_id, $now);
                if ($m !== null && $m['state'] === EVC_Customer_Membership::ACTIVE && is_string($m['paid_until_utc'])
                    && self::athens_day($m['paid_until_utc']) === $target
                    && (string) get_user_meta($wp_user_id, self::EXPIRY_META, true) !== $m['paid_until_utc']) {
                    update_user_meta($wp_user_id, self::EXPIRY_META, $m['paid_until_utc']);
                    $counts = $this->send_member($sending, $wp_user_id, $payload);
                    $run['members']++;
                    foreach (array('sent', 'failed', 'removed') as $k) {
                        $run[$k] += $counts[$k];
                    }
                }
                update_option(self::RUN_OPTION, $run, false);
            }
            if (count($members) < self::BATCH) {
                $finished = true;
            }
        }
        if (!$finished) {
            update_option(self::RUN_OPTION, $run, false);
            wp_schedule_single_event(time() + 60, self::EXPIRY_HOOK);
            return 'continued';
        }
        $run['done'] = true;
        update_option(self::RUN_OPTION, $run, false);
        $this->audit('auto_expiry', $run);
        $this->schedule_next();
        return 'done';
    }

    private function schedule_next(): void {
        wp_clear_scheduled_hook(self::EXPIRY_HOOK);
        wp_schedule_single_event(self::next_run(time()), self::EXPIRY_HOOK);
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
        ));
    }
}
