<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Append-only audit trail. record() receives the connection to write through,
 * so a successful redemption's event is written inside the SAME transaction
 * as its ledger row (an audit failure rolls the redemption back).
 *
 * Event keys: event_type, outcome, member_id (?int), redemption_id (?int),
 * request_id (?string), staff_wp_user_id (?int), occurred_at_utc (string),
 * details (array of scalars; never personal data, raw QR tokens or SQL text).
 */
interface EVC_Audit_Log {
    /** @return int New audit event id. */
    public function record(EVC_Club_Db $db, array $event): int;
}
