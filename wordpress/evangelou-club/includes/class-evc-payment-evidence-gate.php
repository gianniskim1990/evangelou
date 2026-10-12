<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Trusted payment-source boundary (Task 1D-I). NOT WIRED: the plugin ships
 * no verifier class and never builds this gate (the packager rejects both).
 * Future, version-pinned provider adapters will provide them.
 *
 * Evidence levels (EVC_Payment_Evidence_Level):
 *   L0  raw untrusted notification (webhook body, redirect, browser input)
 *   L1  authenticated event (e.g. verified signature) -- still NOT payment
 *   L2  provider record fetched server-side by the configured verifier
 *   L3  L2 record of a CAPTURED money movement (or authorised Club receipt)
 *   R   L2 refund/void/reversal whose verified parent is a capture
 *   Q   unknown, conflicting or unavailable evidence
 * Only L3 can ever fund a period, and only through
 * EVC_Payment_Identity_Resolver + EVC_Provenance_Recorder (which still
 * apply every existing rule). L0/L1 contribute identifiers to look up,
 * never a status: a notification's claimed status is ignored, and there is
 * no flag or string a caller can set to obtain L3.
 */
final class EVC_Payment_Evidence_Level {
    const L0_RAW = 'L0';
    const L1_AUTHENTICATED = 'L1';
    const L2_PROVIDER_RECORD = 'L2';
    const L3_CAPTURED = 'L3';
    const R_CORRECTION = 'R';
    const Q_UNRESOLVED = 'Q';

    public static function funds_membership(string $level): bool {
        return $level === self::L3_CAPTURED;
    }
}

/**
 * Server-side provider lookup implemented by FUTURE adapters (PayPal,
 * Viva, Club receipt authority) with merchant credentials from trusted
 * configuration. It must fetch EXACTLY the requested identifier from the
 * provider (never echo request data) and return its current state, or throw.
 */
interface EVC_Payment_Source_Verifier {
    /** @throws EVC_Payment_Source_Unavailable_Exception when the provider cannot be reached */
    public function fetch(EVC_Payment_Source_Ref $ref): EVC_Provider_Record;
}

/** The provider/source could not be queried; nothing may be accepted. */
final class EVC_Payment_Source_Unavailable_Exception extends RuntimeException {
}

/**
 * L2: a provider record as returned by a verifier. It names the verifier
 * instance that fetched it; the gate only accepts records fetched by ITS
 * configured verifier, so a record assembled from request data elsewhere is
 * refused. States are the adapter's normalisation of the provider record:
 *   money:   captured | pending | authorized | failed | voided |
 *            refunded | partially_refunded | reversed | disputed | unknown
 *   refunds: completed | pending | failed (correction_kind says what it is)
 */
final class EVC_Provider_Record {
    const STATE_CAPTURED = 'captured';
    const STATE_PENDING = 'pending';
    const STATE_AUTHORIZED = 'authorized';
    const STATE_FAILED = 'failed';
    const STATE_VOIDED = 'voided';
    const STATE_REFUNDED = 'refunded';
    const STATE_PARTIALLY_REFUNDED = 'partially_refunded';
    const STATE_REVERSED = 'reversed';
    const STATE_DISPUTED = 'disputed';
    const STATE_UNKNOWN = 'unknown';
    const STATE_COMPLETED = 'completed';

    /** @var EVC_Payment_Source_Verifier */
    private $fetched_by;
    /** @var EVC_Payment_Source_Ref */
    private $ref;
    /** @var string */
    private $state;
    /** @var string|null */
    private $correction_kind;
    /** @var int */
    private $amount_minor;
    /** @var string */
    private $currency;
    /** @var EVC_Payment_Source_Ref[] */
    private $related;
    /** @var EVC_Payment_Source_Ref[] */
    private $parents;

