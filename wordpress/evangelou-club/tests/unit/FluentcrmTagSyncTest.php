<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-06: FluentCRM membership tags, pure core (config, mapping, verification, matching, the
 * exactly-one-tag rule and the never-touch rules) against an in-memory fake FluentCRM.
 */
final class FluentcrmTagSyncTest extends TestCase {
    const NOW = '2026-11-05T10:00:00+00:00';
    const USER = 501;

    /** @return array<string,array{id:mixed,name:mixed}> */
    private static function raw(array $override = array()): array {
        $raw = array(
            'active' => array('id' => 2, 'name' => 'Active'),
            'inactive' => array('id' => 3, 'name' => 'Inactive'),
            'pending' => array('id' => 4, 'name' => 'Pending payment'),
        );
        foreach ($override as $role => $values) {
            $raw[$role] = array_merge($raw[$role], $values);
        }
        return $raw;
    }

    private static function config(array $override = array()): EVC_Fluentcrm_Tag_Config {
        list($config, $problem) = EVC_Fluentcrm_Tag_Config::parse(self::raw($override));
        if ($config === null) {
            throw new LogicException((string) $problem);
        }
        return $config;
    }

    private function sync(EVC_Fake_Fluentcrm_Gateway $gateway, EVC_Mock_Membership_Adapter $membership, ?EVC_Fluentcrm_Tag_Config $config = null, ?string $email = ' Member@Example.COM '): EVC_Fluentcrm_Tag_Sync {
        return new EVC_Fluentcrm_Tag_Sync($config ?? self::config(), $gateway, $membership, function (int $id) use ($email) {
            return $id === self::USER ? $email : null;
        }, new EVC_Fixed_Clock(self::NOW));
    }

