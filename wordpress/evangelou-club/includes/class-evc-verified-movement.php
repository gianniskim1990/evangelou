<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Immutable fact: ONE real money movement as reported by a FUTURE trusted
 * verification adapter (gateway API re-check, verified order state or an
 * authorised manual confirmation). Task 1D-E.
 *
 * This class does NOT verify anything and there is deliberately no
 * production code that builds it from a webhook or a browser request. It
 * only enforces shape:
 *  - movement_id: canonical, source-independent identity (opaque 64-hex);
 *    the adapter must derive it the same way whichever signal arrives first;
 *  - aliases: the verified source identifiers; exactly one ORDER anchor;
 *  - verified_at: an explicit instant chosen by the adapter. Which instant
 *    (gateway settlement vs later server verification) is owner decision O1,
 *    still OPEN. It must be a whole-second UTC instant: PMPro stores whole
 *    seconds, so a fractional instant could never reconcile, and it is
 *    REJECTED here rather than silently rounded;
 *  - state: only "confirmed" can fund a period; pending/failed/unknown exist
 *    so an adapter can report them without them ever being recorded.
 * No names, e-mails, phones, card data or raw gateway/order ids.
 */
final class EVC_Verified_Movement {
    const STATE_CONFIRMED = 'confirmed';
    const STATE_PENDING = 'pending';
    const STATE_FAILED = 'failed';
    const STATE_UNKNOWN = 'unknown';

    const AUTHORITY_GATEWAY_API = 'gateway_api';
    const AUTHORITY_ORDER_STATE = 'order_state';
    const AUTHORITY_MANUAL_ADMIN = 'manual_admin';

    const ID_PATTERN = '/^[0-9a-f]{64}$/D';
    const CURRENCY_PATTERN = '/^[A-Z]{3}$/D';
    const ENVIRONMENT_PATTERN = '/^[a-z][a-z0-9_]{0,15}$/D';

    /** @var string */
    private $movement_id;
    /** @var string */
    private $environment;
    /** @var int */
    private $wp_user_id;
    /** @var int */
    private $level_id;
    /** @var string */
    private $pmpro_row_ref;
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
    /** @var string EVC_Payment_Evidence::KIND_* */
    private $evidence_kind;
    /** @var string */
    private $state;

    /**
     * @param EVC_Movement_Alias[] $aliases
     */
    public function __construct(
        string $movement_id,
        string $environment,
        int $wp_user_id,
        int $level_id,
        string $pmpro_row_ref,
        DateTimeImmutable $verified_at_utc,
        int $amount_minor,
        string $currency,
        array $aliases,
        string $authority,
        string $evidence_kind,
        string $state
    ) {
        if (!preg_match(self::ID_PATTERN, $movement_id)) {
            throw new InvalidArgumentException('Movement id must be an opaque 64-hex value.');
        }
        if (!preg_match(self::ENVIRONMENT_PATTERN, $environment)) {
            throw new InvalidArgumentException('Invalid environment.');
        }
        if ($wp_user_id < 1 || $level_id < 1) {
            throw new InvalidArgumentException('User and level ids must be positive.');
        }
        if (!preg_match(self::ID_PATTERN, $pmpro_row_ref)) {
            throw new InvalidArgumentException('PMPro row reference must be an opaque 64-hex value.');
        }
        if ($verified_at_utc->getTimezone()->getName() !== 'UTC') {
            throw new InvalidArgumentException('Verification instant must be expressed in UTC.');
        }
        if ($verified_at_utc->format('u') !== '000000') {
            throw new InvalidArgumentException('Verification instant must be a whole second (not rounded here).');
        }
        if ($amount_minor < 1 || !preg_match(self::CURRENCY_PATTERN, $currency)) {
            throw new InvalidArgumentException('Invalid amount or currency.');
        }
        if (!in_array($authority, array(self::AUTHORITY_GATEWAY_API, self::AUTHORITY_ORDER_STATE, self::AUTHORITY_MANUAL_ADMIN), true)) {
            throw new InvalidArgumentException('Unknown verification authority.');
        }
        if (!in_array($evidence_kind, EVC_Payment_Evidence::kinds(), true)) {
            throw new InvalidArgumentException('Unknown evidence kind.');
        }
        if (!in_array($state, array(self::STATE_CONFIRMED, self::STATE_PENDING, self::STATE_FAILED, self::STATE_UNKNOWN), true)) {
            throw new InvalidArgumentException('Unknown movement state.');
        }
        $by_key = array();
        foreach ($aliases as $alias) {
            if (!$alias instanceof EVC_Movement_Alias) {
                throw new InvalidArgumentException('Aliases must be EVC_Movement_Alias objects.');
            }
            $by_key[$alias->key()] = $alias;
        }
        ksort($by_key);
        $this->movement_id = $movement_id;
        $this->environment = $environment;
        $this->wp_user_id = $wp_user_id;
        $this->level_id = $level_id;
        $this->pmpro_row_ref = $pmpro_row_ref;
        $this->verified_at_utc = $verified_at_utc;
        $this->amount_minor = $amount_minor;
        $this->currency = $currency;
        $this->aliases = $by_key;
        $this->authority = $authority;
        $this->evidence_kind = $evidence_kind;
        $this->state = $state;
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

    public function pmpro_row_ref(): string {
        return $this->pmpro_row_ref;
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

    public function state(): string {
        return $this->state;
    }

    /**
     * Same underlying money movement facts (aliases excluded: a later
     * verified signal may legitimately add one).
     */
    public function same_facts_as(EVC_Verified_Movement $other): bool {
        return $this->movement_id === $other->movement_id
            && $this->environment === $other->environment
            && $this->wp_user_id === $other->wp_user_id
            && $this->level_id === $other->level_id
            && $this->pmpro_row_ref === $other->pmpro_row_ref
            && $this->verified_at_utc == $other->verified_at_utc
            && $this->amount_minor === $other->amount_minor
            && $this->currency === $other->currency
            && $this->authority === $other->authority
            && $this->evidence_kind === $other->evidence_kind
            && $this->state === $other->state;
    }
}
