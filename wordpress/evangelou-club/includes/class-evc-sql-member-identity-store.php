<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-05B: Club member identities in `evc_members` (migration 001, unchanged).
 *
 * The ONLY shipped EVC_Member_Identity_Store. Used solely by EVC_Club_Member_Sync
 * (behind EVC_CLUB_WC_COMPLETION_ENABLED); the packager forbids constructing it
 * anywhere else.
 *
 * - One identity per WordPress user and a unique opaque public id are enforced
 *   by the database (uq_members_wp_user / uq_members_public_id), never by a
 *   check-then-insert: a racing second create() fails with
 *   EVC_Db_Duplicate_Key_Exception and EVC_Member_Sync_Service re-reads.
 * - create() is ONE INSERT in its own transaction; a lost COMMIT is
 *   EVC_Db_Commit_Unknown_Exception (the sync re-reads on the next run and never
 *   adds a second identity).
 * - Stored: the opaque member id, the numeric WordPress user id, the status and
 *   two UTC instants (exactly the 001 columns). No name, e-mail, phone or
 *   payment data.
 * - Migration 001 has no status version / reason columns: every row reads as
 *   status_version 1 with no reason, and set_status() is refused (account status
 *   changes are not available until an approved migration adds them; nothing
 *   shipped calls it).
 * - A stored row that is not exactly well-formed is an error (fail closed),
 *   never silently accepted.
 */
final class EVC_Sql_Member_Identity_Store implements EVC_Member_Identity_Store {
    const STATUSES = array('enabled', 'disabled');

    /** @var EVC_Club_Db */
    private $db;

    public function __construct(EVC_Club_Db $db) {
        $this->db = $db;
    }

    public function find_by_wp_user(int $wp_user_id): ?array {
        if ($wp_user_id < 1) {
            return null;
        }
        return self::row($this->db->fetch_one(
            'SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE wp_user_id = ?',
            array($wp_user_id)
        ));
    }

    public function find_by_public_id(string $member_public_id): ?array {
        if (!EVC_Member_Id::is_valid_format($member_public_id)) {
            return null;
        }
        return self::row($this->db->fetch_one(
            'SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE member_public_id = ?',
            array($member_public_id)
        ));
    }

    public function create(string $member_public_id, int $wp_user_id, string $now_utc): int {
        if (!EVC_Member_Id::is_valid_format($member_public_id) || $wp_user_id < 1 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $now_utc)) {
            throw new InvalidArgumentException('Invalid member identity.');
        }
        return (int) $this->db->transactional(function (EVC_Club_Db $db) use ($member_public_id, $wp_user_id, $now_utc) {
            return $db->insert(
                "INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, 'enabled', ?, ?)",
                array($member_public_id, $wp_user_id, $now_utc, $now_utc)
            );
        });
    }

    public function set_status(int $member_id, int $expected_version, string $status, string $reason, int $actor_wp_user_id, string $now_utc): void {
        throw new LogicException('Account status changes need a status version and status events (not in migration 001).');
    }

    /** @param array<string,mixed>|null $row */
    private static function row(?array $row): ?array {
        if ($row === null) {
            return null;
        }
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $wp_user_id = filter_var($row['wp_user_id'] ?? null, FILTER_VALIDATE_INT);
        $public_id = $row['member_public_id'] ?? null;
        $status = $row['status'] ?? null;
        if ($id === false || $id < 1 || $wp_user_id === false || $wp_user_id < 1
            || !EVC_Member_Id::is_valid_format($public_id) || !in_array($status, self::STATUSES, true)) {
            throw new UnexpectedValueException('Stored Club member row is malformed.');
        }
        return array(
            'id' => $id,
            'member_public_id' => $public_id,
            'wp_user_id' => $wp_user_id,
            'status' => $status,
            'status_version' => 1,
            'status_reason' => null,
        );
    }
}
