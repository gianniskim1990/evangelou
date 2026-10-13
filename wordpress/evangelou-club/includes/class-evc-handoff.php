<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03D.C — safe fulfillment contract core (pure PHP, definitions only, NOT
 * wired; no SQL, REST, hooks or UI).
 *
 * A redemption RECORD (ledger + snapshot + audit, EVC_Prepared_Redemption_
 * Service) consumes the entitlement. A HANDOFF CONFIRMATION is a separate,
 * later statement by staff about that record:
 *
 *   unconfirmed  "No handoff confirmation has been recorded."
 *                NOT proof that no coffee was handed over.
 *   confirmed    "Staff recorded a handoff confirmation."
 *                NOT proof that a coffee physically changed hands.
 *
 * Software cannot observe a physical handoff. This contract only records who
 * stated what, and when the statement was recorded, so that a replay never
 * looks like a new coffee and an unconfirmed redemption is visible for review.
 *
 * A confirmation is ONE atomic transaction of one handoff record and one audit
 * event. It never touches the ledger, the snapshot or any entitlement, and
 * never grants a coffee. Claims:
 *
 *   handed_now              handoff_at_utc = recorded_at_utc (the staff
 *                           statement is "handed over now")
 *   already_handed_earlier  handoff_at_utc = null: the real handoff time is
 *                           NOT known; recorded_at_utc is only when the
 *                           statement was recorded. No time is ever invented.
 */
final class EVC_Handoff_Record {
    const FINGERPRINT_VERSION = 'evc-handoff-v1';
    const CLAIM_HANDED_NOW = 'handed_now';
    const CLAIM_ALREADY_HANDED_EARLIER = 'already_handed_earlier';
    const CLAIMS = array(self::CLAIM_HANDED_NOW, self::CLAIM_ALREADY_HANDED_EARLIER);
    /** Stored keys (plus `id` once committed). No free-text field exists. */
    const KEYS = array(
        'handoff_request_id', 'redemption_id', 'redemption_request_id', 'member_id', 'claim',
        'handoff_fingerprint', 'fingerprint_version', 'staff_wp_user_id', 'recorded_at_utc', 'handoff_at_utc',
    );
    const TIME_STATED_AT_RECORDING = 'stated_at_recording';
    const TIME_NOT_RECORDED = 'not_recorded';

    /**
     * Binds a handoff request id to its exact statement: member, redemption,
     * claim and staff actor. Excludes time, session and device, so an exact
     * retry (after re-login, on another tablet or after midnight) replays.
     */
    public static function fingerprint(string $member_public_id, string $redemption_request_id, string $claim, int $staff_wp_user_id): string {
        return hash('sha256', implode("\n", array(
            self::FINGERPRINT_VERSION,
            $member_public_id,
            $redemption_request_id,
            $claim,
            (string) $staff_wp_user_id,
        )));
    }

    /** The handoff time implied by a claim: never invented for an earlier handoff. */
    public static function handoff_at(string $claim, string $recorded_at_utc): ?string {
        return $claim === self::CLAIM_HANDED_NOW ? $recorded_at_utc : null;
    }

    /**
     * Null when the stored confirmation is intact and bound to this ledger row
     * (ledger as read back, with member_public_id); otherwise the problem.
     *
     * @param mixed $h
     */
    public static function problem($h, array $ledger): ?string {
        if (!EVC_Prep_Catalogue::is_object_with($h, array_merge(array('id'), self::KEYS))
            || $h['fingerprint_version'] !== self::FINGERPRINT_VERSION
            || !in_array($h['claim'], self::CLAIMS, true)
            || !is_string($h['recorded_at_utc'])
            || $h['handoff_at_utc'] !== self::handoff_at($h['claim'], $h['recorded_at_utc'])) {
            return 'handoff_corrupt';
        }
        if ($h['redemption_id'] !== $ledger['id'] || $h['member_id'] !== $ledger['member_id']
            || $h['redemption_request_id'] !== $ledger['request_id']
            || !is_string($h['handoff_fingerprint']) || !is_int($h['staff_wp_user_id'])
            || !hash_equals($h['handoff_fingerprint'], self::fingerprint((string) $ledger['member_public_id'], (string) $ledger['request_id'], $h['claim'], $h['staff_wp_user_id']))) {
            return 'handoff_conflict';
        }
        return null;
    }

