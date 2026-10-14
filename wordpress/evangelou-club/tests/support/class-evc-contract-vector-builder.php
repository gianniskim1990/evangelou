<?php
/**
 * TEST-ONLY (never shipped). Builds the deterministic cross-language
 * contract vectors (tests/fixtures/contract/vectors.json) by driving the REAL
 * services (with fakes, fixed clocks, ids and keys) and mapping their
 * outcomes with the REAL UI-04G response mappers. Negative vectors are
 * derived by mutating those real bodies. The TypeScript test feeds every
 * vector to the strict parsers in app/src/staff/redemptionRecovery/wire.ts.
 *
 * Regenerate: php tests/bin/write-contract-vectors.php
 */
final class EVC_Contract_Vector_Builder {
    const MEMBER_A = 'mem_0123456789abcdef0123456789abcdef';
    const MEMBER_B = 'mem_fedcba9876543210fedcba9876543210';
    const STAFF = 7;
    const SESSION = '0123456789abcdef0123456789abcdef';

    /** @var array[] */
    private $vectors = array();
    /** @var int */
    private $ids = 0;

    public static function build(): array {
        $b = new self();
        $b->redemption_vectors();
        $b->handoff_vectors();
        $b->status_vectors();
        $b->lookup_vectors();
        $b->usual_vectors();
        $b->negative_vectors();
        return array(
            'schema' => 'evc-contract-vectors-v1',
            'note' => 'Generated from the real PHP services and response mappers (UI-04G). Do not edit by hand.',
            'vectors' => $b->vectors,
        );
    }

