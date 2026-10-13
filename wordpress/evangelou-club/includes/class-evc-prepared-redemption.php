<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03D.A — atomic redemption + preparation transaction model
 * (pure PHP orchestration, definitions only, NOT wired).
 *
 * One verified, eligible coffee redemption produces EXACTLY three records,
 * written together or not at all:
 *
 *   1. one ledger row        (one per member + benefit + Europe/Athens day)
 *   2. one preparation snapshot (EVC_Prep_Snapshot, linked to that row)
 *   3. one success audit event  (linked to that row)
 *
 * Decision order (each step fails closed and writes nothing):
 *
 *   1. shape: member id (URL) + benefit (URL) + staff actor (server session)
 *      + a body with EXACTLY request_id, catalogue_version, coffee_code,
 *      preparation. Member ids, actors, timestamps, dates, eligibility or
 *      catalogue status sent in the body are unknown keys -> invalid_request.
 *   2. preparation parse (free text -> invalid_preparation) and fingerprint v2
 *   3. request_id already committed?
 *        different fingerprint -> idempotency_key_reused
 *        same fingerprint      -> verify the stored snapshot, then REPLAY the
 *                                 stored record (no catalogue, membership,
 *                                 clock or date involved: replays survive
 *                                 midnight, expiry and catalogue updates);
 *                                 a missing / corrupt / conflicting snapshot
 *                                 -> record_integrity_failure, never a coffee
 *   4. server-side catalogue authorisation (status AND pinned digest)
 *   5. catalogue_version must be the active one -> else catalogue_version_stale
 *   6. semantic validation against the active catalogue
 *   7. member resolved server side; ONE clock read; eligibility from the
 *      server-side membership adapter at that instant; Athens business date
 *   8. atomic commit of the three records through the store, then:
 *        duplicate key   -> (already rolled back) fresh reads classify it as
 *                           a replay / conflict of the same request id, or
 *                           benefit_already_redeemed for the member-day
 *        retryable       -> retry the whole transaction (bounded)
 *        commit unknown  -> outcome_unknown: NOT a coffee and NOT a failure;
 *                           the till must retry with the SAME request_id
 *
 * UI-03D.C: recording, fulfillment and instruction are separate facts. Only a
 * FRESH commit instructs `prepare`. A replay reports `previously_recorded`
 * plus the stored handoff status and instructs `verify_handoff` (no handoff
 * recorded — NOT proof that nothing was handed over), `do_not_prepare` (a
 * handoff confirmation is recorded) or `do_not_prepare_for_today` (an earlier
 * Athens business date). Every other outcome instructs `do_not_prepare`.
 * Handoff confirmation is a separate transaction (EVC_Handoff_Service).
 *
 * The future
 * SQL transaction that must satisfy the same contract is described in
 * docs/prepared-redemption-transaction.md.
 */
interface EVC_Prep_Redemption_Store {
    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string}|null */
    public function find_member_by_public_id(string $member_public_id): ?array;

    /**
     * The committed ledger row for this request id plus EVERY preparation
     * snapshot linked to it (normally exactly one) and EVERY handoff
     * confirmation linked to it (none or one), or null when none exists.
     * Must be a fresh read that sees committed rows of other connections.
     *
     * @return array{ledger:array,snapshots:array[],handoffs:array[]}|null
     */
    public function find_by_request_id(string $request_id): ?array;

    /** @return array|null committed ledger row of that member-day (any engine version) */
    public function find_for_day(int $member_id, string $benefit_type, string $business_date): ?array;

    /**
     * Writes the ledger row, the snapshot and the success audit event (with the
     * new redemption id) in ONE transaction: all three, or none.
     *
     * @return int new redemption id
     * @throws EVC_Db_Duplicate_Key_Exception  after rollback (uq_redemptions_one_per_day / uq_redemptions_request)
     * @throws EVC_Db_Retryable_Exception      after rollback (deadlock, lock wait)
     * @throws EVC_Db_Commit_Unknown_Exception COMMIT failed: the outcome is unknown
     * @throws Throwable                       anything else, after rollback
     */
    public function commit_redemption(EVC_Prepared_Redemption_Write $write): int;

    /** Records a rejected attempt OUTSIDE any transaction (best effort). */
    public function record_rejection(array $event): void;
}