    /**
     * @param EVC_Payment_Source_Ref[] $related verified related identifiers (orders) as reported by the provider record
     * @param EVC_Payment_Source_Ref[] $parents verified parent money transactions (refund records only)
     */
    public function __construct(EVC_Payment_Source_Verifier $fetched_by, EVC_Payment_Source_Ref $ref, string $state, ?string $correction_kind, int $amount_minor, string $currency, array $related, array $parents) {
        $states = $ref->kind() === EVC_Payment_Source_Ref::KIND_REFUND
            ? array(self::STATE_COMPLETED, self::STATE_PENDING, self::STATE_FAILED)
            : array(self::STATE_CAPTURED, self::STATE_PENDING, self::STATE_AUTHORIZED, self::STATE_FAILED, self::STATE_VOIDED, self::STATE_REFUNDED,
                self::STATE_PARTIALLY_REFUNDED, self::STATE_REVERSED, self::STATE_DISPUTED, self::STATE_UNKNOWN);
        if (!in_array($state, $states, true)) {
            throw new InvalidArgumentException('Unknown provider record state for this identifier kind.');
        }
        if (($ref->kind() === EVC_Payment_Source_Ref::KIND_REFUND) !== ($correction_kind !== null)
            || ($correction_kind !== null && !in_array($correction_kind, EVC_Movement_Correction::kinds(), true))) {
            throw new InvalidArgumentException('Only refund records carry a correction kind.');
        }
        if ($amount_minor < 0 || !preg_match(EVC_Verified_Movement::CURRENCY_PATTERN, $currency)) {
            throw new InvalidArgumentException('Invalid record amount or currency.');
        }
        foreach (array_merge($related, $parents) as $r) {
            if (!$r instanceof EVC_Payment_Source_Ref) {
                throw new InvalidArgumentException('Related and parent identifiers must be source refs.');
            }
        }
        $this->fetched_by = $fetched_by;
        $this->ref = $ref;
        $this->state = $state;
        $this->correction_kind = $correction_kind;
        $this->amount_minor = $amount_minor;
        $this->currency = $currency;
        $this->related = array_values($related);
        $this->parents = array_values($parents);
    }

    public function fetched_by(): EVC_Payment_Source_Verifier {
        return $this->fetched_by;
    }

    public function ref(): EVC_Payment_Source_Ref {
        return $this->ref;
    }

    public function state(): string {
        return $this->state;
    }

    public function correction_kind(): ?string {
        return $this->correction_kind;
    }

    public function amount_minor(): int {
        return $this->amount_minor;
    }

    public function currency(): string {
        return $this->currency;
    }

    /** @return EVC_Payment_Source_Ref[] */
    public function related(): array {
        return $this->related;
    }

    /** @return EVC_Payment_Source_Ref[] */
    public function parents(): array {
        return $this->parents;
    }
}

/**
 * L0/L1 input: identifiers a notification CLAIMS, plus whether its
 * transport was authenticated. It can only ever ask the gate to look those
 * identifiers up; its claimed status is recorded for diagnostics and never
 * used.
 */
final class EVC_Payment_Notification {
    /** @var EVC_Payment_Source_Ref[] */
    private $claimed_refs;
    /** @var bool */
    private $authenticated;
    /** @var string|null */
    private $claimed_status;

    /** @param EVC_Payment_Source_Ref[] $claimed_refs */
    public function __construct(array $claimed_refs, bool $authenticated, ?string $claimed_status) {
        foreach ($claimed_refs as $r) {
            if (!$r instanceof EVC_Payment_Source_Ref) {
                throw new InvalidArgumentException('Claimed identifiers must be source refs.');
            }
        }
        $this->claimed_refs = array_values($claimed_refs);
        $this->authenticated = $authenticated;
        $this->claimed_status = $claimed_status;
    }

    public function level(): string {
        return $this->authenticated ? EVC_Payment_Evidence_Level::L1_AUTHENTICATED : EVC_Payment_Evidence_Level::L0_RAW;
    }

