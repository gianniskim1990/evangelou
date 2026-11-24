<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Round 9 (owner decision D24): the REAL EVC_Prep_Redemption_Store on the separate Club DB, migration 004
 * (docs/prepared-redemption-transaction.md §4). Constructed only in
 * EVC_Club_Prepared_Redemption_Backend::build() (packager rule set preparedProblemsFor).
 *
 * - ONE transaction (EVC_Club_Db::transactional, READ COMMITTED) writes the ledger row, its preparation
 *   snapshot and the success audit event: all three or none. A failed COMMIT is
 *   EVC_Db_Commit_Unknown_Exception (the service answers outcome_unknown and never retries it itself).
 * - The ledger's unique keys uq_redemptions_one_per_day / uq_redemptions_request (migration 001) decide,
 *   for v1 AND v2 rows alike: a duplicate key is EVC_Db_Duplicate_Key_Exception after the rollback, and
 *   the service then reads the committed winner with fresh statements on this connection.
 * - Reads return EVERY snapshot row of a redemption (normally one; the primary key allows at most one),
 *   rebuilt exactly as stored, so a missing / altered snapshot is caught by the service's integrity check
 *   (record_integrity_failure) and is never repaired here.
 * - There is no handoff table yet: handoffs are always an empty list (a replay of today's coffee then says
 *   verify_handoff, never prepare).
 * - Nothing here logs. The staff note is written to note_text only (never to the audit event).
 */
final class EVC_Sql_Prep_Redemption_Store implements EVC_Prep_Redemption_Store {
    const LEDGER_COLUMNS = 'r.id, r.member_id, m.member_public_id, r.benefit_type, r.business_date, r.coffee_code,'
        . ' r.catalogue_version, r.prep_hash, r.snapshot_hash, r.request_id, r.request_fingerprint, r.fingerprint_version,'
        . ' r.staff_wp_user_id, r.redeemed_at_utc';
    const SNAPSHOT_COLUMNS = 'schema_version, prep_version, catalogue_version, coffee_code, coffee_label, modifiers_json,'
        . ' quick_notes_json, note_text, prep_hash, snapshot_hash';

    /** @var EVC_Club_Db */
    private $db;
    /** @var EVC_Audit_Log */
    private $audit;

    public function __construct(EVC_Club_Db $db, ?EVC_Audit_Log $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new EVC_Db_Audit_Log();
    }

    public function find_member_by_public_id(string $member_public_id): ?array {
        $row = $this->db->fetch_one('SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE member_public_id = ?', array($member_public_id));
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
        $row = $this->db->fetch_one(
            'SELECT ' . self::LEDGER_COLUMNS . ' FROM evc_redemptions r JOIN evc_members m ON m.id = r.member_id WHERE r.request_id = ?',
            array($request_id)
        );
        if (!$row) {
            return null;
        }
        $ledger = self::ledger($row);
        $snapshots = array();
        foreach ($this->db->fetch_all('SELECT ' . self::SNAPSHOT_COLUMNS . ' FROM evc_redemption_preparations WHERE redemption_id = ?', array($ledger['id'])) as $s) {
            $snapshots[] = self::snapshot($s);
        }
        return array('ledger' => $ledger, 'snapshots' => $snapshots, 'handoffs' => array());
    }

    public function find_for_day(int $member_id, string $benefit_type, string $business_date): ?array {
        $row = $this->db->fetch_one(
            'SELECT ' . self::LEDGER_COLUMNS . ' FROM evc_redemptions r JOIN evc_members m ON m.id = r.member_id'
            . ' WHERE r.member_id = ? AND r.benefit_type = ? AND r.business_date = ?',
            array($member_id, $benefit_type, $business_date)
        );
        return $row ? self::ledger($row) : null;
    }

