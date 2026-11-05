<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-06: FluentCRM membership tags, pure core (definitions only; wired solely by
 * EVC_Fluentcrm_Plugin behind EVC_CLUB_FLUENTCRM_SYNC_ENABLED, default OFF).
 *
 * The membership state comes ONLY from the Club's authoritative source (EVC_Membership_Adapter: the
 * WooCommerce / PMPro reader + the Club's recorded payments + EVC_Pmpro_Entitlement_Mapper), never
 * from FluentCRM. Each member contact gets exactly one of three configured tags (Active, Inactive,
 * Pending payment); applying one removes the other two. Nothing else in FluentCRM is ever touched:
 * no other tag (e.g. «Ευαγγέλου Club»), no list, no subscription / consent status, no double opt-in,
 * no name or field, and no contact is created. See docs/fluentcrm-tags.md.
 */

/**
 * The three configured tags: their numeric IDs and the title (or slug) each must carry. There is NO
 * default: every value must be configured explicitly, or the whole sync stays inert.
 */
final class EVC_Fluentcrm_Tag_Config {
    const ROLE_ACTIVE = 'active';
    const ROLE_INACTIVE = 'inactive';
    const ROLE_PENDING = 'pending';

    const PROBLEM_NOT_CONFIGURED = 'tags_not_configured';
    const PROBLEM_INVALID_ID = 'tag_id_invalid';
    const PROBLEM_INVALID_NAME = 'tag_name_invalid';
    const PROBLEM_NOT_DISTINCT = 'tags_not_distinct';
    const PROBLEM_PROTECTED_NAME = 'protected_tag_configured';

    /**
     * Never ours to touch (normalised): the legacy newsletter tag/list «Ευαγγέλου Club». A configured
     * expected name, or a verified tag's title / slug, equal to one of these fails closed.
     */
    const PROTECTED_NAMES = array('ευαγγέλου club', 'ευαγγελου club', 'evangelou club');

    /** @var array<string,int> */
    private $ids;
    /** @var array<string,string> */
    private $names;

    /**
     * @param array<string,int> $ids
     * @param array<string,string> $names
     */
    private function __construct(array $ids, array $names) {
        $this->ids = $ids;
        $this->names = $names;
    }

    /** @return string[] */
    public static function roles(): array {
        return array(self::ROLE_ACTIVE, self::ROLE_INACTIVE, self::ROLE_PENDING);
    }

    /**
     * Pure parser. $raw holds, per role, 'id' and 'name' exactly as configured (null = not defined).
     * Only an int >= 1 is an ID (no numeric strings) and only a non-empty string is a name.
     *
     * @param array<string,array{id:mixed,name:mixed}> $raw
     * @return array{0:?EVC_Fluentcrm_Tag_Config,1:?string} [config, problem]
     */
    public static function parse(array $raw): array {
        $ids = array();
        $names = array();
        foreach (self::roles() as $role) {
            $id = $raw[$role]['id'] ?? null;
            $name = $raw[$role]['name'] ?? null;
            if ($id === null || $name === null) {
                return array(null, self::PROBLEM_NOT_CONFIGURED);
            }
            if (!is_int($id) || $id < 1) {
                return array(null, self::PROBLEM_INVALID_ID);
            }
            if (!is_string($name) || self::normalise($name) === '' || strlen($name) > 191) {
                return array(null, self::PROBLEM_INVALID_NAME);
            }
            if (self::is_protected($name)) {
                return array(null, self::PROBLEM_PROTECTED_NAME);
            }
            $ids[$role] = $id;
            $names[$role] = $name;
        }
        if (count(array_unique(array_values($ids))) !== count($ids)
            || count(array_unique(array_map(array(__CLASS__, 'normalise'), array_values($names)))) !== count($names)) {
            return array(null, self::PROBLEM_NOT_DISTINCT);
        }
        return array(new self($ids, $names), null);
    }

    public function id(string $role): int {
        if (!isset($this->ids[$role])) {
            throw new InvalidArgumentException('Unknown tag role.');
        }
        return $this->ids[$role];
    }

    public function name(string $role): string {
        if (!isset($this->names[$role])) {
            throw new InvalidArgumentException('Unknown tag role.');
        }
        return $this->names[$role];
    }

