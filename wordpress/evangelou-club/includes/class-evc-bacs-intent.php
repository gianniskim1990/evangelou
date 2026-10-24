<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03B.2: a manager's BACS confirmation ATTEMPT, made durable before any
 * WooCommerce / PMPro step so a crash, retry or second manager resumes the
 * SAME attempt (same verification instant, same planned period) instead of
 * planning anew. Stored as signed private order metadata
 * (EVC_Wc_Bacs_Intent_Store); owner decision O1.
 *
 * An intent is NOT proof of payment and never grants anything: eligibility
 * still needs the Club's recorded movement (EVC_Provenance_Recorder) and a
 * matching active PMPro row (EVC_Pmpro_Entitlement_Mapper).
 *
 * Only opaque values: HMAC references (movement id = transfer, order anchor,
 * PMPro rows), numeric WordPress ids (order, customer, actor, level), UTC
 * instants and the lifecycle state. Never the bank reference, IBAN, names,
 * e-mail addresses or keys.
 *
 * Lifecycle (forward only, each step +1 revision):
 *   planned            the attempt is durable; nothing else happened yet
 *   issued             PMPro issued the new row (new_row_ref) with the plan's dates
 *   recorded           the Club recorded the movement bound to that row   (final)
 *   issuance_mismatch  PMPro produced something else; a manager must look
 *   recovery_required  automatic resumption stopped; a manager must look (final)
 * The state is a HINT for resumption, never evidence: recovery re-reads every
 * fact (EVC_Bacs_Recovery_Policy).
 */
final class EVC_Bacs_Intent {
    const SCHEMA_VERSION = 1;

    const STATE_PLANNED = 'planned';
    const STATE_ISSUED = 'issued';
    const STATE_RECORDED = 'recorded';
    const STATE_ISSUANCE_MISMATCH = 'issuance_mismatch';
    const STATE_RECOVERY_REQUIRED = 'recovery_required';

    /** state => states it may move to */
    const TRANSITIONS = array(
        self::STATE_PLANNED => array(self::STATE_ISSUED, self::STATE_ISSUANCE_MISMATCH, self::STATE_RECOVERY_REQUIRED),
        self::STATE_ISSUED => array(self::STATE_RECORDED, self::STATE_RECOVERY_REQUIRED),
        self::STATE_ISSUANCE_MISMATCH => array(self::STATE_RECOVERY_REQUIRED),
        self::STATE_RECORDED => array(),
        self::STATE_RECOVERY_REQUIRED => array(),
    );

    /** Canonical field order (the signature covers exactly these, in this order). */
    const FIELDS = array(
        'v', 'environment', 'order_id', 'customer_id', 'level_id', 'movement_id', 'order_anchor', 'actor_id',
        'verified_at', 'period_start', 'period_end', 'period_kind', 'predecessor_movement_id', 'run_start',
        'previous_row_ref', 'new_row_ref', 'state', 'revision',
    );

    const INSTANT_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @var array<string,mixed> */
    private $f;