    /** What may be shown about a confirmation: no internal ids, actors or sessions. */
    public static function view(array $h): array {
        return array(
            'handoff_request_id' => $h['handoff_request_id'],
            'redemption_request_id' => $h['redemption_request_id'],
            'claim' => $h['claim'],
            'confirmation_recorded_at_utc' => $h['recorded_at_utc'],
            'handoff_at_utc' => $h['handoff_at_utc'],
            'handoff_time' => $h['handoff_at_utc'] === null ? self::TIME_NOT_RECORDED : self::TIME_STATED_AT_RECORDING,
        );
    }
}

/**
 * Storage of handoff confirmations next to the redemption ledger (the same
 * Club database in the future). Only test fakes implement it.
 */
interface EVC_Redemption_Fulfillment_Store {
    /**
     * The committed redemption with this request id, as
     * {ledger (with member_public_id), snapshots[], handoffs[]}, or null.
     */
    public function find_redemption(string $redemption_request_id): ?array;

    /** The committed handoff record with this handoff request id, or null. */
    public function find_handoff_by_request_id(string $handoff_request_id): ?array;

    /** Every committed redemption of one Athens business date, each as find_redemption() returns it. */
    public function redemptions_for_business_date(string $business_date): array;

    /**
     * Writes the handoff record and its audit event in ONE transaction: both
     * or neither. Never writes the ledger, a snapshot or an entitlement.
     *
     * @return int new handoff id
     * @throws EVC_Db_Duplicate_Key_Exception  after rollback (uq_handoffs_request, or one handoff per redemption)
     * @throws EVC_Db_Retryable_Exception      after rollback
     * @throws EVC_Db_Commit_Unknown_Exception COMMIT failed: the outcome is unknown
     * @throws Throwable                       anything else, after rollback
     */
    public function commit_handoff(EVC_Handoff_Write $write): int;

    /** Records a rejected attempt OUTSIDE any transaction (best effort). */
    public function record_rejection(array $event): void;
}

/**
 * Server-side permission check for an authenticated staff actor (in the
 * future: WordPress capabilities of the Club staff / manager roles). Only
 * test fakes implement it.
 */
interface EVC_Club_Staff_Authorizer {
    const CONFIRM_HANDOFF = 'evc_confirm_handoff';
    const VIEW_REDEMPTION_STATUS = 'evc_view_redemption_status';
    const REVIEW_FULFILLMENT = 'evc_review_fulfillment';

    public function allows(EVC_Staff_Actor $actor, string $capability): bool;
}

/**
 * Whether a confirmation for a redemption of an EARLIER Athens business date
 * may still be recorded. The grace window is NOT owner-approved: the only
 * shipped policy refuses every late confirmation.
 */
interface EVC_Late_Handoff_Policy {
    public function allows_late_confirmation(string $redemption_business_date, DateTimeImmutable $now_utc): bool;
}

/** Fail closed until the owner approves a grace window. */
final class EVC_No_Late_Handoff_Policy implements EVC_Late_Handoff_Policy {
    public function allows_late_confirmation(string $redemption_business_date, DateTimeImmutable $now_utc): bool {
        return false;
    }
}

/** One handoff record + its audit event, checked for mutual consistency before any write. */
final class EVC_Handoff_Write {
    const AUDIT_KEYS = array('event_type', 'outcome', 'member_id', 'redemption_id', 'request_id', 'staff_wp_user_id', 'occurred_at_utc', 'details');