    /** @return int[] the three configured IDs, the ONLY tag IDs the sync may ever attach or detach */
    public function ids(): array {
        return array_values($this->ids);
    }

    /** Trimmed, lower-cased (UTF-8 aware when mbstring exists), inner whitespace collapsed. */
    public static function normalise(string $value): string {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** A title or slug («ευαγγέλου-club» counts: hyphens / underscores read as spaces). */
    public static function is_protected(string $title_or_slug): bool {
        $n = self::normalise(str_replace(array('-', '_'), ' ', $title_or_slug));
        return in_array($n, self::PROTECTED_NAMES, true);
    }
}

/**
 * Pure mapping: the authoritative entitlement at an instant -> the one tag role, or null (no write).
 *
 *   eligible now (verified, current paid period)            -> active
 *     (an early renewal or a pending renewal while active stays active)
 *   active row whose paid period has ended                   -> inactive
 *   expired | cancelled | refunded | payment_failed (voided) -> inactive
 *   indeterminate with "payment_reversed" (voided/frozen)    -> inactive
 *   pending_payment (unpaid BACS order, no paid time)        -> pending
 *   none (never a Club member)                               -> null: not a member, no write
 *   anything else that cannot be confirmed (indeterminate,
 *   e.g. the add-on date conflict, partial refund, unknown
 *   expiry, unverified payment)                              -> null: no write
 */
final class EVC_Fluentcrm_Tag_Map {
    const SKIP_NOT_MEMBER = 'not_member';
    const SKIP_STATE_UNKNOWN = 'state_unknown';

    public static function target(EVC_Entitlement $entitlement, DateTimeImmutable $at): ?string {
        $reason = $entitlement->denial_reason(EVC_Clock::to_utc($at));
        if ($reason === null) {
            return EVC_Fluentcrm_Tag_Config::ROLE_ACTIVE;
        }
        switch ($entitlement->status()) {
            case EVC_Entitlement::STATUS_ACTIVE:
                return $reason === EVC_Entitlement::DENIAL_EXPIRED ? EVC_Fluentcrm_Tag_Config::ROLE_INACTIVE : null;
            case EVC_Entitlement::STATUS_EXPIRED:
            case EVC_Entitlement::STATUS_CANCELLED:
            case EVC_Entitlement::STATUS_REFUNDED:
            case EVC_Entitlement::STATUS_PAYMENT_FAILED:
                return EVC_Fluentcrm_Tag_Config::ROLE_INACTIVE;
            case EVC_Entitlement::STATUS_PENDING_PAYMENT:
                return EVC_Fluentcrm_Tag_Config::ROLE_PENDING;
            case EVC_Entitlement::STATUS_INDETERMINATE:
                return in_array('payment_reversed', $entitlement->diagnostic_flags(), true) ? EVC_Fluentcrm_Tag_Config::ROLE_INACTIVE : null;
            default:
                return null;
        }
    }

    /** Why target() gave null (counts only). */
    public static function skip_reason(EVC_Entitlement $entitlement): string {
        return $entitlement->status() === EVC_Entitlement::STATUS_NONE ? self::SKIP_NOT_MEMBER : self::SKIP_STATE_UNKNOWN;
    }
}

/**
 * The ONLY things the sync may ask of FluentCRM (implemented by EVC_Fluentcrm_Api_Gateway, the one
 * shipped file that may call FluentCRM). Contacts are plain arrays {id:int, email:string, user_id:?int}.
 */
interface EVC_Fluentcrm_Gateway {
    /** The documented API surface is present (checked before any other call). */
    public function available(): bool;

    /** @return array{id:int,title:string,slug:string}|null */
    public function tag(int $tag_id): ?array;

    /** Read-only: the contact linked to this WordPress user (never links or creates). @return array{id:int,email:string,user_id:?int}|null */
    public function contact_by_user_id(int $wp_user_id): ?array;

    /** Read-only: the contact with exactly this (normalised) e-mail (never creates). @return array{id:int,email:string,user_id:?int}|null */
    public function contact_by_email(string $email): ?array;

    public function has_tag(int $contact_id, int $tag_id): bool;

    public function attach_tag(int $contact_id, int $tag_id): void;

