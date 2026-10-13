<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03C — decision order for a redemption WITH a full preparation
 * (pure PHP model; definitions only, NOT wired to REST or the engine).
 *
 * Order (fixes the v1 engine's "catalogue before replay" ordering):
 *   1. shape: request_id, catalogue_version, coffee_code, preparation, actor
 *   2. canonical preparation + prep_hash + fingerprint v2
 *   3. request_id already used?  same fingerprint -> REPLAY (catalogue,
 *      membership and date are NOT consulted); different -> CONFLICT
 *   4. catalogue_version must be the ACTIVE version -> else STALE (definitive;
 *      the client reloads the catalogue and opens a NEW intent)
 *   5. semantic validation against the active catalogue
 *   6. PROCEED: member lookup, entitlement at one clock instant and the
 *      single ledger+snapshot+audit transaction remain the engine's job.
 *
 * Nothing here grants a coffee or touches a database. The one-coffee-per-
 * member-per-Europe/Athens-day invariant is unaffected: the preparation is
 * never part of any uniqueness key.
 */
interface EVC_Prep_Request_Lookup {
    /**
     * Stored idempotency fingerprint (v1 or v2) of a committed redemption
     * with this request id, or null when none exists.
     */
    public function fingerprint_for_request(string $request_id): ?string;
}

final class EVC_Prep_Decision {
    const PROCEED = 'proceed';
    const REPLAY = 'replay';
    const IDEMPOTENCY_CONFLICT = 'idempotency_key_reused';
    const CATALOGUE_VERSION_STALE = 'catalogue_version_stale';
    const INVALID_REQUEST = EVC_Prep_Rejection::INVALID_REQUEST;
    const INVALID_PREPARATION = EVC_Prep_Rejection::INVALID_PREPARATION;
    const INVALID_COFFEE = EVC_Prep_Rejection::INVALID_COFFEE;

    /** @var string */
    private $kind;
    /** @var string|null */
    private $reason;
    /** @var string|null */
    private $fingerprint;
    /** @var EVC_Prepared_Order|null */
    private $order;
    /** @var int|null */
    private $active_version;

    private function __construct(string $kind, ?string $reason, ?string $fingerprint, ?EVC_Prepared_Order $order, ?int $active_version) {
        $this->kind = $kind;
        $this->reason = $reason;
        $this->fingerprint = $fingerprint;
        $this->order = $order;
        $this->active_version = $active_version;
    }

    public static function rejected(EVC_Prep_Rejection $r): self {
        return new self($r->kind(), $r->reason(), null, null, null);
    }

    public static function replay(string $fingerprint): self {
        return new self(self::REPLAY, null, $fingerprint, null, null);
    }

    public static function conflict(): self {
        return new self(self::IDEMPOTENCY_CONFLICT, null, null, null, null);
    }

    public static function stale(int $active_version): self {
        return new self(self::CATALOGUE_VERSION_STALE, null, null, null, $active_version);
    }

    public static function proceed(string $fingerprint, EVC_Prepared_Order $order): self {
        return new self(self::PROCEED, null, $fingerprint, $order, null);
    }

    public function kind(): string {
        return $this->kind;
    }

    public function reason(): ?string {
        return $this->reason;
    }

    public function fingerprint(): ?string {
        return $this->fingerprint;
    }

    public function order(): ?EVC_Prepared_Order {
        return $this->order;
    }

    public function active_version(): ?int {
        return $this->active_version;
    }
}

final class EVC_Prepared_Redeem_Precheck {
    const BENEFIT_FREE_COFFEE = 'free_coffee';
    const REQUEST_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    /** Opt-in for tests only: the illustrative fixture is not an approved menu (D3). */
    const ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS = 'allow-illustrative-catalogue-for-tests';

    /** @var EVC_Prep_Catalogue */
    private $catalogue;
    /** @var EVC_Prep_Request_Lookup */
    private $lookup;

    public function __construct(EVC_Prep_Catalogue $active_catalogue, EVC_Prep_Request_Lookup $lookup, ?string $illustrative_opt_in = null) {
        if (!$active_catalogue->is_approved() && $illustrative_opt_in !== self::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS) {
            throw new InvalidArgumentException('An unapproved (illustrative) preparation catalogue cannot be activated.');
        }
        $this->catalogue = $active_catalogue;
        $this->lookup = $lookup;
    }

    /**
     * @param mixed $member_public_id from the URL only
     * @param mixed $benefit_type     from the URL only
     * @param mixed $staff_wp_user_id from the authenticated session only
     * @param mixed $body             decoded JSON request body
     */
    public function decide($member_public_id, $benefit_type, $staff_wp_user_id, $body): EVC_Prep_Decision {
        try {
            // 1. Shape.
            if (!EVC_Member_Id::is_valid_format($member_public_id) || $benefit_type !== self::BENEFIT_FREE_COFFEE
                || !is_int($staff_wp_user_id) || $staff_wp_user_id < 1
                || !EVC_Prep_Catalogue::is_object_with($body, array('request_id', 'catalogue_version', 'coffee_code', 'preparation'))
                || !is_string($body['request_id']) || !preg_match(self::REQUEST_ID_PATTERN, $body['request_id'])
                || !is_int($body['catalogue_version']) || $body['catalogue_version'] < 1
                || !EVC_Prep_Catalogue::is_id($body['coffee_code'])) {
                return EVC_Prep_Decision::rejected(EVC_Prep_Rejection::request('shape'));
            }
            $parsed = EVC_Prep_Canonical::parse($body['preparation']);

            // 2. Canonical form and fingerprint v2 (no catalogue involved).
            $canonical = EVC_Prep_Canonical::canonical($body['catalogue_version'], $body['coffee_code'], $parsed['modifiers'], $parsed['quick_notes']);
            $fingerprint = EVC_Prep_Canonical::fingerprint_v2($member_public_id, $benefit_type, $staff_wp_user_id, EVC_Prep_Canonical::prep_hash($canonical));

            // 3. Replay before any catalogue decision.
            $stored = $this->lookup->fingerprint_for_request($body['request_id']);
            if ($stored !== null) {
                return hash_equals($stored, $fingerprint) ? EVC_Prep_Decision::replay($fingerprint) : EVC_Prep_Decision::conflict();
            }

            // 4. Only the active catalogue version may create a new redemption.
            if ($body['catalogue_version'] !== $this->catalogue->version()) {
                return EVC_Prep_Decision::stale($this->catalogue->version());
            }

            // 5. Semantic validation.
            $order = EVC_Prep_Validator::validate($this->catalogue, $body['coffee_code'], $parsed['modifiers'], $parsed['quick_notes']);
            return EVC_Prep_Decision::proceed($fingerprint, $order);
        } catch (EVC_Prep_Rejection $r) {
            return EVC_Prep_Decision::rejected($r);
        }
    }
}