    /** @var array */
    private $record;
    /** @var array */
    private $audit;

    public function __construct(array $record, array $audit) {
        if (!EVC_Prep_Catalogue::is_object_with($record, EVC_Handoff_Record::KEYS) || !EVC_Prep_Catalogue::is_object_with($audit, self::AUDIT_KEYS)) {
            throw new InvalidArgumentException('Inconsistent handoff write: shape.');
        }
        if ($record['fingerprint_version'] !== EVC_Handoff_Record::FINGERPRINT_VERSION
            || !in_array($record['claim'], EVC_Handoff_Record::CLAIMS, true)
            || $record['handoff_at_utc'] !== EVC_Handoff_Record::handoff_at($record['claim'], $record['recorded_at_utc'])
            || $audit['event_type'] !== 'handoff'
            || $audit['outcome'] !== EVC_Handoff_Outcome::CONFIRMED
            || $audit['member_id'] !== $record['member_id']
            || $audit['redemption_id'] !== $record['redemption_id']
            || $audit['request_id'] !== $record['handoff_request_id']
            || $audit['staff_wp_user_id'] !== $record['staff_wp_user_id']
            || $audit['occurred_at_utc'] !== $record['recorded_at_utc']) {
            throw new InvalidArgumentException('Inconsistent handoff write: records disagree.');
        }
        $this->record = $record;
        $this->audit = $audit;
    }

    public function record(): array {
        return $this->record;
    }

    public function audit(): array {
        return $this->audit;
    }
}

final class EVC_Handoff_Outcome {
    /** A new confirmation was recorded by this request. */
    const CONFIRMED = 'handoff_confirmed';
    /** Exact retry of this handoff request id: the stored confirmation. */
    const REPLAYED = 'handoff_replayed';
    /** Another confirmation is already recorded for this redemption: nothing new is written. */
    const ALREADY_CONFIRMED = 'handoff_already_confirmed';
    const INVALID_REQUEST = 'invalid_request';
    const FORBIDDEN = 'forbidden';
    /** Unknown redemption OR a redemption of another member (indistinguishable on purpose). */
    const REDEMPTION_NOT_FOUND = 'redemption_not_found';
    const LATE_CONFIRMATION_NOT_AUTHORISED = 'late_confirmation_not_authorised';
    const IDEMPOTENCY_CONFLICT = 'idempotency_key_reused';
    const RECORD_INTEGRITY_FAILURE = 'record_integrity_failure';
    const OUTCOME_UNKNOWN = 'outcome_unknown';
    const SERVER_ERROR = 'server_error';

    /** @var string */
    private $kind;
    /** @var array|null */
    private $handoff;
    /** @var array */
    private $details;
    /** @var string|null */
    private $retry_handoff_request_id;

    private function __construct(string $kind, ?array $handoff, array $details, ?string $retry_handoff_request_id) {
        $this->kind = $kind;
        $this->handoff = $handoff;
        $this->details = $details;
        $this->retry_handoff_request_id = $retry_handoff_request_id;
    }

    public static function recorded(string $kind, array $handoff): self {
        if ($kind !== self::CONFIRMED && $kind !== self::REPLAYED && $kind !== self::ALREADY_CONFIRMED) {
            throw new InvalidArgumentException('Not a recorded handoff outcome.');
        }
        return new self($kind, $handoff, array(), null);
    }

    public static function failure(string $kind, array $details = array()): self {
        if (in_array($kind, array(self::CONFIRMED, self::REPLAYED, self::ALREADY_CONFIRMED, self::OUTCOME_UNKNOWN, self::SERVER_ERROR), true)) {
            throw new InvalidArgumentException('Not a definitive handoff failure.');
        }
        return new self($kind, null, $details, null);
    }

