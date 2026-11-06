<?php
/**
 * STAGE-06 wp-fluentcrm suite: EVC_CLUB_FLUENTCRM_SYNC_ENABLED === true, the six tag constants (2/3/4),
 * and the TEST-ONLY fake FluentCRM API (tests/wordpress/support/fake-fluentcrm.php). The membership
 * source and the member list are test seams; the gateway is the real shipped one, over the fake API.
 */
final class FluentcrmTagSyncWpTest extends EVC_WP_Test_Case {
    /** @var string */
    private $log;

    public function set_up() {
        parent::set_up();
        EVC_Test_Fluentcrm::reset();
        delete_option(EVC_Fluentcrm_Plugin::STATUS_OPTION);
        delete_option(EVC_Fluentcrm_Plugin::CURSOR_OPTION);
        delete_option(EVC_Fluentcrm_Plugin::LOCK_OPTION);
        delete_option(EVC_Fluentcrm_Plugin::RETRY_OPTION);
        delete_transient(EVC_Fluentcrm_Plugin::LOG_TRANSIENT);
        wp_clear_scheduled_hook(EVC_Fluentcrm_Plugin::CRON_HOOK);
        wp_clear_scheduled_hook(EVC_Fluentcrm_Plugin::CONTINUE_HOOK);
        $this->log = (string) tempnam(sys_get_temp_dir(), 'evc-log');
        ini_set('error_log', $this->log);
    }

    public function tear_down() {
        $this->assertSame(array(), EVC_Test_Fluentcrm::$forbidden, 'no FluentCRM method outside the documented, allowed set');
        ini_restore('error_log');
        @unlink($this->log);
        parent::tear_down();
    }

