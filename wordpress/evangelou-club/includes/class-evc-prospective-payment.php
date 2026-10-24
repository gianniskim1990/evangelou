<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03B.1: a payment a trusted adapter is ABOUT to have issued by PMPro.
 * Same facts and the same shape rules as EVC_Verified_Movement
 * (EVC_Verified_Movement::assert_payment_fields / alias_map), but:
 *  - NO PMPro row reference: the row does not exist yet, and a placeholder
 *    reference would be a fabricated fact that could later be bound to the
 *    wrong row;
 *  - NO confirmation state: it is input to EVC_Provenance_Recorder::plan()
 *    only. It cannot be passed to record(), correct() or reconcile(), cannot
 *    be stored, and is never a confirmed payment.
 *
 * A recordable movement is built separately once PMPro's row exists;
 * matches() lets the caller prove that movement carries exactly these facts.
 */
final class EVC_Prospective_Payment implements EVC_Payment_Candidate {
    /** @var string */
    private $movement_id;
    /** @var string */
    private $environment;
    /** @var int */
    private $wp_user_id;
    /** @var int */
    private $level_id;
    /** @var DateTimeImmutable */
    private $verified_at_utc;
    /** @var int */
    private $amount_minor;
    /** @var string */
    private $currency;
    /** @var EVC_Movement_Alias[] keyed by alias key */
    private $aliases;
    /** @var string */
    private $authority;
    /** @var string */
    private $evidence_kind;

    /**
     * @param EVC_Movement_Alias[] $aliases
     */
    public function __construct(
        string $movement_id,
        string $environment,
        int $wp_user_id,
        int $level_id,
        DateTimeImmutable $verified_at_utc,
        int $amount_minor,
        string $currency,
        array $aliases,
        string $authority,
        string $evidence_kind
    ) {
        EVC_Verified_Movement::assert_payment_fields($movement_id, $environment, $wp_user_id, $level_id, $verified_at_utc, $amount_minor, $currency, $authority, $evidence_kind);
        $this->movement_id = $movement_id;
        $this->environment = $environment;
        $this->wp_user_id = $wp_user_id;
        $this->level_id = $level_id;
        $this->verified_at_utc = $verified_at_utc;
        $this->amount_minor = $amount_minor;
        $this->currency = $currency;
        $this->aliases = EVC_Verified_Movement::alias_map($aliases);
        $this->authority = $authority;
        $this->evidence_kind = $evidence_kind;
    }

    public function movement_id(): string {
        return $this->movement_id;
    }

    public function environment(): string {
        return $this->environment;
    }

    public function wp_user_id(): int {
        return $this->wp_user_id;
    }

    public function level_id(): int {
        return $this->level_id;
    }

    public function verified_at_utc(): DateTimeImmutable {
        return $this->verified_at_utc;
    }

    public function amount_minor(): int {
        return $this->amount_minor;
    }

    public function currency(): string {
        return $this->currency;
    }

    /** @return EVC_Movement_Alias[] keyed and sorted by alias key */
    public function aliases(): array {
        return $this->aliases;
    }

    /** @return EVC_Movement_Alias[] */
    public function order_anchors(): array {
        return array_values(array_filter($this->aliases, function (EVC_Movement_Alias $a) {
            return $a->is_order_anchor();
        }));
    }

    public function authority(): string {
        return $this->authority;
    }

    public function evidence_kind(): string {
        return $this->evidence_kind;
    }

    /**
     * True when $movement is a CONFIRMED movement with exactly these payment
     * facts and the same single order anchor. Its PMPro row reference is
     * whatever PMPro issued; later verified aliases may have been added.
     */
    public function matches(EVC_Verified_Movement $movement): bool {
        $anchors = $this->order_anchors();
        $theirs = $movement->order_anchors();
        return $movement->state() === EVC_Verified_Movement::STATE_CONFIRMED
            && $movement->movement_id() === $this->movement_id
            && $movement->environment() === $this->environment
            && $movement->wp_user_id() === $this->wp_user_id
            && $movement->level_id() === $this->level_id
            && $movement->verified_at_utc() == $this->verified_at_utc
            && $movement->amount_minor() === $this->amount_minor
            && $movement->currency() === $this->currency
            && $movement->authority() === $this->authority
            && $movement->evidence_kind() === $this->evidence_kind
            && count($anchors) === 1 && count($theirs) === 1
            && $anchors[0]->key() === $theirs[0]->key();
    }
}