    public function commit_redemption(EVC_Prepared_Redemption_Write $write): int {
        $ledger = $write->ledger();
        $snapshot = $write->snapshot();
        $audit = $write->audit();
        return $this->db->transactional(function (EVC_Club_Db $db) use ($ledger, $snapshot, $audit) {
            $id = $db->insert(
                'INSERT INTO evc_redemptions'
                . ' (member_id, benefit_type, business_date, coffee_code, request_id, request_fingerprint, staff_wp_user_id,'
                . ' membership_source, membership_status, membership_level_ref, membership_expires_at_utc, redeemed_at_utc,'
                . ' fingerprint_version, catalogue_version, prep_hash, snapshot_hash)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array(
                    (int) $ledger['member_id'],
                    (string) $ledger['benefit_type'],
                    (string) $ledger['business_date'],
                    (string) $ledger['coffee_code'],
                    (string) $ledger['request_id'],
                    (string) $ledger['request_fingerprint'],
                    (int) $ledger['staff_wp_user_id'],
                    (string) $ledger['membership_source'],
                    (string) $ledger['membership_status'],
                    $ledger['membership_level_ref'] === null ? null : (string) $ledger['membership_level_ref'],
                    (string) $ledger['membership_expires_at_utc'],
                    (string) $ledger['redeemed_at_utc'],
                    (string) $ledger['fingerprint_version'],
                    (int) $ledger['catalogue_version'],
                    (string) $ledger['prep_hash'],
                    (string) $ledger['snapshot_hash'],
                )
            );
            $db->execute(
                'INSERT INTO evc_redemption_preparations (redemption_id, ' . self::SNAPSHOT_COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array(
                    $id,
                    (string) $snapshot['schema'],
                    (string) $snapshot['prep_version'],
                    (int) $snapshot['catalogue_version'],
                    (string) $snapshot['coffee_code'],
                    (string) $snapshot['coffee_label'],
                    self::json($snapshot['modifiers']),
                    self::json($snapshot['quick_notes']),
                    EVC_Prep_Snapshot::note_of($snapshot),
                    (string) $snapshot['prep_hash'],
                    (string) $snapshot['snapshot_hash'],
                )
            );
            $event = $audit;
            $event['redemption_id'] = $id;
            $this->audit->record($db, $event);
            return $id;
        });
    }

    public function record_rejection(array $event): void {
        $this->audit->record($this->db, $event);
    }

    /** Ledger row as the prepared-redemption model reads it (v1 rows: the four v2 fields are null). */
    public static function ledger(array $row): array {
        return array(
            'id' => (int) $row['id'],
            'member_id' => (int) $row['member_id'],
            'member_public_id' => (string) $row['member_public_id'],
            'benefit_type' => (string) $row['benefit_type'],
            'business_date' => (string) $row['business_date'],
            'coffee_code' => (string) $row['coffee_code'],
            'catalogue_version' => $row['catalogue_version'] === null ? null : (int) $row['catalogue_version'],
            'prep_hash' => $row['prep_hash'] === null ? null : (string) $row['prep_hash'],
            'snapshot_hash' => $row['snapshot_hash'] === null ? null : (string) $row['snapshot_hash'],
            'request_id' => (string) $row['request_id'],
            'request_fingerprint' => (string) $row['request_fingerprint'],
            'fingerprint_version' => $row['fingerprint_version'] === null ? null : (string) $row['fingerprint_version'],
            'staff_wp_user_id' => (int) $row['staff_wp_user_id'],
            'redeemed_at_utc' => EVC_Redemption_Store::normalise_datetime((string) $row['redeemed_at_utc']),
        );
    }

    /**
     * A stored snapshot row rebuilt into the EVC_Prep_Snapshot record shape WITHOUT any repair: an
     * undecodable list stays undecodable (null) and a note on a v1 record keeps its `note` key, so
     * EVC_Prep_Snapshot::verify() refuses both.
     */
    public static function snapshot(array $s): array {
        $record = array(
            'schema' => (string) $s['schema_version'],
            'prep_version' => (string) $s['prep_version'],
            'catalogue_version' => (int) $s['catalogue_version'],
            'coffee_code' => (string) $s['coffee_code'],
            'coffee_label' => (string) $s['coffee_label'],
            'modifiers' => self::decode($s['modifiers_json']),
            'quick_notes' => self::decode($s['quick_notes_json']),
            'prep_hash' => (string) $s['prep_hash'],
            'snapshot_hash' => (string) $s['snapshot_hash'],
        );
        if ($record['schema'] === EVC_Prep_Snapshot::SCHEMA_V2 || $s['note_text'] !== null) {
            $record['note'] = $s['note_text'] === null ? null : (string) $s['note_text'];
        }
        return $record;
    }

    private static function json(array $list): string {
        return json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array|null null when the stored text is not a JSON list (verification then fails closed) */
    private static function decode($text): ?array {
        if (!is_string($text)) {
            return null;
        }
        $value = json_decode($text, true);
        return is_array($value) ? $value : null;
    }
}
