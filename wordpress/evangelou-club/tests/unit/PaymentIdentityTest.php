<?php
use PHPUnit\Framework\TestCase;

/**
 * Task 1D-I: canonical payment identity, versioned HMAC key ring, trusted
 * evidence gate, verified alias resolution and refund-parent correlation.
 * Synthetic facts only: a fake verifier, fake ids and fake keys. No network,
 * no provider, no WordPress. Numbers in test names refer to the 1D-I brief.
 */
final class PaymentIdentityTest extends TestCase {
    const USER = 42;

    /** @var EVC_Fake_Payment_Source_Verifier */
    private $verifier;
    /** @var EVC_Payment_Evidence_Gate */
    private $gate;
    /** @var EVC_In_Memory_Provenance_Store */
    private $store;

    protected function setUp(): void {
        $this->verifier = new EVC_Fake_Payment_Source_Verifier();
        $this->gate = F::gate($this->verifier);
        $this->store = new EVC_In_Memory_Provenance_Store();
    }

    private function resolver(?EVC_Payment_Key_Ring $ring = null): EVC_Payment_Identity_Resolver {
        return new EVC_Payment_Identity_Resolver(F::identity($ring), $this->store);
    }

    private function recorder(): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($this->store);
    }

    /** Puts a captured PayPal capture linked to a WooCommerce order and verifies it through the gate. */
    private function captured(string $capture, string $order, string $state = EVC_Provider_Record::STATE_CAPTURED, string $merchant = F::PAYPAL_MERCHANT): EVC_Payment_Source_Ref {
        $ref = F::paypal_capture($capture, $merchant);
        $this->verifier->put($ref, $state, 2000, array(F::wc_order($order)));
        return $ref;
    }

    private function verified(EVC_Payment_Source_Ref $ref): EVC_Verified_Payment {
        $o = $this->gate->verify_payment($ref);
        $this->assertSame(EVC_Payment_Evidence_Outcome::VERIFIED, $o->status(), (string) $o->reason());
        return $o->payment();
    }

    /** Resolve + build + record; returns the recorder outcome. */
    private function record(EVC_Verified_Payment $p, ?EVC_Payment_Key_Ring $ring = null, string $at = '2026-10-15 12:00:00'): EVC_Record_Outcome {
        $resolver = $this->resolver($ring);
        $res = $resolver->resolve_payment($p);
        $this->assertSame(EVC_Payment_Identity_Resolution::RESOLVED, $res->status(), (string) $res->reason());
        $m = $resolver->build_movement($p, $res, self::USER, EVC_Pmpro_Fixture::CLUB_LEVEL, EVC_Provenance_Test_Fixture::ref('row1'), EVC_Pmpro_Fixture::athens($at));
        $this->assertNotNull($m);
        return $this->recorder()->record($m);
    }

    private function refund(string $refund, string $parent_capture, string $kind = 'refunded', string $merchant = F::PAYPAL_MERCHANT, ?array $parents = null): EVC_Payment_Source_Ref {
        $ref = F::paypal_refund($refund, $merchant);
        $this->verifier->put($ref, EVC_Provider_Record::STATE_COMPLETED, 2000, array(), $parents ?? array(F::paypal_capture($parent_capture, $merchant)), $kind);
        return $ref;
    }

    // ---- 1-5: identity derivation ----------------------------------------------------

    public function test_01_same_capture_from_woocommerce_and_gateway_has_one_identity(): void {
        $capture = $this->captured('5TY05013RG002845M', '1001');
        // WooCommerce-originated observation (order + capture) and a gateway webhook (capture only).
        $from_woo = $this->gate->verify_notification(new EVC_Payment_Notification(array(F::wc_order('1001'), $capture), false, 'completed'));
        $from_gateway = $this->gate->verify_notification(new EVC_Payment_Notification(array($capture), true, 'PAYMENT.CAPTURE.COMPLETED'));
        $a = $this->resolver()->resolve_payment($from_woo->payment());
        $b = $this->resolver()->resolve_payment($from_gateway->payment());
        $this->assertSame($a->movement_id(), $b->movement_id());
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->record($from_gateway->payment())->outcome());
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $this->record($from_woo->payment())->outcome());
        $this->assertSame(1, $this->store->counts()['periods']);
        // Reverse arrival order gives the same id too (pure derivation).
        $this->assertSame($a->movement_id(), F::identity()->movement_id($capture, 1));
    }

    public function test_02_different_captures_have_different_identities(): void {
        $id = F::identity();
        $this->assertNotSame($id->movement_id(F::paypal_capture('CAP-A'), 1), $id->movement_id(F::paypal_capture('CAP-B'), 1));
        $this->assertNotSame($id->movement_id(F::paypal_capture('CAP-A'), 1), $id->movement_id(F::viva_capture('CAP-A'), 1), 'provider is part of identity');
    }

    public function test_03_overlapping_raw_ids_of_different_merchants_never_collide(): void {
        $id = F::identity();
        $this->assertNotSame($id->movement_id(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT), 1), $id->movement_id(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT_B), 1));
        $this->assertNotSame($id->alias_ref(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT), 1), $id->alias_ref(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT_B), 1));
        // End to end: same raw capture id at two merchants = two movements.
        $this->verifier->put(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT), 'captured', 2000, array(F::wc_order('2001')));
        $this->verifier->put(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT_B), 'captured', 2000, array(F::wc_order('2002')));
        $this->record($this->verified(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT)));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->record($this->verified(F::paypal_capture('SAME-ID', F::PAYPAL_MERCHANT_B)), null, '2026-11-01 09:00:00')->outcome());
        $this->assertSame(2, $this->store->counts()['movements']);
    }

    public function test_04_live_and_sandbox_are_isolated(): void {
        $live = F::identity(F::ring('live'));
        $sandbox = F::identity(F::ring('sandbox'));
        $this->assertNotSame($live->movement_id(F::paypal_capture('X1', F::PAYPAL_MERCHANT, 'live'), 1), $sandbox->movement_id(F::paypal_capture('X1', F::PAYPAL_MERCHANT, 'sandbox'), 1));
        try {
            $live->movement_id(F::paypal_capture('X1', F::PAYPAL_MERCHANT, 'sandbox'), 1);
            $this->fail('live key ring derived a sandbox identity');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('environment', $e->getMessage());
        }
        $sandbox_capture = F::paypal_capture('X1', F::PAYPAL_MERCHANT, 'sandbox');
        $this->verifier->put($sandbox_capture, 'captured');
        $o = $this->gate->verify_payment($sandbox_capture);
        $this->assertSame(array(EVC_Payment_Evidence_Outcome::CONFLICT, 'environment_mismatch'), array($o->status(), $o->reason()));
        $this->assertSame(0, $this->verifier->calls, 'out-of-scope ids are never even looked up');
        // Even if an operator reused ONE secret for both environments, ids still differ.
        $shared = F::secret(1);
        $l = new EVC_Payment_Identity(new EVC_Payment_Key_Ring('live', array(1 => $shared), 1, 1, array(1)));
        $s = new EVC_Payment_Identity(new EVC_Payment_Key_Ring('sandbox', array(1 => $shared), 1, 1, array(1)));
        $this->assertNotSame($l->movement_id(F::paypal_capture('X1', F::PAYPAL_MERCHANT, 'live'), 1), $s->movement_id(F::paypal_capture('X1', F::PAYPAL_MERCHANT, 'sandbox'), 1));
        $this->assertNotSame($l->alias_ref(F::wc_order('7', 'live'), 1), $s->alias_ref(F::wc_order('7', 'sandbox'), 1));
        // A merchant account this deployment is not configured for is refused before lookup.
        $unknown = F::paypal_capture('X2', 'PPNOTOURS0001');
        $this->verifier->put($unknown, 'captured');
        $o = $this->gate->verify_payment($unknown);
        $this->assertSame(array(EVC_Payment_Evidence_Outcome::CONFLICT, 'merchant_scope_not_configured'), array($o->status(), $o->reason()));
        $this->assertSame(0, $this->verifier->calls);
    }

    public function test_05_canonical_encoding_is_unambiguous_exact_and_deterministic(): void {
        $this->assertNotSame(EVC_Payment_Identity::encode(array('ab', 'c')), EVC_Payment_Identity::encode(array('a', 'bc')));
        $this->assertSame("\x00\x00\x00\x02ab\x00\x00\x00\x00", EVC_Payment_Identity::encode(array('ab', '')));
        // Known-answer: recompute the documented construction independently.
        $secret = F::secret(1);
        $key = hash_hkdf('sha256', $secret, 32, 'evc/movement/v1/live', 'evangelou-club/payment-identity/hkdf/v1');
        $msg = '';
        foreach (array('evc.payment.movement.v1', 'paypal', F::PAYPAL_MERCHANT, 'live', 'capture', 'CAP-1') as $f) {
            $msg .= pack('N', strlen($f)) . $f;
        }
        $this->assertSame(hash_hmac('sha256', $msg, $key), F::identity()->movement_id(F::paypal_capture('CAP-1'), 1));
        $alias_key = hash_hkdf('sha256', $secret, 32, 'evc/alias/v1/live', 'evangelou-club/payment-identity/hkdf/v1');
        $alias_msg = '';
        foreach (array('evc.payment.alias.v1', 'woocommerce', F::SITE, 'live', 'commerce_order', '1001') as $f) {
            $alias_msg .= pack('N', strlen($f)) . $f;
        }
        $this->assertSame(hash_hmac('sha256', $alias_msg, $alias_key), F::identity()->alias_ref(F::wc_order('1001'), 1));
        $this->assertSame(F::identity()->movement_id(F::paypal_capture('CAP-1'), 1), F::identity()->movement_id(F::paypal_capture('CAP-1'), 1));
        // Exact identifiers: case is significant, whitespace and control characters are refused (never trimmed).
        $this->assertNotSame(F::identity()->movement_id(F::paypal_capture('cap-1'), 1), F::identity()->movement_id(F::paypal_capture('CAP-1'), 1));
        foreach (array(' CAP-1', 'CAP-1 ', "CAP-1\n", 'CAP 1', '', str_repeat('A', 256)) as $bad) {
            try {
                F::paypal_capture($bad);
                $this->fail('accepted identifier ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('never normalised', $e->getMessage());
            }
        }
        // Orders are never identity inputs.
        $this->expectException(InvalidArgumentException::class);
        F::identity()->movement_id(F::wc_order('1001'), 1);
    }

    // ---- 6-8: keys ------------------------------------------------------------------------

    public function test_06_missing_or_wrong_key_fails_closed(): void {
        $bad = array(
            'no keys' => function () { return new EVC_Payment_Key_Ring('live', array(), 1, 1, array(1)); },
            'identity version absent' => function () { return new EVC_Payment_Key_Ring('live', array(2 => F::secret(2)), 1, 2, array(1)); },
            'alias version absent' => function () { return new EVC_Payment_Key_Ring('live', array(1 => F::secret(1)), 1, 2, array(1)); },
            'pinned version not issued' => function () { return new EVC_Payment_Key_Ring('live', array(1 => F::secret(1)), 1, 1, array()); },
            'wrong key for its fingerprint' => function () {
                $fp = F::ring()->fingerprint(1);
                return new EVC_Payment_Key_Ring('live', array(1 => F::secret(9)), 1, 1, array(1), array(1 => $fp));
            },
        );
        foreach ($bad as $label => $make) {
            try {
                $make();
                $this->fail('accepted ' . $label);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
        // The right key with its fingerprint is accepted.
        $ring = F::ring();
        $this->assertInstanceOf(EVC_Payment_Key_Ring::class, new EVC_Payment_Key_Ring('live', array(1 => F::secret(1)), 1, 1, array(1), array(1 => $ring->fingerprint(1))));
        // An unconfigured version is never silently replaced.
        $this->expectException(EVC_Payment_Key_Unavailable_Exception::class);
        F::identity()->movement_id(F::paypal_capture('CAP-1'), 2);
    }

    public function test_07_too_short_or_low_entropy_key_is_refused(): void {
        foreach (array(str_repeat("\x01", 31), str_repeat('k', 64), substr(F::secret(1), 0, 16)) as $weak) {
            try {
                new EVC_Payment_Key_Ring('live', array(1 => $weak), 1, 1, array(1));
                $this->fail('accepted weak key of ' . strlen($weak) . ' bytes');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('too weak', $e->getMessage());
            }
        }
    }

    public function test_08_purposes_are_domain_separated(): void {
        $id = F::identity();
        $capture = F::paypal_capture('CAP-1');
        $refund = F::paypal_refund('CAP-1');
        $outputs = array(
            $id->movement_id($capture, 1),
            $id->alias_ref($capture, 1),
            $id->correction_id($refund, 1),
            $id->alias_ref($refund, 1),
            $id->row_ref(F::SITE, 'CAP-1'),
            $id->ring()->fingerprint(1),
        );
        $this->assertCount(count($outputs), array_unique($outputs), 'no purpose output equals another');
        $ring = $id->ring();
        $this->assertNotSame($ring->mac('movement', 1, 'm'), $ring->mac('alias', 1, 'm'), 'HKDF purpose keys differ');
        // Secrets never leak through debugging or serialization.
        $this->assertStringNotContainsString(bin2hex(F::secret(1)), bin2hex(print_r($ring, true)));
        $this->assertStringNotContainsString('CAP-1', print_r($capture, true));
        $this->expectException(LogicException::class);
        serialize($ring);
    }

    // ---- 9-13: aliases and versions -------------------------------------------------------------

    public function test_09_same_alias_reported_twice_is_idempotent(): void {
        $p = $this->verified($this->captured('CAP-1', '1001'));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->record($p)->outcome());
        $commits = $this->store->commits;
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $this->record($p)->outcome());
        $this->assertSame($commits, $this->store->commits);
        $alias = $this->resolver()->resolve_alias(F::wc_order('1001'));
        $this->assertSame(EVC_Payment_Identity_Resolution::RESOLVED, $alias->status());
        $this->assertSame(F::identity()->movement_id(F::paypal_capture('CAP-1'), 1), $alias->movement_id());
        $this->assertSame(array(EVC_Payment_Identity_Resolution::UNRESOLVED, 'unknown_alias'), array($this->resolver()->resolve_alias(F::wc_order('9999'))->status(), $this->resolver()->resolve_alias(F::wc_order('9999'))->reason()));
        $this->assertSame('namespace_not_in_migration_002', $this->resolver()->resolve_alias(new EVC_Payment_Source_Ref('paypal', F::PAYPAL_MERCHANT, 'live', 'provider_order', 'ORDER-1'))->reason());
    }

    public function test_10_alias_owned_by_another_movement_is_a_conflict(): void {
        $this->record($this->verified($this->captured('CAP-1', '1001')));
        // Another capture claiming the same WooCommerce order.
        $res = $this->resolver()->resolve_payment($this->verified($this->captured('CAP-2', '1001')));
        $this->assertSame(array(EVC_Payment_Identity_Resolution::CONFLICT, 'alias_owned_by_other_movement'), array($res->status(), $res->reason()));
        $this->assertNull($res->movement_id());
        $this->assertSame(1, $this->store->counts()['movements']);
    }

    public function test_11_old_alias_key_version_is_found_and_reused(): void {
        $this->record($this->verified($this->captured('CAP-1', '1001')), F::ring('live', array(1)));
        // Alias key rotated to v2 (v1 retained), identity still pinned to v1.
        $ring = F::ring('live', array(1, 2), 1, 2, array(1));
        $p = $this->verified($this->captured('CAP-1', '1001'));
        $res = $this->resolver($ring)->resolve_payment($p);
        $this->assertSame(EVC_Payment_Identity_Resolution::RESOLVED, $res->status());
        $this->assertTrue($res->existing());
        $this->assertSame(1, $res->alias_version(), 'stored aliases were written with v1');
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $this->record($p, $ring)->outcome(), 'no order_anchor_conflict after rotation');
        $this->assertSame(1, $this->store->counts()['periods']);
    }

    public function test_12_new_aliases_use_the_current_version_and_are_found_with_it(): void {
        $ring = F::ring('live', array(1, 2), 1, 2, array(1));
        $this->record($this->verified($this->captured('CAP-9', '1009')), $ring);
        $found = $this->resolver($ring)->resolve_alias(F::wc_order('1009'));
        $this->assertSame(array(EVC_Payment_Identity_Resolution::RESOLVED, 2), array($found->status(), $found->alias_version()));
        $this->assertSame('unknown_alias', $this->resolver(F::ring('live', array(1)))->resolve_alias(F::wc_order('1009'))->reason(), 'v1-only ring cannot see v2 aliases');
    }

    public function test_13_identity_key_change_never_mints_a_second_id(): void {
        $p = $this->verified($this->captured('CAP-1', '1001'));
        $this->record($p);
        $v1_id = F::identity()->movement_id(F::paypal_capture('CAP-1'), 1);
        // Recovery ring: new pinned identity v2, v1 still retained -> existing v1 id is reused.
        $both = F::ring('live', array(1, 2), 2, 2, array(1, 2));
        $res = $this->resolver($both)->resolve_payment($p);
        $this->assertSame(array(true, $v1_id), array($res->existing(), $res->movement_id()));
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $this->record($p, $both)->outcome());
        // v1 issued but its key is LOST: nothing may be minted (the payment could exist under v1).
        $lost = new EVC_Payment_Key_Ring('live', array(2 => F::secret(2)), 2, 2, array(1, 2));
        $this->assertFalse($lost->identity_history_complete());
        foreach (array($p, $this->verified($this->captured('CAP-NEW', '1002'))) as $any) {
            $r = $this->resolver($lost)->resolve_payment($any);
            $this->assertSame(array(EVC_Payment_Identity_Resolution::UNRESOLVED, 'identity_history_incomplete'), array($r->status(), $r->reason()));
        }
        $this->assertSame(1, $this->store->counts()['periods']);
        // Two identity versions both recorded for one payment: conflict, never a choice.
        $v2_id = F::identity($both)->movement_id(F::paypal_capture('CAP-1'), 2);
        $dup = new EVC_Verified_Movement($v2_id, 'live', 7, 7, EVC_Provenance_Test_Fixture::ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'), 2000, 'EUR',
            array(new EVC_Movement_Alias('manual_receipt', EVC_Provenance_Test_Fixture::ref('rc'))), 'manual_admin', 'manual_confirmation', 'confirmed');
        $this->recorder()->record($dup);
        $this->assertSame('identity_versions_disagree', $this->resolver($both)->resolve_payment($p)->reason());
    }

    // ---- 14-19: refunds -------------------------------------------------------------------------

    public function test_14_refund_before_payment_resolves_to_the_same_movement_and_is_quarantined(): void {
        $capture = F::paypal_capture('CAP-1');
        $this->verifier->put($capture, 'refunded', 2000, array(F::wc_order('1001')));
        $o = $this->gate->verify_refund($this->refund('RF-1', 'CAP-1'));
        $this->assertSame(EVC_Payment_Evidence_Level::R_CORRECTION, $o->level());
        $resolver = $this->resolver();
        $res = $resolver->resolve_refund($o->refund());
        $this->assertSame(F::identity()->movement_id($capture, 1), $res->movement_id(), 'same id the payment will have');
        $this->assertFalse($res->existing());
        $c = $resolver->build_correction($o->refund(), $res, EVC_Pmpro_Fixture::athens('2026-10-20 10:00'));
        $this->assertSame(EVC_Record_Outcome::QUARANTINED, $this->recorder()->correct($c)->outcome());
        // The provider now reports the capture as refunded: never a clean L3 payment.
        $this->assertSame(EVC_Payment_Evidence_Outcome::REQUIRES_RECONCILIATION, $this->gate->verify_payment($capture)->status());
        // Even a stale "captured" read resolves to the quarantined id and is blocked by the recorder.
        $this->verifier->put($capture, 'captured', 2000, array(F::wc_order('1001')));
        $this->assertSame('unresolved_correction', $this->record($this->verified($capture))->reason());
        $this->assertSame(0, $this->store->counts()['periods']);
    }

    public function test_15_refund_after_payment_attaches_to_the_movement(): void {
        $this->record($this->verified($this->captured('CAP-1', '1001')));
        $o = $this->gate->verify_refund($this->refund('RF-1', 'CAP-1'));
        $resolver = $this->resolver();
        $res = $resolver->resolve_refund($o->refund());
        $this->assertTrue($res->existing());
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder()->correct($resolver->build_correction($o->refund(), $res, EVC_Pmpro_Fixture::athens('2026-10-20 10:00')))->outcome());
        $this->assertSame(array('refunded'), array_map(function ($c) { return $c->kind(); }, $this->store->corrections_for($res->movement_id())));
    }

    public function test_16_unknown_parent_is_unresolved_and_manufactures_no_identity(): void {
        $ref = $this->refund('RF-1', 'X', 'refunded', F::PAYPAL_MERCHANT, array());
        $o = $this->gate->verify_refund($ref);
        $this->assertSame(array(EVC_Payment_Evidence_Outcome::UNRESOLVED, 'parent_unknown'), array($o->status(), $o->reason()));
        $this->assertNull($o->refund());
        $this->assertSame(EVC_Payment_Evidence_Level::Q_UNRESOLVED, $o->level());
        // Parent named but its record cannot be fetched: not verified either.
        $ref2 = $this->refund('RF-2', 'CAP-MISSING');
        $this->assertSame(EVC_Payment_Evidence_Outcome::UNAVAILABLE, $this->gate->verify_refund($ref2)->status());
    }

    public function test_17_conflicting_parent_associations_fail_closed(): void {
        $this->verifier->put(F::paypal_capture('CAP-1'), 'captured');
        $this->verifier->put(F::paypal_capture('CAP-2'), 'captured');
        $two = $this->refund('RF-1', 'X', 'refunded', F::PAYPAL_MERCHANT, array(F::paypal_capture('CAP-1'), F::paypal_capture('CAP-2')));
        $this->assertSame('multiple_parents', $this->gate->verify_refund($two)->reason());
        $other_merchant = $this->refund('RF-2', 'X', 'refunded', F::PAYPAL_MERCHANT, array(F::paypal_capture('CAP-1', F::PAYPAL_MERCHANT_B)));
        $this->assertSame('parent_out_of_scope', $this->gate->verify_refund($other_merchant)->reason());
        $this->verifier->put(F::paypal_capture('AUTH-ONLY'), 'authorized');
        $this->assertSame('parent_not_a_capture', $this->gate->verify_refund($this->refund('RF-3', 'AUTH-ONLY'))->reason());
        $order_parent = $this->refund('RF-4', 'X', 'refunded', F::PAYPAL_MERCHANT, array(new EVC_Payment_Source_Ref('paypal', F::PAYPAL_MERCHANT, 'live', 'provider_order', 'O-1')));
        $this->assertSame('parent_out_of_scope', $this->gate->verify_refund($order_parent)->reason());
    }

    public function test_18_duplicate_refund_has_one_correction_identity(): void {
        $this->record($this->verified($this->captured('CAP-1', '1001')));
        $resolver = $this->resolver();
        $first = $resolver->resolve_refund($this->gate->verify_refund($this->refund('RF-1', 'CAP-1'))->refund());
        $this->recorder()->correct($resolver->build_correction($this->gate->verify_refund($this->refund('RF-1', 'CAP-1'))->refund(), $first, EVC_Pmpro_Fixture::athens('2026-10-20 10:00')));
        $again = $resolver->resolve_refund($this->gate->verify_refund($this->refund('RF-1', 'CAP-1'))->refund());
        $this->assertSame($first->correction_id(), $again->correction_id());
        $this->assertTrue($again->correction_existing());
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $this->recorder()->correct($resolver->build_correction($this->gate->verify_refund($this->refund('RF-1', 'CAP-1'))->refund(), $again, EVC_Pmpro_Fixture::athens('2026-10-20 10:00')))->outcome());
        $this->assertSame(1, $this->store->counts()['corrections']);
        // The refund id of another merchant is another correction.
        $this->assertNotSame(F::identity()->correction_id(F::paypal_refund('RF-1'), 1), F::identity()->correction_id(F::paypal_refund('RF-1', F::PAYPAL_MERCHANT_B), 1));
    }

    public function test_19_same_refund_id_with_changed_verified_facts_is_a_conflict(): void {
        $this->record($this->verified($this->captured('CAP-1', '1001')));
        $resolver = $this->resolver();
        $r = $this->gate->verify_refund($this->refund('RF-1', 'CAP-1', 'partially_refunded'))->refund();
        $this->recorder()->correct($resolver->build_correction($r, $resolver->resolve_refund($r), EVC_Pmpro_Fixture::athens('2026-10-20 10:00')));
        // Provider now reports the same refund id as a full refund.
        $changed = $resolver->resolve_refund($this->gate->verify_refund($this->refund('RF-1', 'CAP-1', 'refunded'))->refund());
        $this->assertSame(array(EVC_Payment_Identity_Resolution::CONFLICT, 'refund_facts_changed'), array($changed->status(), $changed->reason()));
        // ...or as belonging to another capture.
        $this->verifier->put(F::paypal_capture('CAP-2'), 'captured');
        $moved = $resolver->resolve_refund($this->gate->verify_refund($this->refund('RF-1', 'CAP-2', 'partially_refunded'))->refund());
        $this->assertSame('refund_facts_changed', $moved->reason());
    }

    // ---- 20-25: trust levels and compatibility ---------------------------------------------------

    public function test_20_provider_unavailable_accepts_nothing(): void {
        $capture = $this->captured('CAP-1', '1001');
        $this->verifier->unavailable = true;
        foreach (array($this->gate->verify_payment($capture), $this->gate->verify_refund($this->refund('RF-1', 'CAP-1')),
            $this->gate->verify_notification(new EVC_Payment_Notification(array($capture), true, 'COMPLETED'))) as $o) {
            $this->assertSame(array(EVC_Payment_Evidence_Outcome::UNAVAILABLE, null, null), array($o->status(), $o->payment(), $o->refund()));
        }
        $failing = new EVC_In_Memory_Provenance_Store();
        $failing->unavailable = true;
        $this->verifier->unavailable = false;
        $res = (new EVC_Payment_Identity_Resolver(F::identity(), $failing))->resolve_payment($this->verified($capture));
        $this->assertSame(EVC_Payment_Identity_Resolution::UNAVAILABLE, $res->status());
        $this->assertSame(0, $this->store->counts()['movements']);
    }

    public function test_21_l0_l1_notifications_cannot_fund_membership(): void {
        $capture = F::paypal_capture('CAP-1');
        $this->verifier->put($capture, 'pending');
        $raw = new EVC_Payment_Notification(array($capture), false, 'COMPLETED');
        $signed = new EVC_Payment_Notification(array($capture), true, 'COMPLETED');
        $this->assertSame(array('L0', 'L1'), array($raw->level(), $signed->level()));
        $this->assertFalse(EVC_Payment_Evidence_Level::funds_membership($raw->level()));
        $this->assertFalse(EVC_Payment_Evidence_Level::funds_membership($signed->level()));
        foreach (array($raw, $signed) as $n) {
            $o = $this->gate->verify_notification($n);
            $this->assertSame(array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'capture_pending'), array($o->status(), $o->reason()), 'claimed status ignored');
        }
        // WooCommerce "completed" with only an order reference is not payment.
        $order_only = $this->gate->verify_notification(new EVC_Payment_Notification(array(F::wc_order('1001')), true, 'completed'));
        $this->assertSame(array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'order_only_evidence'), array($order_only->status(), $order_only->reason()));
        $this->assertSame('not_a_settlement_reference', $this->gate->verify_payment(F::wc_order('1001'))->reason());
        $this->assertSame('no_settlement_reference', $this->gate->verify_notification(new EVC_Payment_Notification(array(), true, 'COMPLETED'))->reason());
        $this->assertSame('multiple_settlement_references', $this->gate->verify_notification(new EVC_Payment_Notification(array($capture, F::paypal_capture('CAP-2')), true, null))->reason());
        // A record assembled by anyone other than the gate's own verifier is refused.
        $forged = new EVC_Provider_Record(new EVC_Fake_Payment_Source_Verifier(), $capture, 'captured', null, 2000, 'EUR', array(F::wc_order('1001')), array());
        try {
            new EVC_Verified_Payment($this->gate, $forged);
            $this->fail('forged record became L3');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified by this gate', $e->getMessage());
        }
        $this->verifier->answer_with = F::paypal_capture('CAP-OTHER');
        $this->verifier->put(F::paypal_capture('CAP-OTHER'), 'captured');
        $this->assertSame('record_identity_mismatch', $this->gate->verify_payment($capture)->reason(), 'a record for another id is refused');
    }

    public function test_22_l2_records_without_captured_money_cannot_fund_membership(): void {
        $cases = array(
            'authorized' => array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'authorization_not_capture'),
            'pending' => array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'capture_pending'),
            'failed' => array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'not_captured'),
            'voided' => array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'not_captured'),
            'unknown' => array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'not_captured'),
            'disputed' => array(EVC_Payment_Evidence_Outcome::REQUIRES_RECONCILIATION, 'captured_then_corrected'),
            'partially_refunded' => array(EVC_Payment_Evidence_Outcome::REQUIRES_RECONCILIATION, 'captured_then_corrected'),
        );
        foreach ($cases as $state => $expected) {
            $ref = F::paypal_capture('CAP-' . $state);
            $this->verifier->put($ref, $state);
            $o = $this->gate->verify_payment($ref);
            $this->assertSame($expected, array($o->status(), $o->reason()), $state);
            $this->assertNull($o->payment());
        }
        $this->verifier->put(F::paypal_capture('CAP-ZERO'), 'captured', 0);
        $this->assertSame('no_money', $this->gate->verify_payment(F::paypal_capture('CAP-ZERO'))->reason());
        $this->verifier->put(F::paypal_refund('RF-P'), 'pending', 2000, array(), array(F::paypal_capture('CAP-captured')), 'refunded');
        $this->assertSame('refund_not_completed', $this->gate->verify_refund(F::paypal_refund('RF-P'))->reason());
    }

    public function test_23_l3_evidence_has_the_verified_shape(): void {
        $p = $this->verified($this->captured('CAP-1', '1001'));
        $this->assertSame(EVC_Payment_Evidence_Level::L3_CAPTURED, $p->level());
        $this->assertTrue(EVC_Payment_Evidence_Level::funds_membership($p->level()));
        $this->assertTrue($p->settlement_ref()->same_as(F::paypal_capture('CAP-1')));
        $this->assertSame(array(2000, 'EUR'), array($p->amount_minor(), $p->currency()));
        $this->assertCount(1, $p->related_refs());
        // Manual receipts are settlement anchors too, through the same gate.
        $this->verifier->put(F::receipt('R-2026-0001'), 'captured');
        $manual = $this->verified(F::receipt('R-2026-0001'));
        $m = $this->resolver()->build_movement($manual, $this->resolver()->resolve_payment($manual), self::USER, 7, EVC_Provenance_Test_Fixture::ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'));
        $this->assertSame(array('manual_admin', 'manual_confirmation'), array($m->authority(), $m->evidence_kind()));
        $this->assertCount(1, $m->order_anchors());
        // Verified, but without a storable order anchor: no movement is built (recorder would refuse too).
        $this->verifier->put(F::viva_capture('9f0c1b2a-viva-tx'), 'captured');
        $viva = $this->verified(F::viva_capture('9f0c1b2a-viva-tx'));
        $this->assertNull($this->resolver()->build_movement($viva, $this->resolver()->resolve_payment($viva), self::USER, 7, EVC_Provenance_Test_Fixture::ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00')));
    }

    public function test_24_multiple_captures_per_order_remain_fail_closed(): void {
        $this->record($this->verified($this->captured('CAP-1', '1001')));
        $second = $this->resolver()->resolve_payment($this->verified($this->captured('CAP-2', '1001')));
        $this->assertSame('alias_owned_by_other_movement', $second->reason());
        // A single capture claiming two commerce orders is refused at the gate.
        $this->verifier->put(F::paypal_capture('CAP-3'), 'captured', 2000, array(F::wc_order('1003'), F::wc_order('1004')));
        $this->assertSame('multiple_order_references', $this->gate->verify_payment(F::paypal_capture('CAP-3'))->reason());
        $this->assertSame(1, $this->store->counts()['movements']);
    }

    public function test_25_identity_core_feeds_the_existing_recorder_and_mapper_unchanged(): void {
        $p = $this->verified($this->captured('CAP-1', '1001'));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->record($p)->outcome());
        $rows = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'));
        $map = function () use ($rows) {
            return EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($this->store, self::USER, $rows), EVC_Pmpro_Fixture::athens('2026-10-20 09:00'));
        };
        $this->assertSame('active', $map()->status());
        $resolver = $this->resolver();
        $r = $this->gate->verify_refund($this->refund('RF-1', 'CAP-1'))->refund();
        $this->recorder()->correct($resolver->build_correction($r, $resolver->resolve_refund($r), EVC_Pmpro_Fixture::athens('2026-10-18 10:00')));
        $this->assertSame('refunded', $map()->status());
        // Resolutions cannot be reused for another payment or refund.
        $other = $this->verified($this->captured('CAP-2', '1002'));
        $this->expectException(InvalidArgumentException::class);
        $resolver->build_movement($other, $resolver->resolve_payment($p), self::USER, 7, EVC_Provenance_Test_Fixture::ref('row1'), EVC_Pmpro_Fixture::athens('2026-11-01 09:00:00'));
    }

    // ---- Task 1D-I.R1-B: evidence provenance ------------------------------------------------

    public function test_r1b_forged_record_naming_the_same_verifier_cannot_become_l3_or_r(): void {
        $capture = $this->captured('CAP-1', '1001');
        // Fabricated in-process, naming the gate's OWN verifier, never fetched by the gate.
        $forged = new EVC_Provider_Record($this->verifier, $capture, 'captured', null, 2000, 'EUR', array(F::wc_order('1001')), array());
        try {
            new EVC_Verified_Payment($this->gate, $forged);
            $this->fail('same-verifier forged record became L3');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified by this gate', $e->getMessage());
        }
        $this->assertFalse($this->gate->redeem_approval($forged, EVC_Payment_Evidence_Gate::APPROVED_PAYMENT));
        $refund = new EVC_Provider_Record($this->verifier, F::paypal_refund('RF-1'), 'completed', 'refunded', 2000, 'EUR', array(), array($capture));
        try {
            new EVC_Verified_Refund($this->gate, $refund, $forged);
            $this->fail('same-verifier forged records became R');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified parent', $e->getMessage());
        }
    }

    public function test_r1b_refused_or_already_used_records_cannot_be_elevated_later(): void {
        // Captured, but refused by the gate (two order references): the very record object it fetched.
        $this->verifier->put(F::paypal_capture('CAP-2'), 'captured', 2000, array(F::wc_order('1'), F::wc_order('2')));
        $this->assertSame('multiple_order_references', $this->gate->verify_payment(F::paypal_capture('CAP-2'))->reason());
        $refused = $this->verifier->last_record;
        $this->assertSame('captured', $refused->state());
        try {
            new EVC_Verified_Payment($this->gate, $refused);
            $this->fail('refused record elevated to L3');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('verified by this gate', $e->getMessage());
        }
        // A genuinely verified record: its approval is one-shot and was consumed by the gate itself.
        $genuine = $this->verified($this->captured('CAP-3', '1003'));
        $this->assertSame(EVC_Payment_Evidence_Level::L3_CAPTURED, $genuine->level());
        $this->assertFalse($this->gate->redeem_approval($this->verifier->last_record, EVC_Payment_Evidence_Gate::APPROVED_PAYMENT), 'approval already consumed');
        $this->expectException(InvalidArgumentException::class);
        new EVC_Verified_Payment($this->gate, $this->verifier->last_record);
    }

    public function test_r1b_genuine_refund_with_verified_parent_is_accepted_and_one_shot(): void {
        $this->verifier->put(F::paypal_capture('CAP-1'), 'captured');
        $o = $this->gate->verify_refund($this->refund('RF-1', 'CAP-1'));
        $this->assertSame(EVC_Payment_Evidence_Outcome::VERIFIED, $o->status());
        $this->assertSame(EVC_Payment_Evidence_Level::R_CORRECTION, $o->refund()->level());
        // Records fetched by ANOTHER gate (same verifier) are not evidence for this gate.
        $other_gate = F::gate($this->verifier);
        $other_gate->verify_payment(F::paypal_capture('CAP-1'));
        $this->expectException(InvalidArgumentException::class);
        new EVC_Verified_Payment($this->gate, $this->verifier->last_record);
    }

    public function test_r1b_replayed_and_untrusted_notifications_never_become_evidence(): void {
        $capture = F::paypal_capture('CAP-1');
        $this->verifier->put($capture, 'pending');
        $n = new EVC_Payment_Notification(array($capture), true, 'PAYMENT.CAPTURE.COMPLETED');
        for ($i = 0; $i < 3; $i++) {
            $o = $this->gate->verify_notification($n);
            $this->assertSame(array(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, null), array($o->status(), $o->payment()));
        }
        // Once the provider record really is captured, a replay is verified again (and
        // the resolver/recorder keep it idempotent); the notification itself proved nothing.
        $this->verifier->put($capture, 'captured', 2000, array(F::wc_order('1001')));
        $this->assertSame(EVC_Payment_Evidence_Outcome::VERIFIED, $this->gate->verify_notification($n)->status());
        $this->assertSame(EVC_Payment_Evidence_Outcome::VERIFIED, $this->gate->verify_notification($n)->status());
        $this->assertSame(4 + 1, $this->verifier->calls, 'every verification fetches from the source again');
    }

    public function test_r1b_no_source_record_substitution(): void {
        $capture = $this->captured('CAP-1', '1001');
        // The configured verifier hands back a record produced by ANOTHER verifier.
        $other = new EVC_Fake_Payment_Source_Verifier();
        $other->put($capture, 'captured', 2000, array(F::wc_order('1001')));
        $this->verifier->delegate = $other;
        $this->assertSame(array(EVC_Payment_Evidence_Outcome::CONFLICT, 'record_identity_mismatch'), array($this->gate->verify_payment($capture)->status(), $this->gate->verify_payment($capture)->reason()));
        $this->verifier->delegate = null;
        // ...or a record for a different identifier.
        $this->verifier->answer_with = F::paypal_capture('CAP-OTHER');
        $this->verifier->put(F::paypal_capture('CAP-OTHER'), 'captured');
        $this->assertSame('record_identity_mismatch', $this->gate->verify_payment($capture)->reason());
    }

    // ---- Task 1D-I.R1: key-version limitations addressed in pure PHP -----------------------------

    public function test_r1_minting_requires_a_pinned_identity_key_fingerprint(): void {
        $p = $this->verified($this->captured('CAP-1', '1001'));
        $unpinned = F::ring('live', array(1), 1, 1, null, false);
        $this->assertFalse($unpinned->identity_key_pinned());
        $r = $this->resolver($unpinned)->resolve_payment($p);
        $this->assertSame(array(EVC_Payment_Identity_Resolution::UNRESOLVED, 'identity_key_unpinned'), array($r->status(), $r->reason()));
        // Lookups of already-recorded payments still work without minting.
        $this->record($p);
        $found = $this->resolver($unpinned)->resolve_payment($p);
        $this->assertSame(array(EVC_Payment_Identity_Resolution::RESOLVED, true), array($found->status(), $found->existing()));
        // Refund ids are not minted either.
        $this->verifier->put(F::paypal_capture('CAP-1'), 'captured');
        $rr = $this->resolver($unpinned)->resolve_refund($this->gate->verify_refund($this->refund('RF-1', 'CAP-1'))->refund());
        $this->assertSame('identity_key_unpinned', $rr->reason());
        $this->assertTrue(F::ring()->identity_key_pinned());
    }

    public function test_r1_row_refs_do_not_drift_when_alias_keys_rotate(): void {
        $before = F::identity(F::ring('live', array(1)))->row_ref(F::SITE, 'pmpro-row-17');
        $alias_rotated = F::identity(F::ring('live', array(1, 2), 1, 2, array(1)));
        $this->assertSame($before, $alias_rotated->row_ref(F::SITE, 'pmpro-row-17'));
        // Identity recovery (pinned v2) keeps the old row ref findable via candidates.
        $recovery = F::identity(F::ring('live', array(1, 2), 2, 2, array(1, 2)));
        $this->assertContains($before, $recovery->row_ref_candidates(F::SITE, 'pmpro-row-17'));
        $this->assertNotSame($before, $recovery->row_ref(F::SITE, 'pmpro-row-17'));
    }

    public function test_no_shipped_code_implements_a_verifier_or_constructs_the_gate(): void {
        foreach (glob(dirname(__DIR__, 2) . '/includes/*.php') as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringNotContainsString('implements EVC_Payment_Source_Verifier', $src, basename($file));
            $this->assertStringNotContainsString('new EVC_Payment_Evidence_Gate', $src, basename($file));
        }
    }
}

class_alias('EVC_Payment_Identity_Fixture', 'F');