    public function detach_tag(int $contact_id, int $tag_id): void;
}

/**
 * One member's tag sync. Fails closed: an unverified tag configuration, an unknown membership state,
 * a missing or ambiguous contact, or any error means NO write. Never throws (fire-and-forget); the
 * outcome is a code for counts only (no e-mail, name or ID is ever logged or stored).
 */
final class EVC_Fluentcrm_Tag_Sync {
    const OUTCOME_ACTIVE = 'tagged_active';
    const OUTCOME_INACTIVE = 'tagged_inactive';
    const OUTCOME_PENDING = 'tagged_pending';
    const OUTCOME_UNCHANGED = 'unchanged';
    const OUTCOME_NO_CONTACT = 'no_contact';
    const OUTCOME_AMBIGUOUS = 'ambiguous_contact';
    const OUTCOME_NOT_MEMBER = 'not_member';
    const OUTCOME_STATE_UNKNOWN = 'state_unknown';
    const OUTCOME_SOURCE_ERROR = 'source_error';
    const OUTCOME_API_ERROR = 'api_error';
    const OUTCOME_CONFIG_INVALID = 'config_invalid';

    const PROBLEM_API_UNAVAILABLE = 'api_unavailable';
    const PROBLEM_TAG_MISSING = 'tag_missing';
    const PROBLEM_TAG_MISMATCH = 'tag_mismatch';
    const PROBLEM_TAG_CHECK_FAILED = 'tag_check_failed';

    /** @var EVC_Fluentcrm_Tag_Config */
    private $config;
    /** @var EVC_Fluentcrm_Gateway */
    private $gateway;
    /** @var EVC_Membership_Adapter */
    private $membership;
    /** @var callable(int): ?string */
    private $email_of;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var string|null|false false = not verified yet */
    private $problem = false;

    /** @param callable(int): ?string $email_of the WordPress user's e-mail (null when the user does not exist) */
    public function __construct(EVC_Fluentcrm_Tag_Config $config, EVC_Fluentcrm_Gateway $gateway, EVC_Membership_Adapter $membership, callable $email_of, EVC_Clock_Source $clock) {
        $this->config = $config;
        $this->gateway = $gateway;
        $this->membership = $membership;
        $this->email_of = $email_of;
        $this->clock = $clock;
    }

    /**
     * Verifies, once per instance, that the API surface exists and that every configured ID is an
     * existing tag whose title or slug equals the configured name (and is not a protected tag).
     * Null = verified; otherwise the problem code. Read-only.
     */
    public function verify(): ?string {
        if ($this->problem !== false) {
            return $this->problem;
        }
        $this->problem = $this->check();
        return $this->problem;
    }

    private function check(): ?string {
        try {
            if (!$this->gateway->available()) {
                return self::PROBLEM_API_UNAVAILABLE;
            }
            foreach (EVC_Fluentcrm_Tag_Config::roles() as $role) {
                $id = $this->config->id($role);
                $tag = $this->gateway->tag($id);
                if ($tag === null) {
                    return self::PROBLEM_TAG_MISSING;
                }
                if (!isset($tag['id'], $tag['title'], $tag['slug']) || $tag['id'] !== $id) {
                    return self::PROBLEM_TAG_MISMATCH;
                }
                if (EVC_Fluentcrm_Tag_Config::is_protected((string) $tag['title']) || EVC_Fluentcrm_Tag_Config::is_protected((string) $tag['slug'])) {
                    return EVC_Fluentcrm_Tag_Config::PROBLEM_PROTECTED_NAME;
                }
                $want = EVC_Fluentcrm_Tag_Config::normalise($this->config->name($role));
                if (EVC_Fluentcrm_Tag_Config::normalise((string) $tag['title']) !== $want && EVC_Fluentcrm_Tag_Config::normalise((string) $tag['slug']) !== $want) {
                    return self::PROBLEM_TAG_MISMATCH;
                }
            }
            return null;
        } catch (Throwable $e) {
            return self::PROBLEM_TAG_CHECK_FAILED;
        }
    }

