<?php
/**
 * TEST-ONLY fakes for UI-04F (never shipped; the packager rejects them).
 * The identity store enforces the evc_members unique keys (one identity per
 * WP user, unique public id), applies writes all-or-nothing, and offers a
 * one-shot interleaving hook and lost-COMMIT simulation.
 */
final class EVC_Fake_Member_Identity_Store implements EVC_Member_Identity_Store {
    /** @var array<int,array> */
    private $members = array();
    /** @var array[] append-only status events */
    public $status_events = array();
    /** @var callable|null one-shot, runs inside the next write before its checks */
    public $before_write;
    /** @var string|null 'committed' | 'not_committed' */
    public $commit_unknown;
    /** @var bool */
    public $unavailable = false;
    /** @var int */
    public $writes = 0;

    public function find_by_wp_user(int $wp_user_id): ?array {
        $this->guard();
        foreach ($this->members as $m) {
            if ($m['wp_user_id'] === $wp_user_id) {
                return $m;
            }
        }
        return null;
    }

    public function find_by_public_id(string $member_public_id): ?array {
        $this->guard();
        foreach ($this->members as $m) {
            if ($m['member_public_id'] === $member_public_id) {
                return $m;
            }
        }
        return null;
    }

    public function create(string $member_public_id, int $wp_user_id, string $now_utc): int {
        $this->hook();
        $staged = $this->members;
        foreach ($staged as $m) {
            if ($m['wp_user_id'] === $wp_user_id) {
                throw new EVC_Db_Duplicate_Key_Exception('uq_members_wp_user', '23000', 1062);
            }
            if ($m['member_public_id'] === $member_public_id) {
                throw new EVC_Db_Duplicate_Key_Exception('uq_members_public_id', '23000', 1062);
            }
        }
        $id = count($staged) + 1;
        $staged[$id] = array('id' => $id, 'member_public_id' => $member_public_id, 'wp_user_id' => $wp_user_id, 'status' => 'enabled', 'status_version' => 1, 'status_reason' => null);
        $this->commit(function () use ($staged) {
            $this->members = $staged;
        });
        return $id;
    }

    public function set_status(int $member_id, int $expected_version, string $status, string $reason, int $actor_wp_user_id, string $now_utc): void {
        $this->hook();
        if (!isset($this->members[$member_id]) || $this->members[$member_id]['status_version'] !== $expected_version) {
            throw new EVC_Member_Status_Conflict('member changed');
        }
        $staged = $this->members;
        $staged[$member_id]['status'] = $status;
        $staged[$member_id]['status_reason'] = $reason;
        $staged[$member_id]['status_version']++;
        $event = array('member_id' => $member_id, 'status' => $status, 'reason' => $reason, 'actor' => $actor_wp_user_id, 'at' => $now_utc);
        $this->commit(function () use ($staged, $event) {
            $this->members = $staged;
            $this->status_events[] = $event;
        });
    }

    /** Raw insert for corruption tests only. */
    public function insert_raw(array $member): void {
        $this->members[$member['id']] = $member;
    }

    /** @return array[] */
    public function all(): array {
        return array_values($this->members);
    }

    private function commit(callable $apply): void {
        $this->writes++;
        $unknown = $this->commit_unknown;
        $this->commit_unknown = null;
        if ($unknown === 'not_committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
        $apply();
        if ($unknown === 'committed') {
            throw new EVC_Db_Commit_Unknown_Exception('Club database commit outcome unknown.', '08S01', 2013);
        }
    }

    private function hook(): void {
        $this->guard();
        if ($this->before_write !== null) {
            $hook = $this->before_write;
            $this->before_write = null;
            $hook($this);
        }
    }

    private function guard(): void {
        if ($this->unavailable) {
            throw new EVC_Db_Exception('Club database connection failed.', 'HY000', 2002);
        }
    }
}

/** TEST-ONLY WordPress account directory: wp user id => kind. */
final class EVC_Fake_Wp_Account_Directory implements EVC_Wp_Account_Directory {
    /** @var array<int,string> */
    public $kinds = array();
    /** @var bool */
    public $fail = false;

    public function account_kind(int $wp_user_id): string {
        if ($this->fail) {
            throw new RuntimeException('directory down');
        }
        return $this->kinds[$wp_user_id] ?? self::MISSING;
    }
}

/** TEST-ONLY status reason policy (the real reason codes are an OPEN owner decision). */
final class EVC_Fake_Member_Status_Policy implements EVC_Member_Status_Policy {
    public function disable_reasons(): array {
        return array('member_request', 'suspected_misuse');
    }

    public function enable_reasons(): array {
        return array('reviewed_by_manager');
    }
}

/** TEST-ONLY QR delivery policy (D8 is OPEN). */
final class EVC_Fake_Qr_Delivery_Policy implements EVC_Qr_Delivery_Policy {
    /** @var bool */
    public $offer = false;

    public function offer_first_qr_after_provisioning(): bool {
        return $this->offer;
    }
}
