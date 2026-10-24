<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03B.2: the signed BACS intent — schema invariants, canonical signed
 * encoding, strict decoding, binding to its order, lifecycle. Synthetic data.
 */
final class BacsIntentTest extends TestCase {
    private static function fx(): string {
        return EVC_Bacs_Intent_Fixture::class;
    }

    private static function decode(string $raw, int $order = EVC_Bacs_Intent_Fixture::ORDER, int $customer = EVC_Bacs_Intent_Fixture::CUSTOMER, string $env = 'live', ?EVC_Bacs_Intent_Codec $codec = null): EVC_Bacs_Intent_Read {
        return ($codec ?? EVC_Bacs_Intent_Fixture::codec())->decode($raw, $order, $customer, $env);
    }

    /** Re-encodes edited JSON exactly like the codec does (compact, unescaped slashes). */
    private static function edit(string $raw, callable $change): string {
        $data = json_decode($raw, true);
        $data = $change($data);
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function assertInvalid(EVC_Bacs_Intent_Read $read, string $reason): void {
        $this->assertSame(EVC_Bacs_Intent_Read::INVALID, $read->outcome, 'got ' . $read->outcome . '/' . (string) $read->reason);
        $this->assertSame($reason, $read->reason);
        $this->assertNull($read->intent);
    }

    // ---- 1: round trip -------------------------------------------------------------------

    public function test_valid_signed_intent_round_trips(): void {
        $intent = EVC_Bacs_Intent_Fixture::initial();
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode($intent);
        $read = self::decode($raw);
        $this->assertSame(EVC_Bacs_Intent_Read::VALID, $read->outcome, (string) $read->reason);
        $this->assertTrue($read->intent->same_as($intent));
        $this->assertSame($raw, $read->raw);
        $this->assertSame($raw, EVC_Bacs_Intent_Fixture::codec()->encode($read->intent), 'canonical encoding is stable');
        $this->assertSame(array_merge(EVC_Bacs_Intent::FIELDS, array('sig')), array_keys(json_decode($raw, true)));
        $this->assertSame('2026-10-15T09:00:00Z', json_decode($raw, true)['verified_at']);
        $this->assertSame('2026-11-15T10:00:00Z', json_decode($raw, true)['period_end']); // 12:00 EET (DST ended)
    }

    public function test_every_lifecycle_state_round_trips(): void {
        $planned = EVC_Bacs_Intent_Fixture::initial();
        $issued = $planned->issued(EVC_Bacs_Intent_Fixture::ref('row:new'));
        foreach (array($planned, $issued, $issued->recorded(), $planned->issuance_mismatch(), $issued->recovery_required(), $planned->issuance_mismatch()->recovery_required()) as $intent) {
            $read = self::decode(EVC_Bacs_Intent_Fixture::codec()->encode($intent));
            $this->assertSame(EVC_Bacs_Intent_Read::VALID, $read->outcome, $intent->state() . ': ' . (string) $read->reason);
            $this->assertTrue($read->intent->same_as($intent));
        }
    }

    public function test_the_stored_value_holds_only_opaque_references_ids_instants_and_state(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00')->issued(EVC_Bacs_Intent_Fixture::ref('row:new')));
        foreach (json_decode($raw, true) as $key => $value) {
            $this->assertMatchesRegularExpression('/^([0-9a-f]{64}|[0-9]+|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z|live|initial|early|late|planned|issued|recorded|issuance_mismatch|recovery_required)?$/D', (string) $value, $key);
        }
        $this->assertStringNotContainsString('@', $raw);
        $this->assertStringNotContainsString(EVC_Bacs_Intent_Fixture::KEY, $raw);
        $this->assertLessThan(EVC_Bacs_Intent_Codec::MAX_BYTES, strlen($raw));
    }

    // ---- 2, 4: tampering, signature ------------------------------------------------------

    public function test_any_tampered_field_is_rejected(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00', array('order' => EVC_Bacs_Intent_Fixture::ORDER)));
        $changes = array(
            'environment' => 'test',
            'level_id' => 8,
            'movement_id' => EVC_Bacs_Intent_Fixture::ref('mv:other'),
            'order_anchor' => EVC_Bacs_Intent_Fixture::ref('anchor:other'),
            'actor_id' => 8,
            'verified_at' => '2026-11-10T07:00:01Z',
            'run_start' => '2026-10-15T09:00:01Z',
            'predecessor_movement_id' => EVC_Bacs_Intent_Fixture::ref('mv:other'),
            'previous_row_ref' => EVC_Bacs_Intent_Fixture::ref('row:other'),
        );
        foreach ($changes as $field => $value) {
            $tampered = self::edit($raw, function (array $d) use ($field, $value) {
                $d[$field] = $value;
                return $d;
            });
            $this->assertNotSame($raw, $tampered, $field);
            $this->assertInvalid(self::decode($tampered), 'signature_mismatch');
        }
        // Moving the period (start + matching end) keeps the schema valid; the signature catches it.
        $moved = self::edit($raw, function (array $d) {
            $d['period_start'] = '2026-11-16T10:00:00Z';
            $d['period_end'] = '2026-12-16T10:00:00Z';
            return $d;
        });
        $this->assertInvalid(self::decode($moved), 'signature_mismatch');
        // A state jump with the original signature.
        $jumped = self::edit($raw, function (array $d) {
            $d['state'] = 'recorded';
            $d['new_row_ref'] = EVC_Bacs_Intent_Fixture::ref('row:forged');
            $d['revision'] = 3;
            return $d;
        });
        $this->assertInvalid(self::decode($jumped), 'signature_mismatch');
    }

    public function test_schema_violations_are_rejected_before_any_signature_is_trusted(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::initial());
        foreach (array(
            'order_id' => '100',          // string, not int
            'period_end' => '2026-11-15T10:00:01Z', // not one calendar month
            'state' => 'paid',
            'revision' => 2,              // planned is revision 1
            'actor_id' => EVC_Bacs_Intent_Fixture::CUSTOMER,
            'verified_at' => '2026-10-15 09:00:00',
        ) as $field => $value) {
            $bad = self::edit($raw, function (array $d) use ($field, $value) {
                $d[$field] = $value;
                return $d;
            });
            $this->assertInvalid(self::decode($bad), 'malformed_fields');
        }
    }