    /** Nothing is known to be recorded: retry with the SAME handoff request id, then read the server state. */
    public static function unresolved(string $kind, ?string $handoff_request_id, array $details = array()): self {
        if ($kind !== self::OUTCOME_UNKNOWN && $kind !== self::SERVER_ERROR) {
            throw new InvalidArgumentException('Not an unresolved handoff outcome.');
        }
        return new self($kind, null, $details, $handoff_request_id);
    }

    public function kind(): string {
        return $this->kind;
    }

    /** "confirmed" when a confirmation is recorded for the redemption; null when nothing is known. */
    public function fulfillment(): ?string {
        return $this->handoff !== null ? EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED : null;
    }

    /** The stored confirmation (view), or null. */
    public function handoff(): ?array {
        return $this->handoff;
    }

    public function details(): array {
        return $this->details;
    }

    public function retry_handoff_request_id(): ?string {
        return $this->retry_handoff_request_id;
    }
}

final class EVC_Handoff_Service {
    const MAX_TRANSACTION_ATTEMPTS = 3;
    const REQUEST_ID_PATTERN = EVC_Prepared_Redemption_Service::REQUEST_ID_PATTERN;
    const DEVICE_REF_PATTERN = '/^[0-9a-f]{32}$/D';
    const BODY_KEYS = array('handoff_request_id', 'redemption_request_id', 'claim');

    /** @var EVC_Redemption_Fulfillment_Store */
    private $store;
    /** @var EVC_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Late_Handoff_Policy */
    private $late_policy;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable|null */
    private $error_reporter;

    /** @var EVC_Staff_Actor|null */
    private $actor;
    /** @var string|null */
    private $handoff_request_id;
    /** @var string|null audit only, never an authority */
    private $device_ref;
    /** @var DateTimeImmutable|null */
    private $now;

    public function __construct(
        EVC_Redemption_Fulfillment_Store $store,
        EVC_Club_Staff_Authorizer $authorizer,
        EVC_Late_Handoff_Policy $late_policy,
        EVC_Clock_Source $clock,
        ?callable $error_reporter = null
    ) {
        $this->store = $store;
        $this->authorizer = $authorizer;
        $this->late_policy = $late_policy;
        $this->clock = $clock;
        $this->error_reporter = $error_reporter;
    }

    /**
     * @param mixed $member_public_id from the URL only
     * @param mixed $body decoded JSON: handoff_request_id, redemption_request_id,
     *   claim, optional device_ref (audit only; never identifies or authorises)
     */
    public function confirm($member_public_id, EVC_Staff_Actor $actor, $body): EVC_Handoff_Outcome {
        $this->actor = $actor;
        $this->handoff_request_id = null;
        $this->device_ref = null;
        $this->now = null;
        try {
            return $this->confirm_or_throw($member_public_id, $actor, $body);
        } catch (Throwable $e) {
            $this->report('confirm', $e);
            return EVC_Handoff_Outcome::unresolved(EVC_Handoff_Outcome::SERVER_ERROR, $this->handoff_request_id);
        }
    }

