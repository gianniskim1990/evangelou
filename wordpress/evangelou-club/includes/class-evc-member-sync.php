<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-04F — Club member identity and trusted synchronisation core (pure PHP,
 * definitions only, NOT wired: no hooks, no PMPro/WooCommerce/FluentCRM,
 * no SQL, no REST, no UI).
 *
 * Five separate concepts, never conflated:
 *   A. WordPress user           (account; NOT evidence of a paid membership)
 *   B. Club member identity     (evc_members row: stable opaque member_public_id,
 *                                exactly one WP user, never derived from phone/e-mail)
 *   C. Club member account status (enabled | disabled; changed ONLY by an
 *                                authorised administrator with a policy reason)
 *   D. Paid membership period   (EVC_Membership_Adapter / PMPro mapper + verified
 *                                payment provenance; decided at redemption time)
 *   E. Today's free coffee      (the redemption engine; one per Athens day)
 *
 * Synchronisation signals (from WordPress, PMPro, WooCommerce or a gateway)
 * are HINTS that "something may have changed for this WP user". They carry
 * no status, amount or date, and are never payment proof. The service always
 * re-reads current, verified facts from the injected membership adapter, so a
 * duplicate or out-of-order signal is harmless by construction.
 *
 * Rules:
 *  - A member identity is created only when the WP user is an ordinary
 *    customer account AND currently holds a verified, eligible membership
 *    (first eligible activation). Zero legacy paid members: nothing is ever
 *    imported, newsletter/FluentCRM contacts are not a source.
 *  - Creation is idempotent per WP user (unique key); recovery after an
 *    uncertain commit re-reads first and never leaves two identities.
 *  - Expiry, cancellation, refund and renewal never create, replace, disable
 *    or re-enable an identity: the same member_public_id stays forever;
 *    entitlement is re-decided by the redemption engine.
 *  - A disabled account stays disabled whatever payment arrives.
 *  - QR tokens are never issued or rotated by synchronisation (D8 open).
 */
final class EVC_Member_Sync_Signal {
    /** FluentCRM / newsletter sources are deliberately absent: they never imply Club membership. */
    const SOURCES = array('pmpro', 'woocommerce', 'gateway', 'scheduled_reconcile');
    const KINDS = array('membership_changed', 'order_changed', 'payment_changed', 'refund_signal', 'cancellation_signal', 'periodic_check');

    /** @var string */
    private $source;
    /** @var string */
    private $kind;
    /** @var int */
    private $wp_user_id;
    /** @var string|null */
    private $member_public_id_hint;

    private function __construct(string $source, string $kind, int $wp_user_id, ?string $hint) {
        $this->source = $source;
        $this->kind = $kind;
        $this->wp_user_id = $wp_user_id;
        $this->member_public_id_hint = $hint;
    }

    /**
     * @param mixed $source
     * @param mixed $kind
     * @param mixed $wp_user_id
     * @param mixed $member_public_id_hint optional id the signalling system believes belongs to this WP user
     */
    public static function of($source, $kind, $wp_user_id, $member_public_id_hint = null): ?self {
        if (!in_array($source, self::SOURCES, true) || !in_array($kind, self::KINDS, true)
            || !is_int($wp_user_id) || $wp_user_id < 1
            || ($member_public_id_hint !== null && !EVC_Member_Id::is_valid_format($member_public_id_hint))) {
            return null;
        }
        return new self($source, $kind, $wp_user_id, $member_public_id_hint);
    }

    public function source(): string {
        return $this->source;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function wp_user_id(): int {
        return $this->wp_user_id;
    }

    public function member_public_id_hint(): ?string {
        return $this->member_public_id_hint;
    }
}

/** Club member identities (future SQL: evc_members). Only test fakes implement it. */
interface EVC_Member_Identity_Store {
    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string,status_version:int,status_reason:?string}|null */
    public function find_by_wp_user(int $wp_user_id): ?array;

    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string,status_version:int,status_reason:?string}|null */
    public function find_by_public_id(string $member_public_id): ?array;

    /**
     * Inserts an ENABLED identity (status_version 1).
     *
     * @throws EVC_Db_Duplicate_Key_Exception  uq_members_wp_user / uq_members_public_id (rolled back)
     * @throws EVC_Db_Commit_Unknown_Exception COMMIT outcome unknown
     */
    public function create(string $member_public_id, int $wp_user_id, string $now_utc): int;

    /**
     * Compare-and-set of the account status + one status audit event, in one
     * transaction: only if status_version still equals $expected_version.
     *
     * @throws EVC_Member_Status_Conflict      the record changed meanwhile
     * @throws EVC_Db_Commit_Unknown_Exception COMMIT outcome unknown
     */
    public function set_status(int $member_id, int $expected_version, string $status, string $reason, int $actor_wp_user_id, string $now_utc): void;
}