/**
 * Server-side source of the active preparation catalogue and of the owner's
 * production approval records. Never fed from request data. No production
 * implementation exists (the packager rejects one).
 */
interface EVC_Prep_Catalogue_Authority {
    /** Decoded document of the catalogue that is active on the server. */
    public function active_catalogue_data(): array;

    /**
     * Digest (EVC_Prep_Catalogue_Digest) of the exact catalogue document the
     * owner approved for production use under this version, from a trusted
     * server-side record; null when no approval exists.
     */
    public function approved_digest(int $catalogue_version): ?string;
}

/** Staff actor taken from the authenticated server-side session (never from a request body). */
final class EVC_Staff_Actor {
    const SESSION_REF_PATTERN = '/^[0-9a-f]{32}$/D';

    /** @var int */
    private $wp_user_id;
    /** @var string|null */
    private $session_ref;

    public function __construct(int $wp_user_id, ?string $session_ref = null) {
        if ($wp_user_id < 1 || ($session_ref !== null && !preg_match(self::SESSION_REF_PATTERN, $session_ref))) {
            throw new InvalidArgumentException('Invalid staff actor.');
        }
        $this->wp_user_id = $wp_user_id;
        $this->session_ref = $session_ref;
    }

    public function wp_user_id(): int {
        return $this->wp_user_id;
    }

    /** Audit only; never part of the fingerprint (a retry after re-login stays a replay). */
    public function session_ref(): ?string {
        return $this->session_ref;
    }
}

/**
 * The three records of one redemption, checked for mutual consistency BEFORE
 * any write, so a store can only ever persist an agreeing triple.
 */
final class EVC_Prepared_Redemption_Write {
    const LEDGER_KEYS = array(
        'member_id', 'benefit_type', 'business_date', 'coffee_code', 'catalogue_version', 'prep_hash', 'snapshot_hash',
        'request_id', 'request_fingerprint', 'fingerprint_version', 'staff_wp_user_id',
        'membership_source', 'membership_status', 'membership_level_ref', 'membership_expires_at_utc', 'redeemed_at_utc',
    );
    const AUDIT_KEYS = array('event_type', 'outcome', 'member_id', 'request_id', 'staff_wp_user_id', 'occurred_at_utc', 'details');

    /** @var array */
    private $ledger;
    /** @var array */
    private $snapshot;
    /** @var array */
    private $audit;

    public function __construct(array $ledger, array $snapshot, array $audit) {
        if (!EVC_Prep_Catalogue::is_object_with($ledger, self::LEDGER_KEYS) || !EVC_Prep_Catalogue::is_object_with($audit, self::AUDIT_KEYS)) {
            throw new InvalidArgumentException('Inconsistent redemption write: shape.');
        }
        try {
            EVC_Prep_Snapshot::verify($snapshot);
        } catch (EVC_Prep_Snapshot_Invalid $e) {
            throw new InvalidArgumentException('Inconsistent redemption write: snapshot.');
        }
        if ($ledger['fingerprint_version'] !== EVC_Prep_Canonical::FINGERPRINT_VERSION
            || $ledger['prep_hash'] !== $snapshot['prep_hash']
            || $ledger['snapshot_hash'] !== $snapshot['snapshot_hash']
            || $ledger['coffee_code'] !== $snapshot['coffee_code']
            || $ledger['catalogue_version'] !== $snapshot['catalogue_version']
            || $audit['outcome'] !== EVC_Prepared_Redemption_Outcome::REDEEMED
            || $audit['member_id'] !== $ledger['member_id']
            || $audit['request_id'] !== $ledger['request_id']
            || $audit['staff_wp_user_id'] !== $ledger['staff_wp_user_id']
            || $audit['occurred_at_utc'] !== $ledger['redeemed_at_utc']) {
            throw new InvalidArgumentException('Inconsistent redemption write: records disagree.');
        }
        $this->ledger = $ledger;
        $this->snapshot = $snapshot;
        $this->audit = $audit;
    }

