<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03D.A — the immutable preparation snapshot stored with exactly ONE
 * redemption (pure PHP, definitions only, NOT wired).
 *
 * Stored shape (exactly these keys; anything else, including any `note`,
 * makes the snapshot corrupt):
 *
 *   schema             "evc-prep-snapshot-v1"
 *   prep_version       "evc-prep-v1" (EVC_Prep_Canonical::PREP_VERSION)
 *   catalogue_version  int the coffee was validated against
 *   coffee_code        canonical id
 *   coffee_label       label shown at the counter at that time
 *   modifiers          [ {group, option, group_label, label} ] strictly sorted by group
 *   quick_notes        [ {id, label} ] strictly sorted by id
 *   prep_hash          sha256 of the canonical text rebuilt from the codes above
 *   snapshot_hash      sha256 over every field above INCLUDING the labels
 *
 * prep_hash binds the preparation semantics (codes) and is part of the
 * idempotency fingerprint; snapshot_hash also binds the label copies, so a
 * corrupted label is detected too. The ledger row stores both hashes, so a
 * self-consistent rewrite of the snapshot alone still conflicts with its row.
 *
 * Verification NEVER consults a catalogue: a snapshot stays valid after its
 * options are retired or relabelled, and its labels are what history shows.
 * A snapshot that is missing, malformed, out of order, carries extra keys or
 * whose codes no longer hash to prep_hash is rejected (fail closed).
 */
final class EVC_Prep_Snapshot_Invalid extends Exception {
    /** @var string */
    private $reason;

    public function __construct(string $reason) {
        parent::__construct('preparation_snapshot_' . $reason);
        $this->reason = $reason;
    }

    public function reason(): string {
        return $this->reason;
    }
}

final class EVC_Prep_Snapshot {
    const SCHEMA = 'evc-prep-snapshot-v1';
    const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    /** The record to store for a validated order (contains no free text by construction). */
    public static function from_order(EVC_Prepared_Order $order): array {
        $s = $order->snapshot();
        $record = array(
            'schema' => self::SCHEMA,
            'prep_version' => EVC_Prep_Canonical::PREP_VERSION,
            'catalogue_version' => $s['catalogue_version'],
            'coffee_code' => $s['coffee_code'],
            'coffee_label' => $s['coffee_label'],
            'modifiers' => $s['modifiers'],
            'quick_notes' => $s['quick_notes'],
            'prep_hash' => $s['prep_hash'],
        );
        $record['snapshot_hash'] = self::snapshot_hash($record);
        return $record;
    }

    /**
     * Verifies a stored snapshot and returns its canonical text.
     *
     * @param mixed $raw
     * @throws EVC_Prep_Snapshot_Invalid
     */
    public static function verify($raw): string {
        if (!EVC_Prep_Catalogue::is_object_with($raw, array('schema', 'prep_version', 'catalogue_version', 'coffee_code', 'coffee_label', 'modifiers', 'quick_notes', 'prep_hash', 'snapshot_hash'))) {
            throw new EVC_Prep_Snapshot_Invalid('shape');
        }
        if ($raw['schema'] !== self::SCHEMA || $raw['prep_version'] !== EVC_Prep_Canonical::PREP_VERSION) {
            throw new EVC_Prep_Snapshot_Invalid('version');
        }
        if (!is_int($raw['catalogue_version']) || $raw['catalogue_version'] < 1 || $raw['catalogue_version'] > EVC_Prep_Catalogue::MAX_VERSION
            || !EVC_Prep_Catalogue::is_id($raw['coffee_code']) || !EVC_Prep_Catalogue::is_label($raw['coffee_label'])
            || !is_string($raw['prep_hash']) || !preg_match(self::HASH_PATTERN, $raw['prep_hash'])
            || !is_string($raw['snapshot_hash']) || !preg_match(self::HASH_PATTERN, $raw['snapshot_hash'])) {
            throw new EVC_Prep_Snapshot_Invalid('field');
        }
        if (!EVC_Prep_Catalogue::is_list($raw['modifiers']) || count($raw['modifiers']) > EVC_Prep_Canonical::MAX_MODIFIERS
            || !EVC_Prep_Catalogue::is_list($raw['quick_notes']) || count($raw['quick_notes']) > EVC_Prep_Canonical::MAX_QUICK_NOTES) {
            throw new EVC_Prep_Snapshot_Invalid('shape');
        }
        $modifiers = array();
        $previous = null;
        foreach ($raw['modifiers'] as $m) {
            if (!EVC_Prep_Catalogue::is_object_with($m, array('group', 'option', 'group_label', 'label'))
                || !EVC_Prep_Catalogue::is_id($m['group']) || !EVC_Prep_Catalogue::is_id($m['option'])
                || !EVC_Prep_Catalogue::is_label($m['group_label']) || !EVC_Prep_Catalogue::is_label($m['label'])) {
                throw new EVC_Prep_Snapshot_Invalid('field');
            }
            if ($previous !== null && strcmp($previous, $m['group']) >= 0) {
                throw new EVC_Prep_Snapshot_Invalid('order');
            }
            $previous = $m['group'];
            $modifiers[$m['group']] = $m['option'];
        }
        $quick = array();
        $previous = null;
        foreach ($raw['quick_notes'] as $q) {
            if (!EVC_Prep_Catalogue::is_object_with($q, array('id', 'label'))
                || !EVC_Prep_Catalogue::is_id($q['id']) || !EVC_Prep_Catalogue::is_label($q['label'])) {
                throw new EVC_Prep_Snapshot_Invalid('field');
            }
            if ($previous !== null && strcmp($previous, $q['id']) >= 0) {
                throw new EVC_Prep_Snapshot_Invalid('order');
            }
            $previous = $q['id'];
            $quick[] = $q['id'];
        }
        $canonical = EVC_Prep_Canonical::canonical($raw['catalogue_version'], $raw['coffee_code'], $modifiers, $quick);
        if (!hash_equals($raw['prep_hash'], EVC_Prep_Canonical::prep_hash($canonical))) {
            throw new EVC_Prep_Snapshot_Invalid('hash_mismatch');
        }
        if (!hash_equals($raw['snapshot_hash'], self::snapshot_hash($raw))) {
            throw new EVC_Prep_Snapshot_Invalid('hash_mismatch');
        }
        return $canonical;
    }

    /** sha256 over the whole record except snapshot_hash itself (labels included). */
    private static function snapshot_hash(array $r): string {
        return hash('sha256', self::SCHEMA . "
" . json_encode(array(
            $r['prep_version'],
            $r['catalogue_version'],
            $r['coffee_code'],
            $r['coffee_label'],
            array_map(function (array $m): array {
                return array($m['group'], $m['option'], $m['group_label'], $m['label']);
            }, $r['modifiers']),
            array_map(function (array $q): array {
                return array($q['id'], $q['label']);
            }, $r['quick_notes']),
            $r['prep_hash'],
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

/**
 * Content digest of one catalogue document (keys sorted recursively, lists
 * kept in order). A server-side approval record pins this digest, so a
 * document that merely SAYS `"status": "approved"` is never enough.
 */
final class EVC_Prep_Catalogue_Digest {
    const VERSION = 'evc-prep-catalogue-digest-v1';

    public static function of(array $data): string {
        return hash('sha256', self::VERSION . "\n" . json_encode(self::sorted($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sorted($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (!EVC_Prep_Catalogue::is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sorted($item);
        }
        return $value;
    }
}