    public function test_missing_or_malformed_signature_is_rejected(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::initial());
        $this->assertInvalid(self::decode(self::edit($raw, function (array $d) {
            unset($d['sig']);
            return $d;
        })), 'signature_missing');
        foreach (array('', 'ZZ', str_repeat('A', 64), 123) as $sig) {
            $this->assertInvalid(self::decode(self::edit($raw, function (array $d) use ($sig) {
                $d['sig'] = $sig;
                return $d;
            })), 'signature_missing');
        }
        $this->assertInvalid(self::decode(self::edit($raw, function (array $d) {
            $d['sig'] = str_repeat('0', 64);
            return $d;
        })), 'signature_mismatch');
    }

    public function test_another_key_never_validates(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec('another-test-only-key-with-at-least-32-bytes!!')->encode(EVC_Bacs_Intent_Fixture::initial());
        $this->assertInvalid(self::decode($raw), 'signature_mismatch');
    }

    public function test_signature_is_domain_separated_from_evidence_references(): void {
        $intent = EVC_Bacs_Intent_Fixture::initial();
        $data = json_decode(EVC_Bacs_Intent_Fixture::codec()->encode($intent), true);
        $sig = $data['sig'];
        unset($data['sig']);
        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES);
        // Not an evidence ref of the same key, not a plain HMAC with the raw key, not unprefixed.
        $this->assertNotSame(hash_hmac('sha256', $canonical, EVC_Bacs_Intent_Fixture::KEY), $sig);
        $this->assertNotSame(hash_hmac('sha256', EVC_Bacs_Intent_Codec::MAC_DOMAIN . '1' . "\0" . $canonical, EVC_Bacs_Intent_Fixture::KEY), $sig);
        foreach (array('wc_order', 'bacs_transfer', 'pmpro_membership_row') as $ns) {
            $this->assertNotSame(EVC_Evidence_Ref::hmac(EVC_Bacs_Intent_Fixture::KEY, $ns, $canonical), $sig);
        }
        $derived = hash_hmac('sha256', EVC_Bacs_Intent_Codec::KEY_LABEL, EVC_Bacs_Intent_Fixture::KEY, true);
        $this->assertSame(hash_hmac('sha256', EVC_Bacs_Intent_Codec::MAC_DOMAIN . '1' . "\0" . $canonical, $derived), $sig);
    }

    public function test_non_canonical_representations_are_rejected(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::initial());
        $this->assertInvalid(self::decode(json_encode(json_decode($raw, true), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)), 'non_canonical');
        $this->assertInvalid(self::decode(' ' . $raw), 'non_canonical');
        $reordered = json_decode($raw, true);
        $reordered = array('order_id' => $reordered['order_id']) + $reordered;
        $this->assertInvalid(self::decode(json_encode($reordered, JSON_UNESCAPED_SLASHES)), 'unexpected_shape');
        $extra = json_decode($raw, true);
        $extra['note'] = 'x';
        $this->assertInvalid(self::decode(json_encode($extra, JSON_UNESCAPED_SLASHES)), 'unexpected_shape');
    }

    public function test_garbage_is_rejected(): void {
        $this->assertInvalid(self::decode(''), 'not_a_string');
        $this->assertInvalid(EVC_Bacs_Intent_Fixture::codec()->decode(array('v' => 1), 100, 42, 'live'), 'not_a_string');
        $this->assertInvalid(self::decode('{"v":1'), 'malformed');
        $this->assertInvalid(self::decode('a:1:{i:0;s:1:"x";}'), 'malformed'); // never PHP-unserialised
        $this->assertInvalid(self::decode(str_repeat(' ', EVC_Bacs_Intent_Codec::MAX_BYTES + 1)), 'oversized');
        $this->assertInvalid(self::decode('{"a":{"b":{"c":1}}}'), 'malformed');
    }

    public function test_unknown_schema_version_is_rejected(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::initial());
        $this->assertInvalid(self::decode(self::edit($raw, function (array $d) {
            $d['v'] = 2;
            return $d;
        })), 'unsupported_version');
    }

    // ---- 3: binding ----------------------------------------------------------------------

    public function test_a_valid_intent_read_from_another_order_customer_or_environment_is_rejected(): void {
        $raw = EVC_Bacs_Intent_Fixture::codec()->encode(EVC_Bacs_Intent_Fixture::initial());
        $this->assertInvalid(self::decode($raw, 101), 'cross_order');
        $this->assertInvalid(self::decode($raw, EVC_Bacs_Intent_Fixture::ORDER, 43), 'cross_customer');
        $this->assertInvalid(self::decode($raw, EVC_Bacs_Intent_Fixture::ORDER, EVC_Bacs_Intent_Fixture::CUSTOMER, 'staging'), 'cross_environment');
    }

    // ---- schema invariants ---------------------------------------------------------------

    public function test_the_customer_can_never_be_the_actor(): void {
        $this->expectException(InvalidArgumentException::class);
        EVC_Bacs_Intent_Fixture::initial('2026-10-15 12:00:00', array('actor' => EVC_Bacs_Intent_Fixture::CUSTOMER));
    }

    public function test_an_intent_needs_a_matching_planned_period(): void {
        $at = EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00');
        $refused = EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('alias_conflict'));
        $other = EVC_Period_Plan::planned(EVC_Bacs_Intent_Fixture::ref('mv:other'), $at, EVC_Membership_Calendar::add_calendar_month($at), 'initial', null);
        foreach (array($refused, $other) as $plan) {
            try {
                EVC_Bacs_Intent::planned('live', 100, 42, 7, EVC_Bacs_Intent_Fixture::ref('mv:transfer'), EVC_Bacs_Intent_Fixture::ref('a'), 7, $at, $plan, $at, null);
                $this->fail('accepted a plan that is not this movement\'s');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_period_kind_invariants(): void {
        $at = EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00');
        $mv = EVC_Bacs_Intent_Fixture::ref('mv:transfer');
        $cases = array(
            'early without paid row' => array(EVC_Period_Plan::planned($mv, $at->modify('+5 days'), EVC_Membership_Calendar::add_calendar_month($at->modify('+5 days')), 'early', EVC_Bacs_Intent_Fixture::ref('mv:p')), $at->modify('-25 days'), null),
            'early run after its period start' => array(EVC_Period_Plan::planned($mv, $at->modify('+5 days'), EVC_Membership_Calendar::add_calendar_month($at->modify('+5 days')), 'early', EVC_Bacs_Intent_Fixture::ref('mv:p')), $at->modify('+5 days'), EVC_Bacs_Intent_Fixture::ref('row')),
            'late starting before confirmation' => array(EVC_Period_Plan::planned($mv, $at->modify('-1 second'), EVC_Membership_Calendar::add_calendar_month($at->modify('-1 second')), 'late', EVC_Bacs_Intent_Fixture::ref('mv:p')), $at->modify('-1 second'), null),
            'initial replacing a row' => array(EVC_Period_Plan::planned($mv, $at, EVC_Membership_Calendar::add_calendar_month($at), 'initial', null), $at, EVC_Bacs_Intent_Fixture::ref('row')),
        );
        foreach ($cases as $label => $c) {
            try {
                EVC_Bacs_Intent::planned('live', 100, 42, 7, $mv, EVC_Bacs_Intent_Fixture::ref('a'), 7, $at, $c[0], $c[1], $c[2]);
                $this->fail('accepted: ' . $label);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $early = EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $this->assertSame('early', $early->period_kind());
        $this->assertSame(EVC_Bacs_Intent_Fixture::ref('row:old'), $early->previous_row_ref());
    }

    // ---- lifecycle -----------------------------------------------------------------------

    public function test_lifecycle_moves_forward_only_one_step_at_a_time(): void {
        $planned = EVC_Bacs_Intent_Fixture::initial();
        $issued = $planned->issued(EVC_Bacs_Intent_Fixture::ref('row:new'));
        $recorded = $issued->recorded();
        $this->assertSame(array(1, 2, 3), array($planned->revision(), $issued->revision(), $recorded->revision()));
        $this->assertTrue($planned->is_followed_by($issued));
        $this->assertTrue($issued->is_followed_by($recorded));
        $this->assertTrue($planned->is_followed_by($planned->issuance_mismatch()));
        $this->assertTrue($issued->is_followed_by($issued->recovery_required()));
        $this->assertFalse($planned->is_followed_by($recorded), 'skips a step');
        // Allowed target state, same row (none), but two revisions on: a skipped write, not one step.
        $this->assertTrue($planned->is_followed_by($planned->recovery_required()));
        $this->assertFalse($planned->is_followed_by($planned->issuance_mismatch()->recovery_required()), 'revision skipped');
        $this->assertFalse($issued->is_followed_by($planned));
        $this->assertFalse($planned->is_followed_by($planned));
        $this->assertFalse($planned->is_followed_by(EVC_Bacs_Intent_Fixture::initial('2026-10-15 12:00:01')->issued(EVC_Bacs_Intent_Fixture::ref('row:new'))), 'other facts');
        $this->assertFalse($issued->is_followed_by($planned->issued(EVC_Bacs_Intent_Fixture::ref('row:other'))->recorded()), 'other row');
        foreach (array(
            function () use ($recorded) {
                return $recorded->recovery_required();
            },
            function () use ($planned) {
                return $planned->recorded();
            },
            function () use ($issued) {
                return $issued->issuance_mismatch();
            },
            function () use ($planned) {
                return $planned->recovery_required()->issued(EVC_Bacs_Intent_Fixture::ref('row:new'));
            },
        ) as $i => $illegal) {
            try {
                $illegal();
                $this->fail('illegal transition ' . $i);
            } catch (LogicException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_issued_names_a_new_row(): void {
        $early = EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $this->expectException(InvalidArgumentException::class);
        $early->issued(EVC_Bacs_Intent_Fixture::ref('row:old'));
    }

    public function test_as_plan_is_exactly_the_fixed_period(): void {
        $i = EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $p = $i->as_plan();
        $this->assertSame(EVC_Period_Plan::PLANNED, $p->outcome());
        $this->assertEquals($i->period_start_utc(), $p->start_utc());
        $this->assertEquals($i->period_end_utc(), $p->end_utc());
        $this->assertSame($i->predecessor_movement_id(), $p->predecessor_movement_id());
    }

    public function test_codec_requires_a_real_key(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Bacs_Intent_Codec('short');
    }
}