    public function ledger(): array {
        return $this->ledger;
    }

    public function snapshot(): array {
        return $this->snapshot;
    }

    /** The audit event WITHOUT redemption_id: the store adds the id it assigns inside the transaction. */
    public function audit(): array {
        return $this->audit;
    }
}

final class EVC_Prepared_Redemption_Outcome {
    const REDEEMED = 'redeemed';
    const REPLAYED = 'replayed';
    const INVALID_REQUEST = EVC_Prep_Rejection::INVALID_REQUEST;
    const INVALID_PREPARATION = EVC_Prep_Rejection::INVALID_PREPARATION;
    const INVALID_COFFEE = EVC_Prep_Rejection::INVALID_COFFEE;
    const CATALOGUE_VERSION_STALE = 'catalogue_version_stale';
    const CATALOGUE_NOT_AUTHORISED = 'catalogue_not_authorised';
    const MEMBER_NOT_FOUND = 'member_not_found';
    const MEMBERSHIP_INACTIVE = 'membership_inactive';
    const ALREADY_REDEEMED = 'benefit_already_redeemed';
    const IDEMPOTENCY_CONFLICT = 'idempotency_key_reused';
    const RECORD_INTEGRITY_FAILURE = 'record_integrity_failure';
    const OUTCOME_UNKNOWN = 'outcome_unknown';
    const SERVER_ERROR = 'server_error';

    // UI-03D.C — recording, fulfillment and instruction are SEPARATE facts.
    const RECORDED_NOW = 'recorded_now';
    const PREVIOUSLY_RECORDED = 'previously_recorded';
    /** No handoff confirmation has been recorded. NOT proof that no coffee was handed over. */
    const FULFILLMENT_UNCONFIRMED = 'unconfirmed';
    /** Staff recorded a handoff confirmation. NOT proof that a coffee changed hands. */
    const FULFILLMENT_CONFIRMED = 'confirmed';
    /** Only a fresh commit, through its own fresh result. */
    const PREPARE = 'prepare';
    /** Replay of today's redemption without a recorded handoff: check before preparing anything. */
    const VERIFY_HANDOFF = 'verify_handoff';
    const DO_NOT_PREPARE = 'do_not_prepare';
    /** Replay of a redemption of an EARLIER Athens business date: never today's coffee. */
    const DO_NOT_PREPARE_FOR_TODAY = 'do_not_prepare_for_today';

    /** @var string */
    private $kind;
    /** @var array|null */
    private $redemption;
    /** @var array */
    private $details;
    /** @var string|null */
    private $retry_request_id;
    /** @var string|null */
    private $fulfillment;
    /** @var string */
    private $instruction;
    /** @var array|null */
    private $handoff;

    private function __construct(string $kind, ?array $redemption, array $details, ?string $retry_request_id, ?string $fulfillment, string $instruction, ?array $handoff) {
        $this->kind = $kind;
        $this->redemption = $redemption;
        $this->details = $details;
        $this->retry_request_id = $retry_request_id;
        $this->fulfillment = $fulfillment;
        $this->instruction = $instruction;
        $this->handoff = $handoff;
    }

    /** A fresh commit: the ONLY outcome that instructs preparation. */
    public static function redeemed(array $redemption): self {
        return new self(self::REDEEMED, $redemption, array(), null, self::FULFILLMENT_UNCONFIRMED, self::PREPARE, null);
    }