final class EVC_Member_Status_Conflict extends RuntimeException {
}

/** What kind of WordPress account a user is (future: WP user + roles). Only test fakes implement it. */
interface EVC_Wp_Account_Directory {
    const CUSTOMER = 'customer';
    /** Staff, administrators and any privileged account: never a Club member. */
    const RESTRICTED = 'restricted';
    const MISSING = 'missing';

    public function account_kind(int $wp_user_id): string;
}

/** Owner policy for account status reasons (OPEN decision: no default ships). */
interface EVC_Member_Status_Policy {
    /** @return string[] reason codes allowed for disabling */
    public function disable_reasons(): array;

    /** @return string[] reason codes allowed for re-enabling */
    public function enable_reasons(): array;
}

final class EVC_Member_Sync_Result {
    const PROVISIONED = 'provisioned';
    const ALREADY_MEMBER = 'already_member';
    const NOT_ELIGIBLE = 'not_eligible';
    const MEMBER_DISABLED = 'member_disabled';
    const REFUSED_ACCOUNT = 'refused_account';
    const IDENTITY_UNAVAILABLE = 'identity_unavailable';
    const MEMBERSHIP_UNAVAILABLE = 'membership_unavailable';
    const IDENTITY_CONFLICT = 'identity_conflict';
    const INVALID_SIGNAL = 'invalid_signal';
    const OUTCOME_UNKNOWN = 'outcome_unknown';
    const SERVER_ERROR = 'server_error';

    /** @var string */
    private $kind;
    /** @var string|null */
    private $member_public_id;
    /** @var string|null */
    private $account_status;

    private function __construct(string $kind, ?string $member_public_id, ?string $account_status) {
        $this->kind = $kind;
        $this->member_public_id = $member_public_id;
        $this->account_status = $account_status;
    }

    public static function member(string $kind, array $member): self {
        if (!in_array($kind, array(self::PROVISIONED, self::ALREADY_MEMBER, self::MEMBER_DISABLED), true)) {
            throw new InvalidArgumentException('Not a member result.');
        }
        return new self($kind, $member['member_public_id'], $member['status']);
    }

    public static function of(string $kind): self {
        if (in_array($kind, array(self::PROVISIONED, self::ALREADY_MEMBER, self::MEMBER_DISABLED), true)) {
            throw new InvalidArgumentException('A member result needs its member.');
        }
        return new self($kind, null, null);
    }

    public function kind(): string {
        return $this->kind;
    }

    /** Identity only: never a payment or entitlement statement. */
    public function member_public_id(): ?string {
        return $this->member_public_id;
    }

    public function account_status(): ?string {
        return $this->account_status;
    }

    /** Synchronisation never grants a coffee. */
    public function grants_entitlement(): bool {
        return false;
    }

    /** Synchronisation never issues or rotates a QR token (D8 open). */
    public function issued_qr(): bool {
        return false;
    }
}

final class EVC_Member_Sync_Service {
    /** @var EVC_Member_Identity_Store */
    private $members;
    /** @var EVC_Wp_Account_Directory */
    private $accounts;
    /** @var EVC_Membership_Adapter */
    private $membership;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable function (): string member public id */
    private $mint;
    /** @var callable|null */
    private $error_reporter;

    public function __construct(
        EVC_Member_Identity_Store $members,
        EVC_Wp_Account_Directory $accounts,
        EVC_Membership_Adapter $membership,
        EVC_Clock_Source $clock,
        ?callable $mint = null,
        ?callable $error_reporter = null
    ) {
        $this->members = $members;
        $this->accounts = $accounts;
        $this->membership = $membership;
        $this->clock = $clock;
        $this->mint = $mint ?? array('EVC_Member_Id', 'mint');
        $this->error_reporter = $error_reporter;
    }