    public function sync_user(int $wp_user_id): string {
        if ($wp_user_id < 1 || $this->verify() !== null) {
            return self::OUTCOME_CONFIG_INVALID;
        }
        try {
            $at = $this->clock->now();
            $entitlement = $this->membership->entitlement_for($wp_user_id, $at);
            $role = EVC_Fluentcrm_Tag_Map::target($entitlement, $at);
        } catch (Throwable $e) {
            return self::OUTCOME_SOURCE_ERROR;
        }
        if ($role === null) {
            return EVC_Fluentcrm_Tag_Map::skip_reason($entitlement) === EVC_Fluentcrm_Tag_Map::SKIP_NOT_MEMBER ? self::OUTCOME_NOT_MEMBER : self::OUTCOME_STATE_UNKNOWN;
        }
        try {
            $contact = $this->contact_for($wp_user_id);
            if (is_string($contact)) {
                return $contact;
            }
            return $this->apply((int) $contact['id'], $role);
        } catch (Throwable $e) {
            return self::OUTCOME_API_ERROR;
        }
    }

    /**
     * The member's existing contact, matched ONLY by the FluentCRM user link or by the WordPress
     * user's e-mail (trimmed, case-insensitive); never by name or phone, never created or linked.
     *
     * @return array{id:int,email:string,user_id:?int}|string a contact, or an outcome code
     */
    private function contact_for(int $wp_user_id) {
        $email = self::normalise_email(($this->email_of)($wp_user_id));
        $by_user = $this->gateway->contact_by_user_id($wp_user_id);
        $by_email = $email === '' ? null : $this->gateway->contact_by_email($email);
        if ($by_user === null && $by_email === null) {
            return self::OUTCOME_NO_CONTACT;
        }
        if ($by_user !== null && ($by_user['user_id'] ?? null) !== $wp_user_id) {
            return self::OUTCOME_AMBIGUOUS;
        }
        if ($by_email !== null) {
            if (self::normalise_email($by_email['email'] ?? null) !== $email) {
                return self::OUTCOME_AMBIGUOUS;
            }
            $linked = $by_email['user_id'] ?? null;
            if ($linked !== null && $linked !== 0 && $linked !== $wp_user_id) {
                return self::OUTCOME_AMBIGUOUS; // the e-mail's contact belongs to another WordPress user
            }
        }
        if ($by_user !== null && $by_email !== null && $by_user['id'] !== $by_email['id']) {
            return self::OUTCOME_AMBIGUOUS;
        }
        $contact = $by_user ?? $by_email;
        if (!is_int($contact['id'] ?? null) || $contact['id'] < 1) {
            return self::OUTCOME_AMBIGUOUS;
        }
        return $contact;
    }

    /** Exactly one of the three tags: attach the wanted one if missing, then detach the other two if present. */
    private function apply(int $contact_id, string $role): string {
        $want = $this->config->id($role);
        $writes = 0;
        if (!$this->gateway->has_tag($contact_id, $want)) {
            $this->gateway->attach_tag($contact_id, $want);
            $writes++;
        }
        foreach ($this->config->ids() as $other) {
            if ($other !== $want && $this->gateway->has_tag($contact_id, $other)) {
                $this->gateway->detach_tag($contact_id, $other);
                $writes++;
            }
        }
        if ($writes === 0) {
            return self::OUTCOME_UNCHANGED;
        }
        switch ($role) {
            case EVC_Fluentcrm_Tag_Config::ROLE_ACTIVE:
                return self::OUTCOME_ACTIVE;
            case EVC_Fluentcrm_Tag_Config::ROLE_INACTIVE:
                return self::OUTCOME_INACTIVE;
            default:
                return self::OUTCOME_PENDING;
        }
    }

    /** Trimmed and lower-cased; '' when missing or not an address. */
    public static function normalise_email($email): string {
        if (!is_string($email)) {
            return '';
        }
        $email = strtolower(trim($email));
        return strpos($email, '@') > 0 ? $email : '';
    }

    /** @return string[] every outcome code (for the counts) */
    public static function outcomes(): array {
        return array(self::OUTCOME_ACTIVE, self::OUTCOME_INACTIVE, self::OUTCOME_PENDING, self::OUTCOME_UNCHANGED, self::OUTCOME_NO_CONTACT, self::OUTCOME_AMBIGUOUS,
            self::OUTCOME_NOT_MEMBER, self::OUTCOME_STATE_UNKNOWN, self::OUTCOME_SOURCE_ERROR, self::OUTCOME_API_ERROR, self::OUTCOME_CONFIG_INVALID);
    }
}