    /** @return EVC_Payment_Source_Ref[] */
    public function claimed_refs(): array {
        return $this->claimed_refs;
    }

    /** Diagnostic only: never evidence of payment. */
    public function claimed_status(): ?string {
        return $this->claimed_status;
    }
}

/** L3: a captured money movement vouched for by the gate's own verifier. */
final class EVC_Verified_Payment {
    /** @var EVC_Provider_Record */
    private $record;

    /** @internal issued by EVC_Payment_Evidence_Gate only */
    public function __construct(EVC_Payment_Evidence_Gate $issuer, EVC_Provider_Record $record) {
        if (!$issuer->vouches_for($record) || !$record->ref()->is_settlement_anchor()
            || $record->state() !== EVC_Provider_Record::STATE_CAPTURED || $record->amount_minor() < 1) {
            throw new InvalidArgumentException('Not a captured money movement verified by this gate.');
        }
        $this->record = $record;
    }

    public function level(): string {
        return EVC_Payment_Evidence_Level::L3_CAPTURED;
    }

    public function settlement_ref(): EVC_Payment_Source_Ref {
        return $this->record->ref();
    }

    public function amount_minor(): int {
        return $this->record->amount_minor();
    }

    public function currency(): string {
        return $this->record->currency();
    }

    /** @return EVC_Payment_Source_Ref[] verified related identifiers (orders) */
    public function related_refs(): array {
        return $this->record->related();
    }
}

/** R: a completed refund/void/reversal whose single parent is a verified capture. */
final class EVC_Verified_Refund {
    /** @var EVC_Provider_Record */
    private $refund;
    /** @var EVC_Provider_Record */
    private $parent;

    /** @internal issued by EVC_Payment_Evidence_Gate only */
    public function __construct(EVC_Payment_Evidence_Gate $issuer, EVC_Provider_Record $refund, EVC_Provider_Record $parent) {
        if (!$issuer->vouches_for($refund) || !$issuer->vouches_for($parent)
            || $refund->ref()->kind() !== EVC_Payment_Source_Ref::KIND_REFUND || $refund->state() !== EVC_Provider_Record::STATE_COMPLETED
            || count($refund->parents()) !== 1 || !$refund->parents()[0]->same_as($parent->ref()) || !$parent->ref()->is_settlement_anchor()) {
            throw new InvalidArgumentException('Not a completed refund with a verified parent capture.');
        }
        $this->refund = $refund;
        $this->parent = $parent;
    }

    public function level(): string {
        return EVC_Payment_Evidence_Level::R_CORRECTION;
    }

    public function refund_ref(): EVC_Payment_Source_Ref {
        return $this->refund->ref();
    }

    public function parent_settlement_ref(): EVC_Payment_Source_Ref {
        return $this->parent->ref();
    }

    /** EVC_Movement_Correction::KIND_* */
    public function kind(): string {
        return (string) $this->refund->correction_kind();
    }

    public function amount_minor(): int {
        return $this->refund->amount_minor();
    }
}

/** Result of a gate check. Only VERIFIED carries evidence; everything else grants nothing. */
final class EVC_Payment_Evidence_Outcome {
    const VERIFIED = 'verified';
    const NOT_ACCEPTED = 'not_accepted';
    const UNRESOLVED = 'unresolved';
    const CONFLICT = 'conflict';
    const UNAVAILABLE = 'unavailable';
    const REQUIRES_RECONCILIATION = 'requires_reconciliation';

    /** @var string */
    private $status;
    /** @var string|null */
    private $reason;
    /** @var EVC_Verified_Payment|EVC_Verified_Refund|null */
    private $evidence;

    private function __construct(string $status, ?string $reason, $evidence) {
        $this->status = $status;
        $this->reason = $reason;
        $this->evidence = $evidence;
    }

    /** @param EVC_Verified_Payment|EVC_Verified_Refund $evidence */
    public static function verified($evidence): self {
        return new self(self::VERIFIED, null, $evidence);
    }

