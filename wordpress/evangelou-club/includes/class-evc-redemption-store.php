<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * SQL for members and the redemption ledger. All statements are prepared with
 * bound values. Reads made outside a transaction are fresh READ COMMITTED
 * reads (see EVC_Club_Db), which is what duplicate-key recovery relies on.
 */
final class EVC_Redemption_Store {
    const REDEMPTION_COLUMNS = 'r.id, r.member_id, m.member_public_id, r.benefit_type, r.business_date,'
        . ' r.coffee_code, r.request_id, r.request_fingerprint, r.staff_wp_user_id, r.redeemed_at_utc';

    /** @var EVC_Club_Db */
    private $db;

    public function __construct(EVC_Club_Db $db) {
        $this->db = $db;
    }

    public function db(): EVC_Club_Db {
        return $this->db;
    }

    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string}|null */
    public function find_member_by_public_id(string $member_public_id): ?array {
        $row = $this->db->fetch_one(
            'SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE member_public_id = ?',
            array($member_public_id)
        );
        if (!$row) {
            return null;
        }
        return array(
            'id' => (int) $row['id'],
            'member_public_id' => (string) $row['member_public_id'],
            'wp_user_id' => (int) $row['wp_user_id'],
            'status' => (string) $row['status'],
        );
    }

    public function find_by_request_id(string $request_id): ?array {
        return $this->fetch_redemption(
            'SELECT ' . self::REDEMPTION_COLUMNS
            . ' FROM evc_redemptions r JOIN evc_members m ON m.id = r.member_id WHERE r.request_id = ?',
            array($request_id)
        );
    }

    public function find_for_day(int $member_id, string $benefit_type, string $business_date): ?array {
        return $this->fetch_redemption(
            'SELECT ' . self::REDEMPTION_COLUMNS
            . ' FROM evc_redemptions r JOIN evc_members m ON m.id = r.member_id'
            . ' WHERE r.member_id = ? AND r.benefit_type = ? AND r.business_date = ?',
            array($member_id, $benefit_type, $business_date)
        );
    }

    /** Inserts one ledger row through $db (the caller's transaction). */
    public function insert_redemption(EVC_Club_Db $db, array $row): int {
        return $db->insert(
            'INSERT INTO evc_redemptions'
            . ' (member_id, benefit_type, business_date, coffee_code, request_id, request_fingerprint,'
            . ' staff_wp_user_id, membership_source, membership_status, membership_level_ref,'
            . ' membership_expires_at_utc, redeemed_at_utc)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array(
                (int) $row['member_id'],
                (string) $row['benefit_type'],
                (string) $row['business_date'],
                (string) $row['coffee_code'],
                (string) $row['request_id'],
                (string) $row['request_fingerprint'],
                (int) $row['staff_wp_user_id'],
                (string) $row['membership_source'],
                (string) $row['membership_status'],
                $row['membership_level_ref'] === null ? null : (string) $row['membership_level_ref'],
                (string) $row['membership_expires_at_utc'],
                (string) $row['redeemed_at_utc'],
            )
        );
    }

    private function fetch_redemption(string $sql, array $params): ?array {
        $row = $this->db->fetch_one($sql, $params);
        if (!$row) {
            return null;
        }
        return array(
            'id' => (int) $row['id'],
            'member_id' => (int) $row['member_id'],
            'member_public_id' => (string) $row['member_public_id'],
            'benefit_type' => (string) $row['benefit_type'],
            'business_date' => (string) $row['business_date'],
            'coffee_code' => (string) $row['coffee_code'],
            'request_id' => (string) $row['request_id'],
            'request_fingerprint' => (string) $row['request_fingerprint'],
            'staff_wp_user_id' => (int) $row['staff_wp_user_id'],
            'redeemed_at_utc' => self::normalise_datetime((string) $row['redeemed_at_utc']),
        );
    }

    /** DATETIME(6) as "Y-m-d H:i:s.uuuuuu" regardless of driver formatting. */
    public static function normalise_datetime(string $value): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            return $value . '.000000';
        }
        return $value;
    }
}