    private function member(string $email = 'maria@example.com'): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_email' => $email));
    }

    private static function active(): EVC_Entitlement {
        return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, new DateTimeImmutable('+10 days', new DateTimeZone('UTC')), 'pmpro:1', 'pmpro');
    }

    private function boot_with(EVC_Mock_Membership_Adapter $membership, ?callable $candidates = null): void {
        EVC_Fluentcrm_Plugin::boot(array('membership' => $membership, 'candidates' => $candidates ?? function () {
            return array();
        }));
    }

    private function writes(): array {
        return array_values(array_filter(EVC_Test_Fluentcrm::$calls, function ($c) {
            return in_array($c, array('attachTags', 'detachTags'), true);
        }));
    }

    // ---- flag, configuration and the held-back states --------------------------------------------

    public function test_the_flag_is_on_only_for_boolean_true(): void {
        $this->assertTrue(EVC_Fluentcrm_Feature::enabled());
        foreach (array(1, '1', 'true', 'yes', array(true), null, false) as $value) {
            $this->assertFalse(EVC_Fluentcrm_Feature::is_enabled_value($value), var_export($value, true));
        }
        list($config, $problem) = EVC_Fluentcrm_Feature::tag_config();
        $this->assertNull($problem);
        $this->assertSame(array(2, 3, 4), $config->ids());
    }

    public function test_without_the_membership_source_nothing_is_hooked_and_the_administrator_is_told(): void {
        EVC_Fluentcrm_Plugin::boot('');
        $this->assertSame(EVC_Fluentcrm_Plugin::PROBLEM_SOURCE, EVC_Fluentcrm_Plugin::problem());
        $this->assertFalse(has_action('woocommerce_order_status_completed', array('EVC_Fluentcrm_Plugin', 'note_order')));
        $this->assertFalse(has_action('shutdown', array('EVC_Fluentcrm_Plugin', 'flush')));
        $this->assertFalse(has_action(EVC_Fluentcrm_Plugin::CRON_HOOK, array('EVC_Fluentcrm_Plugin', 'reconcile_daily')));
        do_action('init');
        $this->assertFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CRON_HOOK));
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile());
        wp_set_current_user($this->create_user_with_role('administrator'));
        $this->assertStringContainsString(EVC_Fluentcrm_Plugin::PROBLEM_SOURCE, $this->notice());
        wp_set_current_user($this->create_user_with_role('shop_manager'));
        $this->assertSame('', $this->notice(), 'administrators only');
        $this->assertSame(array(), EVC_Test_Fluentcrm::$calls, 'FluentCRM never called');
    }

    private function notice(): string {
        ob_start();
        EVC_Fluentcrm_Plugin::notice();
        return (string) ob_get_clean();
    }

    public function test_a_renamed_tag_holds_every_write_back_with_a_notice_and_a_class_name_log(): void {
        $user = $this->member();
        EVC_Test_Fluentcrm::contact(9, 'maria@example.com', null, array(1, 3));
        EVC_Test_Fluentcrm::$tags[3] = array('id' => 3, 'title' => 'Πρώην μέλη', 'slug' => 'proin-meli');
        $this->boot_with((new EVC_Mock_Membership_Adapter())->set($user, self::active()), function () use ($user) {
            return array($user);
        });
        EVC_Fluentcrm_Plugin::note_user($user);
        EVC_Fluentcrm_Plugin::flush();
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertSame(array(), $this->writes());
        $this->assertSame(array(1, 3), EVC_Test_Fluentcrm::tags_of(9));
        wp_set_current_user($this->create_user_with_role('administrator'));
        $this->assertStringContainsString(EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_MISMATCH, $this->notice());
        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('(EVC_Fluentcrm_Tag_Sync)', $log);
        $this->assertStringNotContainsString('maria', $log);
        $this->assertStringNotContainsString('Πρώην', $log);
    }

    // ---- lifecycle events: noted during the request, synced at its end --------------------------

    public function test_a_level_change_is_synced_at_the_end_of_the_request(): void {
        $user = $this->member('Maria@Example.com');
        EVC_Test_Fluentcrm::contact(9, 'MARIA@example.com', null, array(1, 3, 4));
        $this->boot_with((new EVC_Mock_Membership_Adapter())->set($user, self::active()));
        do_action('pmpro_after_change_membership_level', 1, $user, 0);
        $this->assertSame(array(), $this->writes(), 'nothing during the event itself');
        EVC_Fluentcrm_Plugin::flush();
        $this->assertSame(array(1, 2), EVC_Test_Fluentcrm::tags_of(9), 'Active added, Inactive / Pending removed, the newsletter tag kept');
        $this->assertSame(1, EVC_Fluentcrm_Plugin::status()['events'][EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE]);
        $contact = EVC_Test_Fluentcrm::$contacts[9];
        $this->assertSame(array('subscribed', 'Μαρία', 'MARIA@example.com', null), array($contact['status'], $contact['first_name'], $contact['email'], $contact['user_id']), 'status, name, e-mail and user link untouched');
        EVC_Fluentcrm_Plugin::flush();
        $this->assertCount(3, $this->writes(), 'attach 2, detach 3 and 4; an empty queue then does nothing');
    }

    public function test_the_hooks_are_the_lifecycle_events(): void {
        $this->boot_with(new EVC_Mock_Membership_Adapter());
        foreach (EVC_Fluentcrm_Plugin::ORDER_STATUSES as $status) {
            $this->assertSame(EVC_Fluentcrm_Plugin::EVENT_PRIORITY, has_action('woocommerce_order_status_' . $status, array('EVC_Fluentcrm_Plugin', 'note_order')), $status);
        }
        $this->assertSame(EVC_Fluentcrm_Plugin::EVENT_PRIORITY, has_action('woocommerce_order_refunded', array('EVC_Fluentcrm_Plugin', 'note_order')));
        $this->assertSame(EVC_Fluentcrm_Plugin::EVENT_PRIORITY, has_action('pmpro_after_change_membership_level', array('EVC_Fluentcrm_Plugin', 'note_level_change')));
        $this->assertSame(EVC_Fluentcrm_Plugin::EVENT_PRIORITY, has_action('shutdown', array('EVC_Fluentcrm_Plugin', 'flush')));
        $this->assertSame(10, has_action(EVC_Fluentcrm_Plugin::CRON_HOOK, array('EVC_Fluentcrm_Plugin', 'reconcile_daily')), 'the daily run');
        $this->assertSame(10, has_action(EVC_Fluentcrm_Plugin::CONTINUE_HOOK, array('EVC_Fluentcrm_Plugin', 'reconcile')), 'the follow-up run');
        $this->assertSame(array('completed', 'processing', 'on-hold', 'pending', 'cancelled', 'failed', 'refunded'), EVC_Fluentcrm_Plugin::ORDER_STATUSES);
        // Without WooCommerce in this suite an order event is ignored quietly.
        EVC_Fluentcrm_Plugin::note_order(123);
        EVC_Fluentcrm_Plugin::flush();
        $this->assertSame(array(), EVC_Test_Fluentcrm::$calls);
    }

    public function test_a_request_syncs_at_most_twenty_members(): void {
        $membership = new EVC_Mock_Membership_Adapter();
        $this->boot_with($membership);
        for ($i = 1; $i <= 25; $i++) {
            EVC_Fluentcrm_Plugin::note_user(1000 + $i);
        }
        EVC_Fluentcrm_Plugin::note_user(1001);
        EVC_Fluentcrm_Plugin::note_user(0);
        EVC_Fluentcrm_Plugin::flush();
        $this->assertCount(20, $membership->calls);
        $this->assertSame(20, EVC_Fluentcrm_Plugin::status()['events'][EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER]);
    }

    public function test_failures_never_escape_into_the_request(): void {
        $user = $this->member();
        EVC_Test_Fluentcrm::contact(9, 'maria@example.com', null, array(3));
        $this->boot_with((new EVC_Mock_Membership_Adapter())->set($user, new RuntimeException('Club database down')));
        EVC_Fluentcrm_Plugin::note_user($user);
        EVC_Fluentcrm_Plugin::flush();
        $this->assertSame(1, EVC_Fluentcrm_Plugin::status()['events'][EVC_Fluentcrm_Tag_Sync::OUTCOME_SOURCE_ERROR]);
        $this->boot_with((new EVC_Mock_Membership_Adapter())->set($user, self::active()));
        EVC_Test_Fluentcrm::$fail_on = 'attachTags';
        EVC_Fluentcrm_Plugin::note_user($user);
        EVC_Fluentcrm_Plugin::flush();
        $this->assertSame(1, EVC_Fluentcrm_Plugin::status()['events'][EVC_Fluentcrm_Tag_Sync::OUTCOME_API_ERROR]);
        $this->assertSame(array(3), EVC_Test_Fluentcrm::tags_of(9));
    }

    public function test_members_without_a_contact_are_counted_never_created(): void {
        $user = $this->member('new-member@example.com');
        EVC_Test_Fluentcrm::contact(9, 'someone@example.com', null, array(1));
        $this->boot_with((new EVC_Mock_Membership_Adapter())->set($user, self::active()), function (int $after) use ($user) {
            return $after < $user ? array($user) : array();
        });
        $this->assertSame(1, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertSame(1, EVC_Fluentcrm_Plugin::status()['last'][EVC_Fluentcrm_Tag_Sync::OUTCOME_NO_CONTACT]);
        $this->assertCount(1, EVC_Test_Fluentcrm::$contacts);
        $this->assertSame(array(), $this->writes());
    }

    // ---- daily reconcile -----------------------------------------------------------------------

    public function test_reconcile_is_batched_bounded_resumable_and_idempotent(): void {
        $user = $this->member();
        EVC_Test_Fluentcrm::contact(9, 'maria@example.com', $user, array(4));
        $membership = (new EVC_Mock_Membership_Adapter())->set($user, self::active());
        $population = range(100001, 100600);
        $population[] = $user;
        $asked = array();
        $this->boot_with($membership, function (int $after, int $limit) use ($population, &$asked) {
            $asked[] = $limit;
            $next = array_values(array_filter($population, function ($id) use ($after) {
                return $id > $after;
            }));
            sort($next);
            return array_slice($next, 0, $limit);
        });
        $this->assertSame(EVC_Fluentcrm_Plugin::MAX_PER_RUN, EVC_Fluentcrm_Plugin::reconcile(), 'bounded per run');
        $this->assertSame(array_fill(0, 10, EVC_Fluentcrm_Plugin::BATCH), $asked, 'batches of 50');
        $this->assertSame(100499, (int) get_option(EVC_Fluentcrm_Plugin::CURSOR_OPTION), 'the member first, then 499 more');
        $this->assertNotFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK), 'a follow-up run for the rest');
        $this->assertSame(array(), EVC_Fluentcrm_Plugin::status()['last']);
        $this->assertSame(101, EVC_Fluentcrm_Plugin::reconcile());
        $status = EVC_Fluentcrm_Plugin::status();
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE => 1, EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER => 600), $status['last']);
        $this->assertSame(array(), $status['current']);
        $this->assertGreaterThan(0, $status['last_finished_at']);
        $this->assertSame(0, (int) get_option(EVC_Fluentcrm_Plugin::CURSOR_OPTION));
        $this->assertSame(array(2), EVC_Test_Fluentcrm::tags_of(9));
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value(), 'lock released');
        // A second full pass changes nothing.
        EVC_Test_Fluentcrm::$calls = array();
        EVC_Fluentcrm_Plugin::reconcile();
        EVC_Fluentcrm_Plugin::reconcile();
        $this->assertSame(array(), $this->writes());
        $this->assertSame(1, EVC_Fluentcrm_Plugin::status()['last'][EVC_Fluentcrm_Tag_Sync::OUTCOME_UNCHANGED]);
    }

    private function set_lock(string $value): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("REPLACE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", EVC_Fluentcrm_Plugin::LOCK_OPTION, $value));
    }

    public function test_reconcile_is_lock_protected(): void {
        $membership = new EVC_Mock_Membership_Adapter();
        $this->boot_with($membership, function (int $after) {
            return $after < 3 ? array(1, 2, 3) : array();
        });
        $live = (time() + 600) . '|another-run';
        $this->set_lock($live);
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile(), 'another run holds the lock');
        $this->assertSame(array(), $membership->calls);
        $this->assertNull(EVC_Fluentcrm_Plugin::acquire_lock());
        $this->assertSame($live, EVC_Fluentcrm_Plugin::lock_value(), 'a live lock is never touched');
        $this->set_lock((time() - 1) . '|crashed-run');
        $this->assertSame(3, EVC_Fluentcrm_Plugin::reconcile(), 'a stale lock (crashed run) is taken over');
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value(), 'and released');
    }

    public function test_two_acquirers_never_both_hold_the_lock(): void {
        $first = EVC_Fluentcrm_Plugin::acquire_lock();
        $this->assertIsString($first);
        $this->assertMatchesRegularExpression('/^\d+\|[0-9a-f]{32}$/D', $first);
        $this->assertNull(EVC_Fluentcrm_Plugin::acquire_lock(), 'the INSERT IGNORE of the second inserts nothing');
        $second_token = ((string) (time() + 900)) . '|' . str_repeat('0', 32);
        EVC_Fluentcrm_Plugin::release_lock($second_token);
        $this->assertSame($first, EVC_Fluentcrm_Plugin::lock_value(), 'only the owner may release');
        EVC_Fluentcrm_Plugin::release_lock($first);
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value());
    }

    public function test_two_runs_racing_for_a_stale_lock_cannot_both_win(): void {
        $this->set_lock((time() - 5) . '|crashed-run');
        $other = null;
        $mine = EVC_Fluentcrm_Plugin::acquire_lock(function () use (&$other) {
            // Between our read of the stale value and our takeover, another run takes it over first.
            $other = EVC_Fluentcrm_Plugin::acquire_lock();
        });
        $this->assertIsString($other, 'the other run won the takeover');
        $this->assertNull($mine, 'our conditional UPDATE matched nothing');
        $this->assertSame($other, EVC_Fluentcrm_Plugin::lock_value());
        $this->assertNull(EVC_Fluentcrm_Plugin::acquire_lock(), 'and its lock is live');
        EVC_Fluentcrm_Plugin::release_lock($other);
    }

    public function test_reconcile_survives_a_failing_member_list(): void {
        $this->boot_with(new EVC_Mock_Membership_Adapter(), function () {
            throw new RuntimeException('database');
        });
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value(), 'lock released after a failure');
        $this->assertStringContainsString('(RuntimeException)', (string) file_get_contents($this->log));
        $this->assertNotFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK), 'retried soon');
    }

    public function test_a_failure_midway_keeps_the_counts_and_the_cursor(): void {
        $calls = 0;
        $this->boot_with(new EVC_Mock_Membership_Adapter(), function (int $after, int $limit) use (&$calls) {
            if (++$calls > 1) {
                throw new RuntimeException('database went away');
            }
            return range(1, $limit);
        });
        $this->assertSame(EVC_Fluentcrm_Plugin::BATCH, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertSame(EVC_Fluentcrm_Plugin::BATCH, (int) get_option(EVC_Fluentcrm_Plugin::CURSOR_OPTION));
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER => EVC_Fluentcrm_Plugin::BATCH), EVC_Fluentcrm_Plugin::status()['current']);
        $this->assertNotFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK));
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value());
    }

    /** N3 (round 3): the lock's end moves forward after every batch; a run that lost it stops cleanly. */
    public function test_the_lock_is_refreshed_after_every_batch_and_a_lost_lock_stops_the_run(): void {
        $seen = array();
        $calls = 0;
        $other = (time() + 900) . '|other-run';
        $this->boot_with(new EVC_Mock_Membership_Adapter(), function (int $after, int $limit) use (&$seen, &$calls, $other) {
            $calls++;
            $seen[] = EVC_Fluentcrm_Plugin::lock_value();
            if ($calls === 3) {
                // Another run took the lock over (as if ours had looked stale).
                global $wpdb;
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $other, EVC_Fluentcrm_Plugin::LOCK_OPTION));
            }
            return range($after + 1, $after + $limit);
        });
        update_option(EVC_Fluentcrm_Plugin::RETRY_OPTION, 2, false);
        $this->assertSame(3 * EVC_Fluentcrm_Plugin::BATCH, EVC_Fluentcrm_Plugin::reconcile(), 'stopped after the batch whose refresh failed');
        $this->assertSame(array(), EVC_Fluentcrm_Plugin::status()['current'], 'STAGE-07 nit: no status write after losing the lock');
        $this->assertSame(2, (int) get_option(EVC_Fluentcrm_Plugin::RETRY_OPTION), 'nor a retry-budget write');
        $this->assertCount(3, array_unique($seen), 'a new lock value after every batch');
        $this->assertSame($other, EVC_Fluentcrm_Plugin::lock_value(), 'the other run\'s lock is neither released nor touched');
        $this->assertSame(3 * EVC_Fluentcrm_Plugin::BATCH, (int) get_option(EVC_Fluentcrm_Plugin::CURSOR_OPTION), 'the cursor is saved for whoever continues');
        $this->assertFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK), 'no follow-up of its own');
        $mine = EVC_Fluentcrm_Plugin::acquire_lock();
        $this->assertNull($mine, 'the other run holds it');
        $this->assertNull(EVC_Fluentcrm_Plugin::refresh_lock('1|not-mine'));
    }

    /** STAGE-07 nit: a database error while refreshing the lock is a failed run (one bounded follow-up), not a lost lock. */
    public function test_a_database_error_while_refreshing_the_lock_schedules_one_bounded_follow_up(): void {
        $this->boot_with(new EVC_Mock_Membership_Adapter(), function (int $after, int $limit) {
            return $after === 0 ? range(1, $limit) : array();
        });
        $break = function ($sql) {
            return (strpos($sql, 'UPDATE') === 0 && strpos($sql, EVC_Fluentcrm_Plugin::LOCK_OPTION) !== false) ? 'UPDATE evc_no_such_table SET x = 1' : $sql;
        };
        add_filter('query', $break);
        $GLOBALS['wpdb']->suppress_errors(true);
        try {
            $this->assertSame(EVC_Fluentcrm_Plugin::BATCH, EVC_Fluentcrm_Plugin::reconcile());
        } finally {
            remove_filter('query', $break);
            $GLOBALS['wpdb']->suppress_errors(false);
        }
        $this->assertNotFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK), 'one follow-up');
        $this->assertSame(1, (int) get_option(EVC_Fluentcrm_Plugin::RETRY_OPTION), 'counted against the bounded budget');
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER => EVC_Fluentcrm_Plugin::BATCH), EVC_Fluentcrm_Plugin::status()['current'], 'the counts so far kept');
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value(), 'our own lock released');
        $this->assertStringContainsString('(RuntimeException)', (string) file_get_contents($this->log));
    }

    /** N4 (round 3): after failures, at most three follow-up runs (5, 10, 20 minutes), then the next daily run. */
    public function test_retries_after_failures_are_bounded_with_backoff(): void {
        $this->boot_with(new EVC_Mock_Membership_Adapter(), function () {
            throw new RuntimeException('database');
        });
        foreach (array(300, 600, 1200) as $delay) {
            $before = time();
            $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile());
            $next = wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK);
            $this->assertNotFalse($next);
            $this->assertGreaterThanOrEqual($before + $delay, $next);
            $this->assertLessThanOrEqual(time() + $delay, $next);
            wp_clear_scheduled_hook(EVC_Fluentcrm_Plugin::CONTINUE_HOOK); // as if the follow-up ran (and failed)
        }
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK), 'given up until the daily run');
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile_daily());
        $this->assertNotFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK), 'the daily run has a fresh budget');
        $this->assertSame(1, (int) get_option(EVC_Fluentcrm_Plugin::RETRY_OPTION));
        // A run that works clears the budget.
        $this->boot_with(new EVC_Mock_Membership_Adapter(), function () {
            return array();
        });
        EVC_Fluentcrm_Plugin::reconcile();
        $this->assertFalse(get_option(EVC_Fluentcrm_Plugin::RETRY_OPTION));
    }

    public function test_the_daily_schedule_and_deactivation(): void {
        $this->boot_with(new EVC_Mock_Membership_Adapter());
        EVC_Fluentcrm_Plugin::schedule();
        $this->assertNotFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CRON_HOOK));
        $this->assertSame('daily', wp_get_schedule(EVC_Fluentcrm_Plugin::CRON_HOOK));
        wp_schedule_single_event(time() + 300, EVC_Fluentcrm_Plugin::CONTINUE_HOOK);
        EVC_Fluentcrm_Plugin::deactivate();
        $this->assertFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CRON_HOOK));
        $this->assertFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CONTINUE_HOOK));
    }

    // ---- administrator page: counts only ----------------------------------------------------------

    public function test_the_tools_page_shows_counts_and_no_personal_data(): void {
        $user = $this->member('private.person@example.com');
        EVC_Test_Fluentcrm::contact(9, 'private.person@example.com', null, array());
        $this->boot_with((new EVC_Mock_Membership_Adapter())->set($user, self::active()), function (int $after) use ($user) {
            return $after < $user ? array($user) : array();
        });
        EVC_Fluentcrm_Plugin::reconcile();
        wp_set_current_user($this->create_user_with_role('administrator'));
        ob_start();
        EVC_Fluentcrm_Plugin::render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('tagged_active</td><td>1', $html);
        $this->assertStringContainsString('no_contact</td><td>0', $html);
        $this->assertStringNotContainsString('private.person', $html);
        $this->assertSame('', $this->notice(), 'no notice while verified');
    }

    // ---- the shipped gateway over the documented API --------------------------------------------

    public function test_the_gateway_uses_only_the_documented_surface(): void {
        $g = new EVC_Fluentcrm_Api_Gateway();
        $this->assertTrue($g->available());
        $this->assertSame(array('id' => 2, 'title' => 'Active', 'slug' => 'active'), $g->tag(2));
        $this->assertNull($g->tag(99));
        EVC_Test_Fluentcrm::contact(9, 'maria@example.com', 501, array(3));
        $this->assertSame(array('id' => 9, 'email' => 'maria@example.com', 'user_id' => 501), $g->contact_by_user_id(501));
        $this->assertSame(9, $g->contact_by_email('maria@example.com')['id']);
        $this->assertNull($g->contact_by_email('9'), 'never a numeric string (getContact would read it as an ID)');
        $this->assertNull($g->contact_by_user_id(502));
        $this->assertTrue($g->has_tag(9, 3));
        $g->attach_tag(9, 2);
        $g->detach_tag(9, 3);
        $this->assertSame(array(2), EVC_Test_Fluentcrm::tags_of(9));
        try {
            $g->attach_tag(10, 2);
            $this->fail('an unknown contact');
        } catch (RuntimeException $e) {
            $this->assertSame(array(2), EVC_Test_Fluentcrm::tags_of(9));
        }
        foreach (array('3.2.5' => true, '3.2.6' => true, '3.10.0' => true, '3.2.4' => false, '2.9.0' => false, '' => false, 'dev' => false, '3.2.5-beta' => false) as $version => $ok) {
            $this->assertSame($ok, EVC_Fluentcrm_Api_Gateway::version_supported((string) $version), (string) $version);
        }
        $this->assertFalse(EVC_Fluentcrm_Api_Gateway::version_supported(null));
    }
}