    /**
     * Replay of an already committed redemption. Never instructs preparation:
     * an earlier business date is never today's coffee; a recorded handoff
     * means do not prepare; no recorded handoff means verify first (it is
     * NOT proof that nothing was handed over).
     *
     * @param array|null $handoff verified handoff view, or null when none is recorded
     */
    public static function replayed(array $redemption, bool $same_business_date, ?array $handoff): self {
        if (!$same_business_date) {
            $instruction = self::DO_NOT_PREPARE_FOR_TODAY;
        } elseif ($handoff !== null) {
            $instruction = self::DO_NOT_PREPARE;
        } else {
            $instruction = self::VERIFY_HANDOFF;
        }
        $fulfillment = $handoff !== null ? self::FULFILLMENT_CONFIRMED : self::FULFILLMENT_UNCONFIRMED;
        return new self(self::REPLAYED, $redemption, array(), null, $fulfillment, $instruction, $handoff);
    }

    public static function failure(string $kind, array $details = array()): self {
        if ($kind === self::REDEEMED || $kind === self::REPLAYED || $kind === self::OUTCOME_UNKNOWN || $kind === self::SERVER_ERROR) {
            throw new InvalidArgumentException('Not a definitive failure outcome.');
        }
        return new self($kind, null, $details, null, null, self::DO_NOT_PREPARE, null);
    }

    /**
     * Nothing may be served yet. When the request id was valid, the ONLY safe
     * recovery is to repeat the request with that same id: it then replays a
     * committed redemption, or performs the (first) redemption if nothing was
     * committed. A new request id is never a valid recovery.
     */
    public static function unresolved(string $kind, ?string $request_id, array $details = array()): self {
        if ($kind !== self::OUTCOME_UNKNOWN && $kind !== self::SERVER_ERROR) {
            throw new InvalidArgumentException('Not an unresolved outcome.');
        }
        return new self($kind, null, $details, $request_id, null, self::DO_NOT_PREPARE, null);
    }

    public function kind(): string {
        return $this->kind;
    }

    /**
     * Was today's (or the original day's) entitlement consumed by THIS
     * request? true for a fresh or replayed commit, false for a definitive
     * refusal of this request, null when unknown (unresolved outcome, or a
     * stored record that failed its integrity check).
     */
    public function entitlement_consumed(): ?bool {
        if ($this->kind === self::REDEEMED || $this->kind === self::REPLAYED) {
            return true;
        }
        if ($this->kind === self::OUTCOME_UNKNOWN || $this->kind === self::SERVER_ERROR || $this->kind === self::RECORD_INTEGRITY_FAILURE) {
            return null;
        }
        return false;
    }

    /** recorded_now | previously_recorded, or null when nothing is known to be recorded. */
    public function recording(): ?string {
        if ($this->kind === self::REDEEMED) {
            return self::RECORDED_NOW;
        }
        return $this->kind === self::REPLAYED ? self::PREVIOUSLY_RECORDED : null;
    }

    /** unconfirmed | confirmed for a recorded redemption, null otherwise. */
    public function fulfillment(): ?string {
        return $this->fulfillment;
    }

    /** prepare | verify_handoff | do_not_prepare | do_not_prepare_for_today (never null). */
    public function instruction(): string {
        return $this->instruction;
    }

    /** The verified handoff confirmation of a replayed redemption, or null. */
    public function handoff(): ?array {
        return $this->handoff;
    }

    public function redemption(): ?array {
        return $this->redemption;
    }

    public function details(): array {
        return $this->details;
    }

    public function retry_request_id(): ?string {
        return $this->retry_request_id;
    }
}

final class EVC_Prepared_Redemption_Service {
    const BENEFIT_FREE_COFFEE = 'free_coffee';
    const MAX_TRANSACTION_ATTEMPTS = 3;
    const REQUEST_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    const BODY_KEYS = array('request_id', 'catalogue_version', 'coffee_code', 'preparation');
    /** Opt-in for tests only: the illustrative fixture is not an approved menu (D3). */
    const ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS = 'allow-illustrative-catalogue-for-tests';

