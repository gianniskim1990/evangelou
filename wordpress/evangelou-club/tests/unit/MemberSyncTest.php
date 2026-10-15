<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-04F — Club member identity and trusted synchronisation (pure fakes:
 * identity store, WordPress account directory, membership adapter, clock).
 * Signals are hints; identity never follows payment state.
 */
final class MemberSyncTest extends TestCase {
    const CUSTOMER = 501;
    const OTHER_CUSTOMER = 502;
    const STAFF_ACCOUNT = 7;
    const MANAGER = 20;
    const NOW = '2026-10-14T07:30:00Z';

    /** @var EVC_Fake_Member_Identity_Store */
    private $members;
    /** @var EVC_Fake_Wp_Account_Directory */
    private $accounts;
    /** @var EVC_Mock_Membership_Adapter */
    private $membership;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var EVC_Fake_Club_Staff_Authorizer */
    private $authorizer;
    /** @var string[] */
    private $minted = array();
    /** @var array[] */
    private $reports = array();

    protected function setUp(): void {
        $this->members = new EVC_Fake_Member_Identity_Store();
        $this->accounts = new EVC_Fake_Wp_Account_Directory();
        $this->accounts->kinds = array(self::CUSTOMER => 'customer', self::OTHER_CUSTOMER => 'customer', self::STAFF_ACCOUNT => 'restricted', self::MANAGER => 'restricted');
        $this->membership = new EVC_Mock_Membership_Adapter();
        $this->clock = new EVC_Fixed_Clock(self::NOW);
        $this->authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $this->authorizer->grants[self::MANAGER] = array(EVC_Member_Account_Service::CAPABILITY);
        $this->authorizer->grants[self::STAFF_ACCOUNT] = array('evc_lookup_member', 'evc_redeem_benefit', 'evc_view_redemption_history');
        $this->minted = array();
        $this->reports = array();
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function sync_service(?callable $mint = null): EVC_Member_Sync_Service {
        $minted = &$this->minted;
        $reports = &$this->reports;
        return new EVC_Member_Sync_Service($this->members, $this->accounts, $this->membership, $this->clock, $mint ?? function () use (&$minted) {
            $id = EVC_Member_Id::mint();
            $minted[] = $id;
            return $id;
        }, function (array $d) use (&$reports) {
            $reports[] = $d;
        });
    }

    private function sync(int $wp_user = self::CUSTOMER, string $kind = 'membership_changed', string $source = 'pmpro', ?string $hint = null): EVC_Member_Sync_Result {
        return $this->sync_service()->sync(EVC_Member_Sync_Signal::of($source, $kind, $wp_user, $hint));
    }

    private function paid(int $wp_user = self::CUSTOMER, string $until = '2026-11-14T07:30:00Z'): void {
        $this->membership->set($wp_user, EVC_Mock_Membership_Adapter::active_until($until));
    }

    private function accounts_service(): EVC_Member_Account_Service {
        return new EVC_Member_Account_Service($this->members, $this->authorizer, new EVC_Fake_Member_Status_Policy(), $this->clock);
    }

    private static function manager(): EVC_Staff_Actor {
        return new EVC_Staff_Actor(self::MANAGER, 'cccccccccccccccccccccccccccccccc');
    }

    // ---- provisioning ----------------------------------------------------------------------

    public function test_a_verified_eligible_membership_provisions_exactly_one_stable_identity(): void {
        $this->paid();
        $result = $this->sync();
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $result->kind());
        $this->assertMatchesRegularExpression('/^mem_[0-9a-f]{32}$/', $result->member_public_id());
        $this->assertSame('enabled', $result->account_status());
        $this->assertFalse($result->grants_entitlement());
        $this->assertFalse($result->issued_qr());
        $this->assertCount(1, $this->members->all());
        $this->assertSame(self::CUSTOMER, $this->members->all()[0]['wp_user_id']);
        $this->assertSame(array($result->member_public_id()), $this->minted);
    }