    private function confirm_or_throw($member_public_id, EVC_Staff_Actor $actor, $body): EVC_Handoff_Outcome {
        // 0. Permission before any read.
        if (!$this->authorizer->allows($actor, EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF)) {
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::FORBIDDEN);
        }
        // 1. Shape: no time, actor, member id, entitlement or free text in the body.
        if (!EVC_Member_Id::is_valid_format($member_public_id)
            || !EVC_Prep_Catalogue::is_object_with($body, self::BODY_KEYS, array('device_ref'))
            || !is_string($body['handoff_request_id']) || !preg_match(self::REQUEST_ID_PATTERN, $body['handoff_request_id'])
            || !is_string($body['redemption_request_id']) || !preg_match(self::REQUEST_ID_PATTERN, $body['redemption_request_id'])
            || !in_array($body['claim'], EVC_Handoff_Record::CLAIMS, true)
            || (isset($body['device_ref']) && !(is_string($body['device_ref']) && preg_match(self::DEVICE_REF_PATTERN, $body['device_ref'])))) {
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::INVALID_REQUEST);
        }
        $handoff_request_id = $body['handoff_request_id'];
        $this->handoff_request_id = $handoff_request_id;
        $this->device_ref = isset($body['device_ref']) ? $body['device_ref'] : null;
        $fingerprint = EVC_Handoff_Record::fingerprint($member_public_id, $body['redemption_request_id'], $body['claim'], $actor->wp_user_id());

        // 2. Exact retry before any date or policy decision.
        $existing = $this->store->find_handoff_by_request_id($handoff_request_id);
        if ($existing !== null) {
            return $this->replay_or_reject($existing, $fingerprint, $member_public_id);
        }

        // 3. The redemption, bound to the member of the URL. Unknown and
        //    another member's redemption get the SAME answer.
        $stored = $this->store->find_redemption($body['redemption_request_id']);
        if ($stored === null || $stored['ledger']['member_public_id'] !== $member_public_id) {
            $this->audit_rejection(EVC_Handoff_Outcome::REDEMPTION_NOT_FOUND, null, null);
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::REDEMPTION_NOT_FOUND);
        }
        $ledger = $stored['ledger'];

        // 4. Intact prepared redemption and handoff records only.
        $problem = self::record_problem($stored);
        if ($problem !== null) {
            $this->audit_rejection(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, (int) $ledger['member_id'], (int) $ledger['id'], array('reason' => $problem));
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, array('reason' => $problem));
        }

        // 5. Already confirmed: never a second record. If the stored confirmation
        //    carries THIS handoff id (another tablet committed the same request
        //    after our lookup), it is an exact retry or a conflict, not "another".
        if (count($stored['handoffs']) === 1 && $stored['handoffs'][0]['handoff_request_id'] === $handoff_request_id) {
            return $this->replay_or_reject($stored['handoffs'][0], $fingerprint, $member_public_id);
        }
        if (count($stored['handoffs']) === 1) {
            $this->audit_rejection(EVC_Handoff_Outcome::ALREADY_CONFIRMED, (int) $ledger['member_id'], (int) $ledger['id']);
            return EVC_Handoff_Outcome::recorded(EVC_Handoff_Outcome::ALREADY_CONFIRMED, EVC_Handoff_Record::view($stored['handoffs'][0]));
        }

        // 6. Europe/Athens business date; a later day needs the (unapproved) late policy.
        $this->now = EVC_Clock::to_utc($this->clock->now());
        $today = EVC_Clock::business_date($this->now);
        $business_date = (string) $ledger['business_date'];
        if ($business_date !== $today
            && !(strcmp($business_date, $today) < 0 && $this->late_policy->allows_late_confirmation($business_date, $this->now))) {
            $this->audit_rejection(EVC_Handoff_Outcome::LATE_CONFIRMATION_NOT_AUTHORISED, (int) $ledger['member_id'], (int) $ledger['id']);
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::LATE_CONFIRMATION_NOT_AUTHORISED, array('business_date' => $business_date));
        }

        // 7. One atomic transaction: handoff record + audit event.
        $recorded_at = $this->now->format('Y-m-d H:i:s.u');
        $record = array(
            'handoff_request_id' => $handoff_request_id,
            'redemption_id' => $ledger['id'],
            'redemption_request_id' => $ledger['request_id'],
            'member_id' => $ledger['member_id'],
            'claim' => $body['claim'],
            'handoff_fingerprint' => $fingerprint,
            'fingerprint_version' => EVC_Handoff_Record::FINGERPRINT_VERSION,
            'staff_wp_user_id' => $actor->wp_user_id(),
            'recorded_at_utc' => $recorded_at,
            'handoff_at_utc' => EVC_Handoff_Record::handoff_at($body['claim'], $recorded_at),
        );
        $write = new EVC_Handoff_Write($record, array(
            'event_type' => 'handoff',
            'outcome' => EVC_Handoff_Outcome::CONFIRMED,
            'member_id' => $ledger['member_id'],
            'redemption_id' => $ledger['id'],
            'request_id' => $handoff_request_id,
            'staff_wp_user_id' => $actor->wp_user_id(),
            'occurred_at_utc' => $recorded_at,
            'details' => $this->details(array('redemption_request_id' => $ledger['request_id'], 'claim' => $body['claim'])),
        ));
        for ($attempt = 1; ; $attempt++) {
            try {
                $id = $this->store->commit_handoff($write);
                $record['id'] = $id;
                return EVC_Handoff_Outcome::recorded(EVC_Handoff_Outcome::CONFIRMED, EVC_Handoff_Record::view($record));
            } catch (EVC_Db_Duplicate_Key_Exception $e) {
                return $this->resolve_duplicate($handoff_request_id, $fingerprint, $member_public_id, $ledger['request_id']);
            } catch (EVC_Db_Retryable_Exception $e) {
                if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS) {
                    $this->report('retry_exhausted', $e);
                    return EVC_Handoff_Outcome::unresolved(EVC_Handoff_Outcome::SERVER_ERROR, $handoff_request_id, array('reason' => 'transaction_retry_exhausted'));
                }
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                // Never claimed as confirmed: recover with the same handoff id.
                $this->report('commit_unknown', $e);
                return EVC_Handoff_Outcome::unresolved(EVC_Handoff_Outcome::OUTCOME_UNKNOWN, $handoff_request_id);
            }
        }
    }

    /** Fresh reads after a rolled-back duplicate: the same request, or a competing confirmation. */
    private function resolve_duplicate(string $handoff_request_id, string $fingerprint, string $member_public_id, string $redemption_request_id): EVC_Handoff_Outcome {
        $same = $this->store->find_handoff_by_request_id($handoff_request_id);
        if ($same !== null) {
            return $this->replay_or_reject($same, $fingerprint, $member_public_id);
        }
        $stored = $this->store->find_redemption($redemption_request_id);
        if ($stored !== null && count($stored['handoffs']) > 0) {
            $problem = self::record_problem($stored);
            if ($problem !== null) {
                return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, array('reason' => $problem));
            }
            $this->audit_rejection(EVC_Handoff_Outcome::ALREADY_CONFIRMED, (int) $stored['ledger']['member_id'], (int) $stored['ledger']['id']);
            return EVC_Handoff_Outcome::recorded(EVC_Handoff_Outcome::ALREADY_CONFIRMED, EVC_Handoff_Record::view($stored['handoffs'][0]));
        }
        throw new RuntimeException('Duplicate key reported but no conflicting handoff is visible.');
    }

    /** Exact retry: re-verified against the current records before it is reported. */
    private function replay_or_reject(array $existing, string $fingerprint, string $member_public_id): EVC_Handoff_Outcome {
        if (!is_string($existing['handoff_fingerprint']) || !hash_equals($existing['handoff_fingerprint'], $fingerprint)) {
            $this->audit_rejection(EVC_Handoff_Outcome::IDEMPOTENCY_CONFLICT, null, null);
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::IDEMPOTENCY_CONFLICT);
        }
        $stored = $this->store->find_redemption((string) $existing['redemption_request_id']);
        $problem = $stored === null || $stored['ledger']['member_public_id'] !== $member_public_id ? 'handoff_orphan' : self::record_problem($stored);
        if ($problem === null && (count($stored['handoffs']) !== 1 || $stored['handoffs'][0]['handoff_request_id'] !== $existing['handoff_request_id'])) {
            $problem = 'handoff_conflict';
        }
        if ($problem !== null) {
            $this->audit_rejection(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, null, null, array('reason' => $problem));
            return EVC_Handoff_Outcome::failure(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, array('reason' => $problem));
        }
        return EVC_Handoff_Outcome::recorded(EVC_Handoff_Outcome::REPLAYED, EVC_Handoff_Record::view($stored['handoffs'][0]));
    }

    /** Integrity of a stored prepared redemption and its handoff records (fail closed). */
    public static function record_problem(array $stored): ?string {
        $problem = EVC_Prepared_Redemption_Record::snapshot_problem($stored['ledger'], $stored['snapshots'], null);
        return $problem !== null ? $problem : EVC_Prepared_Redemption_Record::handoff_problem($stored['ledger'], $stored['handoffs']);
    }

    private function details(array $details): array {
        if ($this->actor !== null && $this->actor->session_ref() !== null) {
            $details['session_ref'] = $this->actor->session_ref();
        }
        if ($this->device_ref !== null) {
            $details['device_ref'] = $this->device_ref;
        }
        return $details;
    }

    private function audit_rejection(string $outcome, ?int $member_id, ?int $redemption_id, array $details = array()): void {
        try {
            if ($this->now === null) {
                $this->now = EVC_Clock::to_utc($this->clock->now());
            }
            $this->store->record_rejection(array(
                'event_type' => 'handoff',
                'outcome' => $outcome,
                'member_id' => $member_id,
                'redemption_id' => $redemption_id,
                'request_id' => $this->handoff_request_id,
                'staff_wp_user_id' => $this->actor === null ? null : $this->actor->wp_user_id(),
                'occurred_at_utc' => $this->now->format('Y-m-d H:i:s.u'),
                'details' => $this->details($details),
            ));
        } catch (Throwable $e) {
            $this->report('audit_rejection', $e);
        }
    }

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
            // A broken reporter must not change the outcome.
        }
    }
}

