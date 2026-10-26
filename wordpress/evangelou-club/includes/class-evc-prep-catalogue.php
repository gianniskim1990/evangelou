<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03C — versioned coffee PREPARATION catalogue (definitions only, NOT wired).
 *
 * Strict, immutable model of the coffees staff may serve and the structured
 * preparation options each one supports. Owner decisions (2026-10-13):
 *
 * - D-NOTES: structured options ONLY. `free_text_note` must be false; there is
 *   no free-text field anywhere in this model.
 * - D-PRICING: undecided. `pricing_policy` must be "undecided"; no option
 *   carries a price and no surcharge logic exists. Do not read "no price" as
 *   "free": an approved catalogue still needs the owner's pricing decision.
 * - D3: the real menu is not approved. Only an "illustrative" fixture exists;
 *   consumers refuse an illustrative catalogue unless explicitly in tests.
 *
 * Canonical identifiers (coffee codes, group ids, option ids, quick-note ids):
 * `^[a-z][a-z0-9_]{1,31}$` — lowercase ASCII with UNDERSCORES (same pattern as
 * EVC_Coffee_Catalog and the ledger's coffee_code column). Hyphens are never
 * valid. Published versions are immutable; a code is never reused with a
 * different meaning.
 *
 * Every rule here is mirrored in app/src/club/preparationContract.ts and both
 * implementations are tested against tests/fixtures/preparation/*.json.
 */
final class EVC_Prep_Catalogue {
    const SCHEMA = 'evc-prep-catalogue-v1';
    const ID_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/D';
    const STATUS_ILLUSTRATIVE = 'illustrative';
    const STATUS_APPROVED = 'approved';
    const PRICING_UNDECIDED = 'undecided';
    const TEMPERATURE_HOT = 'hot';
    const TEMPERATURE_COLD = 'cold';
    const APPLIES_ANY = 'any';
    const APPLIES_HOT = 'hot';
    const APPLIES_COLD = 'cold';
    const APPLIES_FOAM = 'foam';
    /** A coffee offers foam notes only when it has a group with this id. */
    const FOAM_GROUP = 'foam';

    const MAX_VERSION = 2147483647;
    const MAX_COFFEES = 64;
    const MAX_GROUPS = 12;
    const MAX_OPTIONS = 16;
    const MAX_QUICK_NOTES_DEFINED = 16;
    const MAX_SELECTABLE_QUICK_NOTES = 8;
    const MAX_LABEL_CODE_POINTS = 64;

    /** @var int */
    private $version;
    /** @var string */
    private $status;
    /** @var int */
    private $max_quick_notes;
    /** @var array<string,array{label:string,applies_to:string}> */
    private $quick_notes = array();
    /** @var array<string,int> quick-note id => exclusive set index */
    private $exclusive = array();
    /**
     * @var array<string,array{
     *   label:string, temperature:string, quick_notes:string[],
     *   groups:array<string,array{label:string,required:bool,options:array<string,string>,visible_when:?array{group:string,mode:string,options:string[]}}>
     * }>
     */
    private $coffees = array();

    /** @param mixed $data decoded JSON (associative arrays) */
    public static function from_array($data): self {
        return new self($data);
    }

    /** @param mixed $d */
    private function __construct($d) {
        if (!self::is_object_with($d, array('schema', 'catalogue_version', 'status', 'pricing_policy', 'free_text_note', 'quick_notes', 'exclusive_quick_notes', 'max_quick_notes', 'coffees'))) {
            self::fail('shape');
        }
        if ($d['schema'] !== self::SCHEMA) {
            self::fail('schema');
        }
        if (!is_int($d['catalogue_version']) || $d['catalogue_version'] < 1 || $d['catalogue_version'] > self::MAX_VERSION) {
            self::fail('catalogue_version');
        }
        if ($d['status'] !== self::STATUS_ILLUSTRATIVE && $d['status'] !== self::STATUS_APPROVED) {
            self::fail('status');
        }
        if ($d['pricing_policy'] !== self::PRICING_UNDECIDED) {
            self::fail('pricing_policy');
        }
        if ($d['free_text_note'] !== false) {
            self::fail('free_text_note');
        }
        if (!is_int($d['max_quick_notes']) || $d['max_quick_notes'] < 1 || $d['max_quick_notes'] > self::MAX_SELECTABLE_QUICK_NOTES) {
            self::fail('max_quick_notes');
        }
        $this->version = $d['catalogue_version'];
        $this->status = $d['status'];
        $this->max_quick_notes = $d['max_quick_notes'];

        if (!self::is_list($d['quick_notes']) || count($d['quick_notes']) > self::MAX_QUICK_NOTES_DEFINED) {
            self::fail('quick_notes');
        }
        foreach ($d['quick_notes'] as $q) {
            if (!self::is_object_with($q, array('id', 'label', 'applies_to')) || !self::is_id($q['id'])
                || isset($this->quick_notes[$q['id']]) || !self::is_label($q['label'])
                || !in_array($q['applies_to'], array(self::APPLIES_ANY, self::APPLIES_HOT, self::APPLIES_COLD, self::APPLIES_FOAM), true)) {
                self::fail('quick_note');
            }
            $this->quick_notes[$q['id']] = array('label' => $q['label'], 'applies_to' => $q['applies_to']);
        }

        if (!self::is_list($d['exclusive_quick_notes'])) {
            self::fail('exclusive_quick_notes');
        }
        foreach ($d['exclusive_quick_notes'] as $index => $set) {
            if (!self::is_list($set) || count($set) < 2) {
                self::fail('exclusive_set');
            }
            foreach ($set as $id) {
                if (!is_string($id) || !isset($this->quick_notes[$id]) || isset($this->exclusive[$id])) {
                    self::fail('exclusive_set');
                }
                $this->exclusive[$id] = $index;
            }
        }

        if (!self::is_list($d['coffees']) || count($d['coffees']) < 1 || count($d['coffees']) > self::MAX_COFFEES) {
            self::fail('coffees');
        }
        foreach ($d['coffees'] as $c) {
            $this->add_coffee($c);
        }
    }

    /** @param mixed $c */
    private function add_coffee($c): void {
        if (!self::is_object_with($c, array('code', 'label', 'temperature', 'groups', 'quick_notes'))
            || !self::is_id($c['code']) || isset($this->coffees[$c['code']]) || !self::is_label($c['label'])
            || ($c['temperature'] !== self::TEMPERATURE_HOT && $c['temperature'] !== self::TEMPERATURE_COLD)
            || !self::is_list($c['groups']) || count($c['groups']) > self::MAX_GROUPS
            || !self::is_list($c['quick_notes'])) {
            self::fail('coffee');
        }
        $groups = array();
        foreach ($c['groups'] as $g) {
            if (!self::is_object_with($g, array('id', 'label', 'required', 'options'), array('visible_when'))
                || !self::is_id($g['id']) || isset($groups[$g['id']]) || !self::is_label($g['label'])
                || !is_bool($g['required']) || !self::is_list($g['options'])
                || count($g['options']) < 1 || count($g['options']) > self::MAX_OPTIONS) {
                self::fail('group');
            }
            $options = array();
            foreach ($g['options'] as $o) {
                if (!self::is_object_with($o, array('id', 'label')) || !self::is_id($o['id'])
                    || isset($options[$o['id']]) || !self::is_label($o['label'])) {
                    self::fail('option');
                }
                $options[$o['id']] = $o['label'];
            }
            $groups[$g['id']] = array(
                'label' => $g['label'],
                'required' => $g['required'],
                'options' => $options,
                'visible_when' => array_key_exists('visible_when', $g) ? $g['visible_when'] : null,
            );
        }
        // Visibility rules are validated once every group of the coffee is known.
        foreach ($groups as $gid => $group) {
            if ($group['visible_when'] === null) {
                continue;
            }
            $groups[$gid]['visible_when'] = self::parse_visibility($group['visible_when'], $gid, $groups);
        }

        $notes = array();
        foreach ($c['quick_notes'] as $id) {
            if (!is_string($id) || !isset($this->quick_notes[$id]) || in_array($id, $notes, true)) {
                self::fail('coffee_quick_note');
            }
            $applies = $this->quick_notes[$id]['applies_to'];
            $ok = $applies === self::APPLIES_ANY
                || ($applies === self::APPLIES_HOT && $c['temperature'] === self::TEMPERATURE_HOT)
                || ($applies === self::APPLIES_COLD && $c['temperature'] === self::TEMPERATURE_COLD)
                || ($applies === self::APPLIES_FOAM && isset($groups[self::FOAM_GROUP]));
            if (!$ok) {
                self::fail('quick_note_not_applicable_to_coffee');
            }
            $notes[] = $id;
        }

        $this->coffees[$c['code']] = array(
            'label' => $c['label'],
            'temperature' => $c['temperature'],
            'groups' => $groups,
            'quick_notes' => $notes,
        );
    }

    /**
     * @param mixed $v
     * @param array<string,array> $groups
     * @return array{group:string,mode:string,options:string[]}
     */
    private static function parse_visibility($v, string $self_id, array $groups): array {
        $mode = null;
        if (self::is_object_with($v, array('group', 'in'))) {
            $mode = 'in';
        } elseif (self::is_object_with($v, array('group', 'not_in'))) {
            $mode = 'not_in';
        }
        if ($mode === null || !is_string($v['group']) || $v['group'] === $self_id || !isset($groups[$v['group']])
            || $groups[$v['group']]['visible_when'] !== null
            || !self::is_list($v[$mode]) || count($v[$mode]) < 1) {
            self::fail('visible_when');
        }
        $options = array();
        foreach ($v[$mode] as $oid) {
            if (!is_string($oid) || !isset($groups[$v['group']]['options'][$oid]) || in_array($oid, $options, true)) {
                self::fail('visible_when');
            }
            $options[] = $oid;
        }
        return array('group' => $v['group'], 'mode' => $mode, 'options' => $options);
    }

    public function version(): int {
        return $this->version;
    }

    public function status(): string {
        return $this->status;
    }

    public function is_approved(): bool {
        return $this->status === self::STATUS_APPROVED;
    }

    public function max_quick_notes(): int {
        return $this->max_quick_notes;
    }

    public function has_coffee(string $code): bool {
        return isset($this->coffees[$code]);
    }

    public function coffee_label(string $code): string {
        return $this->coffee($code)['label'];
    }

    /** @return array<string,array{label:string,required:bool,options:array<string,string>,visible_when:?array{group:string,mode:string,options:string[]}}> */
    public function groups(string $code): array {
        return $this->coffee($code)['groups'];
    }

    public function quick_note_defined(string $id): bool {
        return isset($this->quick_notes[$id]);
    }

    public function quick_note_label(string $id): string {
        if (!isset($this->quick_notes[$id])) {
            throw new InvalidArgumentException('Unknown quick note.');
        }
        return $this->quick_notes[$id]['label'];
    }

    public function coffee_allows_quick_note(string $code, string $id): bool {
        return in_array($id, $this->coffee($code)['quick_notes'], true);
    }

    public function exclusive_set_of(string $id): ?int {
        return isset($this->exclusive[$id]) ? $this->exclusive[$id] : null;
    }

    private function coffee(string $code): array {
        if (!isset($this->coffees[$code])) {
            throw new InvalidArgumentException('Unknown coffee.');
        }
        return $this->coffees[$code];
    }

    // ---- strict JSON shape helpers (shared by the preparation contract) ----

    /** @param mixed $v */
    public static function is_id($v): bool {
        return is_string($v) && (bool) preg_match(self::ID_PATTERN, $v);
    }

    /** @param mixed $v Non-empty valid UTF-8, no control characters, at most 64 code points. */
    public static function is_label($v): bool {
        if (!is_string($v) || $v === '' || !preg_match('//u', $v) || preg_match('/\p{Cc}/u', $v) || trim($v) !== $v) {
            return false;
        }
        return preg_match_all('/./su', $v) <= self::MAX_LABEL_CODE_POINTS;
    }

    /** @param mixed $v */
    public static function is_list($v): bool {
        return is_array($v) && ($v === array() || array_keys($v) === range(0, count($v) - 1));
    }

    /**
     * Object with exactly the required keys plus any of the optional ones (no
     * unknown keys).
     *
     * @param mixed $v
     * @param string[] $required
     * @param string[] $optional
     */
    public static function is_object_with($v, array $required, array $optional = array()): bool {
        if (!is_array($v) || ($v !== array() && self::is_list($v))) {
            return false;
        }
        foreach ($required as $k) {
            if (!array_key_exists($k, $v)) {
                return false;
            }
        }
        foreach (array_keys($v) as $k) {
            if (!in_array($k, $required, true) && !in_array($k, $optional, true)) {
                return false;
            }
        }
        return true;
    }

    private static function fail(string $reason): void {
        throw new InvalidArgumentException('Invalid preparation catalogue: ' . $reason);
    }
}