    /** @param array<string,mixed> $f exactly FIELDS; instants as DateTimeImmutable (UTC, whole second) */
    private function __construct(array $f) {
        if (array_keys($f) !== self::FIELDS) {
            throw new InvalidArgumentException('Intent fields must be exactly the schema fields.');
        }
        if ($f['v'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported intent schema version.');
        }
        if (!is_string($f['environment']) || !preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $f['environment'])) {
            throw new InvalidArgumentException('Invalid environment.');
        }
        foreach (array('order_id', 'customer_id', 'level_id', 'actor_id', 'revision') as $k) {
            if (!is_int($f[$k]) || $f[$k] < 1) {
                throw new InvalidArgumentException('Invalid ' . $k . '.');
            }
        }
        if ($f['actor_id'] === $f['customer_id']) {
            throw new InvalidArgumentException('A customer can never confirm their own payment.');
        }
        foreach (array('movement_id', 'order_anchor') as $k) {
            if (!is_string($f[$k]) || !preg_match(EVC_Verified_Movement::ID_PATTERN, $f[$k])) {
                throw new InvalidArgumentException('Invalid ' . $k . '.');
            }
        }
        foreach (array('predecessor_movement_id', 'previous_row_ref', 'new_row_ref') as $k) {
            if ($f[$k] !== null && (!is_string($f[$k]) || !preg_match(EVC_Verified_Movement::ID_PATTERN, $f[$k]))) {
                throw new InvalidArgumentException('Invalid ' . $k . '.');
            }
        }
        foreach (array('verified_at', 'period_start', 'period_end', 'run_start') as $k) {
            if (!$f[$k] instanceof DateTimeImmutable || $f[$k]->getTimezone()->getName() !== 'UTC' || $f[$k]->format('u') !== '000000') {
                throw new InvalidArgumentException('Invalid ' . $k . ' (UTC whole second).');
            }
        }
        if ($f['period_end'] != EVC_Membership_Calendar::add_calendar_month($f['period_start'])) {
            throw new InvalidArgumentException('Planned end is not one approved calendar month after its start.');
        }
        switch ($f['period_kind']) {
            case EVC_Funded_Period::KIND_INITIAL:
            case EVC_Funded_Period::KIND_LATE:
                // A new paid run starts at the confirmation instant (D2).
                if ($f['period_start'] != $f['verified_at'] || $f['run_start'] != $f['period_start']) {
                    throw new InvalidArgumentException('An initial or late period starts its run at the confirmation instant.');
                }
                if (($f['period_kind'] === EVC_Funded_Period::KIND_INITIAL) !== ($f['predecessor_movement_id'] === null)) {
                    throw new InvalidArgumentException('Only the initial period has no predecessor.');
                }
                if ($f['period_kind'] === EVC_Funded_Period::KIND_INITIAL && $f['previous_row_ref'] !== null) {
                    throw new InvalidArgumentException('An initial period cannot replace a membership row.');
                }
                break;
            case EVC_Funded_Period::KIND_EARLY:
                // Early renewal continues the paid run at the current paid end (D2).
                if (!($f['verified_at'] < $f['period_start']) || !($f['run_start'] < $f['period_start'])
                    || $f['predecessor_movement_id'] === null || $f['previous_row_ref'] === null) {
                    throw new InvalidArgumentException('An early renewal continues a running paid membership.');
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown period kind.');
        }
        if (!isset(self::TRANSITIONS[$f['state']])) {
            throw new InvalidArgumentException('Unknown intent state.');
        }
        $bound = in_array($f['state'], array(self::STATE_ISSUED, self::STATE_RECORDED), true);
        if ($bound && ($f['new_row_ref'] === null || $f['new_row_ref'] === $f['previous_row_ref'])) {
            throw new InvalidArgumentException('An issued or recorded intent names the NEW membership row.');
        }
        if (in_array($f['state'], array(self::STATE_PLANNED, self::STATE_ISSUANCE_MISMATCH), true) && $f['new_row_ref'] !== null) {
            throw new InvalidArgumentException('No membership row was verified in this state.');
        }
        if ($f['state'] === self::STATE_PLANNED && $f['revision'] !== 1) {
            throw new InvalidArgumentException('A planned intent is the first revision.');
        }
        $this->f = $f;
    }

    /**
     * The first, durable record of an attempt: the advisory plan (EVC_Provenance_Recorder::plan)
     * fixed together with its confirmation instant and the paid-run start.
     */
    public static function planned(
        string $environment,
        int $order_id,
        int $customer_id,
        int $level_id,
        string $movement_id,
        string $order_anchor,
        int $actor_id,
        DateTimeImmutable $verified_at_utc,
        EVC_Period_Plan $plan,
        DateTimeImmutable $run_start_utc,
        ?string $previous_row_ref
    ): self {
        if ($plan->outcome() !== EVC_Period_Plan::PLANNED || $plan->movement_id() !== $movement_id) {
            throw new InvalidArgumentException('An intent is made only from a plan for this movement.');
        }
        return new self(array(
            'v' => self::SCHEMA_VERSION,
            'environment' => $environment,
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'level_id' => $level_id,
            'movement_id' => $movement_id,
            'order_anchor' => $order_anchor,
            'actor_id' => $actor_id,
            'verified_at' => $verified_at_utc,
            'period_start' => $plan->start_utc(),
            'period_end' => $plan->end_utc(),
            'period_kind' => $plan->kind(),
            'predecessor_movement_id' => $plan->predecessor_movement_id(),
            'run_start' => $run_start_utc,
            'previous_row_ref' => $previous_row_ref,
            'new_row_ref' => null,
            'state' => self::STATE_PLANNED,
            'revision' => 1,
        ));
    }

    /** @param array<string,mixed> $wire decoded canonical fields (instants as strings) */
    public static function from_wire(array $wire): self {
        if (array_keys($wire) !== self::FIELDS) {
            throw new InvalidArgumentException('Intent fields must be exactly the schema fields.');
        }
        foreach (array('verified_at', 'period_start', 'period_end', 'run_start') as $k) {
            $wire[$k] = self::parse_instant($wire[$k]);
        }
        return new self($wire);
    }

    /** @return array<string,mixed> canonical fields, instants as strings */
    public function to_wire(): array {
        $out = $this->f;
        foreach (array('verified_at', 'period_start', 'period_end', 'run_start') as $k) {
            $out[$k] = $out[$k]->format(self::INSTANT_FORMAT);
        }
        return $out;
    }

    public function issued(string $new_row_ref): self {
        return $this->moved(self::STATE_ISSUED, $new_row_ref);
    }

    public function recorded(): self {
        return $this->moved(self::STATE_RECORDED, $this->f['new_row_ref']);
    }

    public function issuance_mismatch(): self {
        return $this->moved(self::STATE_ISSUANCE_MISMATCH, null);
    }

    public function recovery_required(): self {
        return $this->moved(self::STATE_RECOVERY_REQUIRED, $this->f['new_row_ref']);
    }

    /** True when $next is exactly one allowed lifecycle step after this intent. */
    public function is_followed_by(self $next): bool {
        foreach (self::FIELDS as $k) {
            if (in_array($k, array('state', 'revision', 'new_row_ref'), true)) {
                continue;
            }
            if ($this->f[$k] != $next->f[$k] || gettype($this->f[$k]) !== gettype($next->f[$k])) {
                return false;
            }
        }
        if ($next->f['revision'] !== $this->f['revision'] + 1 || !in_array($next->f['state'], self::TRANSITIONS[$this->f['state']], true)) {
            return false;
        }
        if ($this->f['state'] === self::STATE_PLANNED && $next->f['state'] === self::STATE_ISSUED) {
            return $next->f['new_row_ref'] !== null;
        }
        return $next->f['new_row_ref'] === $this->f['new_row_ref'];
    }

    public function same_as(self $other): bool {
        return $this->to_wire() === $other->to_wire();
    }

    private function moved(string $state, ?string $new_row_ref): self {
        if (!in_array($state, self::TRANSITIONS[$this->f['state']], true)) {
            throw new LogicException('Intent state ' . $this->f['state'] . ' cannot move to ' . $state . '.');
        }
        $f = $this->f;
        $f['state'] = $state;
        $f['new_row_ref'] = $new_row_ref;
        $f['revision'] = $this->f['revision'] + 1;
        return new self($f);
    }

    private static function parse_instant($value): DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
            throw new InvalidArgumentException('Invalid instant.');
        }
        $instant = DateTimeImmutable::createFromFormat('!' . self::INSTANT_FORMAT, $value, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format(self::INSTANT_FORMAT) !== $value) {
            throw new InvalidArgumentException('Invalid instant.');
        }
        return $instant;
    }

    public function environment(): string {
        return $this->f['environment'];
    }

    public function order_id(): int {
        return $this->f['order_id'];
    }

    public function customer_id(): int {
        return $this->f['customer_id'];
    }

    public function level_id(): int {
        return $this->f['level_id'];
    }

    public function movement_id(): string {
        return $this->f['movement_id'];
    }

    public function order_anchor(): string {
        return $this->f['order_anchor'];
    }

    public function actor_id(): int {
        return $this->f['actor_id'];
    }

    public function verified_at_utc(): DateTimeImmutable {
        return $this->f['verified_at'];
    }

    public function period_start_utc(): DateTimeImmutable {
        return $this->f['period_start'];
    }

    public function period_end_utc(): DateTimeImmutable {
        return $this->f['period_end'];
    }

    public function period_kind(): string {
        return $this->f['period_kind'];
    }

    public function predecessor_movement_id(): ?string {
        return $this->f['predecessor_movement_id'];
    }

    public function run_start_utc(): DateTimeImmutable {
        return $this->f['run_start'];
    }

    public function previous_row_ref(): ?string {
        return $this->f['previous_row_ref'];
    }

    public function new_row_ref(): ?string {
        return $this->f['new_row_ref'];
    }

    public function state(): string {
        return $this->f['state'];
    }

    public function revision(): int {
        return $this->f['revision'];
    }

    /** The planned period as an advisory plan (to compare with what record() returns). */
    public function as_plan(): EVC_Period_Plan {
        return EVC_Period_Plan::planned($this->f['movement_id'], $this->f['period_start'], $this->f['period_end'], $this->f['period_kind'], $this->f['predecessor_movement_id']);
    }
}