/** Result of a read-model query: a kind plus minimised data (never internal ids, actors or sessions). */
final class EVC_Fulfillment_Read_Result {
    const FOUND = 'found';
    const FORBIDDEN = 'forbidden';
    const INVALID_REQUEST = 'invalid_request';
    /** Unknown OR another member's redemption (indistinguishable on purpose). */
    const NOT_FOUND = 'not_found';
    const RECORD_INTEGRITY_FAILURE = 'record_integrity_failure';
    const SERVER_ERROR = 'server_error';

    /** @var string */
    private $kind;
    /** @var array|null */
    private $data;

    public function __construct(string $kind, ?array $data = null) {
        $this->kind = $kind;
        $this->data = $data;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function data(): ?array {
        return $this->data;
    }
}

/**
 * Pure read model (no endpoint in this task). Member-linked history is
 * personal data: every query needs a server-side capability, returns only
 * what the role needs and never internal ids, staff ids, session or device
 * references. A status is INFORMATION ONLY: it never authorises a redemption
 * or a confirmation (both re-check everything themselves).
 */
final class EVC_Fulfillment_Read_Model {
    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    /** @var EVC_Redemption_Fulfillment_Store */
    private $store;
    /** @var EVC_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Clock_Source */
    private $clock;

    public function __construct(EVC_Redemption_Fulfillment_Store $store, EVC_Club_Staff_Authorizer $authorizer, EVC_Clock_Source $clock) {
        $this->store = $store;
        $this->authorizer = $authorizer;
        $this->clock = $clock;
    }