    private static function active(array $flags = array()): EVC_Entitlement {
        return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, new DateTimeImmutable('2026-12-01T00:00:00+00:00'), 'pmpro:1', 'pmpro', new DateTimeImmutable('2026-11-01T00:00:00+00:00'), null, $flags);
    }

    private static function with(string $status, array $flags = array()): EVC_Entitlement {
        return new EVC_Entitlement($status, false, null, null, 'pmpro', null, null, $flags);
    }

    private static function instant(): DateTimeImmutable {
        return new DateTimeImmutable(self::NOW);
    }

    // ---- configuration: explicit, no defaults ----------------------------------------------------

    public function test_a_complete_configuration_parses(): void {
        list($config, $problem) = EVC_Fluentcrm_Tag_Config::parse(self::raw());
        $this->assertNull($problem);
        $this->assertSame(array(2, 3, 4), $config->ids());
        $this->assertSame(2, $config->id('active'));
        $this->assertSame('Pending payment', $config->name('pending'));
    }

    public function test_every_value_is_required_and_strictly_typed(): void {
        $cases = array(
            'nothing configured' => array(array(), EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_CONFIGURED, true),
            'active id missing' => array(array('active' => array('id' => null)), EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_CONFIGURED, false),
            'inactive name missing' => array(array('inactive' => array('name' => null)), EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_CONFIGURED, false),
            'pending id missing' => array(array('pending' => array('id' => null)), EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_CONFIGURED, false),
            'numeric string id' => array(array('active' => array('id' => '2')), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_ID, false),
            'zero id' => array(array('inactive' => array('id' => 0)), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_ID, false),
            'boolean id' => array(array('pending' => array('id' => true)), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_ID, false),
            'float id' => array(array('pending' => array('id' => 4.0)), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_ID, false),
            'empty name' => array(array('active' => array('name' => '')), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_NAME, false),
            'blank name' => array(array('pending' => array('name' => "  \t ")), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_NAME, false),
            'name not a string' => array(array('inactive' => array('name' => 3)), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_NAME, false),
            'name too long' => array(array('inactive' => array('name' => str_repeat('a', 192))), EVC_Fluentcrm_Tag_Config::PROBLEM_INVALID_NAME, false),
            'same id twice' => array(array('pending' => array('id' => 2)), EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_DISTINCT, false),
            'same name twice (case)' => array(array('pending' => array('name' => ' ACTIVE ')), EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_DISTINCT, false),
            'the newsletter tag' => array(array('active' => array('id' => 1, 'name' => 'Ευαγγέλου Club')), EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_ID, false),
            'tag 1 under another name' => array(array('active' => array('id' => 1, 'name' => 'Active')), EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_ID, false),
            'tag 1 as pending' => array(array('pending' => array('id' => 1)), EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_ID, false),
            'the newsletter name on another ID' => array(array('active' => array('id' => 7, 'name' => 'Ευαγγέλου Club')), EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_NAME, false),
            'the newsletter slug' => array(array('inactive' => array('name' => 'ευαγγέλου-club')), EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_NAME, false),
            'the newsletter tag in Latin capitals' => array(array('pending' => array('name' => 'EVANGELOU CLUB')), EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_NAME, false),
        );
        foreach ($cases as $label => list($override, $problem, $empty)) {
            list($config, $got) = EVC_Fluentcrm_Tag_Config::parse($empty ? array() : self::raw($override));
            $this->assertNull($config, $label);
            $this->assertSame($problem, $got, $label);
        }
    }

    // ---- mapping: the Club's state, never FluentCRM's ---------------------------------------------

    public function test_the_mapping_table(): void {
        $cases = array(
            'active, verified, current' => array(self::active(), 'active'),
            'early renewal while active' => array(self::active(array('duplicate_payment_event_collapsed')), 'active'),
            'pending renewal while active' => array(self::active(array('renewal_pending')), 'active'),
            'expired with an unpaid renewal (before PMPro cron)' => array(self::with(EVC_Entitlement::STATUS_EXPIRED, array('paid_period_ended', 'renewal_pending')), 'pending'),
            'ended paid period with an unpaid renewal' => array(new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, new DateTimeImmutable('2026-11-05T10:00:00+00:00'), 'pmpro:1', 'pmpro', null, null, array('renewal_pending')), 'pending'),
            'expired, no renewal' => array(self::with(EVC_Entitlement::STATUS_EXPIRED, array('paid_period_ended')), 'inactive'),
            'legacy: active row, no confirmed payment, pending order' => array(self::with(EVC_Entitlement::STATUS_PENDING_PAYMENT, array('no_payment_evidence', 'renewal_pending')), 'pending'),
            'active row past its paid end' => array(new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, new DateTimeImmutable('2026-11-05T10:00:00+00:00'), 'pmpro:1', 'pmpro'), 'inactive'),
            'active without a verified payment' => array(new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, false, new DateTimeImmutable('2026-12-01T00:00:00+00:00'), 'pmpro:1', 'pmpro'), null),
            'active without a known end' => array(new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, null, 'pmpro:1', 'pmpro'), null),
            'expired' => array(self::with(EVC_Entitlement::STATUS_EXPIRED), 'inactive'),
            'cancelled' => array(self::with(EVC_Entitlement::STATUS_CANCELLED), 'inactive'),
            'refunded' => array(self::with(EVC_Entitlement::STATUS_REFUNDED), 'inactive'),
            'voided (payment failed)' => array(self::with(EVC_Entitlement::STATUS_PAYMENT_FAILED), 'inactive'),
            'frozen (reversed / voided correction)' => array(self::with(EVC_Entitlement::STATUS_INDETERMINATE, array('payment_reversed')), 'inactive'),
            'unpaid BACS order' => array(self::with(EVC_Entitlement::STATUS_PENDING_PAYMENT, array('payment_awaiting_confirmation')), 'pending'),
            'never a member' => array(EVC_Entitlement::none('pmpro'), null),
            'add-on date conflict' => array(self::with(EVC_Entitlement::STATUS_INDETERMINATE, array('pmpro_period_mismatch', 'verified_paid_period_conflict')), null),
            'partial refund policy open' => array(self::with(EVC_Entitlement::STATUS_INDETERMINATE, array('partial_refund_policy_unresolved')), null),
            'order ended before the correction' => array(self::with(EVC_Entitlement::STATUS_INDETERMINATE, array('source_conflict')), null),
        );
        foreach ($cases as $label => list($entitlement, $role)) {
            $this->assertSame($role, EVC_Fluentcrm_Tag_Map::target($entitlement, self::instant()), $label);
        }
        $this->assertSame('not_member', EVC_Fluentcrm_Tag_Map::skip_reason(EVC_Entitlement::none('pmpro')));
        $this->assertSame('state_unknown', EVC_Fluentcrm_Tag_Map::skip_reason(self::with(EVC_Entitlement::STATUS_INDETERMINATE, array('mapper_error'))));
    }

    public function test_the_paid_end_is_exclusive(): void {
        $end = new DateTimeImmutable('2026-12-01T00:00:00+00:00');
        $e = new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, $end, 'pmpro:1', 'pmpro');
        $this->assertSame('active', EVC_Fluentcrm_Tag_Map::target($e, $end->modify('-1 second')));
        $this->assertSame('inactive', EVC_Fluentcrm_Tag_Map::target($e, $end));
    }

    // ---- verification of the configured tags -------------------------------------------------------

    public function test_verified_tags_match_by_title_or_slug(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard();
        $this->assertNull($this->sync($g, new EVC_Mock_Membership_Adapter())->verify());
        $g = EVC_Fake_Fluentcrm_Gateway::standard();
        $g->tags[4]['title'] = 'Αναμονή πληρωμής';
        $this->assertNull($this->sync($g, new EVC_Mock_Membership_Adapter(), self::config(array('pending' => array('name' => 'PENDING-PAYMENT'))))->verify(), 'a slug compared case-insensitively');
        $this->assertNull($this->sync($g, new EVC_Mock_Membership_Adapter(), self::config(array('pending' => array('name' => 'pending-payment'))))->verify(), 'matches the slug');
        $this->assertNull($this->sync($g, new EVC_Mock_Membership_Adapter(), self::config(array('pending' => array('name' => ' αναμονή  πληρωμής '))))->verify(), 'matches the title');
    }

    public function test_any_mismatch_holds_everything_back(): void {
        $cases = array(
            'FluentCRM missing' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->available = false;
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_API_UNAVAILABLE),
            'tag missing' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                unset($g->tags[3]);
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_MISSING),
            'title and slug differ' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->tags[2] = array('id' => 2, 'title' => 'VIP', 'slug' => 'vip');
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_MISMATCH),
            'another tag answers for the ID' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->tags[4]['id'] = 5;
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_MISMATCH),
            'incomplete tag record' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->tags[3] = array('id' => 3, 'title' => 'Inactive');
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_MISMATCH),
            'the tag lookup fails' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->fail_on = 'tag';
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_CHECK_FAILED),
            'the API check fails' => array(function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->fail_on = 'available';
            }, EVC_Fluentcrm_Tag_Sync::PROBLEM_TAG_CHECK_FAILED),
        );
        foreach ($cases as $label => list($break, $problem)) {
            $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, array(1, 3));
            $break($g);
            $membership = (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active());
            $sync = $this->sync($g, $membership);
            $this->assertSame($problem, $sync->verify(), $label);
            $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_CONFIG_INVALID, $sync->sync_user(self::USER), $label);
            $this->assertSame(array(), $g->writes(), $label . ': no write');
            $this->assertSame(array(1, 3), $g->tags_of(9), $label);
            $this->assertSame(array(), $membership->calls, $label . ': nothing read');
            $this->assertNotContains('contact_by_email', $g->calls, $label);
        }
    }

    /** A config parse() would refuse, forged for the second line of defence (verify()). */
    private static function forged(array $ids): EVC_Fluentcrm_Tag_Config {
        $r = new ReflectionClass(EVC_Fluentcrm_Tag_Config::class);
        $config = $r->newInstanceWithoutConstructor();
        foreach (array('ids' => $ids, 'names' => array('active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending payment')) as $name => $value) {
            $p = $r->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($config, $value);
        }
        return $config;
    }

    public function test_tag_1_is_refused_by_id_whatever_it_is_called(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, array(1));
        $g->tags[1] = array('id' => 1, 'title' => 'Active', 'slug' => 'active'); // renamed completely
        $sync = $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()), self::forged(array('active' => 1, 'inactive' => 3, 'pending' => 4)));
        $this->assertSame(EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_ID, $sync->verify());
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_CONFIG_INVALID, $sync->sync_user(self::USER));
        $this->assertSame(array(), $g->writes());
        $this->assertSame(array(1), $g->tags_of(9));
        list($config, $problem) = EVC_Fluentcrm_Tag_Config::parse(self::raw(array('active' => array('id' => 1))));
        $this->assertNull($config);
        $this->assertSame(EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_ID, $problem);
    }

    public function test_a_renamed_newsletter_tag_on_another_id_is_refused_by_name(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard();
        $g->tags[2] = array('id' => 2, 'title' => 'Active', 'slug' => 'ευαγγέλου-club');
        $this->assertSame(EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_NAME, $this->sync($g, new EVC_Mock_Membership_Adapter())->verify(), 'by its slug');
        $g->tags[2] = array('id' => 2, 'title' => 'Ευαγγέλου  CLUB', 'slug' => 'active');
        $this->assertSame(EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_NAME, $this->sync($g, new EVC_Mock_Membership_Adapter())->verify(), 'by its title');
    }

    public function test_without_mbstring_the_configuration_fails_closed(): void {
        list($config, $problem) = EVC_Fluentcrm_Tag_Config::parse(self::raw(), false);
        $this->assertNull($config);
        $this->assertSame(EVC_Fluentcrm_Tag_Config::PROBLEM_NO_MBSTRING, $problem);
        list($config, $problem) = EVC_Fluentcrm_Tag_Config::parse(self::raw(), true);
        $this->assertNull($problem);
    }

    public function test_names_compare_in_unicode_nfc(): void {
        if (!class_exists('Normalizer')) {
            $this->markTestSkipped('intl is not installed');
        }
        $decomposed = "Αναμονη\u{0301} πληρωμη\u{0301}ς"; // ή typed as η + combining acute
        $g = EVC_Fake_Fluentcrm_Gateway::standard();
        $g->tags[4] = array('id' => 4, 'title' => $decomposed, 'slug' => 'x4');
        $this->assertNull($this->sync($g, new EVC_Mock_Membership_Adapter(), self::config(array('pending' => array('name' => 'Αναμονή πληρωμής'))))->verify());
    }

    public function test_verification_runs_once_per_sync(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null);
        $sync = $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()));
        $sync->sync_user(self::USER);
        $sync->sync_user(self::USER);
        $this->assertSame(1, count(array_keys($g->calls, 'available', true)));
    }

    // ---- exactly one of the three tags; nothing else touched ----------------------------------------

    public function test_each_state_leaves_exactly_one_tag_and_never_touches_the_newsletter_tag(): void {
        $cases = array(
            'active' => array(self::active(), array(1, 3, 4), array(1, 2), EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE),
            'inactive' => array(self::with(EVC_Entitlement::STATUS_EXPIRED), array(1, 2), array(1, 3), EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE),
            'pending' => array(self::with(EVC_Entitlement::STATUS_PENDING_PAYMENT), array(), array(4), EVC_Fluentcrm_Tag_Sync::OUTCOME_PENDING),
            'pending, from all three' => array(self::with(EVC_Entitlement::STATUS_PENDING_PAYMENT), array(2, 3, 4, 7), array(4, 7), EVC_Fluentcrm_Tag_Sync::OUTCOME_PENDING),
            'inactive, only detaching' => array(self::with(EVC_Entitlement::STATUS_CANCELLED), array(2, 3), array(3), EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE),
        );
        foreach ($cases as $label => list($entitlement, $before, $after, $outcome)) {
            $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, $before);
            $sync = $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, $entitlement));
            $this->assertSame($outcome, $sync->sync_user(self::USER), $label);
            $this->assertSame($after, $g->tags_of(9), $label);
            foreach ($g->writes() as $write) {
                $this->assertMatchesRegularExpression('#^(attach|detach)_tag:9/[234]$#', $write, $label . ': only the three configured tags');
            }
            // Idempotent: a second run changes nothing.
            $g->calls = array();
            $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_UNCHANGED, $sync->sync_user(self::USER), $label);
            $this->assertSame(array(), $g->writes(), $label);
            $this->assertSame($after, $g->tags_of(9), $label);
        }
    }

    public function test_the_wanted_tag_is_attached_before_the_others_are_removed(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, array(3));
        $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()))->sync_user(self::USER);
        $this->assertSame(array('attach_tag:9/2', 'detach_tag:9/3'), $g->writes());
    }

    public function test_unknown_states_never_reach_fluentcrm(): void {
        $cases = array(
            'add-on date conflict' => array(self::with(EVC_Entitlement::STATUS_INDETERMINATE, array('verified_paid_period_conflict')), EVC_Fluentcrm_Tag_Sync::OUTCOME_STATE_UNKNOWN),
            'source throws' => array(new RuntimeException('Club database down'), EVC_Fluentcrm_Tag_Sync::OUTCOME_SOURCE_ERROR),
        );
        foreach ($cases as $label => list($entitlement, $outcome)) {
            $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', self::USER, array(2));
            $this->assertSame($outcome, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, $entitlement))->sync_user(self::USER), $label);
            $this->assertSame(array('available', 'tag:2', 'tag:3', 'tag:4'), $g->calls, $label . ': only the tag check');
            $this->assertSame(array(2), $g->tags_of(9), $label);
        }
    }

    /** The mapper's answer for a Club order voided before it was paid (no PMPro row, only failed Club facts). */
    private static function voided(): EVC_Entitlement {
        return EVC_Entitlement::none('pmpro', array('no_club_membership', 'club_order_voided'));
    }

    /** N1 (round 3): any other "none" — no Club history, another level, a wrong level setting — writes nothing. */
    public function test_a_non_member_without_the_voided_evidence_is_never_touched(): void {
        foreach (array(array(), array('no_club_membership'), array('non_club_level_only')) as $flags) {
            foreach (array(array(2), array(4), array(2, 4), array(1)) as $tags) {
                $label = implode(',', $flags) . ' / ' . implode(',', $tags);
                $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', self::USER, $tags);
                $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, EVC_Entitlement::none('pmpro', $flags)))->sync_user(self::USER), $label);
                $this->assertSame(array('available', 'tag:2', 'tag:3', 'tag:4'), $g->calls, $label . ': no contact lookup, no write');
                $expected = $tags;
                sort($expected);
                $this->assertSame($expected, $g->tags_of(9), $label);
            }
        }
    }

    /** HIGH 1 (round 2): e.g. a first BACS order put on hold (Pending) and then cancelled unpaid -> mapper "none". */
    public function test_a_non_member_left_with_active_or_pending_is_moved_to_inactive(): void {
        $cases = array(
            'pending left behind' => array(array(1, 4), array(1, 3), EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE),
            'active left behind' => array(array(2), array(3), EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE),
            'both left behind' => array(array(2, 3, 4), array(3), EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE),
            'already inactive' => array(array(3), array(3), EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER),
            'none of the three' => array(array(1), array(1), EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER),
        );
        foreach ($cases as $label => list($before, $after, $outcome)) {
            $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, $before);
            $sync = $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::voided()));
            $this->assertSame($outcome, $sync->sync_user(self::USER), $label);
            $this->assertSame($after, $g->tags_of(9), $label);
            foreach ($g->writes() as $write) {
                $this->assertMatchesRegularExpression('#^(attach|detach)_tag:9/[234]$#', $write, $label);
            }
            $g->calls = array();
            $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER, $sync->sync_user(self::USER), $label . ': then idempotent');
            $this->assertSame(array(), $g->writes(), $label);
        }
        $g = EVC_Fake_Fluentcrm_Gateway::standard();
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_NOT_MEMBER, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::voided()))->sync_user(self::USER), 'no contact: still not a member, nothing created');
        $this->assertSame(array(), $g->contacts);
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', 777, array(4));
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_AMBIGUOUS, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::voided()))->sync_user(self::USER));
        $this->assertSame(array(4), $g->tags_of(9));
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, array(4));
        $g->fail_on = 'has_tag';
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_API_ERROR, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::voided()))->sync_user(self::USER));
    }

    // ---- matching: user link or e-mail only; never created ------------------------------------------

    public function test_a_member_without_a_contact_is_counted_never_created(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'someone-else@example.com', null, array(1));
        $sync = $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()));
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_NO_CONTACT, $sync->sync_user(self::USER));
        $this->assertSame(array(), $g->writes());
        $this->assertCount(1, $g->contacts);
        $nobody = $this->sync(EVC_Fake_Fluentcrm_Gateway::standard(), (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()), null, null);
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_NO_CONTACT, $nobody->sync_user(self::USER), 'no e-mail and no link');
    }

    public function test_the_email_match_is_trimmed_and_case_insensitive(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null);
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()), null, "  MEMBER@example.com\n")->sync_user(self::USER));
        $this->assertSame(array(2), $g->tags_of(9));
        $this->assertContains('contact_by_email', $g->calls);
    }

    public function test_the_user_link_is_used_when_the_emails_differ(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'old-address@example.com', self::USER, array(3));
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()))->sync_user(self::USER));
        $this->assertSame(array(2), $g->tags_of(9));
    }

    public function test_ambiguous_contacts_are_never_written(): void {
        $cases = array(
            'link and e-mail point at two contacts' => function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->contact(9, 'member@example.com', null, array(3))->contact(10, 'other@example.com', self::USER, array(3));
            },
            'the e-mail contact belongs to another WordPress user' => function (EVC_Fake_Fluentcrm_Gateway $g) {
                $g->contact(9, 'member@example.com', 777, array(3));
            },
        );
        foreach ($cases as $label => $setup) {
            $g = EVC_Fake_Fluentcrm_Gateway::standard();
            $setup($g);
            $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_AMBIGUOUS, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()))->sync_user(self::USER), $label);
            $this->assertSame(array(), $g->writes(), $label);
        }
    }

    public function test_a_gateway_that_answers_for_the_wrong_contact_is_refused(): void {
        $sync = function (EVC_Fluentcrm_Gateway $g) {
            return new EVC_Fluentcrm_Tag_Sync(self::config(), $g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()), function () {
                return 'member@example.com';
            }, new EVC_Fixed_Clock(self::NOW));
        };
        $wrong_user = new class extends EVC_Fluentcrm_Fake_Lookup {
            public function contact_by_user_id(int $wp_user_id): ?array {
                return array('id' => 9, 'email' => 'member@example.com', 'user_id' => 778);
            }
        };
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_AMBIGUOUS, $sync($wrong_user)->sync_user(self::USER));
        $wrong_email = new class extends EVC_Fluentcrm_Fake_Lookup {
            public function contact_by_email(string $email): ?array {
                return array('id' => 9, 'email' => 'not-the-member@example.com', 'user_id' => null);
            }
        };
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_AMBIGUOUS, $sync($wrong_email)->sync_user(self::USER));
        $bad_id = new class extends EVC_Fluentcrm_Fake_Lookup {
            public function contact_by_email(string $email): ?array {
                return array('id' => 0, 'email' => 'member@example.com', 'user_id' => null);
            }
        };
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_AMBIGUOUS, $sync($bad_id)->sync_user(self::USER));
        $this->assertSame(array(), $wrong_user->writes + $wrong_email->writes + $bad_id->writes);
    }

    // ---- failures never escape -----------------------------------------------------------------------

    public function test_api_failures_are_swallowed_and_counted(): void {
        foreach (array('contact_by_user_id', 'contact_by_email', 'has_tag', 'attach_tag', 'detach_tag') as $method) {
            $g = EVC_Fake_Fluentcrm_Gateway::standard()->contact(9, 'member@example.com', null, array(3));
            $g->fail_on = $method;
            $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_API_ERROR, $this->sync($g, (new EVC_Mock_Membership_Adapter())->set(self::USER, self::active()))->sync_user(self::USER), $method);
        }
    }

    public function test_an_invalid_user_id_is_refused(): void {
        $g = EVC_Fake_Fluentcrm_Gateway::standard();
        $this->assertSame(EVC_Fluentcrm_Tag_Sync::OUTCOME_CONFIG_INVALID, $this->sync($g, new EVC_Mock_Membership_Adapter())->sync_user(0));
        $this->assertSame(array(), $g->calls);
    }

    public function test_email_normalisation(): void {
        $this->assertSame('a@b.gr', EVC_Fluentcrm_Tag_Sync::normalise_email('  A@B.GR '));
        $this->assertSame('', EVC_Fluentcrm_Tag_Sync::normalise_email('no-at-sign'));
        $this->assertSame('', EVC_Fluentcrm_Tag_Sync::normalise_email('@b.gr'));
        $this->assertSame('', EVC_Fluentcrm_Tag_Sync::normalise_email(null));
        $this->assertSame('', EVC_Fluentcrm_Tag_Sync::normalise_email(42));
    }

    public function test_outcomes_are_codes_only(): void {
        foreach (EVC_Fluentcrm_Tag_Sync::outcomes() as $outcome) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/D', $outcome);
        }
        $this->assertCount(11, array_unique(EVC_Fluentcrm_Tag_Sync::outcomes()));
    }
}

/** A gateway whose tags verify and whose lookups find nothing; subclasses answer one lookup. */
abstract class EVC_Fluentcrm_Fake_Lookup implements EVC_Fluentcrm_Gateway {
    /** @var string[] */
    public $writes = array();

    public function available(): bool {
        return true;
    }

    public function tag(int $tag_id): ?array {
        $names = array(2 => 'Active', 3 => 'Inactive', 4 => 'Pending payment');
        return array('id' => $tag_id, 'title' => $names[$tag_id], 'slug' => 'x' . $tag_id);
    }

    public function contact_by_user_id(int $wp_user_id): ?array {
        return null;
    }

    public function contact_by_email(string $email): ?array {
        return null;
    }

    public function has_tag(int $contact_id, int $tag_id): bool {
        return false;
    }

    public function attach_tag(int $contact_id, int $tag_id): void {
        $this->writes[] = 'attach';
    }

    public function detach_tag(int $contact_id, int $tag_id): void {
        $this->writes[] = 'detach';
    }
}
