<?php

/** Proves the database itself enforces the invariants (no PHP involved). */
final class SchemaConstraintsTest extends EVC_Db_Test_Case {
    /** @var int */
    private $member_id;

    protected function setUp(): void {
        parent::setUp();
        $public = $this->create_member(1001);
        $this->member_id = (int) $this->db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
    }

    private function insert_redemption(array $overrides = array()): int {
        $row = array_merge(array(
            'member_id' => $this->member_id,
            'benefit_type' => 'free_coffee',
            'business_date' => '2026-10-09',
            'coffee_code' => 'espresso',
            'request_id' => self::uuid4(),
            'request_fingerprint' => str_repeat('a', 64),
            'staff_wp_user_id' => self::STAFF_ID,
            'membership_source' => 'mock',
            'membership_status' => 'active',
            'membership_level_ref' => null,
            'membership_expires_at_utc' => '2026-11-09 10:00:00.000000',
            'redeemed_at_utc' => '2026-10-09 10:00:00.000000',
        ), $overrides);
        return (new EVC_Redemption_Store($this->db))->insert_redemption($this->db, $row);
    }

    public function test_one_redemption_per_member_benefit_business_date(): void {
        $this->insert_redemption();
        try {
            $this->insert_redemption();
            $this->fail('Second redemption on the same business date must violate the unique key.');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_redemptions_one_per_day', $e->key_name());
        }
        $this->insert_redemption(array('business_date' => '2026-10-10'));
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
    }

    public function test_request_id_is_globally_unique(): void {
        $request_id = self::uuid4();
        $this->insert_redemption(array('request_id' => $request_id));
        try {
            $this->insert_redemption(array('request_id' => $request_id, 'business_date' => '2026-10-10'));
            $this->fail('Reused request_id must violate the unique key.');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_redemptions_request', $e->key_name());
            $this->assertStringNotContainsString($request_id, $e->getMessage(), 'no values leak through the exception');
        }
    }

    public function test_redemption_requires_existing_member(): void {
        try {
            $this->insert_redemption(array('member_id' => 999999));
            $this->fail('Foreign key must reject an unknown member.');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame(1452, $e->driver_code());
        }
    }

    public function test_member_with_history_cannot_be_deleted(): void {
        $this->insert_redemption();
        try {
            $this->db->execute('DELETE FROM evc_members WHERE id = ?', array($this->member_id));
            $this->fail('ON DELETE RESTRICT must protect redemption history.');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame(1451, $e->driver_code());
        }
    }

    public function test_coffee_code_is_mandatory(): void {
        try {
            // Raw SQL: the store would cast NULL to '', so bypass it here.
            $this->db->execute(
                'INSERT INTO evc_redemptions (member_id, benefit_type, business_date, coffee_code, request_id,'
                . ' request_fingerprint, staff_wp_user_id, membership_source, membership_status,'
                . ' membership_expires_at_utc, redeemed_at_utc) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)',
                array($this->member_id, 'free_coffee', '2026-10-09', self::uuid4(), str_repeat('c', 64), self::STAFF_ID,
                    'mock', 'active', '2026-11-09 10:00:00.000000', '2026-10-09 10:00:00.000000')
            );
            $this->fail('NULL coffee_code must be rejected.');
        } catch (EVC_Db_Exception $e) {
            $this->assertSame(1048, $e->driver_code());
        }
        try {
            $this->insert_redemption(array('coffee_code' => ''));
            $this->fail('Empty coffee_code must be rejected by the CHECK constraint.');
        } catch (EVC_Db_Exception $e) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_only_active_membership_snapshots_are_storable(): void {
        $this->expectException(EVC_Db_Exception::class);
        $this->insert_redemption(array('membership_status' => 'pending_payment'));
    }

    public function test_wp_user_maps_to_at_most_one_member(): void {
        $this->expectException(EVC_Db_Duplicate_Key_Exception::class);
        $this->create_member(1001);
    }

    public function test_at_most_one_active_qr_token_per_member(): void {
        $insert = function (string $hash, string $status) {
            $this->db->execute(
                'INSERT INTO evc_qr_tokens (member_id, token_hash, status, issued_at_utc) VALUES (?, ?, ?, ?)',
                array($this->member_id, $hash, $status, '2026-10-09 10:00:00.000000')
            );
        };
        $insert(str_repeat('1', 64), 'revoked');
        $insert(str_repeat('2', 64), 'revoked');
        $insert(str_repeat('3', 64), 'active');
        try {
            $insert(str_repeat('4', 64), 'active');
            $this->fail('A second active QR token must be rejected.');
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            $this->assertSame('uq_qr_tokens_one_active', $e->key_name());
        }
        $this->assertSame(3, $this->count_rows('evc_qr_tokens'));
    }

    public function test_transactional_rolls_back_on_failure(): void {
        try {
            $this->db->transactional(function (EVC_Club_Db $db) {
                (new EVC_Redemption_Store($db))->insert_redemption($db, array(
                    'member_id' => $this->member_id, 'benefit_type' => 'free_coffee', 'business_date' => '2026-10-09',
                    'coffee_code' => 'espresso', 'request_id' => self::uuid4(), 'request_fingerprint' => str_repeat('b', 64),
                    'staff_wp_user_id' => self::STAFF_ID, 'membership_source' => 'mock', 'membership_status' => 'active',
                    'membership_level_ref' => null, 'membership_expires_at_utc' => '2026-11-09 10:00:00.000000',
                    'redeemed_at_utc' => '2026-10-09 10:00:00.000000',
                ));
                throw new RuntimeException('boom');
            });
            $this->fail('Exception must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFalse($this->db->in_transaction());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_nested_transactions_are_refused(): void {
        $this->expectException(LogicException::class);
        $this->db->transactional(function (EVC_Club_Db $db) {
            $db->transactional(function () {
                return null;
            });
        });
    }

    public function test_session_is_pinned_to_utc_strict_read_committed(): void {
        $row = $this->db->fetch_one('SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode');
        $this->assertSame('+00:00', $row['tz']);
        $this->assertStringContainsString('STRICT_ALL_TABLES', $row['mode']);
        // MySQL 8 / MariaDB >= 11.1 name it transaction_isolation; MariaDB 10.x tx_isolation.
        try {
            $iso = $this->db->fetch_one('SELECT @@session.transaction_isolation AS iso');
        } catch (EVC_Db_Exception $e) {
            $iso = $this->db->fetch_one('SELECT @@session.tx_isolation AS iso');
        }
        $this->assertSame('READ-COMMITTED', $iso['iso']);
    }
}