    /**
     * Status of ONE redemption of the member being served.
     *
     * @param mixed $member_public_id
     * @param mixed $redemption_request_id
     */
    public function redemption_status(EVC_Staff_Actor $actor, $member_public_id, $redemption_request_id): EVC_Fulfillment_Read_Result {
        if (!$this->authorizer->allows($actor, EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS)) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::FORBIDDEN);
        }
        if (!EVC_Member_Id::is_valid_format($member_public_id)
            || !is_string($redemption_request_id) || !preg_match(EVC_Prepared_Redemption_Service::REQUEST_ID_PATTERN, $redemption_request_id)) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::INVALID_REQUEST);
        }
        try {
            $stored = $this->store->find_redemption($redemption_request_id);
            if ($stored === null || $stored['ledger']['member_public_id'] !== $member_public_id) {
                return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::NOT_FOUND);
            }
            if (EVC_Handoff_Service::record_problem($stored) !== null) {
                return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::RECORD_INTEGRITY_FAILURE);
            }
            $ledger = $stored['ledger'];
            $snapshot = $stored['snapshots'][0];
            $handoff = count($stored['handoffs']) === 1 ? EVC_Handoff_Record::view($stored['handoffs'][0]) : null;
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::FOUND, array(
                'redemption_request_id' => (string) $ledger['request_id'],
                'business_date' => (string) $ledger['business_date'],
                'redeemed_at_utc' => (string) $ledger['redeemed_at_utc'],
                'is_current_business_date' => (string) $ledger['business_date'] === EVC_Clock::business_date(EVC_Clock::to_utc($this->clock->now())),
                'coffee_label' => $snapshot['coffee_label'],
                'modifiers' => array_column($snapshot['modifiers'], 'label', 'group_label'),
                'quick_notes' => array_column($snapshot['quick_notes'], 'label'),
                'fulfillment' => $handoff !== null ? EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED : EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED,
                'handoff' => $handoff,
            ));
        } catch (Throwable $e) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::SERVER_ERROR);
        }
    }

    /** Today's (Athens) redemptions without a recorded confirmation, for the tills: no member ids. */
    public function unconfirmed_today(EVC_Staff_Actor $actor): EVC_Fulfillment_Read_Result {
        if (!$this->authorizer->allows($actor, EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS)) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::FORBIDDEN);
        }
        return $this->unconfirmed(EVC_Clock::business_date(EVC_Clock::to_utc($this->clock->now())), false);
    }

    /** Unconfirmed redemptions of any Athens business date, for management review (opaque member id). */
    public function unconfirmed_for_review(EVC_Staff_Actor $actor, $business_date): EVC_Fulfillment_Read_Result {
        if (!$this->authorizer->allows($actor, EVC_Club_Staff_Authorizer::REVIEW_FULFILLMENT)) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::FORBIDDEN);
        }
        if (!is_string($business_date) || !preg_match(self::DATE_PATTERN, $business_date)
            || !checkdate((int) substr($business_date, 5, 2), (int) substr($business_date, 8, 2), (int) substr($business_date, 0, 4))) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::INVALID_REQUEST);
        }
        return $this->unconfirmed($business_date, true);
    }

    private function unconfirmed(string $business_date, bool $with_member): EVC_Fulfillment_Read_Result {
        try {
            $rows = array();
            $integrity_failures = 0;
            $legacy = 0;
            foreach ($this->store->redemptions_for_business_date($business_date) as $stored) {
                if ($stored['ledger']['fingerprint_version'] === EVC_Request_Fingerprint::VERSION) {
                    // v1-engine row: no preparation snapshot, so no handoff contract applies.
                    $legacy++;
                    continue;
                }
                if (EVC_Handoff_Service::record_problem($stored) !== null) {
                    $integrity_failures++;
                    continue;
                }
                if (count($stored['handoffs']) !== 0) {
                    continue;
                }
                $row = array(
                    'redemption_request_id' => (string) $stored['ledger']['request_id'],
                    'redeemed_at_utc' => (string) $stored['ledger']['redeemed_at_utc'],
                    'coffee_code' => (string) $stored['ledger']['coffee_code'],
                );
                if ($with_member) {
                    $row['member_public_id'] = (string) $stored['ledger']['member_public_id'];
                }
                $rows[] = $row;
            }
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::FOUND, array(
                'business_date' => $business_date,
                'unconfirmed' => $rows,
                'integrity_failures' => $integrity_failures,
                'legacy_without_preparation' => $legacy,
            ));
        } catch (Throwable $e) {
            return new EVC_Fulfillment_Read_Result(EVC_Fulfillment_Read_Result::SERVER_ERROR);
        }
    }
}
