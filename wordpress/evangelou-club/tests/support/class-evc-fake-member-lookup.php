<?php
/**
 * TEST-ONLY fakes for UI-04C (never shipped; the packager rejects them).
 * The token store applies rotate()/revoke() to a COPY of its state and swaps
 * it in only at the end (all or nothing), enforces the same unique keys as
 * evc_qr_tokens (unique hash, at most one active token per member) and
 * offers deterministic interleaving hooks and lost-COMMIT simulation.
 */
final class EVC_Fake_Qr_Token_Store implements EVC_Qr_Token_Store {
    /** @var array{members:array<int,array>,tokens:array<int,array>,next_token_id:int} */
    private $state = array('members' => array(), 'tokens' => array(), 'next_token_id' => 1);

    /** @var callable|null one-shot: runs inside the next rotate()/revoke() before the compare-and-set */
    public $before_write;
    /** @var string|null 'committed' | 'not_committed' for the next write */
    public $commit_unknown;
    /** @var bool */
    public $unavailable = false;
    /** @var int */
    public $reads = 0;

    public function add_member(string $public_id, int $wp_user_id, string $status = 'enabled'): int {
        $id = count($this->state['members']) + 1;
        $this->state['members'][$id] = array('id' => $id, 'member_public_id' => $public_id, 'wp_user_id' => $wp_user_id, 'status' => $status);
        return $id;
    }

    public function set_member_status(int $id, string $status): void {
        $this->state['members'][$id]['status'] = $status;
    }

    public function find_member_by_public_id(string $member_public_id): ?array {
        $this->read();
        foreach ($this->state['members'] as $m) {
            if ($m['member_public_id'] === $member_public_id) {
                return $m;
            }
        }
        return null;
    }

    public function find_member_by_id(int $member_id): ?array {
        $this->read();
        return $this->state['members'][$member_id] ?? null;
    }

    public function find_token_by_hash(string $token_hash): ?array {
        $this->read();
        foreach ($this->state['tokens'] as $t) {
            if ($t['token_hash'] === $token_hash) {
                return array('member_id' => $t['member_id'], 'status' => $t['status'], 'issued_at_utc' => $t['issued_at_utc'], 'revoked_at_utc' => $t['revoked_at_utc']);
            }
        }
        return null;
    }

    public function active_token_hash(int $member_id): ?string {
        $this->read();
        return self::active_of($this->state, $member_id);
    }

    public function rotate(int $member_id, ?string $expected_active_hash, string $new_hash, string $now_utc): void {
        $this->write_hook();
        $staged = $this->state;
        if (self::active_of($staged, $member_id) !== $expected_active_hash) {
            throw new EVC_Qr_Rotation_Conflict('active token changed');
        }
        foreach ($staged['tokens'] as $t) {
            if ($t['token_hash'] === $new_hash) {
                throw new EVC_Db_Duplicate_Key_Exception('uq_qr_tokens_hash', '23000', 1062);
            }
        }
        foreach ($staged['tokens'] as $i => $t) {
            if ($t['member_id'] === $member_id && $t['status'] === 'active') {
                $staged['tokens'][$i]['status'] = 'revoked';
                $staged['tokens'][$i]['revoked_at_utc'] = $now_utc;
            }
        }
        $id = $staged['next_token_id']++;
        $staged['tokens'][$id] = array('id' => $id, 'member_id' => $member_id, 'token_hash' => $new_hash, 'status' => 'active', 'issued_at_utc' => $now_utc, 'revoked_at_utc' => null);
        $this->commit($staged);
    }

    public function revoke(int $member_id, string $expected_active_hash, string $now_utc): void {
        $this->write_hook();
        $staged = $this->state;
        if (self::active_of($staged, $member_id) !== $expected_active_hash) {
            throw new EVC_Qr_Rotation_Conflict('active token changed');
        }
        foreach ($staged['tokens'] as $i => $t) {
            if ($t['member_id'] === $member_id && $t['status'] === 'active') {
                $staged['tokens'][$i]['status'] = 'revoked';
                $staged['tokens'][$i]['revoked_at_utc'] = $now_utc;
            }
        }
        $this->commit($staged);
    }

