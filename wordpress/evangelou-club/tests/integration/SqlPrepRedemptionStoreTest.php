<?php

/**
 * Round 9 (owner decision D24): migration 004 + the real EVC_Sql_Prep_Redemption_Store under the UI-03D.A
 * service, the approved catalogue, one coffee per member per Athens day across the v1 and v2 routes, the
 * staff-note limits, and the coffee history's privacy and retired-option rules, on a real MySQL / MariaDB.
 * Its own mutants run in run-provenance-sql-mutations.php (command 'prepared'); the engine mutation runner
 * excludes this group to keep the engine job within its time limit.
 *
 * @group prepared-store
 */
final class SqlPrepRedemptionStoreTest extends EVC_Db_Test_Case {
    const R1 = '11111111-1111-4111-8111-111111111111';
    const R2 = '22222222-2222-4222-8222-222222222222';
    const R3 = '33333333-3333-4333-8333-333333333333';

    protected function migrate_on_setup(): bool {
        return false;
    }

    private function migrate(int $up_to = PHP_INT_MAX): array {
        return (new EVC_Migrator($this->db, EVC_Test_Database::migrations_dir()))->migrate($up_to);
    }

    private function prepared(?EVC_Club_Db $db = null, ?EVC_Prep_Catalogue_Authority $authority = null): EVC_Prepared_Redemption_Service {
        $reported = &$this->reported;
        return new EVC_Prepared_Redemption_Service(
            new EVC_Sql_Prep_Redemption_Store($db ?: $this->db),
            $authority ?: new EVC_Club_Prep_Catalogue_Authority(),
            $this->adapter,
            $this->clock,
            null,
            function (array $d) use (&$reported) {
                $reported[] = $d;
            }
        );
    }