    /** @return array<string,array{?callable}> */
    public static function not_paid(): array {
        return array(
            'a WordPress account with no membership' => array(null),
            'checkout/pending payment' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_PENDING_PAYMENT, false, '2026-11-14T07:30:00Z');
            }),
            '"active" without verified payment' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_ACTIVE, false, '2026-11-14T07:30:00Z');
            }),
            'expired' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-10-01T00:00:00Z');
            }),
            'cancelled' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_CANCELLED, true, '2026-11-14T07:30:00Z');
            }),
            'refunded' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_REFUNDED, true, '2026-11-14T07:30:00Z');
            }),
            'payment failed' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_PAYMENT_FAILED, false, null);
            }),
            'indeterminate facts' => array(function () {
                return EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_INDETERMINATE, false, null);
            }),
            'active but already past its expiry instant' => array(function () {
                return EVC_Mock_Membership_Adapter::active_until(self::NOW);
            }),
        );
    }

    /** @dataProvider not_paid */
    public function test_no_identity_without_a_verified_current_paid_membership(?callable $entitlement): void {
        if ($entitlement !== null) {
            $this->membership->set(self::CUSTOMER, $entitlement());
        }
        foreach (EVC_Member_Sync_Signal::KINDS as $kind) {
            $this->assertSame(EVC_Member_Sync_Result::NOT_ELIGIBLE, $this->sync(self::CUSTOMER, $kind)->kind(), $kind);
        }
        $this->assertSame(array(), $this->members->all());
        $this->assertSame(array(), $this->minted);
    }

    public function test_staff_and_privileged_accounts_never_become_members(): void {
        $this->paid(self::STAFF_ACCOUNT);
        $this->assertSame(EVC_Member_Sync_Result::REFUSED_ACCOUNT, $this->sync(self::STAFF_ACCOUNT)->kind());
        $this->assertSame(array(), $this->membership->calls, 'not even consulted');
        $this->paid(999);
        $this->assertSame(EVC_Member_Sync_Result::IDENTITY_UNAVAILABLE, $this->sync(999)->kind(), 'unknown WordPress account');
        $this->accounts->fail = true;
        $this->paid();
        $this->assertSame(EVC_Member_Sync_Result::IDENTITY_UNAVAILABLE, $this->sync()->kind());
        $this->assertSame(array(), $this->members->all());
    }

    public function test_untrusted_or_malformed_signals_are_refused(): void {
        $bad = array(
            array('fluentcrm', 'membership_changed', self::CUSTOMER, null),
            array('newsletter', 'membership_changed', self::CUSTOMER, null),
            array('checkout_page', 'payment_changed', self::CUSTOMER, null),
            array('pmpro', 'import_members', self::CUSTOMER, null),
            array('pmpro', 'membership_changed', 0, null),
            array('pmpro', 'membership_changed', '501', null),
            array('pmpro', 'membership_changed', self::CUSTOMER, 'mem_123'),
        );
        $this->paid();
        foreach ($bad as $b) {
            $this->assertNull(EVC_Member_Sync_Signal::of(...$b), json_encode($b));
            $this->assertSame(EVC_Member_Sync_Result::INVALID_SIGNAL, $this->sync_service()->sync(EVC_Member_Sync_Signal::of(...$b))->kind());
        }
        $this->assertSame(array(), $this->members->all());
        $params = (new ReflectionMethod('EVC_Member_Sync_Signal', 'of'))->getParameters();
        $this->assertSame(array('source', 'kind', 'wp_user_id', 'member_public_id_hint'), array_map(function ($p) {
            return $p->getName();
        }, $params), 'a signal carries no status, amount, date, e-mail or phone');
    }

    public function test_an_unavailable_membership_source_fails_closed(): void {
        $this->membership->set(self::CUSTOMER, new EVC_Membership_Source_Exception('reader down'));
        $this->assertSame(EVC_Member_Sync_Result::MEMBERSHIP_UNAVAILABLE, $this->sync()->kind());
        $this->assertSame(array(), $this->members->all());
        $this->assertSame(array(array('stage' => 'member_sync_membership', 'exception' => 'EVC_Membership_Source_Exception')), $this->reports);
    }

    // ---- identity is not entitlement ---------------------------------------------------------

    public function test_expiry_cancellation_refund_and_renewal_never_touch_the_identity(): void {
        $this->paid();
        $id = $this->sync()->member_public_id();
        $calls = count($this->membership->calls);
        $states = array(
            EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-10-13T00:00:00Z'),
            EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_CANCELLED, true, '2026-11-14T07:30:00Z'),
            EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_REFUNDED, true, '2026-11-14T07:30:00Z'),
            EVC_Mock_Membership_Adapter::active_until('2026-12-14T07:30:00Z'), // early renewal
            EVC_Mock_Membership_Adapter::active_until('2027-01-20T09:00:00Z'), // late renewal
        );
        foreach ($states as $state) {
            $this->membership->set(self::CUSTOMER, $state);
            foreach (EVC_Member_Sync_Signal::KINDS as $kind) {
                $r = $this->sync(self::CUSTOMER, $kind, 'woocommerce');
                $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $r->kind());
                $this->assertSame($id, $r->member_public_id(), 'the same public id forever');
                $this->assertSame('enabled', $r->account_status(), 'payment state never disables an account');
            }
        }
        $this->assertCount(1, $this->members->all());
        $this->assertSame($calls, count($this->membership->calls), 'an existing identity does not even read payment state');
        $this->assertSame(array(), $this->members->status_events);
    }

    public function test_a_manually_disabled_member_stays_disabled_whatever_payment_arrives(): void {
        $this->paid();
        $id = $this->sync()->member_public_id();
        $this->assertSame(EVC_Member_Account_Result::CHANGED, $this->accounts_service()->disable(self::manager(), $id, 'suspected_misuse')->kind());
        $this->paid(self::CUSTOMER, '2027-06-01T00:00:00Z');
        foreach (array('payment_changed', 'membership_changed', 'periodic_check') as $kind) {
            $r = $this->sync(self::CUSTOMER, $kind, 'gateway');
            $this->assertSame(EVC_Member_Sync_Result::MEMBER_DISABLED, $r->kind());
            $this->assertSame('disabled', $r->account_status());
            $this->assertSame($id, $r->member_public_id());
        }
        $this->assertCount(1, $this->members->status_events, 'only the administrator changed the status');
    }

    public function test_duplicate_and_out_of_order_signals_are_harmless(): void {
        // A stale cancellation hint arrives first, but current verified facts say "paid".
        $this->paid();
        $first = $this->sync(self::CUSTOMER, 'cancellation_signal', 'woocommerce');
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $first->kind());
        // The (older) activation hint and a duplicate arrive afterwards.
        foreach (array('membership_changed', 'membership_changed', 'payment_changed') as $kind) {
            $this->assertSame($first->member_public_id(), $this->sync(self::CUSTOMER, $kind)->member_public_id());
        }
        $this->assertCount(1, $this->members->all());
        $this->assertCount(1, $this->minted);
    }

    // ---- races and recovery -------------------------------------------------------------------

    public function test_two_concurrent_creates_for_one_wordpress_user_leave_one_identity(): void {
        $this->paid();
        $other = null;
        $this->members->before_write = function () use (&$other) {
            $other = $this->sync(self::CUSTOMER, 'payment_changed', 'gateway'); // the other worker commits first
        };
        $loser = $this->sync();
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $other->kind());
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $loser->kind());
        $this->assertSame($other->member_public_id(), $loser->member_public_id());
        $this->assertCount(1, $this->members->all());
    }

    public function test_an_uncertain_creation_is_recovered_without_a_second_identity(): void {
        $this->paid();
        $this->members->commit_unknown = 'committed';
        $this->assertSame(EVC_Member_Sync_Result::OUTCOME_UNKNOWN, $this->sync()->kind());
        $stored = $this->members->all()[0]['member_public_id'];
        $retry = $this->sync();
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $retry->kind());
        $this->assertSame($stored, $retry->member_public_id());
        $this->assertCount(1, $this->minted, 'recovery never mints another id');
        $this->assertSame('member_sync_commit_unknown', $this->reports[0]['stage']);

        $this->setUp();
        $this->paid();
        $this->members->commit_unknown = 'not_committed';
        $this->assertSame(EVC_Member_Sync_Result::OUTCOME_UNKNOWN, $this->sync()->kind());
        $this->assertSame(array(), $this->members->all());
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $this->sync()->kind());
        $this->assertCount(1, $this->members->all());
    }

    public function test_a_colliding_or_malformed_public_id_never_creates_a_second_identity(): void {
        $this->paid();
        $this->paid(self::OTHER_CUSTOMER);
        $existing = $this->sync()->member_public_id();
        $collide = $this->sync_service(function () use ($existing) {
            return $existing;
        })->sync(EVC_Member_Sync_Signal::of('pmpro', 'membership_changed', self::OTHER_CUSTOMER));
        $this->assertSame(EVC_Member_Sync_Result::SERVER_ERROR, $collide->kind());
        $this->assertSame(EVC_Member_Sync_Result::SERVER_ERROR, $this->sync_service(function () {
            return 'member-1';
        })->sync(EVC_Member_Sync_Signal::of('pmpro', 'membership_changed', self::OTHER_CUSTOMER))->kind());
        $this->assertCount(1, $this->members->all());
    }

    public function test_cross_member_identity_mismatches_fail_closed(): void {
        $this->paid();
        $this->paid(self::OTHER_CUSTOMER);
        $a = $this->sync()->member_public_id();
        // A system believes OTHER_CUSTOMER owns A's identity: never linked, never created.
        $this->assertSame(EVC_Member_Sync_Result::IDENTITY_CONFLICT, $this->sync(self::OTHER_CUSTOMER, 'membership_changed', 'pmpro', $a)->kind());
        $this->assertCount(1, $this->members->all());
        // A wrong hint for an existing member.
        $this->assertSame(EVC_Member_Sync_Result::IDENTITY_CONFLICT, $this->sync(self::CUSTOMER, 'membership_changed', 'pmpro', 'mem_' . str_repeat('9', 32))->kind());
        // The correct hint is fine.
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $this->sync(self::CUSTOMER, 'membership_changed', 'pmpro', $a)->kind());
    }

    // ---- account status administration --------------------------------------------------------

    public function test_only_an_authorised_administrator_with_a_policy_reason_changes_status(): void {
        $this->paid();
        $id = $this->sync()->member_public_id();
        $service = $this->accounts_service();
        $shared_staff = new EVC_Staff_Actor(self::STAFF_ACCOUNT, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertSame(EVC_Member_Account_Result::FORBIDDEN, $service->disable($shared_staff, $id, 'suspected_misuse')->kind());
        $this->assertSame(EVC_Member_Account_Result::INVALID_REQUEST, $service->disable(self::manager(), $id, 'Free text reason!')->kind());
        $this->assertSame(EVC_Member_Account_Result::REASON_NOT_ALLOWED, $service->disable(self::manager(), $id, 'payment_expired')->kind());
        $this->assertSame(EVC_Member_Account_Result::REASON_NOT_ALLOWED, $service->disable(self::manager(), $id, 'reviewed_by_manager')->kind(), 'an enable reason cannot disable');
        $this->assertSame(EVC_Member_Account_Result::MEMBER_NOT_FOUND, $service->disable(self::manager(), 'mem_' . str_repeat('1', 32), 'suspected_misuse')->kind());
        $this->assertSame(EVC_Member_Account_Result::INVALID_REQUEST, $service->disable(self::manager(), 'mem_1', 'suspected_misuse')->kind());
        $this->assertSame('enabled', $this->members->all()[0]['status']);

        $this->assertSame(EVC_Member_Account_Result::CHANGED, $service->disable(self::manager(), $id, 'suspected_misuse')->kind());
        $this->assertSame(EVC_Member_Account_Result::ALREADY_IN_STATE, $service->disable(self::manager(), $id, 'member_request')->kind());
        $this->assertSame(EVC_Member_Account_Result::REASON_NOT_ALLOWED, $service->enable(self::manager(), $id, 'suspected_misuse')->kind());
        $this->assertSame(EVC_Member_Account_Result::CHANGED, $service->enable(self::manager(), $id, 'reviewed_by_manager')->kind());
        $this->assertSame(array(
            array('member_id' => 1, 'status' => 'disabled', 'reason' => 'suspected_misuse', 'actor' => self::MANAGER, 'at' => '2026-10-14 07:30:00.000000'),
            array('member_id' => 1, 'status' => 'enabled', 'reason' => 'reviewed_by_manager', 'actor' => self::MANAGER, 'at' => '2026-10-14 07:30:00.000000'),
        ), $this->members->status_events);
        $this->assertSame($id, $this->members->all()[0]['member_public_id'], 'status changes never replace the identity');
    }

    public function test_status_changes_are_compare_and_set_and_recoverable(): void {
        $this->paid();
        $id = $this->sync()->member_public_id();
        $service = $this->accounts_service();
        $this->members->before_write = function () use ($service, $id) {
            $service->disable(self::manager(), $id, 'member_request'); // another manager acted first
        };
        $this->assertSame(EVC_Member_Account_Result::CONCURRENT_CHANGE, $service->disable(self::manager(), $id, 'suspected_misuse')->kind());
        $this->assertCount(1, $this->members->status_events);

        $this->members->commit_unknown = 'committed';
        $this->assertSame(EVC_Member_Account_Result::OUTCOME_UNKNOWN, $service->enable(self::manager(), $id, 'reviewed_by_manager')->kind());
        $this->assertSame(EVC_Member_Account_Result::ALREADY_IN_STATE, $service->enable(self::manager(), $id, 'reviewed_by_manager')->kind(), 'retry resolves it');
        $this->members->unavailable = true;
        $this->assertSame(EVC_Member_Account_Result::SERVER_ERROR, $service->disable(self::manager(), $id, 'member_request')->kind());
    }

    public function test_a_disabled_account_fails_closed_for_lookup_and_redemption(): void {
        $this->paid();
        $id = $this->sync()->member_public_id();
        $this->accounts_service()->disable(self::manager(), $id, 'suspected_misuse');
        $status = $this->members->all()[0]['status'];

        // The lookup and redemption stores read the same status value.
        $tokens = new EVC_Fake_Qr_Token_Store();
        $tokens->add_member($id, self::CUSTOMER, $status);
        $token = 'evc_' . str_repeat('ab', 24);
        $tokens->add_member('mem_' . str_repeat('5', 32), 777);
        $authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $authorizer->grants[self::STAFF_ACCOUNT] = array('evc_lookup_member');
        $tokens->rotate(1, null, hash('sha256', $token), '2026-10-14 07:00:00.000000');
        $lookup = new EVC_Member_Lookup_Service($tokens, new EVC_Fake_Member_Phone_Directory(), $authorizer, new EVC_Fake_Lookup_Rate_Limiter(), new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x01", 32)), 'k1'), new EVC_Lookup_Context_Policy(300), EVC_Qr_Token_Policy::without_expiry(), $this->clock, 'member_view');
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $lookup->by_qr(new EVC_Staff_Actor(self::STAFF_ACCOUNT, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $token)->kind());

        $ledger = new EVC_In_Memory_Prepared_Redemption_Store();
        $ledger->add_member($id, self::CUSTOMER, $status);
        $engine = new EVC_Prepared_Redemption_Service($ledger, new EVC_Fake_Prep_Catalogue_Authority(json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR)), $this->membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $outcome = $engine->redeem($id, 'free_coffee', new EVC_Staff_Actor(self::STAFF_ACCOUNT), array(
            'request_id' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()),
        ));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::MEMBER_NOT_FOUND, $outcome->kind(), 'paid, but the account is disabled');
    }

    // ---- QR coordination --------------------------------------------------------------------

    public function test_synchronisation_never_issues_or_rotates_a_qr(): void {
        $this->paid();
        $provisioned = $this->sync();
        $tokens = new EVC_Fake_Qr_Token_Store();
        $tokens->add_member($provisioned->member_public_id(), self::CUSTOMER);
        $policy = new EVC_Fake_Qr_Delivery_Policy();
        $coordinator = new EVC_Member_Qr_Coordinator($tokens, $policy);

        $this->assertSame(EVC_Member_Qr_Coordinator::NO_ACTION, $coordinator->after_sync($provisioned), 'D8 open: no prompt by default');
        $policy->offer = true;
        $this->assertSame(EVC_Member_Qr_Coordinator::OFFER_FIRST_QR, $coordinator->after_sync($provisioned));
        $this->assertSame(array(), $tokens->token_rows(), 'offering is not issuing');

        // The member obtains a QR through the QR service's own authorisation, later.
        $qr = new EVC_Qr_Token_Service($tokens, $this->authorizer, $this->clock);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $qr->issue(EVC_Qr_Issuance_Requester::member_self(self::CUSTOMER), $provisioned->member_public_id())->kind());
        $before = $tokens->token_rows();

        // Renewals and every other signal leave the token untouched.
        $this->paid(self::CUSTOMER, '2027-01-14T07:30:00Z');
        foreach (EVC_Member_Sync_Signal::KINDS as $kind) {
            $r = $this->sync(self::CUSTOMER, $kind, 'gateway');
            $this->assertSame(EVC_Member_Qr_Coordinator::HAS_ACTIVE_QR, $coordinator->after_sync($r));
            $this->assertFalse($r->issued_qr());
        }
        $this->assertSame($before, $tokens->token_rows(), 'never rotated by a renewal');

        $this->assertSame(EVC_Member_Qr_Coordinator::NOT_APPLICABLE, $coordinator->after_sync(EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::NOT_ELIGIBLE)));
        $this->accounts_service()->disable(self::manager(), $provisioned->member_public_id(), 'suspected_misuse');
        $this->assertSame(EVC_Member_Qr_Coordinator::NOT_APPLICABLE, $coordinator->after_sync($this->sync()));
    }

    // ---- privacy and zero legacy -----------------------------------------------------------

    /**
     * Structure-based privacy check (UI-04J follow-up). The previous version
     * searched print_r() for the substring "501", which also matched a
     * RANDOM valid member id that happened to contain those digits
     * (e.g. mem_da9f5e49d708ed217794baa501030f1c; ~0.7 % of ids). The result
     * is now checked by what it EXPOSES: its exact properties and methods,
     * each value against its fixed vocabulary, no WordPress user id as a
     * value, and — with the one legitimate free value (the member id)
     * redacted — no digit or personal-data word anywhere in the dump.
     */
    private function assertMinimalSyncResult(EVC_Member_Sync_Result $result, string $label): void {
        $class = new ReflectionClass($result);
        $props = array_map(function (ReflectionProperty $p) {
            return $p->getName();
        }, $class->getProperties());
        sort($props);
        $this->assertSame(array('account_status', 'kind', 'member_public_id'), $props, $label . ': exposed fields');
        $methods = array();
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $methods[] = ($m->isStatic() ? 'static ' : '') . $m->getName();
        }
        sort($methods);
        $this->assertSame(array('account_status', 'grants_entitlement', 'issued_qr', 'kind', 'member_public_id', 'static member', 'static of'), $methods, $label . ': public surface');

        $values = array();
        foreach ($class->getProperties() as $p) {
            $p->setAccessible(true);
            $values[$p->getName()] = $p->getValue($result);
        }
        $this->assertContains($values['kind'], array_values($class->getConstants()), $label . ': kind vocabulary');
        $id = $values['member_public_id'];
        $this->assertTrue($id === null || (is_string($id) && EVC_Member_Id::is_valid_format($id)), $label . ': member id is an opaque id or null');
        $this->assertContains($values['account_status'], array(null, 'enabled', 'disabled'), $label . ': status vocabulary');
        $this->assertSame($id === null, $values['account_status'] === null, $label . ': identity and status come together');
        foreach ($values as $name => $value) {
            // Whole-value comparison: a WordPress user id is never a field value.
            $this->assertNotSame(self::CUSTOMER, $value, $label . ': ' . $name);
            $this->assertNotSame((string) self::CUSTOMER, $value, $label . ': ' . $name);
        }

        $dump = print_r($result, true);
        if ($id !== null) {
            $this->assertSame(1, substr_count($dump, $id), $label . ': the id appears once');
            $dump = str_replace($id, '<member_public_id>', $dump);
        }
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $dump, $label . ': no number outside the id');
        foreach (array('wp_user', 'mail', 'phone', 'name', 'payment', 'expires') as $absent) {
            $this->assertStringNotContainsString($absent, $dump, $label);
        }
    }

    public function test_outputs_and_reports_are_minimal_and_there_is_no_import_path(): void {
        $this->paid();
        $this->assertMinimalSyncResult($this->sync(), 'provisioned');
        $this->assertMinimalSyncResult($this->sync(), 'already member');
        $this->accounts_service()->disable(self::manager(), $this->members->all()[0]['member_public_id'], 'suspected_misuse');
        $this->assertMinimalSyncResult($this->sync(), 'disabled member');
        foreach (array(
            EVC_Member_Sync_Result::NOT_ELIGIBLE, EVC_Member_Sync_Result::REFUSED_ACCOUNT, EVC_Member_Sync_Result::IDENTITY_UNAVAILABLE,
            EVC_Member_Sync_Result::MEMBERSHIP_UNAVAILABLE, EVC_Member_Sync_Result::IDENTITY_CONFLICT, EVC_Member_Sync_Result::INVALID_SIGNAL,
            EVC_Member_Sync_Result::OUTCOME_UNKNOWN, EVC_Member_Sync_Result::SERVER_ERROR,
        ) as $kind) {
            $this->assertMinimalSyncResult(EVC_Member_Sync_Result::of($kind), $kind);
        }
        $this->setUp();
        $this->members->unavailable = true;
        $this->sync();
        $this->assertSame(array(array('stage' => 'member_sync_sync', 'exception' => 'EVC_Db_Exception')), $this->reports);
        foreach (array('EVC_Member_Sync_Service', 'EVC_Member_Account_Service', 'EVC_Member_Qr_Coordinator') as $class) {
            foreach (get_class_methods($class) as $method) {
                foreach (array('import', 'newsletter', 'fluent', 'consent', 'bulk') as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, strtolower($method), $class . '::' . $method);
                }
            }
        }
        $this->assertNotContains('fluentcrm', EVC_Member_Sync_Signal::SOURCES);
    }

    /** @return array<string,array{string}> valid ids that contain the digits of the test WordPress user id */
    public static function ids_containing_the_wp_user_id(): array {
        return array(
            'in the middle (the CI failure)' => array('mem_da9f5e49d708ed217794baa501030f1c'),
            'at the start' => array('mem_501aaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            'at the end' => array('mem_aaaaaaaaaaaaaaaaaaaaaaaaaaaaa501'),
            'repeated' => array('mem_50150150150150150150150150150150'),
            'the whole id is digits' => array('mem_95015015015015015015015015015015'),
        );
    }

    /** @dataProvider ids_containing_the_wp_user_id */
    public function test_a_valid_member_id_containing_the_wp_user_id_digits_is_not_a_privacy_failure(string $id): void {
        $this->assertTrue(EVC_Member_Id::is_valid_format($id));
        $this->paid();
        $result = $this->sync_service(function () use ($id) {
            return $id;
        })->sync(EVC_Member_Sync_Signal::of('pmpro', 'membership_changed', self::CUSTOMER, null));
        $this->assertSame($id, $result->member_public_id());
        // Precondition: the old substring check really would have failed here.
        $this->assertStringContainsString('501', print_r($result, true));
        $this->assertMinimalSyncResult($result, $id);
        $this->assertSame(self::CUSTOMER, $this->members->all()[0]['wp_user_id'], 'the store keeps the link; the result never exposes it');
    }

    public function test_result_factories_refuse_inconsistent_construction(): void {
        $this->expectException(InvalidArgumentException::class);
        EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::PROVISIONED);
    }
}