/**
 * Canonical, signed wire format of an EVC_Bacs_Intent.
 *
 * Value = compact JSON object of EVC_Bacs_Intent::FIELDS in that order, then
 * "sig" (64 hex). sig = HMAC-SHA256(K, "EVC-BACS-INTENT\0" . v . "\0" . JSON
 * without sig) with K = HMAC-SHA256(evidence key, KEY_LABEL): a key and a
 * message domain of their own, so an intent MAC can never equal (or be
 * forged from) an EVC_Evidence_Ref reference made with the same evidence key.
 * The order id, customer, environment and version are inside the signed
 * bytes; decode() additionally requires them to match the order the value is
 * read from, so a valid intent copied to another order is rejected.
 *
 * KEY LIFECYCLE (limitation, documented): there is ONE key, the wp-config
 * evidence key. Changing it makes every stored intent "signature_mismatch":
 * recovery then stops for a manager (never accepts, never deletes). There is
 * no automatic rotation or key ring for intents.
 */
final class EVC_Bacs_Intent_Codec {
    const KEY_LABEL = "evangelou-club/bacs-intent-signing-key\0v1";
    const MAC_DOMAIN = "EVC-BACS-INTENT\0";
    const MAX_BYTES = 4096;

    /** @var string */
    private $key;

    public function __construct(string $evidence_key) {
        if (strlen($evidence_key) < EVC_Evidence_Ref::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException('Intent signing needs the configured evidence key.');
        }
        $this->key = hash_hmac('sha256', self::KEY_LABEL, $evidence_key, true);
    }

