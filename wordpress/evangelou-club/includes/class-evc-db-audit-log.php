<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/** Writes audit events to evc_audit_events in the separate Club database. */
final class EVC_Db_Audit_Log implements EVC_Audit_Log {
    public function record(EVC_Club_Db $db, array $event): int {
        $details = isset($event['details']) ? $event['details'] : array();
        foreach ($details as $key => $value) {
            if (!is_string($key) || !(is_scalar($value) || $value === null)) {
                throw new InvalidArgumentException('Audit details must be a flat map of scalars.');
            }
        }
        $json = $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null;
        if ($json === false) {
            throw new InvalidArgumentException('Audit details are not JSON-encodable.');
        }
        return $db->insert(
            'INSERT INTO evc_audit_events'
            . ' (event_type, outcome, member_id, redemption_id, request_id, staff_wp_user_id, occurred_at_utc, details_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            array(
                (string) $event['event_type'],
                (string) $event['outcome'],
                isset($event['member_id']) ? (int) $event['member_id'] : null,
                isset($event['redemption_id']) ? (int) $event['redemption_id'] : null,
                isset($event['request_id']) ? (string) $event['request_id'] : null,
                isset($event['staff_wp_user_id']) ? (int) $event['staff_wp_user_id'] : null,
                (string) $event['occurred_at_utc'],
                $json,
            )
        );
    }
}
