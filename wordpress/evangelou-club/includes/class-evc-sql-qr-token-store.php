<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-05C: QR tokens in `evc_qr_tokens` (migration 001, unchanged; no new table or column).
 *
 * The ONLY shipped EVC_Qr_Token_Store (UI-04C interface). Constructed at exactly ONE site,
 * EVC_Club_Qr_Services::store() (packager rule set qrProblemsFor), which only the customer QR
 * backend and the staff lookup backend reach, each behind its own opt-in.
 *
 * - Tokens are stored ONLY as SHA-256 digests (`token_hash`); this class never receives,
 *   stores, returns or logs a plaintext token. A digest is never returned by a lookup
 *   (find_token_by_hash() answers status and times only); active_token_hash() exists solely
 *   for the service's compare-and-set and is never passed to a caller.
 * - At most one ACTIVE token per member is enforced by the database
 *   (`uq_qr_tokens_one_active` on the stored generated column `active_member_id`), and a
 *   digest can exist once (`uq_qr_tokens_hash`).
 * - rotate() / revoke() are ONE READ COMMITTED transaction each: lock the member's active row
 *   (`SELECT ... FOR UPDATE` through the unique key), compare it with the expected digest,
 *   revoke it with a guarded UPDATE whose affected-row count must be exactly 1, then (rotate)
 *   INSERT the new active row. A concurrent first issuance is decided by the unique key
 *   (EVC_Db_Duplicate_Key_Exception, rolled back). A deadlock or lock-wait timeout means a
 *   concurrent writer: rolled back and reported as EVC_Qr_Rotation_Conflict (no token is
 *   issued; the caller may simply try again). A lost COMMIT is EVC_Db_Commit_Unknown_Exception.
 * - It never takes an explicit lock on `evc_members` (no FOR UPDATE / FOR SHARE there), so the runtime
 *   user needs only SELECT on `evc_members` and SELECT / INSERT / UPDATE (status, revoked_at_utc) on
 *   `evc_qr_tokens`; never DELETE. InnoDB's foreign-key check on the INSERT does take a SHARED lock on
 *   the member's `evc_members` row for the rest of the transaction; only a concurrent writer of that
 *   member row could wait on it (nothing shipped updates member rows). The shipped wiring
 *   (EVC_Club_Qr_Services) sets `SESSION innodb_lock_wait_timeout = 5` on the connection, like the
 *   STAGE-05A / 05B paths, so any row-lock wait here gives up after 5 s (→ EVC_Qr_Rotation_Conflict).
 * - A stored row that is not exactly well-formed is an error (fail closed), never accepted.
 */
final class EVC_Sql_Qr_Token_Store implements EVC_Qr_Token_Store {
    const MEMBER_STATUSES = array('enabled', 'disabled');
    const HASH_PATTERN = '/^[0-9a-f]{64}$/D';
    const INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D';
    /** The least privilege the runtime Club DB user needs for this store (column-level UPDATE; never DELETE). */
    const RUNTIME_GRANTS = array(
        'evc_members' => 'SELECT',
        'evc_qr_tokens' => 'SELECT, INSERT, UPDATE (status, revoked_at_utc)',
    );

    /** @var EVC_Club_Db */
    private $db;

    public function __construct(EVC_Club_Db $db) {
        $this->db = $db;
    }

    public function find_member_by_public_id(string $member_public_id): ?array {
        if (!EVC_Member_Id::is_valid_format($member_public_id)) {
            return null;
        }
        return self::member_row($this->db->fetch_one(
            'SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE member_public_id = ?',
            array($member_public_id)
        ));
    }

    /** The member row of a WordPress user (not part of the UI-04C interface; used by the QR backends). */
    public function find_member_by_wp_user(int $wp_user_id): ?array {
        if ($wp_user_id < 1) {
            return null;
        }
        return self::member_row($this->db->fetch_one(
            'SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE wp_user_id = ?',
            array($wp_user_id)
        ));
    }

    public function find_member_by_id(int $member_id): ?array {
        if ($member_id < 1) {
            return null;
        }
        return self::member_row($this->db->fetch_one(
            'SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE id = ?',
            array($member_id)
        ));
    }

