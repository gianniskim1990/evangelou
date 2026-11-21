<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Round 9 (owner decision D24): ONE member's own most recent free coffees, read-only, for
 *   - the till after a QR scan (EVC_Club_Lookup_Backend, last STAFF_LIMIT = 3): date, coffee, preparation,
 *     the staff note and a «Το ίδιο με την τελευταία φορά» pre-fill checked against the CURRENT catalogue;
 *   - the member card (EVC_Customer_Qr_Backend, last CUSTOMER_LIMIT = 5): date, coffee and preparation only.
 *
 * Privacy (both callers pass the member id they resolved themselves, never a client value):
 *   - only rows of that member id are read (`WHERE r.member_id = ?`);
 *   - no staff id, staff name, session, request id, hash or membership field is ever returned;
 *   - the staff note is returned by for_staff() only; for_customer() never carries it.
 *
 * Schema below 4 (migration 004 not applied): the ledger alone is read and every coffee shows as its name
 * only (as do v1 rows, and v2 rows whose snapshot is missing or fails verification: nothing is repaired or
 * guessed). Pre-fill never invents an option: an option or quick note the current catalogue no longer
 * offers for that coffee (or a group that is hidden now) is left out and the item says prefill_partial, so
 * staff choose it again; a coffee that is no longer offered gets no pre-fill. A pre-fill is a suggestion,
 * never a confirmed order (D-USUAL): staff review and confirm, and the redeem route re-validates it.
 */
final class EVC_Coffee_History {
    /** Migration 004 (preparation snapshots): below it, history shows coffee names only. */
    const PREPARED_SCHEMA_VERSION = 4;
    const STAFF_LIMIT = 3;
    const CUSTOMER_LIMIT = 5;
    const BENEFIT = 'free_coffee';
    /** Shown when a stored code is not in the catalogue any more (never a raw code). */
    const UNKNOWN_COFFEE_LABEL = 'Καφές';

    /**
     * @param array<string,string> $labels code => coffee label (EVC_Club_Prep_Catalogue_Authority::coffee_labels())
     * @param EVC_Prep_Catalogue|null $current the catalogue the till uses now (null: no pre-fill at all)
     * @return array<int,array{business_date:string,coffee_code:string,coffee_label:string,details:string[],note:?string,prefill:?array,prefill_partial:bool}> newest first
     */
    public static function recent(EVC_Club_Db $db, int $member_id, int $limit, bool $prepared_schema, array $labels, ?EVC_Prep_Catalogue $current): array {
        if ($member_id < 1 || $limit < 1 || $limit > 10) {
            throw new InvalidArgumentException('Invalid coffee history request.');
        }
        if ($prepared_schema) {
            $rows = $db->fetch_all(
                'SELECT r.business_date, r.coffee_code, r.catalogue_version AS ledger_catalogue_version, r.prep_hash AS ledger_prep_hash,'
                . ' r.snapshot_hash AS ledger_snapshot_hash, r.fingerprint_version, p.redemption_id AS snapshot_id, p.schema_version,'
                . ' p.prep_version, p.catalogue_version, p.coffee_code AS snapshot_coffee_code, p.coffee_label, p.modifiers_json,'
                . ' p.quick_notes_json, p.note_text, p.prep_hash, p.snapshot_hash'
                . ' FROM evc_redemptions r LEFT JOIN evc_redemption_preparations p ON p.redemption_id = r.id'
                . ' WHERE r.member_id = ? AND r.benefit_type = ? ORDER BY r.business_date DESC, r.id DESC LIMIT ' . (int) $limit,
                array($member_id, self::BENEFIT)
            );
        } else {
            $rows = $db->fetch_all(
                'SELECT r.business_date, r.coffee_code FROM evc_redemptions r'
                . ' WHERE r.member_id = ? AND r.benefit_type = ? ORDER BY r.business_date DESC, r.id DESC LIMIT ' . (int) $limit,
                array($member_id, self::BENEFIT)
            );
        }
        $items = array();
        foreach ($rows as $row) {
            $items[] = self::item($row, $prepared_schema, $labels, $current);
        }
        return $items;
    }

    private static function item(array $row, bool $prepared_schema, array $labels, ?EVC_Prep_Catalogue $current): array {
        $code = (string) $row['coffee_code'];
        $snapshot = $prepared_schema ? self::verified_snapshot($row) : null;
        if ($snapshot === null) {
            return array(
                'business_date' => (string) $row['business_date'],
                'coffee_code' => $code,
                'coffee_label' => $labels[$code] ?? self::UNKNOWN_COFFEE_LABEL,
                'details' => array(),
                'note' => null,
                'prefill' => self::prefill($current, $code, array(), array(), null)['prefill'],
                'prefill_partial' => false,
            );
        }
        $details = array();
        $modifiers = array();
        foreach (self::in_step_order($snapshot['modifiers'], $current, $code) as $m) {
            $details[] = $m['label'];
            $modifiers[$m['group']] = $m['option'];
        }
        $quick = array();
        foreach ($snapshot['quick_notes'] as $q) {
            $details[] = $q['label'];
            $quick[] = $q['id'];
        }
        $note = EVC_Prep_Snapshot::note_of($snapshot);
        $prefill = self::prefill($current, $code, $modifiers, $quick, $note);
        return array(
            'business_date' => (string) $row['business_date'],
            'coffee_code' => $code,
            'coffee_label' => $snapshot['coffee_label'],
            'details' => $details,
            'note' => $note,
            'prefill' => $prefill['prefill'],
            'prefill_partial' => $prefill['partial'],
        );
    }

