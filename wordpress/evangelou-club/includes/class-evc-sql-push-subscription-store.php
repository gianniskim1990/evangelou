<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the members' push subscriptions (evc_push_subscriptions, migration 005).
 * Needs SELECT, INSERT, UPDATE on that table and SELECT on evc_members; it never deletes (a stopped or dead subscription
 * keeps its row with revoked_at_utc). Constructed only by EVC_Push_Backend (packager-enforced).
 *
 * Ownership (a member can never change another member's subscription):
 *   subscribe(member, endpoint)    no row                         -> INSERT                                  'created'
 *                                  own row, active                -> keys / labels refreshed, consent kept   'refreshed'
 *                                  own row, revoked               -> active again, consent = now             'renewed'
 *                                  another member's row, revoked  -> becomes this member's, consent = now    'renewed'
 *                                  another member's row, ACTIVE   -> nothing changes                         'taken'
 *                                    (the member app then drops the browser's subscription and makes a new one, so
 *                                    the other member's row dies on its next send with 404 / 410)
 *   unsubscribe(member, endpoint)  only a row of THIS member is revoked ('member'); anything else changes nothing.
 * The caller has validated every value (EVC_Push_Endpoint); the store refuses a bad shape again before writing.
 */
final class EVC_Sql_Push_Subscription_Store {
    const TABLE = 'evc_push_subscriptions';
    /** What the Club database user needs on TABLE (docs/club-db-migrations.md, readiness). */
    const PRIVILEGES = array('SELECT', 'INSERT', 'UPDATE');
    const CREATED = 'created';
    const REFRESHED = 'refreshed';
    const RENEWED = 'renewed';
    const TAKEN = 'taken';
    const REASON_MEMBER = 'member';
    const REASON_GONE = 'gone';
    const INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D';
    const KEY_PATTERN = '/^[A-Za-z0-9_-]+$/D';

    /** @var EVC_Club_Db */
    private $db;

    public function __construct(EVC_Club_Db $db) {
        $this->db = $db;
    }

    /** @return string CREATED | REFRESHED | RENEWED | TAKEN */
    public function subscribe(int $member_id, string $endpoint, string $p256dh, string $auth, string $service, string $agent, string $now_utc): string {
        if ($member_id <= 0 || EVC_Push_Endpoint::service($endpoint) !== $service || !in_array($agent, EVC_Push_Endpoint::AGENTS, true)
            || preg_match(self::KEY_PATTERN, $p256dh) !== 1 || preg_match(self::KEY_PATTERN, $auth) !== 1 || preg_match(self::INSTANT_PATTERN, $now_utc) !== 1) {
            throw new InvalidArgumentException('Refused push subscription.');
        }
        $hash = EVC_Push_Endpoint::hash($endpoint);
        for ($try = 0; $try < 2; $try++) {
            try {
                return $this->db->transactional(function (EVC_Club_Db $db) use ($member_id, $endpoint, $hash, $p256dh, $auth, $service, $agent, $now_utc) {
                    $row = $db->fetch_one('SELECT id, member_id, revoked_at_utc FROM ' . self::TABLE . ' WHERE endpoint_hash = ? FOR UPDATE', array($hash));
                    if ($row === null) {
                        $db->insert(
                            'INSERT INTO ' . self::TABLE . ' (member_id, endpoint_hash, endpoint, p256dh, auth, push_service, agent_family, consent_at_utc, created_at_utc, updated_at_utc)'
                            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            array($member_id, $hash, $endpoint, $p256dh, $auth, $service, $agent, $now_utc, $now_utc, $now_utc)
                        );
                        return self::CREATED;
                    }
                    $mine = (int) $row['member_id'] === $member_id;
                    $revoked = $row['revoked_at_utc'] !== null;
                    if (!$mine && !$revoked) {
                        return self::TAKEN;
                    }
                    if ($mine && !$revoked) {
                        // Same member, same browser: the keys may have rotated; the consent stays the first tap's.
                        $db->execute(
                            'UPDATE ' . self::TABLE . ' SET p256dh = ?, auth = ?, agent_family = ?, updated_at_utc = ? WHERE id = ? AND member_id = ? AND revoked_at_utc IS NULL',
                            array($p256dh, $auth, $agent, $now_utc, (int) $row['id'], $member_id)
                        );
                        return self::REFRESHED;
                    }
                    // A revoked row (this member's, or a previous member's on the same browser): a new consent now.
                    $db->execute(
                        'UPDATE ' . self::TABLE . ' SET member_id = ?, p256dh = ?, auth = ?, push_service = ?, agent_family = ?, consent_at_utc = ?, updated_at_utc = ?,'
                        . ' last_success_at_utc = NULL, success_count = 0, failure_count = 0, last_failure_at_utc = NULL, revoked_at_utc = NULL, revoked_reason = NULL'
                        . ' WHERE id = ? AND revoked_at_utc IS NOT NULL',
                        array($member_id, $p256dh, $auth, $service, $agent, $now_utc, $now_utc, (int) $row['id'])
                    );
                    return self::RENEWED;
                });
            } catch (EVC_Db_Duplicate_Key_Exception $e) {
                // Two taps of the same browser at the same moment: the second reads the first one's row.
                continue;
            }
        }
        throw new RuntimeException('Push subscription kept changing.');
    }

    /** Revokes THIS member's active row of that endpoint. @return bool whether a row changed */
    public function unsubscribe(int $member_id, string $endpoint, string $now_utc): bool {
        return $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET revoked_at_utc = ?, revoked_reason = ?, updated_at_utc = ? WHERE endpoint_hash = ? AND member_id = ? AND revoked_at_utc IS NULL',
            array($now_utc, self::REASON_MEMBER, $now_utc, EVC_Push_Endpoint::hash($endpoint), $member_id)
        ) > 0;
    }

    /**
     * The next active subscriptions after $after_id (ascending id), with the member's WordPress user and status.
     * @return array<int,array{id:int,member_id:int,wp_user_id:int,member_status:string,endpoint:string,p256dh:string,auth:string}>
     */
    public function batch(int $after_id, int $limit, ?int $member_id = null): array {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->fetch_all(
            'SELECT s.id, s.member_id, m.wp_user_id, m.status AS member_status, s.endpoint, s.p256dh, s.auth FROM ' . self::TABLE . ' s'
            . ' JOIN evc_members m ON m.id = s.member_id WHERE s.revoked_at_utc IS NULL AND s.id > ?' . ($member_id === null ? '' : ' AND s.member_id = ?')
            . ' ORDER BY s.id LIMIT ' . $limit,
            $member_id === null ? array($after_id) : array($after_id, $member_id)
        );
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id' => (int) $row['id'],
                'member_id' => (int) $row['member_id'],
                'wp_user_id' => (int) $row['wp_user_id'],
                'member_status' => (string) $row['member_status'],
                'endpoint' => (string) $row['endpoint'],
                'p256dh' => (string) $row['p256dh'],
                'auth' => (string) $row['auth'],
            );
        }
        return $out;
    }

    /** Members with at least one active subscription: [member_id => wp_user_id], ascending, after $after_member. */
    public function subscribed_members(int $after_member, int $limit): array {
        $limit = max(1, min(1000, $limit));
        $rows = $this->db->fetch_all(
            'SELECT DISTINCT s.member_id, m.wp_user_id FROM ' . self::TABLE . ' s JOIN evc_members m ON m.id = s.member_id'
            . " WHERE s.revoked_at_utc IS NULL AND m.status = 'enabled' AND s.member_id > ? ORDER BY s.member_id LIMIT " . $limit,
            array($after_member)
        );
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row['member_id']] = (int) $row['wp_user_id'];
        }
        return $out;
    }

    /**
     * Round 18 (D30): every ENABLED Club member, with or without a subscription: [member_id => wp_user_id], ascending,
     * after $after_member (the audience of an in-app message). Reads evc_members only (SELECT, as before).
     */
    public function enabled_members(int $after_member, int $limit): array {
        $limit = max(1, min(1000, $limit));
        $rows = $this->db->fetch_all(
            "SELECT id, wp_user_id FROM evc_members WHERE status = 'enabled' AND id > ? ORDER BY id LIMIT " . $limit,
            array($after_member)
        );
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (int) $row['wp_user_id'];
        }
        return $out;
    }

    /** The Club member of a WordPress user: [id, status], or null. */
    public function member_for_wp_user(int $wp_user_id): ?array {
        if ($wp_user_id <= 0) {
            return null;
        }
        $row = $this->db->fetch_one('SELECT id, status FROM evc_members WHERE wp_user_id = ?', array($wp_user_id));
        return $row === null ? null : array('id' => (int) $row['id'], 'status' => (string) $row['status']);
    }

    public function count_active(): int {
        $row = $this->db->fetch_one(
            'SELECT COUNT(*) AS n FROM ' . self::TABLE . " s JOIN evc_members m ON m.id = s.member_id WHERE s.revoked_at_utc IS NULL AND m.status = 'enabled'"
        );
        return (int) ($row['n'] ?? 0);
    }

    public function record_success(int $id, string $now_utc): void {
        $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET last_success_at_utc = ?, success_count = success_count + 1, failure_count = 0 WHERE id = ? AND revoked_at_utc IS NULL',
            array($now_utc, $id)
        );
    }

    public function record_failure(int $id, string $now_utc): void {
        $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET last_failure_at_utc = ?, failure_count = failure_count + 1 WHERE id = ? AND revoked_at_utc IS NULL',
            array($now_utc, $id)
        );
    }

    /** The push service said the subscription no longer exists (404 / 410): never sent to again. */
    public function revoke_gone(int $id, string $now_utc): void {
        $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET revoked_at_utc = ?, revoked_reason = ?, updated_at_utc = ? WHERE id = ? AND revoked_at_utc IS NULL',
            array($now_utc, self::REASON_GONE, $now_utc, $id)
        );
    }
}