    /** Canonical file text (stable key order, unescaped, trailing newline). */
    public static function encode(): string {
        return json_encode(self::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    // ---- fixtures --------------------------------------------------------------------------

    private function id(): string {
        $this->ids++;
        return sprintf('00000000-0000-4000-8000-%012x', $this->ids);
    }

    private static function catalogue(): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function order(string $request_id): array {
        return array(
            'request_id' => $request_id,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array('takeaway')),
        );
    }

    private function world(string $now = '2026-10-14T07:30:00Z'): array {
        $store = new EVC_In_Memory_Prepared_Redemption_Store();
        $store->add_member(self::MEMBER_A, 501);
        $store->add_member(self::MEMBER_B, 502);
        $membership = new EVC_Mock_Membership_Adapter();
        $membership->set(501, EVC_Mock_Membership_Adapter::active_until('2026-11-14T07:30:00Z'));
        $membership->set(502, EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-10-01T00:00:00Z'));
        $clock = new EVC_Fixed_Clock($now);
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $authorizer->grants[self::STAFF] = array(EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF, EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS, 'evc_lookup_member', EVC_Usual_Coffee_Service::CAPABILITY);
        return array('store' => $store, 'membership' => $membership, 'clock' => $clock, 'authorizer' => $authorizer, 'authority' => new EVC_Fake_Prep_Catalogue_Authority(self::catalogue()));
    }

    private static function redeemer(array $w, ?string $opt_in = EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS): EVC_Prepared_Redemption_Service {
        return new EVC_Prepared_Redemption_Service($w['store'], $w['authority'], $w['membership'], $w['clock'], $opt_in);
    }

    private static function actor(): EVC_Staff_Actor {
        return new EVC_Staff_Actor(self::STAFF, self::SESSION);
    }

    private function add(string $name, string $surface, array $sent, array $body, array $expect): void {
        $this->vectors[] = array(
            'name' => $name,
            'surface' => $surface,
            'http_status' => EVC_Contract_Http::status($surface, $body),
            'sent' => $sent,
            'body' => $body,
            'expect' => $expect,
        );
    }

    private function redeem_vector(string $name, array $w, string $member, string $request_id, array $expect, ?EVC_Prepared_Redemption_Service $service = null, ?array $body_override = null): array {
        $service = $service ?? self::redeemer($w);
        $outcome = $service->redeem($member, 'free_coffee', self::actor(), $body_override ?? self::order($request_id));
        $body = EVC_Redemption_Response::payload($outcome, $request_id, $w['clock']);
        $this->add($name, 'redemption', array('request_id' => $request_id, 'member_id' => $member), $body, $expect);
        return $body;
    }

    // ---- redemption ------------------------------------------------------------------------

    private function redemption_vectors(): void {
        $w = $this->world();
        $r1 = $this->id();
        $this->redeem_vector('redemption fresh commit', $w, self::MEMBER_A, $r1, array('kind' => 'redeemed', 'instruction' => 'prepare'));
        $this->redeem_vector('redemption exact replay, unconfirmed', $w, self::MEMBER_A, $r1, array('kind' => 'replayed', 'instruction' => 'verify_handoff', 'fulfillment' => 'unconfirmed'));
        $this->redeem_vector('redemption second coffee same day', $w, self::MEMBER_A, $this->id(), array('kind' => 'refused', 'code' => 'benefit_already_redeemed'));
        $this->redeem_vector('redemption request id reused for another member', $w, self::MEMBER_B, $r1, array('kind' => 'refused', 'code' => 'idempotency_key_reused'));
        $this->redeem_vector('redemption expired membership', $w, self::MEMBER_B, $this->id(), array('kind' => 'refused', 'code' => 'membership_inactive'));
        $this->redeem_vector('redemption unknown member', $w, 'mem_11111111111111111111111111111111', $this->id(), array('kind' => 'refused', 'code' => 'member_not_found'));
        $bad = $this->id();
        $this->redeem_vector('redemption invalid coffee', $w, self::MEMBER_A, $bad, array('kind' => 'refused', 'code' => 'invalid_coffee'), null, array_merge(self::order($bad), array('coffee_code' => 'mocha')));
        $this->redeem_vector('redemption catalogue not authorised', $w, self::MEMBER_A, $this->id(), array('kind' => 'refused', 'code' => 'catalogue_not_authorised'), self::redeemer($w, null));

        // Handed over, then replayed the same day.
        $handoffs = new EVC_Handoff_Service($w['store'], $w['authorizer'], new EVC_No_Late_Handoff_Policy(), $w['clock']);
        $handoffs->confirm(self::MEMBER_A, self::actor(), array('handoff_request_id' => $this->id(), 'redemption_request_id' => $r1, 'claim' => 'handed_now'));
        $this->redeem_vector('redemption replay after handoff', $w, self::MEMBER_A, $r1, array('kind' => 'replayed', 'instruction' => 'do_not_prepare', 'fulfillment' => 'confirmed'));

        // The next Athens day.
        $w['clock']->set('2026-10-14T21:00:05Z');
        $this->redeem_vector('redemption replay on a later Athens day', $w, self::MEMBER_A, $r1, array('kind' => 'replayed', 'instruction' => 'do_not_prepare_for_today', 'fulfillment' => 'confirmed'));

        // Unknown commit (did commit), store outage, damaged record.
        $u = $this->world();
        $u['store']->commit_unknown = 'committed';
        $this->redeem_vector('redemption unknown commit', $u, self::MEMBER_A, $this->id(), array('kind' => 'unresolved', 'code' => 'outcome_unknown'));
        $u['store']->unavailable = true;
        $this->redeem_vector('redemption server error', $u, self::MEMBER_A, $this->id(), array('kind' => 'unresolved', 'code' => 'server_error'));
        $i = $this->world();
        $ri = $this->id();
        self::redeemer($i)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($ri));
        $i['store']->tamper_snapshots($ri, array());
        $this->redeem_vector('redemption record integrity failure', $i, self::MEMBER_A, $ri, array('kind' => 'integrity_failure'));
        $malformed = $this->id();
        $this->redeem_vector('redemption malformed request', $i, self::MEMBER_A, $malformed, array('kind' => 'refused', 'code' => 'invalid_request'), null, array('request_id' => $malformed));
    }

    // ---- handoff -------------------------------------------------------------------------------

    private function handoff_vector(string $name, array $w, string $member, string $hid, string $rid, string $claim, array $expect, ?EVC_Handoff_Service $service = null): array {
        $service = $service ?? new EVC_Handoff_Service($w['store'], $w['authorizer'], new EVC_No_Late_Handoff_Policy(), $w['clock']);
        $outcome = $service->confirm($member, self::actor(), array('handoff_request_id' => $hid, 'redemption_request_id' => $rid, 'claim' => $claim));
        $body = EVC_Handoff_Response::payload($outcome, $hid);
        $this->add($name, 'handoff', array('handoff_request_id' => $hid, 'redemption_request_id' => $rid, 'claim' => $claim), $body, $expect);
        return $body;
    }

    private function handoff_vectors(): void {
        $w = $this->world();
        $r1 = $this->id();
        $r2 = $this->id();
        self::redeemer($w)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($r1));
        $w['clock']->set('2026-10-14T07:35:00Z');
        $h1 = $this->id();
        $this->handoff_vector('handoff first confirmation (handed now)', $w, self::MEMBER_A, $h1, $r1, 'handed_now', array('kind' => 'recorded', 'how' => 'confirmed', 'handoff_time' => 'stated_at_recording'));
        $this->handoff_vector('handoff exact replay', $w, self::MEMBER_A, $h1, $r1, 'handed_now', array('kind' => 'recorded', 'how' => 'replayed', 'handoff_time' => 'stated_at_recording'));
        $this->handoff_vector('handoff previously confirmed by another request', $w, self::MEMBER_A, $this->id(), $r1, 'already_handed_earlier', array('kind' => 'recorded', 'how' => 'already_confirmed', 'handoff_time' => 'stated_at_recording'));
        $this->handoff_vector('handoff request id reused with another claim', $w, self::MEMBER_A, $h1, $r1, 'already_handed_earlier', array('kind' => 'refused', 'code' => 'idempotency_key_reused'));
        $this->handoff_vector('handoff of an unknown redemption', $w, self::MEMBER_A, $this->id(), $r2, 'handed_now', array('kind' => 'refused', 'code' => 'redemption_not_found'));
        $this->handoff_vector('handoff of another member\'s redemption', $w, self::MEMBER_B, $this->id(), $r1, 'handed_now', array('kind' => 'refused', 'code' => 'redemption_not_found'));

        $e = $this->world();
        $re = $this->id();
        self::redeemer($e)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($re));
        $this->handoff_vector('handoff already handed earlier (no invented time)', $e, self::MEMBER_A, $this->id(), $re, 'already_handed_earlier', array('kind' => 'recorded', 'how' => 'confirmed', 'handoff_time' => 'not_recorded'));

