<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03C — preparation contract core (pure PHP, definitions only, NOT wired).
 *
 * Request shape (inside the redeem body):
 *   "preparation": {
 *     "modifiers":   [ { "group": "<id>", "option": "<id>" }, ... ],
 *     "quick_notes": [ "<id>", ... ]
 *   }
 * `note` is NOT part of the contract (owner decision D-NOTES, 2026-10-13):
 * an absent or null `note` is tolerated for robustness; any other value —
 * including "" — is rejected with `free_text_not_supported`. Nothing of a
 * note is ever hashed, stored, logged or echoed.
 *
 * Canonical form (catalogue-independent; computed BEFORE any catalogue or
 * replay decision so that exact retries replay even after a catalogue change):
 *
 *   evc-prep-v1
 *   catalogue_version=<int>
 *   coffee=<code>
 *   modifier=<group>:<option>      one line per modifier, sorted by group id
 *   quick_note=<id>                one line per quick note, sorted
 *
 * Lines are joined with "\n" (no trailing newline). Ids are restricted to
 * `^[a-z][a-z0-9_]{1,31}$`, so ':' '=' and '\n' can never occur inside a
 * value and the encoding is unambiguous. Ordering is plain byte order (ASCII).
 *
 * prep_hash      = sha256_hex(canonical)
 * fingerprint v2 = sha256_hex("evc-redeem-v2\n<member>\n<benefit>\n<staff>\n<prep_hash>")
 *
 * The v2 domain tag differs from v1 ("evc-redeem-v1"), so a v2 request can
 * never match a v1 fingerprint by accident (reuse is a conflict). The date is
 * deliberately excluded, as in v1, so a retry after Athens midnight replays.
 */
final class EVC_Prep_Rejection extends Exception {
    const INVALID_REQUEST = 'invalid_request';
    const INVALID_PREPARATION = 'invalid_preparation';
    const INVALID_COFFEE = 'invalid_coffee';

    /** @var string */
    private $kind;
    /** @var string|null */
    private $reason;

    public function __construct(string $kind, ?string $reason = null) {
        parent::__construct($kind . ($reason !== null ? ':' . $reason : ''));
        $this->kind = $kind;
        $this->reason = $reason;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function reason(): ?string {
        return $this->reason;
    }

    public static function request(string $reason): self {
        return new self(self::INVALID_REQUEST, $reason);
    }

    public static function preparation(string $reason): self {
        return new self(self::INVALID_PREPARATION, $reason);
    }
}

/** Shape parsing, canonical encoding and hashes. Never consults a catalogue. */
final class EVC_Prep_Canonical {
    const PREP_VERSION = 'evc-prep-v1';
    const FINGERPRINT_VERSION = 'evc-redeem-v2';
    /** Upper bounds on request size, independent of any catalogue. */
    const MAX_MODIFIERS = 12;
    const MAX_QUICK_NOTES = 8;

    /**
     * Parses the `preparation` object into sorted maps.
     *
     * @param mixed $preparation
     * @return array{modifiers:array<string,string>,quick_notes:string[]}
     * @throws EVC_Prep_Rejection
     */
    public static function parse($preparation): array {
        if (!EVC_Prep_Catalogue::is_object_with($preparation, array('modifiers', 'quick_notes'), array('note'))) {
            throw EVC_Prep_Rejection::request('preparation_shape');
        }
        if (array_key_exists('note', $preparation) && $preparation['note'] !== null) {
            throw EVC_Prep_Rejection::preparation('free_text_not_supported');
        }
        $mods = $preparation['modifiers'];
        $notes = $preparation['quick_notes'];
        if (!EVC_Prep_Catalogue::is_list($mods) || count($mods) > self::MAX_MODIFIERS
            || !EVC_Prep_Catalogue::is_list($notes) || count($notes) > self::MAX_QUICK_NOTES) {
            throw EVC_Prep_Rejection::request('preparation_shape');
        }
        $modifiers = array();
        foreach ($mods as $m) {
            if (!EVC_Prep_Catalogue::is_object_with($m, array('group', 'option'))
                || !EVC_Prep_Catalogue::is_id($m['group']) || !EVC_Prep_Catalogue::is_id($m['option'])) {
                throw EVC_Prep_Rejection::request('modifier_shape');
            }
            if (isset($modifiers[$m['group']])) {
                throw EVC_Prep_Rejection::preparation('duplicate_group');
            }
            $modifiers[$m['group']] = $m['option'];
        }
        $quick = array();
        foreach ($notes as $id) {
            if (!EVC_Prep_Catalogue::is_id($id)) {
                throw EVC_Prep_Rejection::request('quick_note_shape');
            }
            if (in_array($id, $quick, true)) {
                throw EVC_Prep_Rejection::preparation('duplicate_quick_note');
            }
            $quick[] = $id;
        }
        ksort($modifiers, SORT_STRING);
        sort($quick, SORT_STRING);
        return array('modifiers' => $modifiers, 'quick_notes' => $quick);
    }