    /** @param EVC_Member_Sync_Signal|null $signal null = a signal that failed validation */
    public function sync(?EVC_Member_Sync_Signal $signal): EVC_Member_Sync_Result {
        if ($signal === null) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::INVALID_SIGNAL);
        }
        try {
            return $this->sync_or_throw($signal);
        } catch (Throwable $e) {
            $this->report('sync', $e);
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::SERVER_ERROR);
        }
    }

    private function sync_or_throw(EVC_Member_Sync_Signal $signal): EVC_Member_Sync_Result {
        $wp_user_id = $signal->wp_user_id();

        // 1. The WordPress account must be an ordinary customer account.
        try {
            $kind = $this->accounts->account_kind($wp_user_id);
        } catch (Throwable $e) {
            $this->report('account', $e);
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::IDENTITY_UNAVAILABLE);
        }
        if ($kind === EVC_Wp_Account_Directory::RESTRICTED) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::REFUSED_ACCOUNT);
        }
        if ($kind !== EVC_Wp_Account_Directory::CUSTOMER) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::IDENTITY_UNAVAILABLE);
        }

        // 2. An existing identity is never replaced, re-enabled or disabled by a signal.
        $existing = $this->members->find_by_wp_user($wp_user_id);
        if ($existing !== null) {
            return $this->existing($existing, $signal);
        }
        if ($signal->member_public_id_hint() !== null && $this->members->find_by_public_id($signal->member_public_id_hint()) !== null) {
            // The hinted identity belongs to ANOTHER WordPress user.
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::IDENTITY_CONFLICT);
        }

        // 3. First eligible activation: verified, current paid membership only.
        $now = EVC_Clock::to_utc($this->clock->now());
        try {
            $entitlement = $this->membership->entitlement_for($wp_user_id, $now);
        } catch (Throwable $e) {
            $this->report('membership', $e);
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::MEMBERSHIP_UNAVAILABLE);
        }
        if (!$entitlement->is_eligible_at($now) || !$entitlement->payment_verified()) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::NOT_ELIGIBLE);
        }

        // 4. Create exactly one identity for this WP user (unique key decides races).
        $public_id = call_user_func($this->mint);
        if (!EVC_Member_Id::is_valid_format($public_id)) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::SERVER_ERROR);
        }
        try {
            $this->members->create($public_id, $wp_user_id, $now->format('Y-m-d H:i:s.u'));
        } catch (EVC_Db_Duplicate_Key_Exception $e) {
            // A concurrent sync created it first, or (2^-128) the public id collided: re-read.
            $winner = $this->members->find_by_wp_user($wp_user_id);
            return $winner !== null ? $this->existing($winner, $signal) : EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::SERVER_ERROR);
        } catch (EVC_Db_Commit_Unknown_Exception $e) {
            // Recovery = run the sync again: it re-reads by WP user first and never adds a second identity.
            $this->report('commit_unknown', $e);
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::OUTCOME_UNKNOWN);
        }
        $created = $this->members->find_by_wp_user($wp_user_id);
        if ($created === null || $created['member_public_id'] !== $public_id) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::SERVER_ERROR);
        }
        return EVC_Member_Sync_Result::member(EVC_Member_Sync_Result::PROVISIONED, $created);
    }

    private function existing(array $member, EVC_Member_Sync_Signal $signal): EVC_Member_Sync_Result {
        if ($member['wp_user_id'] !== $signal->wp_user_id()
            || ($signal->member_public_id_hint() !== null && $signal->member_public_id_hint() !== $member['member_public_id'])) {
            return EVC_Member_Sync_Result::of(EVC_Member_Sync_Result::IDENTITY_CONFLICT);
        }
        if ($member['status'] !== 'enabled') {
            // Manually disabled: no payment event ever re-enables it.
            return EVC_Member_Sync_Result::member(EVC_Member_Sync_Result::MEMBER_DISABLED, $member);
        }
        return EVC_Member_Sync_Result::member(EVC_Member_Sync_Result::ALREADY_MEMBER, $member);
    }

    /** Stage + exception class only: never a member, user id or payment detail. */
    private function report(string $stage, Throwable $e): void {
        if ($this->error_reporter === null) {
            return;
        }
        try {
            call_user_func($this->error_reporter, array('stage' => 'member_sync_' . $stage, 'exception' => get_class($e)));
        } catch (Throwable $ignored) {
            // never changes the result
        }
    }
}

final class EVC_Member_Account_Result {
    const CHANGED = 'changed';
    const ALREADY_IN_STATE = 'already_in_state';
    const FORBIDDEN = 'forbidden';
    const INVALID_REQUEST = 'invalid_request';
    const REASON_NOT_ALLOWED = 'reason_not_allowed';
    const MEMBER_NOT_FOUND = 'member_not_found';
    const CONCURRENT_CHANGE = 'concurrent_change';
    const OUTCOME_UNKNOWN = 'outcome_unknown';
    const SERVER_ERROR = 'server_error';

    /** @var string */
    private $kind;
    /** @var string|null */
    private $status;

    public function __construct(string $kind, ?string $status = null) {
        $this->kind = $kind;
        $this->status = $status;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function status(): ?string {
        return $this->status;
    }
}

/** Administrative account status changes: the ONLY way to disable or re-enable an identity. */
final class EVC_Member_Account_Service {
    const CAPABILITY = 'evc_manage_club';
    const REASON_PATTERN = '/^[a-z][a-z0-9_]{2,31}$/D';

    /** @var EVC_Member_Identity_Store */
    private $members;
    /** @var EVC_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Member_Status_Policy */
    private $policy;
    /** @var EVC_Clock_Source */
    private $clock;

    public function __construct(EVC_Member_Identity_Store $members, EVC_Club_Staff_Authorizer $authorizer, EVC_Member_Status_Policy $policy, EVC_Clock_Source $clock) {
        $this->members = $members;
        $this->authorizer = $authorizer;
        $this->policy = $policy;
        $this->clock = $clock;
    }