    /** @var EVC_Prep_Redemption_Store */
    private $store;
    /** @var EVC_Prep_Catalogue_Authority */
    private $authority;
    /** @var EVC_Membership_Adapter */
    private $membership;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var string|null */
    private $illustrative_opt_in;
    /** @var callable|null function (array $diagnostic): void */
    private $error_reporter;

    /** @var EVC_Staff_Actor|null per-call state */
    private $actor;
    /** @var string|null per-call state */
    private $request_id;
    /** @var DateTimeImmutable|null per-call state */
    private $now;

    public function __construct(
        EVC_Prep_Redemption_Store $store,
        EVC_Prep_Catalogue_Authority $authority,
        EVC_Membership_Adapter $membership,
        EVC_Clock_Source $clock,
        ?string $illustrative_opt_in = null,
        ?callable $error_reporter = null
    ) {
        $this->store = $store;
        $this->authority = $authority;
        $this->membership = $membership;
        $this->clock = $clock;
        $this->illustrative_opt_in = $illustrative_opt_in;
        $this->error_reporter = $error_reporter;
    }

    /**
     * @param mixed $member_public_id from the URL (QR resolution) only
     * @param mixed $benefit_type     from the URL only
     * @param mixed $body             decoded JSON request body
     */
    public function redeem($member_public_id, $benefit_type, EVC_Staff_Actor $actor, $body): EVC_Prepared_Redemption_Outcome {
        $this->actor = $actor;
        $this->request_id = null;
        $this->now = null;
        try {
            return $this->redeem_or_throw($member_public_id, $benefit_type, $actor, $body);
        } catch (Throwable $e) {
            $this->report('redeem', $e);
            return EVC_Prepared_Redemption_Outcome::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $this->request_id);
        }
    }

    private function redeem_or_throw($member_public_id, $benefit_type, EVC_Staff_Actor $actor, $body): EVC_Prepared_Redemption_Outcome {
        // 1. Shape. The body carries ONLY the four intent keys.
        if (!EVC_Member_Id::is_valid_format($member_public_id) || $benefit_type !== self::BENEFIT_FREE_COFFEE
            || !EVC_Prep_Catalogue::is_object_with($body, self::BODY_KEYS)
            || !is_string($body['request_id']) || !preg_match(self::REQUEST_ID_PATTERN, $body['request_id'])
            || !is_int($body['catalogue_version']) || $body['catalogue_version'] < 1
            || !EVC_Prep_Catalogue::is_id($body['coffee_code'])) {
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::INVALID_REQUEST, array('reason' => 'shape'));
        }
        try {
            $parsed = EVC_Prep_Canonical::parse($body['preparation']);
        } catch (EVC_Prep_Rejection $r) {
            return self::rejected($r);
        }
        $request_id = $body['request_id'];
        $this->request_id = $request_id;

        // 2. Canonical preparation and fingerprint v2 (no catalogue involved).
        $canonical = EVC_Prep_Canonical::canonical($body['catalogue_version'], $body['coffee_code'], $parsed['modifiers'], $parsed['quick_notes']);
        $fingerprint = EVC_Prep_Canonical::fingerprint_v2($member_public_id, $benefit_type, $actor->wp_user_id(), EVC_Prep_Canonical::prep_hash($canonical));

        // 3. Replay before any catalogue, member, membership, clock or date decision.
        $stored = $this->store->find_by_request_id($request_id);
        if ($stored !== null) {
            return $this->replay_or_reject($stored, $fingerprint, $canonical, null);
        }

        // 4.-6. Server-side catalogue: authorised, current, and the order valid against it.
        $catalogue = $this->authorised_catalogue();
        if ($catalogue === null) {
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED);
        }
        if ($body['catalogue_version'] !== $catalogue->version()) {
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::CATALOGUE_VERSION_STALE, array('active_version' => $catalogue->version()));
        }
        try {
            $order = EVC_Prep_Validator::validate($catalogue, $body['coffee_code'], $parsed['modifiers'], $parsed['quick_notes']);
        } catch (EVC_Prep_Rejection $r) {
            return self::rejected($r);
        }

        // 7. Member, time and eligibility are decided on the server only.
        $member = $this->store->find_member_by_public_id($member_public_id);
        if ($member === null || $member['status'] !== 'enabled') {
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::MEMBER_NOT_FOUND);
        }
        $this->now = EVC_Clock::to_utc($this->clock->now());
        $now_sql = $this->now->format('Y-m-d H:i:s.u');
        $entitlement = $this->membership->entitlement_for($member['wp_user_id'], $this->now);
        $denial = $entitlement->denial_reason($this->now);
        if ($denial !== null) {
            $this->audit_rejection(EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE, $member['id'], array('reason' => $denial, 'source' => $entitlement->source()));
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE, array(
                'status' => $entitlement->public_status($this->now),
                'reason' => $denial,
            ));
        }
        $business_date = EVC_Clock::business_date($this->now);

        $snapshot = EVC_Prep_Snapshot::from_order($order);
        $ledger = array(
            'member_id' => $member['id'],
            'benefit_type' => $benefit_type,
            'business_date' => $business_date,
            'coffee_code' => $order->coffee_code(),
            'catalogue_version' => $order->catalogue_version(),
            'prep_hash' => $order->prep_hash(),
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'request_id' => $request_id,
            'request_fingerprint' => $fingerprint,
            'fingerprint_version' => EVC_Prep_Canonical::FINGERPRINT_VERSION,
            'staff_wp_user_id' => $actor->wp_user_id(),
            'membership_source' => $entitlement->source(),
            'membership_status' => $entitlement->status(),
            'membership_level_ref' => $entitlement->level_ref(),
            'membership_expires_at_utc' => $entitlement->expires_at_utc()->format('Y-m-d H:i:s.u'),
            'redeemed_at_utc' => $now_sql,
        );
        $write = new EVC_Prepared_Redemption_Write($ledger, $snapshot, array(
            'event_type' => 'redemption',
            'outcome' => EVC_Prepared_Redemption_Outcome::REDEEMED,
            'member_id' => $member['id'],
            'request_id' => $request_id,
            'staff_wp_user_id' => $actor->wp_user_id(),
            'occurred_at_utc' => $now_sql,
            'details' => $this->details(array(
                'benefit_type' => $benefit_type,
                'business_date' => $business_date,
                'coffee_code' => $order->coffee_code(),
                'catalogue_version' => $order->catalogue_version(),
                'prep_hash' => $order->prep_hash(),
                'fingerprint_version' => EVC_Prep_Canonical::FINGERPRINT_VERSION,
            )),
        ));

        // 8. One atomic transaction for the three records.
        for ($attempt = 1; ; $attempt++) {
            try {
                $redemption_id = $this->store->commit_redemption($write);
                return EVC_Prepared_Redemption_Outcome::redeemed(self::view(
                    $redemption_id,
                    $member_public_id,
                    $ledger,
                    $snapshot
                ));
            } catch (EVC_Db_Duplicate_Key_Exception $e) {
                // Already rolled back: the reads below see the committed winner.
                return $this->resolve_duplicate($request_id, $fingerprint, $canonical, $member, $benefit_type, $business_date);
            } catch (EVC_Db_Retryable_Exception $e) {
                if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS) {
                    $this->report('retry_exhausted', $e);
                    return EVC_Prepared_Redemption_Outcome::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, array('reason' => 'transaction_retry_exhausted'));
                }
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                // Never retried here and never reported as a failure: the coffee
                // may already be recorded. Only the same request id resolves it.
                $this->report('commit_unknown', $e);
                return EVC_Prepared_Redemption_Outcome::unresolved(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $request_id);
            }
        }
    }

    private function resolve_duplicate(string $request_id, string $fingerprint, string $canonical, array $member, string $benefit_type, string $business_date): EVC_Prepared_Redemption_Outcome {
        $same_request = $this->store->find_by_request_id($request_id);
        if ($same_request !== null) {
            return $this->replay_or_reject($same_request, $fingerprint, $canonical, $member['id']);
        }
        $winner = $this->store->find_for_day($member['id'], $benefit_type, $business_date);
        if ($winner !== null) {
            $this->audit_rejection(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $member['id'], array('business_date' => $business_date));
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, array(
                'business_date' => (string) $winner['business_date'],
                'redeemed_at_utc' => (string) $winner['redeemed_at_utc'],
            ));
        }
        throw new RuntimeException('Duplicate key reported but no conflicting redemption is visible.');
    }

    /**
     * A committed record exists for this request id. Replays it only when the
     * payload is identical AND its stored snapshot and handoff records are
     * intact; never exposes another payload's data. A replay NEVER instructs
     * preparation (see EVC_Prepared_Redemption_Outcome::replayed()).
     */
    private function replay_or_reject(array $stored, string $fingerprint, string $canonical, ?int $member_id): EVC_Prepared_Redemption_Outcome {
        $ledger = $stored['ledger'];
        if (!is_string($ledger['request_fingerprint']) || !hash_equals($ledger['request_fingerprint'], $fingerprint)) {
            $this->audit_rejection(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT, $member_id, array());
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT);
        }
        $problem = EVC_Prepared_Redemption_Record::snapshot_problem($ledger, $stored['snapshots'], $canonical);
        if ($problem === null) {
            $problem = EVC_Prepared_Redemption_Record::handoff_problem($ledger, $stored['handoffs']);
        }
        if ($problem !== null) {
            $this->audit_rejection(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, (int) $ledger['member_id'], array('reason' => $problem));
            return EVC_Prepared_Redemption_Outcome::failure(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, array('reason' => $problem));
        }
        // Today's Athens date only distinguishes "earlier day" from "today";
        // it never re-decides eligibility, catalogue or membership.
        if ($this->now === null) {
            $this->now = EVC_Clock::to_utc($this->clock->now());
        }
        return EVC_Prepared_Redemption_Outcome::replayed(
            self::view((int) $ledger['id'], (string) $ledger['member_public_id'], $ledger, $stored['snapshots'][0]),
            (string) $ledger['business_date'] === EVC_Clock::business_date($this->now),
            count($stored['handoffs']) === 1 ? EVC_Handoff_Record::view($stored['handoffs'][0]) : null
        );
    }

    /**
     * The active catalogue, or null unless it is authorised on the SERVER:
     * an "approved" status needs a matching owner approval digest; an
     * illustrative catalogue needs the explicit test-only opt-in.
     */
    private function authorised_catalogue(): ?EVC_Prep_Catalogue {
        $data = $this->authority->active_catalogue_data();
        try {
            $catalogue = EVC_Prep_Catalogue::from_array($data);
        } catch (InvalidArgumentException $e) {
            $this->report('catalogue_invalid', $e);
            return null;
        }
        if (!$catalogue->is_approved()) {
            return $this->illustrative_opt_in === self::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS ? $catalogue : null;
        }
        $approved = $this->authority->approved_digest($catalogue->version());
        if ($approved === null || !hash_equals($approved, EVC_Prep_Catalogue_Digest::of($data))) {
            return null;
        }
        return $catalogue;
    }

    /** Public view of a committed redemption, identical for the original call and every replay. */
    private static function view(int $redemption_id, string $member_public_id, array $ledger, array $snapshot): array {
        return array(
            'redemption_id' => $redemption_id,
            'member_public_id' => $member_public_id,
            'benefit_type' => (string) $ledger['benefit_type'],
            'business_date' => (string) $ledger['business_date'],
            'redeemed_at_utc' => (string) $ledger['redeemed_at_utc'],
            'request_id' => (string) $ledger['request_id'],
            'preparation' => $snapshot,
        );
    }

    private static function rejected(EVC_Prep_Rejection $r): EVC_Prepared_Redemption_Outcome {
        return EVC_Prepared_Redemption_Outcome::failure($r->kind(), $r->reason() === null ? array() : array('reason' => $r->reason()));
    }

    private function details(array $details): array {
        if ($this->actor !== null && $this->actor->session_ref() !== null) {
            $details['session_ref'] = $this->actor->session_ref();
        }
        return $details;
    }

    /** Rejections are audited outside any transaction; an audit failure never changes the outcome. */
    private function audit_rejection(string $outcome, ?int $member_id, array $details): void {
        try {
            if ($this->now === null) {
                $this->now = EVC_Clock::to_utc($this->clock->now());
            }
            $this->store->record_rejection(array(
                'event_type' => 'redemption',
                'outcome' => $outcome,
                'member_id' => $member_id,
                'request_id' => $this->request_id,
                'staff_wp_user_id' => $this->actor === null ? null : $this->actor->wp_user_id(),
                'occurred_at_utc' => $this->now->format('Y-m-d H:i:s.u'),
                'details' => $this->details($details),
            ));
        } catch (Throwable $e) {
            $this->report('audit_rejection', $e);
        }
    }

    /** Passes only the exception class and DB codes to the reporter: no messages. */
    private function report(string $stage, Throwable $e): void {
        if ($this->error_reporter === null) {
            return;
        }
        try {
            call_user_func($this->error_reporter, array(
                'stage' => $stage,
                'exception' => get_class($e),
                'sql_state' => $e instanceof EVC_Db_Exception ? $e->sql_state() : null,
                'driver_code' => $e instanceof EVC_Db_Exception ? $e->driver_code() : null,
            ));
        } catch (Throwable $ignored) {
            // A broken reporter must not change the redemption outcome.
        }
    }
}