    /**
     * @param array<string,string> $modifiers sorted by group
     * @param string[] $quick_notes sorted
     */
    public static function canonical(int $catalogue_version, string $coffee_code, array $modifiers, array $quick_notes): string {
        $lines = array(self::PREP_VERSION, 'catalogue_version=' . $catalogue_version, 'coffee=' . $coffee_code);
        foreach ($modifiers as $group => $option) {
            $lines[] = 'modifier=' . $group . ':' . $option;
        }
        foreach ($quick_notes as $id) {
            $lines[] = 'quick_note=' . $id;
        }
        return implode("\n", $lines);
    }

    public static function prep_hash(string $canonical): string {
        return hash('sha256', $canonical);
    }

    public static function fingerprint_v2(string $member_public_id, string $benefit_type, int $staff_wp_user_id, string $prep_hash): string {
        return hash('sha256', implode("\n", array(
            self::FINGERPRINT_VERSION,
            $member_public_id,
            $benefit_type,
            (string) $staff_wp_user_id,
            $prep_hash,
        )));
    }
}

/** A preparation validated against one catalogue version (immutable). */
final class EVC_Prepared_Order {
    /** @var int */
    private $catalogue_version;
    /** @var string */
    private $coffee_code;
    /** @var string */
    private $coffee_label;
    /** @var array<int,array{group:string,option:string,group_label:string,label:string}> */
    private $modifiers;
    /** @var array<int,array{id:string,label:string}> */
    private $quick_notes;
    /** @var string */
    private $canonical;

    public function __construct(int $catalogue_version, string $coffee_code, string $coffee_label, array $modifiers, array $quick_notes, string $canonical) {
        $this->catalogue_version = $catalogue_version;
        $this->coffee_code = $coffee_code;
        $this->coffee_label = $coffee_label;
        $this->modifiers = $modifiers;
        $this->quick_notes = $quick_notes;
        $this->canonical = $canonical;
    }

    public function catalogue_version(): int {
        return $this->catalogue_version;
    }

    public function coffee_code(): string {
        return $this->coffee_code;
    }

    public function coffee_label(): string {
        return $this->coffee_label;
    }

    /** @return array<int,array{group:string,option:string,group_label:string,label:string}> sorted by group */
    public function modifiers(): array {
        return $this->modifiers;
    }

    /** @return array<int,array{id:string,label:string}> sorted by id */
    public function quick_notes(): array {
        return $this->quick_notes;
    }

    public function canonical(): string {
        return $this->canonical;
    }

    public function prep_hash(): string {
        return EVC_Prep_Canonical::prep_hash($this->canonical);
    }