    /** @param mixed $member_public_id @param mixed $reason */
    public function disable(EVC_Staff_Actor $actor, $member_public_id, $reason): EVC_Member_Account_Result {
        return $this->change($actor, $member_public_id, $reason, 'disabled', $this->policy->disable_reasons());
    }

    /** @param mixed $member_public_id @param mixed $reason */
    public function enable(EVC_Staff_Actor $actor, $member_public_id, $reason): EVC_Member_Account_Result {
        return $this->change($actor, $member_public_id, $reason, 'enabled', $this->policy->enable_reasons());
    }

    private function change(EVC_Staff_Actor $actor, $member_public_id, $reason, string $target, array $allowed): EVC_Member_Account_Result {
        if (!$this->authorizer->allows($actor, self::CAPABILITY)) {
            return new EVC_Member_Account_Result(EVC_Member_Account_Result::FORBIDDEN);
        }
        if (!EVC_Member_Id::is_valid_format($member_public_id) || !is_string($reason) || !preg_match(self::REASON_PATTERN, $reason)) {
            return new EVC_Member_Account_Result(EVC_Member_Account_Result::INVALID_REQUEST);
        }
        if (!in_array($reason, $allowed, true)) {
            return new EVC_Member_Account_Result(EVC_Member_Account_Result::REASON_NOT_ALLOWED);
        }
        try {
            $member = $this->members->find_by_public_id($member_public_id);
            if ($member === null) {
                return new EVC_Member_Account_Result(EVC_Member_Account_Result::MEMBER_NOT_FOUND);
            }
            if ($member['status'] === $target) {
                return new EVC_Member_Account_Result(EVC_Member_Account_Result::ALREADY_IN_STATE, $target);
            }
            try {
                $this->members->set_status($member['id'], $member['status_version'], $target, $reason, $actor->wp_user_id(), EVC_Clock::to_utc($this->clock->now())->format('Y-m-d H:i:s.u'));
            } catch (EVC_Member_Status_Conflict $e) {
                return new EVC_Member_Account_Result(EVC_Member_Account_Result::CONCURRENT_CHANGE);
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                // Recovery: repeat the request; it answers already_in_state if it had committed.
                return new EVC_Member_Account_Result(EVC_Member_Account_Result::OUTCOME_UNKNOWN);
            }
            return new EVC_Member_Account_Result(EVC_Member_Account_Result::CHANGED, $target);
        } catch (Throwable $e) {
            return new EVC_Member_Account_Result(EVC_Member_Account_Result::SERVER_ERROR);
        }
    }
}

/**
 * QR coordination (UI-04C service, D8 delivery OPEN). Synchronisation never
 * issues or rotates a token. This read-only helper only tells whether an
 * enabled member currently has no active token, i.e. could obtain a first
 * QR through EVC_Qr_Token_Service under its own authorisation (member_self or
 * an administrator). Whether and how to prompt/deliver is a policy decision.
 */
interface EVC_Qr_Delivery_Policy {
    /** True only if the owner approved prompting a newly provisioned member for a first QR. */
    public function offer_first_qr_after_provisioning(): bool;
}

final class EVC_Member_Qr_Coordinator {
    const NO_ACTION = 'no_action';
    const OFFER_FIRST_QR = 'offer_first_qr';
    const HAS_ACTIVE_QR = 'has_active_qr';
    const NOT_APPLICABLE = 'not_applicable';

    /** @var EVC_Qr_Token_Store */
    private $tokens;
    /** @var EVC_Qr_Delivery_Policy */
    private $policy;

    public function __construct(EVC_Qr_Token_Store $tokens, EVC_Qr_Delivery_Policy $policy) {
        $this->tokens = $tokens;
        $this->policy = $policy;
    }

    /**
     * What may follow a sync result. Never issues, rotates or revokes; never
     * says anything about payment.
     */
    public function after_sync(EVC_Member_Sync_Result $result): string {
        if ($result->kind() !== EVC_Member_Sync_Result::PROVISIONED && $result->kind() !== EVC_Member_Sync_Result::ALREADY_MEMBER) {
            return self::NOT_APPLICABLE;
        }
        $member = $this->tokens->find_member_by_public_id((string) $result->member_public_id());
        if ($member === null || $member['status'] !== 'enabled') {
            return self::NOT_APPLICABLE;
        }
        if ($this->tokens->active_token_hash($member['id']) !== null) {
            return self::HAS_ACTIVE_QR;
        }
        return $result->kind() === EVC_Member_Sync_Result::PROVISIONED && $this->policy->offer_first_qr_after_provisioning()
            ? self::OFFER_FIRST_QR
            : self::NO_ACTION;
    }
}