    public function encode(EVC_Bacs_Intent $intent): string {
        $wire = $intent->to_wire();
        $wire['sig'] = $this->mac(self::json($intent->to_wire()));
        return self::json($wire);
    }

    /** Strict decode of a stored value, bound to the order it was read from. */
    public function decode($raw, int $order_id, int $customer_id, string $environment): EVC_Bacs_Intent_Read {
        if (!is_string($raw) || $raw === '') {
            return EVC_Bacs_Intent_Read::invalid('not_a_string');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return EVC_Bacs_Intent_Read::invalid('oversized');
        }
        $data = json_decode($raw, true, 3);
        if (!is_array($data)) {
            return EVC_Bacs_Intent_Read::invalid('malformed');
        }
        $keys = array_keys($data);
        if ($keys !== array_merge(EVC_Bacs_Intent::FIELDS, array('sig'))) {
            return EVC_Bacs_Intent_Read::invalid(in_array('sig', $keys, true) ? 'unexpected_shape' : 'signature_missing');
        }
        if (!is_string($data['sig']) || !preg_match('/^[0-9a-f]{64}$/D', $data['sig'])) {
            return EVC_Bacs_Intent_Read::invalid('signature_missing');
        }
        if ($data['v'] !== EVC_Bacs_Intent::SCHEMA_VERSION) {
            return EVC_Bacs_Intent_Read::invalid('unsupported_version');
        }
        $sig = $data['sig'];
        unset($data['sig']);
        try {
            $intent = EVC_Bacs_Intent::from_wire($data);
        } catch (InvalidArgumentException $e) {
            return EVC_Bacs_Intent_Read::invalid('malformed_fields');
        }
        if (!hash_equals($this->mac(self::json($intent->to_wire())), $sig)) {
            return EVC_Bacs_Intent_Read::invalid('signature_mismatch');
        }
        if ($this->encode($intent) !== $raw) {
            return EVC_Bacs_Intent_Read::invalid('non_canonical');
        }
        if ($intent->order_id() !== $order_id) {
            return EVC_Bacs_Intent_Read::invalid('cross_order');
        }
        if ($intent->customer_id() !== $customer_id) {
            return EVC_Bacs_Intent_Read::invalid('cross_customer');
        }
        if ($intent->environment() !== $environment) {
            return EVC_Bacs_Intent_Read::invalid('cross_environment');
        }
        return EVC_Bacs_Intent_Read::valid($intent, $raw);
    }

    private function mac(string $canonical): string {
        return hash_hmac('sha256', self::MAC_DOMAIN . EVC_Bacs_Intent::SCHEMA_VERSION . "\0" . $canonical, $this->key);
    }

    private static function json(array $wire): string {
        $json = json_encode($wire, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new LogicException('Intent could not be encoded.');
        }
        return $json;
    }
}

/** Result of reading a stored intent: missing / valid / invalid / unavailable (with a reason). */
final class EVC_Bacs_Intent_Read {
    const MISSING = 'missing';
    const VALID = 'valid';
    const INVALID = 'invalid';
    const UNAVAILABLE = 'unavailable';

    /** @var string */
    public $outcome;
    /** @var string|null */
    public $reason;
    /** @var EVC_Bacs_Intent|null */
    public $intent;
    /** @var string|null exact stored bytes (compare-and-set) */
    public $raw;

    private function __construct(string $outcome, ?string $reason, ?EVC_Bacs_Intent $intent, ?string $raw) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->intent = $intent;
        $this->raw = $raw;
    }

    public static function missing(): self {
        return new self(self::MISSING, null, null, null);
    }

    public static function valid(EVC_Bacs_Intent $intent, string $raw): self {
        return new self(self::VALID, null, $intent, $raw);
    }

    public static function invalid(string $reason): self {
        return new self(self::INVALID, $reason, null, null);
    }

    public static function unavailable(string $reason): self {
        return new self(self::UNAVAILABLE, $reason, null, null);
    }
}