    /**
     * The immutable snapshot a future redemption row would store: codes plus
     * the labels shown at the counter, so later catalogue edits never rewrite
     * history. Contains no free text by construction.
     */
    public function snapshot(): array {
        return array(
            'catalogue_version' => $this->catalogue_version,
            'coffee_code' => $this->coffee_code,
            'coffee_label' => $this->coffee_label,
            'modifiers' => $this->modifiers,
            'quick_notes' => $this->quick_notes,
            'prep_hash' => $this->prep_hash(),
        );
    }
}

/** Semantic validation of a parsed preparation against the active catalogue. */
final class EVC_Prep_Validator {
    /**
     * @param array<string,string> $modifiers sorted by group (from EVC_Prep_Canonical::parse)
     * @param string[] $quick_notes sorted
     * @throws EVC_Prep_Rejection
     */
    public static function validate(EVC_Prep_Catalogue $catalogue, string $coffee_code, array $modifiers, array $quick_notes): EVC_Prepared_Order {
        if (!$catalogue->has_coffee($coffee_code)) {
            throw new EVC_Prep_Rejection(EVC_Prep_Rejection::INVALID_COFFEE);
        }
        $groups = $catalogue->groups($coffee_code);

        // 1. Every submitted modifier names a group and option of THIS coffee.
        foreach ($modifiers as $group => $option) {
            if (!isset($groups[$group])) {
                throw EVC_Prep_Rejection::preparation('unknown_group');
            }
            if (!isset($groups[$group]['options'][$option])) {
                throw EVC_Prep_Rejection::preparation('unknown_option');
            }
        }

        // 2. Visibility from the submitted selections. A hidden group must be
        //    absent (never silently dropped); a visible required group must be
        //    present (no defaults are ever applied).
        $visible = self::visible_groups($groups, $modifiers);
        foreach (array_keys($modifiers) as $group) {
            if (!in_array($group, $visible, true)) {
                throw EVC_Prep_Rejection::preparation('hidden_group_present');
            }
        }
        foreach ($visible as $group) {
            if ($groups[$group]['required'] && !isset($modifiers[$group])) {
                throw EVC_Prep_Rejection::preparation('required_missing');
            }
        }

        // 3. Quick notes: count, defined, applicable to this coffee, at most one per exclusive set.
        if (count($quick_notes) > $catalogue->max_quick_notes()) {
            throw EVC_Prep_Rejection::preparation('too_many_quick_notes');
        }
        $sets = array();
        foreach ($quick_notes as $id) {
            if (!$catalogue->quick_note_defined($id)) {
                throw EVC_Prep_Rejection::preparation('unknown_quick_note');
            }
            if (!$catalogue->coffee_allows_quick_note($coffee_code, $id)) {
                throw EVC_Prep_Rejection::preparation('quick_note_not_applicable');
            }
            $set = $catalogue->exclusive_set_of($id);
            if ($set !== null) {
                if (isset($sets[$set])) {
                    throw EVC_Prep_Rejection::preparation('exclusive_conflict');
                }
                $sets[$set] = true;
            }
        }

        $normalized_modifiers = array();
        foreach ($modifiers as $group => $option) {
            $normalized_modifiers[] = array(
                'group' => $group,
                'option' => $option,
                'group_label' => $groups[$group]['label'],
                'label' => $groups[$group]['options'][$option],
            );
        }
        $normalized_notes = array();
        foreach ($quick_notes as $id) {
            $normalized_notes[] = array('id' => $id, 'label' => $catalogue->quick_note_label($id));
        }
        return new EVC_Prepared_Order(
            $catalogue->version(),
            $coffee_code,
            $catalogue->coffee_label($coffee_code),
            $normalized_modifiers,
            $normalized_notes,
            EVC_Prep_Canonical::canonical($catalogue->version(), $coffee_code, $modifiers, $quick_notes)
        );
    }

    /**
     * Ids of the groups visible for the given selections, in catalogue order.
     * A group with a visibility rule is visible only when its referenced group
     * HAS a selection and that selection satisfies the rule.
     *
     * @param array<string,array> $groups
     * @param array<string,string> $modifiers
     * @return string[]
     */
    public static function visible_groups(array $groups, array $modifiers): array {
        $visible = array();
        foreach ($groups as $id => $group) {
            $rule = $group['visible_when'];
            if ($rule === null) {
                $visible[] = $id;
                continue;
            }
            if (!isset($modifiers[$rule['group']])) {
                continue;
            }
            $hit = in_array($modifiers[$rule['group']], $rule['options'], true);
            if (($rule['mode'] === 'in' && $hit) || ($rule['mode'] === 'not_in' && !$hit)) {
                $visible[] = $id;
            }
        }
        return $visible;
    }
}