    /** Stored rows (tests only): hashes, statuses and times, never a plaintext token. */
    public function token_rows(): array {
        return array_values($this->state['tokens']);
    }

    /** Violations of the evc_qr_tokens invariants (empty = consistent). */
    public function violations(): array {
        $out = array();
        $active = array();
        $hashes = array();
        foreach ($this->state['tokens'] as $t) {
            if (isset($hashes[$t['token_hash']])) {
                $out[] = 'duplicate hash';
            }
            $hashes[$t['token_hash']] = true;
            if ($t['status'] === 'active') {
                $active[$t['member_id']] = ($active[$t['member_id']] ?? 0) + 1;
            }
            if (!preg_match('/^[0-9a-f]{64}$/D', $t['token_hash'])) {
                $out[] = 'not a hash';
            }
        }
        foreach ($active as $member => $n) {
            if ($n > 1) {
                $out[] = 'two active tokens for member ' . $member;
            }
        }
        return $out;
    }

    private static function active_of(array $state, int $member_id): ?string {
        foreach ($state['tokens'] as $t) {
            if ($t['member_id'] === $member_id && $t['status'] === 'active') {
                return $t['token_hash'];
            }
        }
        return null;
    }

    private function commit(array $staged): void {
        $unknown = $this->commit_unknown;
        $this->commit_unknown = null;
        if ($unknown === 'not_committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
        $this->state = $staged;
        if ($unknown === 'committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
    }

    private function write_hook(): void {
        if ($this->unavailable) {
            throw new EVC_Db_Exception('Club database connection failed.', 'HY000', 2002);
        }
        if ($this->before_write !== null) {
            $hook = $this->before_write;
            $this->before_write = null;
            $hook($this);
        }
    }

    private function read(): void {
        if ($this->unavailable) {
            throw new EVC_Db_Exception('Club database connection failed.', 'HY000', 2002);
        }
        $this->reads++;
    }
}

/** TEST-ONLY phone directory: canonical phone => member ids. */
final class EVC_Fake_Member_Phone_Directory implements EVC_Member_Phone_Directory {
    /** @var array<string,int[]> */
    public $numbers = array();
    /** @var string[] canonical numbers asked for */
    public $queries = array();

    public function member_ids_for_phone(string $canonical_phone): array {
        $this->queries[] = $canonical_phone;
        return $this->numbers[$canonical_phone] ?? array();
    }
}

/** TEST-ONLY rate limiter: max attempts and max failures per key and action. */
final class EVC_Fake_Lookup_Rate_Limiter implements EVC_Lookup_Rate_Limiter {
    /** @var int */
    public $max_attempts;
    /** @var int */
    public $max_failures;
    /** @var bool */
    public $unavailable = false;
    /** @var array<string,int> */
    public $attempts = array();
    /** @var array<string,int> */
    public $failures = array();
    /** @var string[] every key ever seen */
    public $seen = array();

    public function __construct(int $max_attempts = 1000, int $max_failures = 1000) {
        $this->max_attempts = $max_attempts;
        $this->max_failures = $max_failures;
    }

    public function allow(array $keys, string $action): bool {
        if ($this->unavailable) {
            throw new RuntimeException('rate limiter down');
        }
        $ok = true;
        foreach ($keys as $key) {
            $this->seen[] = $action . '|' . $key;
            $k = $action . '|' . $key;
            $this->attempts[$k] = ($this->attempts[$k] ?? 0) + 1;
            if ($this->attempts[$k] > $this->max_attempts || ($this->failures[$k] ?? 0) >= $this->max_failures) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function record_failure(array $keys, string $action): void {
        if ($this->unavailable) {
            throw new RuntimeException('rate limiter down');
        }
        foreach ($keys as $key) {
            $k = $action . '|' . $key;
            $this->failures[$k] = ($this->failures[$k] ?? 0) + 1;
        }
    }
}
