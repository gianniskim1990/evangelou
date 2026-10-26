<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03D (owner decision 2026-10-26, Option A): the durable, signed record
 * "shop user X completed this Club order at T" for a completion the Club could
 * NOT confirm in the same request (deferred: member lock busy, Club database
 * down, a confirmation instant PMPro cannot show exactly, a late renewal before
 * PMPro's own expiry ran, the same customer twice in one second, ...) or that
 * stopped for a manager. The recovery step (EVC_Wc_Completion_Recovery) finishes
 * a deferred one later, re-checking everything; this marker never pays anything
 * by itself and is never evidence that money arrived.
 *
 * Immutable once written (the follow-up index next to it is not signed and is
 * only a hint for the recovery scan). Only opaque values: numeric WordPress ids
 * (order, customer, actor), the completion instant, a reason code, what the
 * add-on did with its own run (`suppressed`: an active Club row existed and the
 * add-on's run was suppressed; `shipped`: it ran as shipped) and the highest
 * PMPro membership row id of the customer at that moment (`row_floor`, so the
 * add-on's own row for THIS completion can be told apart later). Never names,
 * e-mail addresses, bank data or keys.
 */
final class EVC_Wc_Completion_Marker {
    const SCHEMA_VERSION = 1;
    const ADDON_SUPPRESSED = 'suppressed';
    const ADDON_SHIPPED = 'shipped';
    const FIELDS = array('v', 'environment', 'order_id', 'customer_id', 'actor_id', 'completed_at', 'reason', 'addon', 'row_floor');
    const INSTANT_FORMAT = 'Y-m-d\TH:i:s\Z';
    const REASON_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** @var array<string,mixed> */
    private $f;

    /** @param array<string,mixed> $f exactly FIELDS; completed_at as DateTimeImmutable (UTC, whole second) */
    private function __construct(array $f) {
        if (array_keys($f) !== self::FIELDS || $f['v'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Marker fields must be exactly the schema fields.');
        }
        if (!is_string($f['environment']) || !preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $f['environment'])) {
            throw new InvalidArgumentException('Invalid environment.');
        }
        foreach (array('order_id', 'customer_id', 'actor_id') as $k) {
            if (!is_int($f[$k]) || $f[$k] < 1) {
                throw new InvalidArgumentException('Invalid ' . $k . '.');
            }
        }
        if ($f['actor_id'] === $f['customer_id']) {
            throw new InvalidArgumentException('A customer can never confirm their own payment.');
        }
        if (!$f['completed_at'] instanceof DateTimeImmutable || $f['completed_at']->getTimezone()->getName() !== 'UTC' || $f['completed_at']->format('u') !== '000000') {
            throw new InvalidArgumentException('Invalid completion instant (UTC whole second).');
        }
        if (!is_string($f['reason']) || !preg_match(self::REASON_PATTERN, $f['reason'])) {
            throw new InvalidArgumentException('Invalid reason code.');
        }
        if (!in_array($f['addon'], array(self::ADDON_SUPPRESSED, self::ADDON_SHIPPED), true)) {
            throw new InvalidArgumentException('Invalid add-on outcome.');
        }
        if (!is_int($f['row_floor']) || $f['row_floor'] < 0) {
            throw new InvalidArgumentException('Invalid row floor.');
        }
        $this->f = $f;
    }

    public static function create(string $environment, int $order_id, int $customer_id, int $actor_id, DateTimeImmutable $completed_at_utc, string $reason, string $addon, int $row_floor): self {
        return new self(array(
            'v' => self::SCHEMA_VERSION,
            'environment' => $environment,
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'actor_id' => $actor_id,
            'completed_at' => $completed_at_utc,
            'reason' => $reason,
            'addon' => $addon,
            'row_floor' => $row_floor,
        ));
    }

    /** @param array<string,mixed> $wire */
    public static function from_wire(array $wire): self {
        if (array_keys($wire) !== self::FIELDS) {
            throw new InvalidArgumentException('Marker fields must be exactly the schema fields.');
        }
        $value = $wire['completed_at'];
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
            throw new InvalidArgumentException('Invalid instant.');
        }
        $instant = DateTimeImmutable::createFromFormat('!' . self::INSTANT_FORMAT, $value, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format(self::INSTANT_FORMAT) !== $value) {
            throw new InvalidArgumentException('Invalid instant.');
        }
        $wire['completed_at'] = $instant;
        return new self($wire);
    }

    /** @return array<string,mixed> */
    public function to_wire(): array {
        $out = $this->f;
        $out['completed_at'] = $out['completed_at']->format(self::INSTANT_FORMAT);
        return $out;
    }

    public function same_as(self $other): bool {
        return $this->to_wire() === $other->to_wire();
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

    public function actor_id(): int {
        return $this->f['actor_id'];
    }

    public function completed_at_utc(): DateTimeImmutable {
        return $this->f['completed_at'];
    }

    public function reason(): string {
        return $this->f['reason'];
    }

    public function addon(): string {
        return $this->f['addon'];
    }

    public function row_floor(): int {
        return $this->f['row_floor'];
    }
}

/**
 * Canonical signed wire format: compact JSON of FIELDS in order, then "sig" =
 * HMAC-SHA256(K, "EVC-WC-COMPLETION\0" . v . "\0" . JSON) with
 * K = HMAC-SHA256(evidence key, KEY_LABEL) — its own key and message domain
 * (never equal to an intent signature or an EVC_Evidence_Ref). Strict decode,
 * bound to the order it is read from. Same key lifecycle limitation as the
 * BACS intent: one wp-config key, no rotation.
 */
final class EVC_Wc_Completion_Marker_Codec {
    const KEY_LABEL = "evangelou-club/wc-completion-marker-signing-key\0v1";
    const MAC_DOMAIN = "EVC-WC-COMPLETION\0";
    const MAX_BYTES = 2048;

    /** @var string */
    private $key;

    public function __construct(string $evidence_key) {
        if (strlen($evidence_key) < EVC_Evidence_Ref::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException('Marker signing needs the configured evidence key.');
        }
        $this->key = hash_hmac('sha256', self::KEY_LABEL, $evidence_key, true);
    }

    public function encode(EVC_Wc_Completion_Marker $marker): string {
        $wire = $marker->to_wire();
        $wire['sig'] = $this->mac(self::json($marker->to_wire()));
        return self::json($wire);
    }

    public function decode($raw, int $order_id, int $customer_id, string $environment): EVC_Wc_Completion_Marker_Read {
        if (!is_string($raw) || $raw === '') {
            return EVC_Wc_Completion_Marker_Read::invalid('not_a_string');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return EVC_Wc_Completion_Marker_Read::invalid('oversized');
        }
        $data = json_decode($raw, true, 3);
        if (!is_array($data)) {
            return EVC_Wc_Completion_Marker_Read::invalid('malformed');
        }
        if (array_keys($data) !== array_merge(EVC_Wc_Completion_Marker::FIELDS, array('sig'))) {
            return EVC_Wc_Completion_Marker_Read::invalid('unexpected_shape');
        }
        if (!is_string($data['sig']) || !preg_match('/^[0-9a-f]{64}$/D', $data['sig'])) {
            return EVC_Wc_Completion_Marker_Read::invalid('signature_missing');
        }
        $sig = $data['sig'];
        unset($data['sig']);
        try {
            $marker = EVC_Wc_Completion_Marker::from_wire($data);
        } catch (InvalidArgumentException $e) {
            return EVC_Wc_Completion_Marker_Read::invalid('malformed_fields');
        }
        if (!hash_equals($this->mac(self::json($marker->to_wire())), $sig)) {
            return EVC_Wc_Completion_Marker_Read::invalid('signature_mismatch');
        }
        if ($this->encode($marker) !== $raw) {
            return EVC_Wc_Completion_Marker_Read::invalid('non_canonical');
        }
        if ($marker->order_id() !== $order_id) {
            return EVC_Wc_Completion_Marker_Read::invalid('cross_order');
        }
        if ($marker->customer_id() !== $customer_id) {
            return EVC_Wc_Completion_Marker_Read::invalid('cross_customer');
        }
        if ($marker->environment() !== $environment) {
            return EVC_Wc_Completion_Marker_Read::invalid('cross_environment');
        }
        return EVC_Wc_Completion_Marker_Read::valid($marker, $raw);
    }

    private function mac(string $canonical): string {
        return hash_hmac('sha256', self::MAC_DOMAIN . EVC_Wc_Completion_Marker::SCHEMA_VERSION . "\0" . $canonical, $this->key);
    }

    private static function json(array $wire): string {
        $json = json_encode($wire, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new LogicException('Marker could not be encoded.');
        }
        return $json;
    }
}

/** Result of reading a stored marker: missing / valid / invalid / unavailable. */
final class EVC_Wc_Completion_Marker_Read {
    const MISSING = 'missing';
    const VALID = 'valid';
    const INVALID = 'invalid';
    const UNAVAILABLE = 'unavailable';

    /** @var string */
    public $outcome;
    /** @var string|null */
    public $reason;
    /** @var EVC_Wc_Completion_Marker|null */
    public $marker;
    /** @var string|null */
    public $raw;

    private function __construct(string $outcome, ?string $reason, ?EVC_Wc_Completion_Marker $marker, ?string $raw) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->marker = $marker;
        $this->raw = $raw;
    }

    public static function missing(): self {
        return new self(self::MISSING, null, null, null);
    }

    public static function valid(EVC_Wc_Completion_Marker $marker, string $raw): self {
        return new self(self::VALID, null, $marker, $raw);
    }

    public static function invalid(string $reason): self {
        return new self(self::INVALID, $reason, null, null);
    }

    public static function unavailable(string $reason): self {
        return new self(self::UNAVAILABLE, $reason, null, null);
    }
}