        $u = $this->world();
        $ru = $this->id();
        self::redeemer($u)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($ru));
        $u['store']->handoff_commit_unknown = 'committed';
        $this->handoff_vector('handoff unknown commit', $u, self::MEMBER_A, $this->id(), $ru, 'handed_now', array('kind' => 'unresolved', 'code' => 'outcome_unknown'));
        $u['clock']->set('2026-10-14T21:00:00Z');
        $u2 = $this->world();
        $r3 = $this->id();
        self::redeemer($u2)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($r3));
        $u2['clock']->set('2026-10-14T21:00:00Z');
        $this->handoff_vector('handoff late confirmation not authorised', $u2, self::MEMBER_A, $this->id(), $r3, 'handed_now', array('kind' => 'refused', 'code' => 'late_confirmation_not_authorised'));
        $u2['store']->tamper_snapshots($r3, array());
        $this->handoff_vector('handoff record integrity failure', $u2, self::MEMBER_A, $this->id(), $r3, 'handed_now', array('kind' => 'integrity_failure'));
        $denied = new EVC_Fake_Club_Staff_Authorizer();
        $this->handoff_vector('handoff forbidden', $u2, self::MEMBER_A, $this->id(), $r3, 'handed_now', array('kind' => 'forbidden'), new EVC_Handoff_Service($u2['store'], $denied, new EVC_No_Late_Handoff_Policy(), $u2['clock']));
        $u2['store']->unavailable = true;
        $this->handoff_vector('handoff server error', $u2, self::MEMBER_A, $this->id(), $r3, 'handed_now', array('kind' => 'unresolved', 'code' => 'server_error'));
    }

    // ---- status ----------------------------------------------------------------------------------

    private function status_vector(string $name, array $w, string $member, string $rid, array $expect, ?EVC_Club_Staff_Authorizer $authorizer = null): array {
        $reader = new EVC_Fulfillment_Read_Model($w['store'], $authorizer ?? $w['authorizer'], $w['clock']);
        $body = EVC_Status_Response::payload($reader->redemption_status(self::actor(), $member, $rid));
        $this->add($name, 'status', array('redemption_request_id' => $rid, 'member_id' => $member), $body, $expect);
        return $body;
    }

    private function status_vectors(): void {
        $w = $this->world();
        $r1 = $this->id();
        self::redeemer($w)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($r1));
        $this->status_vector('status recorded, no handoff confirmation', $w, self::MEMBER_A, $r1, array('kind' => 'found', 'fulfillment' => 'unconfirmed', 'is_current_business_date' => true));
        (new EVC_Handoff_Service($w['store'], $w['authorizer'], new EVC_No_Late_Handoff_Policy(), $w['clock']))->confirm(self::MEMBER_A, self::actor(), array('handoff_request_id' => $this->id(), 'redemption_request_id' => $r1, 'claim' => 'already_handed_earlier'));
        $w['clock']->set('2026-10-15T08:00:00Z');
        $this->status_vector('status confirmed, earlier day', $w, self::MEMBER_A, $r1, array('kind' => 'found', 'fulfillment' => 'confirmed', 'is_current_business_date' => false));
        $this->status_vector('status of another member\'s redemption', $w, self::MEMBER_B, $r1, array('kind' => 'not_found'));
        $this->status_vector('status forbidden', $w, self::MEMBER_A, $r1, array('kind' => 'forbidden'), new EVC_Fake_Club_Staff_Authorizer());
        $w['store']->tamper_snapshots($r1, array());
        $this->status_vector('status record integrity failure', $w, self::MEMBER_A, $r1, array('kind' => 'integrity_failure'));
    }

    // ---- lookup and usual coffee -----------------------------------------------------------------

    private function lookup_vectors(): void {
        $store = new EVC_Fake_Qr_Token_Store();
        $store->add_member(self::MEMBER_A, 501);
        $store->add_member(self::MEMBER_B, 502);
        $clock = new EVC_Fixed_Clock('2026-10-14T07:30:00Z');
        $token = 'evc_' . str_repeat('ab', 24);
        $store->rotate(1, null, hash('sha256', $token), '2026-10-01 09:00:00.000000');
        $phones = new EVC_Fake_Member_Phone_Directory();
        $phones->numbers['+306900000001'] = array(1, 2);
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $authorizer->grants[self::STAFF] = array('evc_lookup_member');
        $limiter = new EVC_Fake_Lookup_Rate_Limiter();
        $make = function (EVC_Club_Staff_Authorizer $a) use ($store, $phones, $limiter, $clock): EVC_Member_Lookup_Service {
            return new EVC_Member_Lookup_Service($store, $phones, $a, $limiter, new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x01", 32)), 'k1'), new EVC_Lookup_Context_Policy(300), EVC_Qr_Token_Policy::without_expiry(), $clock, 'member_view', function () {
                return str_repeat('0', 32);
            });
        };
        $lookup = $make($authorizer);
        $cases = array(
            array('lookup by QR found', $lookup->by_qr(self::actor(), $token), 'found'),
            array('lookup by unknown QR', $lookup->by_qr(self::actor(), 'evc_' . str_repeat('cd', 24)), 'not_found'),
            array('lookup by shared phone', $lookup->by_phone(self::actor(), '6900000001'), 'ambiguous'),
            array('lookup by invalid phone', $lookup->by_phone(self::actor(), '12345'), 'invalid_phone'),
            array('lookup forbidden', $make(new EVC_Fake_Club_Staff_Authorizer())->by_qr(self::actor(), $token), 'forbidden'),
        );
        $limiter->unavailable = true;
        $cases[] = array('lookup rate limiter unavailable', $lookup->by_qr(self::actor(), $token), 'unavailable');
        $limiter->unavailable = false;
        $limiter->max_attempts = 0;
        $cases[] = array('lookup rate limited', $lookup->by_qr(self::actor(), $token), 'rate_limited');
        foreach ($cases as $c) {
            $this->add($c[0], 'lookup', array(), EVC_Lookup_Response::payload($c[1]), array('kind' => $c[2]));
        }
    }

    private function usual_vectors(): void {
        $w = $this->world('2026-10-11T08:00:00Z');
        for ($d = 0; $d < 3; $d++) {
            $w['clock']->set(sprintf('2026-10-%02dT08:00:00Z', 11 + $d));
            self::redeemer($w)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::order($this->id()));
        }
        $w['clock']->set('2026-10-14T08:00:00Z');
        $context = new EVC_Fake_Member_Context_Verifier();
        $context->lookups['lookup-a'] = array(self::STAFF, self::SESSION, self::MEMBER_A);
        $context->lookups['lookup-b'] = array(self::STAFF, self::SESSION, self::MEMBER_B);
        $service = new EVC_Usual_Coffee_Service(new EVC_Fake_Redemption_History($w['store']), $w['authority'], $w['authorizer'], $context, $w['clock'], EVC_Usual_Coffee_Policy::provisional(), EVC_Usual_Coffee_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $this->add('usual coffee suggested', 'usual', array(), EVC_Usual_Coffee_Response::payload($service->suggest(self::actor(), self::MEMBER_A, 'lookup-a')), array('kind' => 'suggested'));
        $this->add('usual coffee insufficient history', 'usual', array(), EVC_Usual_Coffee_Response::payload($service->suggest(self::actor(), self::MEMBER_B, 'lookup-b')), array('kind' => 'no_suggestion'));
        $this->add('usual coffee without lookup context', 'usual', array(), EVC_Usual_Coffee_Response::payload($service->suggest(self::actor(), self::MEMBER_A, null)), array('kind' => 'forbidden'));
    }

    // ---- negative vectors: real bodies, mutated ------------------------------------------------

    private function find(string $name): array {
        foreach ($this->vectors as $v) {
            if ($v['name'] === $name) {
                return $v;
            }
        }
        throw new LogicException($name);
    }

    private function negative(string $name, string $from, callable $mutate): void {
        $v = $this->find($from);
        $v['name'] = $name;
        $v['body'] = $mutate($v['body']);
        $v['expect'] = array('kind' => 'invalid');
        $this->vectors[] = $v;
    }

    private function negative_vectors(): void {
        $fresh = 'redemption fresh commit';
        $replay = 'redemption exact replay, unconfirmed';
        $after = 'redemption replay after handoff';
        $this->negative('NEG redemption wrong request id', $fresh, function ($b) {
            $b['request_id'] = '6f9619ff-8b86-4011-b42d-00c04fc964ff';
            return $b;
        });
        $this->negative('NEG redemption wrong member id', $fresh, function ($b) {
            $b['redemption']['member_id'] = self::MEMBER_B;
            return $b;
        });
        $this->negative('NEG redemption extra field', $fresh, function ($b) {
            $b['note'] = 'x';
            return $b;
        });
        $this->negative('NEG redemption missing field', $fresh, function ($b) {
            unset($b['handoff']);
            return $b;
        });
        $this->negative('NEG replay that says prepare', $replay, function ($b) {
            $b['instruction'] = 'prepare';
            return $b;
        });
        $this->negative('NEG confirmed replay that says verify', $after, function ($b) {
            $b['instruction'] = 'verify_handoff';
            return $b;
        });
        $this->negative('NEG redeemed_at without microseconds', $fresh, function ($b) {
            $b['redemption']['redeemed_at_utc'] = substr($b['redemption']['redeemed_at_utc'], 0, 19);
            return $b;
        });
        $this->negative('NEG redeemed_at on another Athens day', $fresh, function ($b) {
            $b['redemption']['redeemed_at_utc'] = '2026-10-14 21:30:00.000000';
            return $b;
        });
        $this->negative('NEG fresh commit on another server day', $fresh, function ($b) {
            $b['server_business_date'] = '2026-10-15';
            return $b;
        });
        $this->negative('NEG unknown commit with another retry id', 'redemption unknown commit', function ($b) {
            $b['retry_request_id'] = '6f9619ff-8b86-4011-b42d-00c04fc964ff';
            return $b;
        });
        $this->negative('NEG refusal claiming consumption', 'redemption second coffee same day', function ($b) {
            $b['entitlement_consumed'] = true;
            return $b;
        });
        $this->negative('NEG handoff with an invented time for an earlier claim', 'handoff already handed earlier (no invented time)', function ($b) {
            $b['handoff']['handoff_at_utc'] = $b['handoff']['confirmation_recorded_at_utc'];
            return $b;
        });
        $this->negative('NEG already confirmed carrying our own id', 'handoff previously confirmed by another request', function ($b) {
            $b['handoff']['handoff_request_id'] = $b['handoff_request_id'];
            return $b;
        });
        $this->negative('NEG status confirmed without a handoff', 'status recorded, no handoff confirmation', function ($b) {
            $b['data']['fulfillment'] = 'confirmed';
            return $b;
        });
        $this->negative('NEG status with a staff field', 'status recorded, no handoff confirmation', function ($b) {
            $b['data']['staff_wp_user_id'] = 7;
            return $b;
        });
        $this->negative('NEG lookup leaking a phone', 'lookup by QR found', function ($b) {
            $b['member']['phone'] = '6900000001';
            return $b;
        });
        $this->negative('NEG lookup with a forged ref', 'lookup by QR found', function ($b) {
            $b['member']['lookup_ref'] = 'lkp1.k1.forged';
            return $b;
        });
        $this->negative('NEG usual coffee presented as a confirmed order', 'usual coffee suggested', function ($b) {
            $b['confirmed_order'] = true;
            return $b;
        });
    }
}