    public static function refused(string $status, string $reason): self {
        if ($status === self::VERIFIED) {
            throw new InvalidArgumentException('A refusal cannot be verified.');
        }
        return new self($status, $reason, null);
    }

    public function status(): string {
        return $this->status;
    }

    public function reason(): ?string {
        return $this->reason;
    }

    public function level(): string {
        return $this->evidence === null ? EVC_Payment_Evidence_Level::Q_UNRESOLVED : $this->evidence->level();
    }

    public function payment(): ?EVC_Verified_Payment {
        return $this->evidence instanceof EVC_Verified_Payment ? $this->evidence : null;
    }

    public function refund(): ?EVC_Verified_Refund {
        return $this->evidence instanceof EVC_Verified_Refund ? $this->evidence : null;
    }
}

final class EVC_Payment_Evidence_Gate {
    /** @var EVC_Payment_Source_Verifier */
    private $verifier;
    /** @var string */
    private $environment;
    /** @var array<string,string[]> provider => merchant/site scopes this deployment is configured for */
    private $scopes;

    /** @param array<string,string[]> $scopes from trusted configuration only (never request data) */
    public function __construct(EVC_Payment_Source_Verifier $verifier, string $environment, array $scopes) {
        if (!preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $environment)) {
            throw new InvalidArgumentException('Invalid gate environment.');
        }
        foreach ($scopes as $provider => $list) {
            if (!isset(EVC_Payment_Source_Ref::kinds_by_provider()[$provider]) || !is_array($list) || $list === array()) {
                throw new InvalidArgumentException('Invalid scope configuration.');
            }
        }
        $this->verifier = $verifier;
        $this->environment = $environment;
        $this->scopes = $scopes;
    }

    /** True only for records fetched by THIS gate's configured verifier. */
    public function vouches_for(EVC_Provider_Record $record): bool {
        return $record->fetched_by() === $this->verifier;
    }

    /** L0/L1 -> lookup of the single claimed settlement anchor; the claimed status is ignored. */
    public function verify_notification(EVC_Payment_Notification $n): EVC_Payment_Evidence_Outcome {
        $anchors = array();
        $orders = 0;
        foreach ($n->claimed_refs() as $ref) {
            if ($ref->is_settlement_anchor()) {
                $anchors[] = $ref;
            } elseif ($ref->kind() === EVC_Payment_Source_Ref::KIND_COMMERCE_ORDER || $ref->kind() === EVC_Payment_Source_Ref::KIND_PROVIDER_ORDER) {
                $orders++;
            }
        }
        if (count($anchors) > 1) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'multiple_settlement_references');
        }
        if ($anchors === array()) {
            return EVC_Payment_Evidence_Outcome::refused(
                $orders > 0 ? EVC_Payment_Evidence_Outcome::NOT_ACCEPTED : EVC_Payment_Evidence_Outcome::UNRESOLVED,
                $orders > 0 ? 'order_only_evidence' : 'no_settlement_reference'
            );
        }
        return $this->verify_payment($anchors[0]);
    }

    /** Server-side verification of one settlement anchor (capture / Club receipt). */
    public function verify_payment(EVC_Payment_Source_Ref $settlement): EVC_Payment_Evidence_Outcome {
        if (!$settlement->is_settlement_anchor()) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'not_a_settlement_reference');
        }
        $scope = $this->scope_problem($settlement);
        if ($scope !== null) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, $scope);
        }
        $record = $this->fetch($settlement);
        if (!$record instanceof EVC_Provider_Record) {
            return $record;
        }
        switch ($record->state()) {
            case EVC_Provider_Record::STATE_CAPTURED:
                break;
            case EVC_Provider_Record::STATE_AUTHORIZED:
                return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'authorization_not_capture');
            case EVC_Provider_Record::STATE_PENDING:
                return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'capture_pending');
            case EVC_Provider_Record::STATE_REFUNDED:
            case EVC_Provider_Record::STATE_PARTIALLY_REFUNDED:
            case EVC_Provider_Record::STATE_REVERSED:
            case EVC_Provider_Record::STATE_DISPUTED:
                // Money moved but was corrected: record the correction first
                // (refund-first quarantine) and reconcile; never record clean.
                return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::REQUIRES_RECONCILIATION, 'captured_then_corrected');
            default:
                return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'not_captured');
        }
        if ($record->amount_minor() < 1) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'no_money');
        }
        $orders = 0;
        foreach ($record->related() as $related) {
            if ($related->is_settlement_anchor() || $related->kind() === EVC_Payment_Source_Ref::KIND_REFUND || $this->scope_problem($related) !== null) {
                return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'related_reference_out_of_scope');
            }
            if ($related->kind() === EVC_Payment_Source_Ref::KIND_COMMERCE_ORDER) {
                $orders++;
            }
        }
        if ($orders > 1) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'multiple_order_references');
        }
        return EVC_Payment_Evidence_Outcome::verified(new EVC_Verified_Payment($this, $record));
    }

    /** Server-side verification of a refund and of its single parent capture. */
    public function verify_refund(EVC_Payment_Source_Ref $refund): EVC_Payment_Evidence_Outcome {
        if ($refund->kind() !== EVC_Payment_Source_Ref::KIND_REFUND) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'not_a_refund_reference');
        }
        $scope = $this->scope_problem($refund);
        if ($scope !== null) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, $scope);
        }
        $record = $this->fetch($refund);
        if (!$record instanceof EVC_Provider_Record) {
            return $record;
        }
        if ($record->state() !== EVC_Provider_Record::STATE_COMPLETED) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::NOT_ACCEPTED, 'refund_not_completed');
        }
        $parents = $record->parents();
        if ($parents === array()) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::UNRESOLVED, 'parent_unknown');
        }
        if (count($parents) > 1) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'multiple_parents');
        }
        $parent_ref = $parents[0];
        if (!$parent_ref->is_settlement_anchor() || $parent_ref->provider() !== $refund->provider()
            || $parent_ref->merchant_scope() !== $refund->merchant_scope() || $parent_ref->environment() !== $refund->environment()) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'parent_out_of_scope');
        }
        $parent = $this->fetch($parent_ref);
        if (!$parent instanceof EVC_Provider_Record) {
            return $parent;
        }
        if (!in_array($parent->state(), array(EVC_Provider_Record::STATE_CAPTURED, EVC_Provider_Record::STATE_REFUNDED, EVC_Provider_Record::STATE_PARTIALLY_REFUNDED,
            EVC_Provider_Record::STATE_REVERSED, EVC_Provider_Record::STATE_DISPUTED), true)) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'parent_not_a_capture');
        }
        return EVC_Payment_Evidence_Outcome::verified(new EVC_Verified_Refund($this, $record, $parent));
    }

    /** @return EVC_Provider_Record|EVC_Payment_Evidence_Outcome */
    private function fetch(EVC_Payment_Source_Ref $ref) {
        try {
            $record = $this->verifier->fetch($ref);
        } catch (Throwable $e) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::UNAVAILABLE, 'source_unavailable');
        }
        if (!$this->vouches_for($record) || !$record->ref()->same_as($ref)) {
            return EVC_Payment_Evidence_Outcome::refused(EVC_Payment_Evidence_Outcome::CONFLICT, 'record_identity_mismatch');
        }
        return $record;
    }

    private function scope_problem(EVC_Payment_Source_Ref $ref): ?string {
        if ($ref->environment() !== $this->environment) {
            return 'environment_mismatch';
        }
        if (!in_array($ref->merchant_scope(), $this->scopes[$ref->provider()] ?? array(), true)) {
            return 'merchant_scope_not_configured';
        }
        return null;
    }
}