/**
 * Integrity of a stored redemption as read back (shared by the redemption
 * service, the handoff service and the read model). Every check fails closed.
 */
final class EVC_Prepared_Redemption_Record {
    /**
     * Null when the ledger row has exactly one intact snapshot that matches
     * the row (and, when given, the replayed request); otherwise the problem.
     *
     * @param array[] $snapshots
     */
    public static function snapshot_problem(array $ledger, array $snapshots, ?string $canonical): ?string {
        if ($ledger['fingerprint_version'] !== EVC_Prep_Canonical::FINGERPRINT_VERSION) {
            return 'fingerprint_version';
        }
        if (count($snapshots) === 0) {
            return 'snapshot_missing';
        }
        if (count($snapshots) !== 1) {
            return 'snapshot_conflict';
        }
        $snapshot = $snapshots[0];
        try {
            $stored_canonical = EVC_Prep_Snapshot::verify($snapshot);
        } catch (EVC_Prep_Snapshot_Invalid $e) {
            return 'snapshot_corrupt';
        }
        if (($canonical !== null && !hash_equals($stored_canonical, $canonical))
            || !is_string($ledger['prep_hash']) || !hash_equals($ledger['prep_hash'], $snapshot['prep_hash'])
            || !is_string($ledger['snapshot_hash']) || !hash_equals($ledger['snapshot_hash'], $snapshot['snapshot_hash'])
            || $ledger['coffee_code'] !== $snapshot['coffee_code']
            || $ledger['catalogue_version'] !== $snapshot['catalogue_version']
            || !hash_equals((string) $ledger['request_fingerprint'], EVC_Prep_Canonical::fingerprint_v2(
                (string) $ledger['member_public_id'],
                (string) $ledger['benefit_type'],
                (int) $ledger['staff_wp_user_id'],
                $snapshot['prep_hash']
            ))) {
            return 'snapshot_conflict';
        }
        return null;
    }

    /**
     * Null when the redemption has no handoff confirmation or exactly one that
     * is intact and bound to THIS redemption; otherwise the problem.
     *
     * @param array[] $handoffs
     */
    public static function handoff_problem(array $ledger, array $handoffs): ?string {
        if (count($handoffs) > 1) {
            return 'handoff_conflict';
        }
        return count($handoffs) === 1 ? EVC_Handoff_Record::problem($handoffs[0], $ledger) : null;
    }
}
