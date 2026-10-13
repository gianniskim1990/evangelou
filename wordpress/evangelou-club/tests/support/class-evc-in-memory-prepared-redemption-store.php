<?php
/**
 * TEST-ONLY in-memory EVC_Prep_Redemption_Store (UI-03D.A). Never shipped
 * (the packager rejects this class).
 *
 * commit_redemption() stages the ledger row, the snapshot and the audit event
 * on a COPY of the state and swaps it in only at the very end, so a failure
 * at any point leaves the store unchanged (all three or none). It enforces the
 * same unique keys the SQL schema has (one per member-day, one per request id,
 * one snapshot per redemption) and the same foreign keys.
 *
 * Failure injection:
 *   $failures         FIFO of [point, Throwable]; a step throws the head entry
 *                     when its point matches. Points: before_ledger,
 *                     after_ledger, after_snapshot, after_audit, commit.
 *   $commit_unknown   'committed' | 'not_committed' | null: the next COMMIT
 *                     throws EVC_Db_Commit_Unknown_Exception after (or
 *                     without) applying the write.
 *   $after_request_lookup / $before_commit  one-shot callables: another till
 *                     acting at that moment (deterministic interleavings).
 *   $unavailable      every call throws (database down).
 *
 * UI-03D.C handoff confirmations (EVC_Redemption_Fulfillment_Store) share the
 * same state, as they will share the Club database: commit_handoff() stages
 * the handoff record and its audit event the same way, enforces one handoff
 * per redemption and one per handoff request id, and NEVER writes the ledger
 * or a snapshot. Handoff failure points: before_handoff, after_handoff,
 * after_handoff_audit, handoff_commit; $handoff_commit_unknown;
 * one-shot $after_handoff_lookup / $before_handoff_commit hooks.
 *
 * Single-process PHP says nothing about real database concurrency: the
 * hooks model interleavings, the SQL transaction must provide them for real.
 */
final class EVC_In_Memory_Prepared_Redemption_Store implements EVC_Prep_Redemption_Store, EVC_Redemption_Fulfillment_Store {
    const KEY_HANDOFF_REQUEST = 'uq_handoffs_request';
    const KEY_HANDOFF_REDEMPTION = 'PRIMARY';

    const KEY_ONE_PER_DAY = 'uq_redemptions_one_per_day';
    const KEY_REQUEST = 'uq_redemptions_request';

    /** @var array{members:array<string,array>,ledger:array<int,array>,by_request:array<string,int>,by_day:array<string,int>,snapshots:array<int,array>,audit:array<int,array>,next_id:int,next_audit_id:int} */
    private $state = array(
        'members' => array(),
        'ledger' => array(),
        'by_request' => array(),
        'by_day' => array(),
        'snapshots' => array(),
        'audit' => array(),
        'handoffs' => array(),
        'handoff_by_request' => array(),
        'handoff_by_redemption' => array(),
        'next_id' => 1,
        'next_audit_id' => 1,
        'next_handoff_id' => 1,
    );

    /** @var array<int,array{0:string,1:Throwable}> */
    public $failures = array();
    /** @var string|null */
    public $commit_unknown = null;
    /** @var callable|null */
    public $after_request_lookup;
    /** @var callable|null */
    public $before_commit;
    /** @var string|null */
    public $handoff_commit_unknown = null;
    /** @var callable|null */
    public $after_handoff_lookup;
    /** @var callable|null */
    public $before_handoff_commit;
    /** @var int */
    public $handoff_commit_attempts = 0;
    /** @var bool */
    public $unavailable = false;
    /** @var bool */
    public $rejections_fail = false;

    /** @var array[] rejection events (outside transactions) */
    public $rejections = array();
    /** @var int */
    public $commit_attempts = 0;
    /** @var string[] */
    public $points_reached = array();

    public function add_member(string $member_public_id, int $wp_user_id, string $status = 'enabled'): int {
        $id = count($this->state['members']) + 1;
        $this->state['members'][$member_public_id] = array(
            'id' => $id,
            'member_public_id' => $member_public_id,
            'wp_user_id' => $wp_user_id,
            'status' => $status,
        );
        return $id;
    }

    /** @var int reads of any kind (proves "rejected before any lookup") */
    public $reads = 0;

    public function find_member_by_public_id(string $member_public_id): ?array {
        $this->guard();
        $this->reads++;
        return $this->state['members'][$member_public_id] ?? null;
    }

    public function find_by_request_id(string $request_id): ?array {
        $this->guard();
        $this->reads++;
        $result = $this->read_request($request_id);
        if ($this->after_request_lookup !== null) {
            $hook = $this->after_request_lookup;
            $this->after_request_lookup = null;
            $hook($this);
        }
        return $result;
    }