    public function find_token_by_hash(string $token_hash): ?array {
        if (!preg_match(self::HASH_PATTERN, $token_hash)) {
            return null;
        }
        $row = $this->db->fetch_one(
            'SELECT member_id, status, issued_at_utc, revoked_at_utc FROM evc_qr_tokens WHERE token_hash = ?',
            array($token_hash)
        );
        if ($row === null) {
            return null;
        }
        $member_id = filter_var($row['member_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $row['status'] ?? null;
        $issued = $row['issued_at_utc'] ?? null;
        $revoked = $row['revoked_at_utc'] ?? null;
        $well_formed = $member_id !== false && $member_id >= 1
            && is_string($issued) && preg_match(self::INSTANT_PATTERN, $issued)
            && (($status === 'active' && $revoked === null)
                || ($status === 'revoked' && is_string($revoked) && preg_match(self::INSTANT_PATTERN, $revoked)));
        if (!$well_formed) {
            throw new UnexpectedValueException('Stored Club QR token row is malformed.');
        }
        return array('member_id' => $member_id, 'status' => $status, 'issued_at_utc' => $issued, 'revoked_at_utc' => $revoked);
    }

    public function active_token_hash(int $member_id): ?string {
        if ($member_id < 1) {
            return null;
        }
        return self::active_hash($this->db->fetch_all(
            'SELECT token_hash FROM evc_qr_tokens WHERE active_member_id = ?',
            array($member_id)
        ));
    }

    public function rotate(int $member_id, ?string $expected_active_hash, string $new_hash, string $now_utc): void {
        if ($member_id < 1 || ($expected_active_hash !== null && !preg_match(self::HASH_PATTERN, $expected_active_hash))
            || !preg_match(self::HASH_PATTERN, $new_hash) || !preg_match(self::INSTANT_PATTERN, $now_utc)) {
            throw new InvalidArgumentException('Invalid QR token rotation.');
        }
        $this->write(function (EVC_Club_Db $db) use ($member_id, $expected_active_hash, $new_hash, $now_utc) {
            self::compare_and_revoke($db, $member_id, $expected_active_hash, $now_utc);
            $db->insert(
                "INSERT INTO evc_qr_tokens (member_id, token_hash, status, issued_at_utc, revoked_at_utc) VALUES (?, ?, 'active', ?, NULL)",
                array($member_id, $new_hash, $now_utc)
            );
        });
    }

    public function revoke(int $member_id, string $expected_active_hash, string $now_utc): void {
        if ($member_id < 1 || !preg_match(self::HASH_PATTERN, $expected_active_hash) || !preg_match(self::INSTANT_PATTERN, $now_utc)) {
            throw new InvalidArgumentException('Invalid QR token revocation.');
        }
        $this->write(function (EVC_Club_Db $db) use ($member_id, $expected_active_hash, $now_utc) {
            self::compare_and_revoke($db, $member_id, $expected_active_hash, $now_utc);
        });
    }

    /** One transaction; a deadlock / lock-wait timeout (rolled back) is a concurrent change. */
    private function write(callable $work): void {
        try {
            $this->db->transactional($work);
        } catch (EVC_Db_Retryable_Exception $e) {
            throw new EVC_Qr_Rotation_Conflict('A concurrent QR token change won.');
        }
    }

    /**
     * Inside the transaction: lock the member's active row (if any), require it to equal the
     * expected digest, and revoke it. Any mismatch throws (the transaction rolls back).
     */
    private static function compare_and_revoke(EVC_Club_Db $db, int $member_id, ?string $expected_active_hash, string $now_utc): void {
        $current = self::active_hash($db->fetch_all(
            'SELECT token_hash FROM evc_qr_tokens WHERE active_member_id = ? FOR UPDATE',
            array($member_id)
        ));
        if ($current === null || $expected_active_hash === null) {
            if ($current !== $expected_active_hash) {
                throw new EVC_Qr_Rotation_Conflict('The active QR token changed.');
            }
            return;
        }
        if (!hash_equals($current, $expected_active_hash)) {
            throw new EVC_Qr_Rotation_Conflict('The active QR token changed.');
        }
        $changed = $db->execute(
            "UPDATE evc_qr_tokens SET status = 'revoked', revoked_at_utc = ? WHERE active_member_id = ? AND token_hash = ? AND status = 'active'",
            array($now_utc, $member_id, $current)
        );
        if ($changed !== 1) {
            throw new EVC_Qr_Rotation_Conflict('The active QR token changed.');
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function active_hash(array $rows): ?string {
        if (count($rows) === 0) {
            return null;
        }
        $hash = $rows[0]['token_hash'] ?? null;
        if (count($rows) !== 1 || !is_string($hash) || !preg_match(self::HASH_PATTERN, $hash)) {
            throw new UnexpectedValueException('Stored Club QR token row is malformed.');
        }
        return $hash;
    }

    /** @param array<string,mixed>|null $row */
    private static function member_row(?array $row): ?array {
        if ($row === null) {
            return null;
        }
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $wp_user_id = filter_var($row['wp_user_id'] ?? null, FILTER_VALIDATE_INT);
        $public_id = $row['member_public_id'] ?? null;
        $status = $row['status'] ?? null;
        if ($id === false || $id < 1 || $wp_user_id === false || $wp_user_id < 1
            || !EVC_Member_Id::is_valid_format($public_id) || !in_array($status, self::MEMBER_STATUSES, true)) {
            throw new UnexpectedValueException('Stored Club member row is malformed.');
        }
        return array('id' => $id, 'member_public_id' => $public_id, 'wp_user_id' => $wp_user_id, 'status' => $status);
    }
}