    private static function body(string $request_id = self::R1, ?string $note = null, string $coffee = 'freddo_espresso'): array {
        $preparation = array(
            'modifiers' => array(
                array('group' => 'size', 'option' => 'large'),
                array('group' => 'shots', 'option' => 'triple'),
                array('group' => 'sweetness', 'option' => 'medium'),
                array('group' => 'sweetener', 'option' => 'brown'),
                array('group' => 'milk', 'option' => 'lactose_free'),
            ),
            'quick_notes' => array('no_straw'),
        );
        if ($coffee === 'greek_coffee') {
            $preparation = array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array());
        }
        if ($note !== null) {
            $preparation['note'] = $note;
        }
        return array('request_id' => $request_id, 'catalogue_version' => 2, 'coffee_code' => $coffee, 'preparation' => $preparation);
    }

    private function redeem_v2(string $member, array $body, ?EVC_Club_Db $db = null): EVC_Prepared_Redemption_Outcome {
        return $this->prepared($db)->redeem($member, 'free_coffee', new EVC_Staff_Actor(self::STAFF_ID, str_repeat('ab', 16)), $body);
    }

    // ---- migration 004 ----------------------------------------------------------------------------------------

    public function test_migration_004_is_additive_on_top_of_001_to_003_and_keeps_v1_rows(): void {
        $this->assertSame(array(1, 2, 3), $this->migrate(3));
        $member = $this->create_active_member(4001);
        $v1 = $this->service()->redeem($this->request($member, 'greek_coffee', self::R1));
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $v1->outcome());

        $this->assertSame(array(4), $this->migrate());
        $row = $this->db->fetch_one('SELECT * FROM evc_redemptions');
        $this->assertSame('greek_coffee', $row['coffee_code']);
        foreach (array('fingerprint_version', 'catalogue_version', 'prep_hash', 'snapshot_hash') as $column) {
            $this->assertArrayHasKey($column, $row);
            $this->assertNull($row[$column], $column . ' stays NULL on a v1 row');
        }
        $this->assertSame(0, $this->count_rows('evc_redemption_preparations'));
        // The v1 engine keeps working on the 004 schema (the next Athens day).
        $this->clock->set('2026-10-10T09:00:00Z');
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->service()->redeem($this->request($member, 'espresso', self::R2))->outcome());
        // Its replay still replays.
        $this->assertSame(EVC_Redemption_Result::REPLAYED, $this->service()->redeem($this->request($member, 'espresso', self::R2))->outcome());
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
    }

    public function test_the_schema_refuses_a_half_prepared_row_and_a_note_over_80_characters(): void {
        $this->migrate();
        $member = $this->create_active_member(4002);
        $member_id = (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($member))['id'];
        $insert = function (array $extra) use ($member_id) {
            $this->db->execute(
                'INSERT INTO evc_redemptions (member_id, benefit_type, business_date, coffee_code, request_id, request_fingerprint, staff_wp_user_id,'
                . ' membership_source, membership_status, membership_level_ref, membership_expires_at_utc, redeemed_at_utc,'
                . ' fingerprint_version, catalogue_version, prep_hash, snapshot_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge(array($member_id, 'free_coffee', $extra[0], 'espresso', $extra[1], str_repeat('a', 64), 501, 'mock', 'active', null,
                    '2026-11-09 10:00:00.000000', '2026-10-09 10:00:00.000000'), $extra[2])
            );
        };
        try {
            $insert(array('2026-10-09', self::R1, array('evc-redeem-v2', 2, str_repeat('b', 64), null)));
            $this->fail('a v2 row without its snapshot hash must be refused');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame(0, $this->count_rows('evc_redemptions'));
        }
        try {
            $insert(array('2026-10-09', self::R1, array(null, 2, null, null)));
            $this->fail('a catalogue version without the v2 fingerprint must be refused');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame(0, $this->count_rows('evc_redemptions'));
        }
        $insert(array('2026-10-09', self::R1, array('evc-redeem-v2', 2, str_repeat('b', 64), str_repeat('c', 64))));
        $id = (int) $this->db->fetch_one('SELECT id FROM evc_redemptions')['id'];
        $snapshot = function (string $note) use ($id) {
            $this->db->execute(
                'INSERT INTO evc_redemption_preparations (redemption_id, schema_version, prep_version, catalogue_version, coffee_code, coffee_label,'
                . ' modifiers_json, quick_notes_json, note_text, prep_hash, snapshot_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array($id, 'evc-prep-snapshot-v2', 'evc-prep-v1', 2, 'espresso', 'Espresso', '[]', '[]', $note, str_repeat('b', 64), str_repeat('c', 64))
            );
        };
        try {
            $snapshot(str_repeat('ω', 81));
            $this->fail('81 characters must be refused by the column');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame(0, $this->count_rows('evc_redemption_preparations'));
        }
        $snapshot(str_repeat('ω', 80));
        $this->assertSame(str_repeat('ω', 80), $this->db->fetch_one('SELECT note_text FROM evc_redemption_preparations')['note_text']);
        try {
            $snapshot('δεύτερο');
            $this->fail('one snapshot per redemption');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame(1, $this->count_rows('evc_redemption_preparations'));
        }
    }

    // ---- v2 end to end ------------------------------------------------------------------------------------------

    public function test_a_prepared_redemption_writes_one_ledger_row_one_snapshot_and_one_audit_event(): void {
        $this->migrate();
        $member = $this->create_active_member(4003);
        $outcome = $this->redeem_v2($member, self::body(self::R1, "  πιο\nπολύ πάγο  "));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind(), json_encode($this->reported));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::PREPARE, $outcome->instruction());

        $row = $this->db->fetch_one('SELECT * FROM evc_redemptions');
        $this->assertSame('evc-redeem-v2', $row['fingerprint_version']);
        $this->assertSame(2, (int) $row['catalogue_version']);
        $this->assertSame('freddo_espresso', $row['coffee_code']);
        $snapshot = $this->db->fetch_one('SELECT * FROM evc_redemption_preparations');
        $this->assertSame((int) $row['id'], (int) $snapshot['redemption_id']);
        $this->assertSame('πιο πολύ πάγο', $snapshot['note_text']);
        $this->assertSame('evc-prep-snapshot-v2', $snapshot['schema_version']);
        $this->assertSame($row['prep_hash'], $snapshot['prep_hash']);
        $this->assertSame($row['snapshot_hash'], $snapshot['snapshot_hash']);
        $this->assertStringContainsString('Τριπλός', $snapshot['modifiers_json']);
        $audit = $this->db->fetch_all('SELECT * FROM evc_audit_events');
        $this->assertCount(1, $audit);
        $this->assertSame((int) $row['id'], (int) $audit[0]['redemption_id']);
        $this->assertStringNotContainsString('πάγο', (string) $audit[0]['details_json'], 'the note never reaches the audit trail');

        // The stored record reads back intact: an exact retry replays (and never says prepare again).
        $replay = $this->redeem_v2($member, self::body(self::R1, 'πιο πολύ πάγο'));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $replay->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::VERIFY_HANDOFF, $replay->instruction());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT, $this->redeem_v2($member, self::body(self::R1, 'λίγο πάγο'))->kind());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_redemption_preparations'));
    }

    public function test_a_redemption_without_a_note_stores_a_v1_snapshot_and_a_null_note(): void {
        $this->migrate();
        $member = $this->create_active_member(4004);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem_v2($member, self::body(self::R1, '   ', 'greek_coffee'))->kind());
        $snapshot = $this->db->fetch_one('SELECT * FROM evc_redemption_preparations');
        $this->assertSame('evc-prep-snapshot-v1', $snapshot['schema_version']);
        $this->assertNull($snapshot['note_text']);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $this->redeem_v2($member, self::body(self::R1, null, 'greek_coffee'))->kind());
    }

    public function test_one_coffee_per_athens_day_holds_across_the_v1_and_v2_routes(): void {
        $this->migrate();
        $a = $this->create_active_member(4005);
        $b = $this->create_active_member(4006);
        // v1 first, then v2 on the same Athens day.
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->service()->redeem($this->request($a, 'espresso', self::R1))->outcome());
        $second = $this->redeem_v2($a, self::body(self::R2));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $second->kind());
        // v2 first, then v1 on the same Athens day.
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem_v2($b, self::body(self::R3))->kind());
        $this->assertSame(EVC_Redemption_Result::ALREADY_REDEEMED, $this->service()->redeem($this->request($b, 'cappuccino'))->outcome());
        // A request id of one route is never replayed by the other.
        $this->assertSame(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT, $this->redeem_v2($a, self::body(self::R1))->kind());
        $this->assertSame(EVC_Redemption_Result::IDEMPOTENCY_CONFLICT, $this->service()->redeem($this->request($b, 'freddo_espresso', self::R3))->outcome());
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_redemption_preparations'));
        // The next Athens day (just after midnight, 22:00Z in October) both may redeem again.
        $this->clock->set('2026-10-09T21:00:01Z');
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem_v2($a, self::body(self::uuid4()))->kind());
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->service()->redeem($this->request($b, 'espresso'))->outcome());
    }

    public function test_two_connections_racing_record_exactly_one_coffee(): void {
        $this->migrate();
        $member = $this->create_active_member(4007);
        $other = $this->database->connect();
        $first = $this->redeem_v2($member, self::body(self::R1), $other);
        $second = $this->redeem_v2($member, self::body(self::R2));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $first->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $second->kind());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_redemption_preparations'));
    }

    public function test_a_deadlock_is_retried_and_a_lost_commit_is_outcome_unknown_then_replayed_with_the_same_id(): void {
        $this->migrate();
        $member = $this->create_active_member(4008);
        $pdo = $this->database->faulty_pdo();
        $db = new EVC_Club_Db($pdo);
        $pdo->deadlock_prepare_matching = '/INSERT INTO evc_redemption_preparations/';
        $pdo->deadlocks_remaining = 1;
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem_v2($member, self::body(self::R1), $db)->kind());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));

        $this->clock->set('2026-10-10T09:00:00Z');
        $pdo->fail_commit_after = true;
        $unknown = $this->redeem_v2($member, self::body(self::R2), $db);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $unknown->kind());
        $this->assertSame(self::R2, $unknown->retry_request_id());
        $pdo->fail_commit_after = false;
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $this->redeem_v2($member, self::body(self::R2))->kind());

        $this->clock->set('2026-10-11T09:00:00Z');
        $pdo->fail_commit_before = true;
        $this->assertSame(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $this->redeem_v2($member, self::body(self::R3), $db)->kind());
        $pdo->fail_commit_before = false;
        $this->assertSame(0, $this->count_rows('evc_redemptions', 'request_id = ?', array(self::R3)));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem_v2($member, self::body(self::R3))->kind());
        $this->assertSame(3, $this->count_rows('evc_redemptions'));
        $this->assertSame(3, $this->count_rows('evc_redemption_preparations'));
        $this->assertSame(3, $this->count_rows('evc_audit_events', "outcome = 'redeemed'"));
    }

    public function test_a_failure_after_the_snapshot_rolls_back_all_three_records(): void {
        $this->migrate();
        $member = $this->create_active_member(4009);
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_prepare_matching = '/INSERT INTO evc_audit_events/';
        $outcome = $this->redeem_v2($member, self::body(self::R1), new EVC_Club_Db($pdo));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $outcome->kind());
        $this->assertSame(self::R1, $outcome->retry_request_id());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame(0, $this->count_rows('evc_redemption_preparations'));
    }

    public function test_an_altered_stored_note_or_missing_snapshot_is_an_integrity_failure_never_a_coffee(): void {
        $this->migrate();
        $a = $this->create_active_member(4010);
        $b = $this->create_active_member(4011);
        $this->redeem_v2($a, self::body(self::R1, 'με κανέλα'));
        $this->db->execute('UPDATE evc_redemption_preparations SET note_text = ?', array('με ζάχαρη'));
        $outcome = $this->redeem_v2($a, self::body(self::R1, 'με κανέλα'));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $outcome->kind());
        $this->assertSame(array('reason' => 'snapshot_corrupt'), $outcome->details());

        $this->redeem_v2($b, self::body(self::R2));
        $this->db->execute('DELETE FROM evc_redemption_preparations WHERE redemption_id = (SELECT id FROM evc_redemptions WHERE request_id = ?)', array(self::R2));
        $missing = $this->redeem_v2($b, self::body(self::R2));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $missing->kind());
        $this->assertSame(array('reason' => 'snapshot_missing'), $missing->details());
        // The damaged member-day still blocks another coffee.
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->redeem_v2($b, self::body(self::R3))->kind());
    }

    // ---- history ------------------------------------------------------------------------------------------------

    private function member_id(string $public_id): int {
        return (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public_id))['id'];
    }

    private function history(string $member, int $limit, bool $prepared = true, ?EVC_Prep_Catalogue $catalogue = null): array {
        return EVC_Coffee_History::recent($this->db, $this->member_id($member), $limit, $prepared, EVC_Club_Prep_Catalogue_Authority::coffee_labels(), $catalogue ?? EVC_Club_Prep_Catalogue_Authority::catalogue());
    }

    public function test_history_shows_only_that_members_coffees_newest_first_and_the_customer_view_never_the_note(): void {
        $this->migrate();
        $a = $this->create_active_member(4012);
        $b = $this->create_active_member(4013);
        $this->service()->redeem($this->request($a, 'greek_coffee', self::R1));                  // 2026-10-09, v1
        $this->redeem_v2($b, self::body(self::R2, 'μυστική σημείωση του Β'));
        $this->clock->set('2026-10-10T09:00:00Z');
        $this->redeem_v2($a, self::body(self::R3, 'λίγο πιο κρύο'));                              // 2026-10-10, v2

        $staff = EVC_Coffee_History::for_staff($this->history($a, 3));
        $this->assertCount(2, $staff);
        $this->assertSame(array('business_date', 'coffee_label', 'details', 'note', 'prefill', 'prefill_partial'), array_keys($staff[0]));
        $this->assertSame('2026-10-10', $staff[0]['business_date']);
        $this->assertSame('Freddo Espresso', $staff[0]['coffee_label']);
        $this->assertSame(array('Μεγάλο μέγεθος', 'Τριπλός', 'Μέτριος', 'Καστανή ζάχαρη', 'Γάλα χωρίς λακτόζη', 'Χωρίς καλαμάκι'), $staff[0]['details']);
        $this->assertSame('λίγο πιο κρύο', $staff[0]['note']);
        $this->assertFalse($staff[0]['prefill_partial']);
        $this->assertSame('freddo_espresso', $staff[0]['prefill']['coffee_code']);
        $this->assertSame('λίγο πιο κρύο', $staff[0]['prefill']['note']);
        // A v1 row: the coffee name only, a coffee-only pre-fill.
        $this->assertSame(array('business_date' => '2026-10-09', 'coffee_label' => 'Ελληνικός', 'details' => array(), 'note' => null,
            'prefill' => array('catalogue_version' => 2, 'coffee_code' => 'greek_coffee', 'modifiers' => array(), 'quick_notes' => array(), 'note' => null),
            'prefill_partial' => false), $staff[1]);
        $encoded = json_encode($staff, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Β', $encoded, 'member B never appears in A\'s history');
        $this->assertStringNotContainsString((string) self::STAFF_ID, $encoded);

        $customer = EVC_Coffee_History::for_customer($this->history($a, 5));
        $this->assertSame(array(
            array('business_date' => '2026-10-10', 'coffee' => 'Freddo Espresso', 'details' => array('Μεγάλο μέγεθος', 'Τριπλός', 'Μέτριος', 'Καστανή ζάχαρη', 'Γάλα χωρίς λακτόζη', 'Χωρίς καλαμάκι')),
            array('business_date' => '2026-10-09', 'coffee' => 'Ελληνικός', 'details' => array()),
        ), $customer);
        $this->assertStringNotContainsString('κρύο', json_encode($customer, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $this->history($a, 1));
    }

    public function test_below_schema_4_the_history_shows_coffee_names_only(): void {
        $this->migrate(3);
        $a = $this->create_active_member(4014);
        $b = $this->create_active_member(4017);
        $this->service()->redeem($this->request($a, 'filter_coffee', self::R1));
        $this->service()->redeem($this->request($b, 'espresso', self::R2));
        $this->clock->set('2026-10-10T09:00:00Z');
        $this->service()->redeem($this->request($a, 'cappuccino', self::R3));
        $items = $this->history($a, 3, false);
        $this->assertSame(array('Cappuccino', 'Φίλτρου'), array_column($items, 'coffee_label'), 'only A, newest first');
        $this->assertCount(1, $this->history($a, 1, false));
        $items = array_slice($items, 1);
        $this->assertSame('Φίλτρου', $items[0]['coffee_label']);
        $this->assertSame(array(), $items[0]['details']);
        $this->assertNull($items[0]['note']);
    }

    public function test_a_damaged_snapshot_shows_the_coffee_name_only_and_no_note(): void {
        $this->migrate();
        $a = $this->create_active_member(4015);
        $this->redeem_v2($a, self::body(self::R1, 'κανονικά'));
        $this->db->execute("UPDATE evc_redemption_preparations SET coffee_label = 'Αλλαγμένο'");
        $item = $this->history($a, 3)[0];
        $this->assertSame('Freddo Espresso', $item['coffee_label']);
        $this->assertSame(array(), $item['details']);
        $this->assertNull($item['note']);
    }

    public function test_a_retired_option_is_shown_but_never_pre_filled(): void {
        $this->migrate();
        $a = $this->create_active_member(4016);
        $this->redeem_v2($a, self::body(self::R1));
        $document = EVC_Club_Prep_Catalogue_Authority::document();
        $document['catalogue_version'] = 3;
        foreach ($document['coffees'] as $i => $coffee) {
            if ($coffee['code'] !== 'freddo_espresso') {
                continue;
            }
            foreach ($coffee['groups'] as $j => $group) {
                if ($group['id'] === 'shots') {
                    $document['coffees'][$i]['groups'][$j]['options'] = array_values(array_filter($group['options'], function ($o) {
                        return $o['id'] !== 'triple';
                    }));
                }
            }
            $document['coffees'][$i]['quick_notes'] = array('takeaway', 'ice_side');
        }
        $item = $this->history($a, 3, true, EVC_Prep_Catalogue::from_array($document))[0];
        $this->assertContains('Τριπλός', $item['details'], 'the past preparation is shown as it was');
        $this->assertTrue($item['prefill_partial']);
        $this->assertSame(3, $item['prefill']['catalogue_version']);
        $this->assertSame(array(
            array('group' => 'size', 'option' => 'large'),
            array('group' => 'sweetness', 'option' => 'medium'),
            array('group' => 'sweetener', 'option' => 'brown'),
            array('group' => 'milk', 'option' => 'lactose_free'),
        ), $item['prefill']['modifiers'], 'the retired triple dose is left for staff to choose (step order)');
        $this->assertSame(array(), $item['prefill']['quick_notes']);

        // A retired coffee: shown, no pre-fill at all.
        $document['coffees'] = array_values(array_filter($document['coffees'], function ($c) {
            return $c['code'] !== 'freddo_espresso';
        }));
        $gone = $this->history($a, 3, true, EVC_Prep_Catalogue::from_array($document))[0];
        $this->assertSame('Freddo Espresso', $gone['coffee_label']);
        $this->assertNull($gone['prefill']);
        $this->assertTrue($gone['prefill_partial']);
    }
}