    public function find_for_day(int $member_id, string $benefit_type, string $business_date): ?array {
        $this->guard();
        $this->reads++;
        $id = $this->state['by_day'][self::day_key($member_id, $benefit_type, $business_date)] ?? null;
        return $id === null ? null : $this->ledger_view($id);
    }

    public function commit_redemption(EVC_Prepared_Redemption_Write $write): int {
        $this->guard();
        $this->commit_attempts++;
        if ($this->before_commit !== null) {
            $hook = $this->before_commit;
            $this->before_commit = null;
            $hook($this);
        }
        $staged = $this->state;

        $this->reach('before_ledger');
        $row = $write->ledger();
        $member_exists = false;
        foreach ($staged['members'] as $m) {
            $member_exists = $member_exists || $m['id'] === $row['member_id'];
        }
        if (!$member_exists) {
            throw new EVC_Db_Exception('Club database foreign key violated.', '23000', 1452);
        }
        if (isset($staged['by_request'][$row['request_id']])) {
            throw new EVC_Db_Duplicate_Key_Exception(self::KEY_REQUEST, '23000', 1062);
        }
        $day = self::day_key($row['member_id'], $row['benefit_type'], $row['business_date']);
        if (isset($staged['by_day'][$day])) {
            throw new EVC_Db_Duplicate_Key_Exception(self::KEY_ONE_PER_DAY, '23000', 1062);
        }
        $id = $staged['next_id']++;
        $row['id'] = $id;
        $staged['ledger'][$id] = $row;
        $staged['by_request'][$row['request_id']] = $id;
        $staged['by_day'][$day] = $id;
        $this->reach('after_ledger');

        if (isset($staged['snapshots'][$id])) {
            throw new EVC_Db_Duplicate_Key_Exception('PRIMARY', '23000', 1062);
        }
        $staged['snapshots'][$id] = array($write->snapshot());
        $this->reach('after_snapshot');

        $event = $write->audit();
        $event['redemption_id'] = $id;
        $staged['audit'][$staged['next_audit_id']++] = $event;
        $this->reach('after_audit');

        $this->reach('commit');
        $unknown = $this->commit_unknown;
        $this->commit_unknown = null;
        if ($unknown === 'not_committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
        $this->state = $staged;
        if ($unknown === 'committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
        return $id;
    }

    public function record_rejection(array $event): void {
        $this->guard();
        if ($this->rejections_fail) {
            throw new EVC_Db_Exception('Club database query failed.', 'HY000', 2006);
        }
        $this->rejections[] = $event;
    }

    // ---- EVC_Redemption_Fulfillment_Store ----------------------------------------------------

    public function find_redemption(string $redemption_request_id): ?array {
        $this->guard();
        $this->reads++;
        return $this->read_request($redemption_request_id);
    }

    public function find_handoff_by_request_id(string $handoff_request_id): ?array {
        $this->guard();
        $this->reads++;
        $id = $this->state['handoff_by_request'][$handoff_request_id] ?? null;
        $result = $id === null ? null : $this->state['handoffs'][$id];
        if ($this->after_handoff_lookup !== null) {
            $hook = $this->after_handoff_lookup;
            $this->after_handoff_lookup = null;
            $hook($this);
        }
        return $result;
    }

    public function redemptions_for_business_date(string $business_date): array {
        $this->guard();
        $this->reads++;
        $out = array();
        foreach ($this->state['ledger'] as $id => $row) {
            if ($row['business_date'] === $business_date) {
                $out[] = $this->read_request($row['request_id']);
            }
        }
        return $out;
    }

    public function commit_handoff(EVC_Handoff_Write $write): int {
        $this->guard();
        $this->handoff_commit_attempts++;
        if ($this->before_handoff_commit !== null) {
            $hook = $this->before_handoff_commit;
            $this->before_handoff_commit = null;
            $hook($this);
        }
        $staged = $this->state;

        $this->reach('before_handoff');
        $record = $write->record();
        if (!isset($staged['ledger'][$record['redemption_id']])) {
            throw new EVC_Db_Exception('Club database foreign key violated.', '23000', 1452);
        }
        if (isset($staged['handoff_by_request'][$record['handoff_request_id']])) {
            throw new EVC_Db_Duplicate_Key_Exception(self::KEY_HANDOFF_REQUEST, '23000', 1062);
        }
        if (isset($staged['handoff_by_redemption'][$record['redemption_id']])) {
            throw new EVC_Db_Duplicate_Key_Exception(self::KEY_HANDOFF_REDEMPTION, '23000', 1062);
        }
        $id = $staged['next_handoff_id']++;
        $record['id'] = $id;
        $staged['handoffs'][$id] = $record;
        $staged['handoff_by_request'][$record['handoff_request_id']] = $id;
        $staged['handoff_by_redemption'][$record['redemption_id']] = $id;
        $this->reach('after_handoff');

        $staged['audit'][$staged['next_audit_id']++] = $write->audit();
        $this->reach('after_handoff_audit');

        $this->reach('handoff_commit');
        $unknown = $this->handoff_commit_unknown;
        $this->handoff_commit_unknown = null;
        if ($unknown === 'not_committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
        $this->state = $staged;
        if ($unknown === 'committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
        return $id;
    }

    // ---- inspection & tampering (tests only) -------------------------------------------------

    /** @return array<int,array> committed handoff records */
    public function handoff_rows(): array {
        return array_values($this->state['handoffs']);
    }

    /** Everything a handoff must never change: ledger rows and snapshots. */
    public function redemption_state(): array {
        return array($this->state['ledger'], $this->state['snapshots'], $this->state['by_day'], $this->state['by_request']);
    }

    /** Replaces every handoff record of a redemption (raw rows; may be corrupt on purpose). */
    public function tamper_handoffs(string $redemption_request_id, array $handoffs): void {
        $rid = $this->state['by_request'][$redemption_request_id];
        foreach ($this->state['handoffs'] as $id => $h) {
            if ($h['redemption_id'] === $rid) {
                unset($this->state['handoffs'][$id], $this->state['handoff_by_request'][$h['handoff_request_id']]);
            }
        }
        unset($this->state['handoff_by_redemption'][$rid]);
        foreach ($handoffs as $h) {
            $id = $this->state['next_handoff_id']++;
            $h['id'] = $h['id'] ?? $id;
            $this->state['handoffs'][$id] = $h;
            if (isset($h['handoff_request_id'])) {
                $this->state['handoff_by_request'][$h['handoff_request_id']] = $id;
            }
            $this->state['handoff_by_redemption'][$rid] = $id;
        }
    }

    /** @return array<int,array> committed ledger rows */
    public function ledger_rows(): array {
        return array_values($this->state['ledger']);
    }

    /** @return array<int,array> committed audit events (success events written in transactions) */
    public function audit_events(): array {
        return array_values($this->state['audit']);
    }

    /** @return array<int,array[]> redemption id => snapshots */
    public function snapshots(): array {
        return $this->state['snapshots'];
    }

    /** Asserts the store-level invariants; returns a list of violations (empty = consistent). */
    public function violations(): array {
        $out = array();
        $days = array();
        foreach ($this->state['ledger'] as $id => $row) {
            $day = self::day_key($row['member_id'], $row['benefit_type'], $row['business_date']);
            if (isset($days[$day])) {
                $out[] = 'two ledger rows for ' . $day;
            }
            $days[$day] = true;
            if (count($this->state['snapshots'][$id] ?? array()) !== 1) {
                $out[] = 'ledger ' . $id . ' without exactly one snapshot';
            }
            $audits = 0;
            foreach ($this->state['audit'] as $event) {
                $audits += $event['event_type'] === 'redemption' && $event['redemption_id'] === $id ? 1 : 0;
            }
            if ($audits !== 1) {
                $out[] = 'ledger ' . $id . ' without exactly one audit event';
            }
        }
        foreach ($this->state['snapshots'] as $id => $snapshots) {
            if (!isset($this->state['ledger'][$id])) {
                $out[] = 'orphan snapshot ' . $id;
            }
        }
        foreach ($this->state['audit'] as $event) {
            if (!isset($this->state['ledger'][$event['redemption_id']])) {
                $out[] = 'orphan audit event';
            }
        }
        $per_redemption = array();
        foreach ($this->state['handoffs'] as $h) {
            if (!isset($this->state['ledger'][$h['redemption_id']])) {
                $out[] = 'orphan handoff';
            }
            $per_redemption[$h['redemption_id']] = ($per_redemption[$h['redemption_id']] ?? 0) + 1;
            $audits = 0;
            foreach ($this->state['audit'] as $event) {
                $audits += $event['event_type'] === 'handoff' && $event['request_id'] === $h['handoff_request_id'] ? 1 : 0;
            }
            if ($audits !== 1) {
                $out[] = 'handoff ' . $h['handoff_request_id'] . ' without exactly one audit event';
            }
        }
        foreach ($per_redemption as $rid => $n) {
            if ($n > 1) {
                $out[] = 'two handoffs for redemption ' . $rid;
            }
        }
        foreach ($this->state['audit'] as $event) {
            if ($event['event_type'] === 'handoff' && !isset($this->state['handoff_by_request'][$event['request_id']])) {
                $out[] = 'handoff audit event without a handoff';
            }
        }
        return $out;
    }

    public function tamper_snapshots(string $request_id, array $snapshots): void {
        $this->state['snapshots'][$this->state['by_request'][$request_id]] = $snapshots;
    }

    public function tamper_ledger(string $request_id, array $fields): void {
        $id = $this->state['by_request'][$request_id];
        $this->state['ledger'][$id] = array_merge($this->state['ledger'][$id], $fields);
    }

    /** A v1-engine row (no snapshot, v1 fingerprint) sharing the same ledger and unique keys. */
    public function insert_legacy_v1(int $member_id, string $business_date, string $request_id, string $coffee_code): void {
        $id = $this->state['next_id']++;
        $this->state['ledger'][$id] = array(
            'id' => $id,
            'member_id' => $member_id,
            'benefit_type' => 'free_coffee',
            'business_date' => $business_date,
            'coffee_code' => $coffee_code,
            'catalogue_version' => null,
            'prep_hash' => null,
            'snapshot_hash' => null,
            'request_id' => $request_id,
            'request_fingerprint' => str_repeat('a', 64),
            'fingerprint_version' => EVC_Request_Fingerprint::VERSION,
            'staff_wp_user_id' => 7,
            'membership_source' => 'mock',
            'membership_status' => 'active',
            'membership_level_ref' => 'club_monthly',
            'membership_expires_at_utc' => '2026-12-01 00:00:00.000000',
            'redeemed_at_utc' => $business_date . ' 08:00:00.000000',
        );
        $this->state['by_request'][$request_id] = $id;
        $this->state['by_day'][self::day_key($member_id, 'free_coffee', $business_date)] = $id;
    }

    private function read_request(string $request_id): ?array {
        $id = $this->state['by_request'][$request_id] ?? null;
        if ($id === null) {
            return null;
        }
        $handoffs = array();
        foreach ($this->state['handoffs'] as $h) {
            if (($h['redemption_id'] ?? null) === $id) {
                $handoffs[] = $h;
            }
        }
        return array('ledger' => $this->ledger_view($id), 'snapshots' => $this->state['snapshots'][$id] ?? array(), 'handoffs' => $handoffs);
    }

    private function ledger_view(int $id): array {
        $row = $this->state['ledger'][$id];
        foreach ($this->state['members'] as $m) {
            if ($m['id'] === $row['member_id']) {
                $row['member_public_id'] = $m['member_public_id'];
            }
        }
        return $row;
    }

    private function reach(string $point): void {
        $this->points_reached[] = $point;
        if ($this->failures && $this->failures[0][0] === $point) {
            $failure = array_shift($this->failures);
            throw $failure[1];
        }
    }

    private function guard(): void {
        if ($this->unavailable) {
            throw new EVC_Db_Exception('Club database connection failed.', 'HY000', 2002);
        }
    }

    private static function day_key(int $member_id, string $benefit_type, string $business_date): string {
        return $member_id . '|' . $benefit_type . '|' . $business_date;
    }
}

/** TEST-ONLY server-side catalogue authority (UI-03D.A). Never shipped. */
final class EVC_Fake_Prep_Catalogue_Authority implements EVC_Prep_Catalogue_Authority {
    /** @var array */
    public $data;
    /** @var array<int,string> */
    public $approvals = array();
    /** @var int */
    public $reads = 0;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function active_catalogue_data(): array {
        $this->reads++;
        return $this->data;
    }

    public function approved_digest(int $catalogue_version): ?string {
        return $this->approvals[$catalogue_version] ?? null;
    }
}

/** TEST-ONLY staff authorizer (UI-03D.C). Never shipped. */
final class EVC_Fake_Club_Staff_Authorizer implements EVC_Club_Staff_Authorizer {
    /** @var array<int,string[]> wp user id => capabilities */
    public $grants = array();
    /** @var array[] */
    public $checks = array();

    public function allows(EVC_Staff_Actor $actor, string $capability): bool {
        $this->checks[] = array($actor->wp_user_id(), $capability);
        return in_array($capability, $this->grants[$actor->wp_user_id()] ?? array(), true);
    }
}

/** TEST-ONLY late-confirmation policy with an explicit window (NOT an approved business rule). */
final class EVC_Test_Window_Late_Handoff_Policy implements EVC_Late_Handoff_Policy {
    /** @var string */
    private $window;
    /** @var array[] */
    public $calls = array();

    /** @param string $window DateTime modifier added to the START of the next Athens day, e.g. "+2 hours" */
    public function __construct(string $window) {
        $this->window = $window;
    }

    public function allows_late_confirmation(string $redemption_business_date, DateTimeImmutable $now_utc): bool {
        $this->calls[] = array($redemption_business_date, $now_utc->format('Y-m-d H:i:s.u'));
        $next_day = new DateTimeImmutable($redemption_business_date . ' 00:00:00', new DateTimeZone('Europe/Athens'));
        return $now_utc < $next_day->modify('+1 day')->modify($this->window);
    }
}