    /**
     * A snapshot keeps its modifiers sorted by group id (UI-03D.A); people read them in the till's step order.
     * Groups the current catalogue still has for this coffee come first in its order, any others after them in
     * their stored order. Display only: the stored labels are never changed.
     */
    private static function in_step_order(array $modifiers, ?EVC_Prep_Catalogue $current, string $code): array {
        $order = ($current !== null && $current->has_coffee($code)) ? array_flip(array_keys($current->groups($code))) : array();
        $keyed = array();
        foreach ($modifiers as $i => $m) {
            $keyed[] = array(isset($order[$m['group']]) ? $order[$m['group']] : count($order) + $i, $m);
        }
        usort($keyed, function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return array_column($keyed, 1);
    }

    /** The stored snapshot when it is intact AND agrees with its ledger row; otherwise null (name only). */
    private static function verified_snapshot(array $row): ?array {
        if (($row['fingerprint_version'] ?? null) !== EVC_Prep_Canonical::FINGERPRINT_VERSION || ($row['snapshot_id'] ?? null) === null) {
            return null;
        }
        $snapshot = EVC_Sql_Prep_Redemption_Store::snapshot(array('coffee_code' => $row['snapshot_coffee_code']) + $row);
        try {
            EVC_Prep_Snapshot::verify($snapshot);
        } catch (EVC_Prep_Snapshot_Invalid $e) {
            return null;
        }
        if ($snapshot['coffee_code'] !== (string) $row['coffee_code']
            || !is_string($row['ledger_prep_hash']) || !hash_equals($row['ledger_prep_hash'], $snapshot['prep_hash'])
            || !is_string($row['ledger_snapshot_hash']) || !hash_equals($row['ledger_snapshot_hash'], $snapshot['snapshot_hash'])
            || (int) $row['ledger_catalogue_version'] !== $snapshot['catalogue_version']) {
            return null;
        }
        return $snapshot;
    }

    /**
     * What the current catalogue still offers of a past order.
     *
     * @param array<string,string> $modifiers group => option
     * @param string[] $quick_notes
     * @return array{prefill:?array,partial:bool}
     */
    public static function prefill(?EVC_Prep_Catalogue $current, string $coffee_code, array $modifiers, array $quick_notes, ?string $note): array {
        if ($current === null || !$current->has_coffee($coffee_code)) {
            return array('prefill' => null, 'partial' => $current !== null);
        }
        $groups = $current->groups($coffee_code);
        $kept = array();
        $partial = false;
        foreach ($modifiers as $group => $option) {
            if (isset($groups[$group]['options'][$option])) {
                $kept[$group] = $option;
            } else {
                $partial = true;
            }
        }
        // Visibility can only shrink when a selection is dropped: settle twice (a rule never chains: UI-03C).
        for ($i = 0; $i < 2; $i++) {
            $visible = EVC_Prep_Validator::visible_groups($groups, $kept);
            foreach (array_keys($kept) as $group) {
                if (!in_array($group, $visible, true)) {
                    unset($kept[$group]);
                    $partial = true;
                }
            }
        }
        $notes = array();
        $sets = array();
        foreach ($quick_notes as $id) {
            $set = $current->quick_note_defined($id) ? $current->exclusive_set_of($id) : null;
            if (!$current->quick_note_defined($id) || !$current->coffee_allows_quick_note($coffee_code, $id) || ($set !== null && isset($sets[$set]))
                || count($notes) >= $current->max_quick_notes()) {
                $partial = true;
                continue;
            }
            if ($set !== null) {
                $sets[$set] = true;
            }
            $notes[] = $id;
        }
        if ($note !== null && !$current->allows_free_text_note()) {
            $note = null;
            $partial = true;
        }
        $out = array();
        foreach ($kept as $group => $option) {
            $out[] = array('group' => $group, 'option' => $option);
        }
        return array(
            'prefill' => array(
                'catalogue_version' => $current->version(),
                'coffee_code' => $coffee_code,
                'modifiers' => $out,
                'quick_notes' => $notes,
                'note' => $note,
            ),
            'partial' => $partial,
        );
    }

    /** The till's view (lookup reply `recent_coffees`): no staff, request id or hash; the note IS included. */
    public static function for_staff(array $items): array {
        return array_map(function (array $i): array {
            return array(
                'business_date' => $i['business_date'],
                'coffee_label' => $i['coffee_label'],
                'details' => $i['details'],
                'note' => $i['note'],
                'prefill' => $i['prefill'],
                'prefill_partial' => $i['prefill_partial'],
            );
        }, $items);
    }

    /** The member card's view: date, coffee and preparation ONLY (never the staff note, never staff). */
    public static function for_customer(array $items): array {
        return array_map(function (array $i): array {
            return array(
                'business_date' => $i['business_date'],
                'coffee' => $i['coffee_label'],
                'details' => $i['details'],
            );
        }, $items);
    }
}
