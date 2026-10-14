<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-03E — "usual coffee" recommendation core (pure PHP, definitions only,
 * NOT wired: no SQL reader, REST route, hook or UI).
 *
 * Owner decision D-USUAL: the usual coffee is DERIVED from the member's own
 * committed redemption snapshots. There is no stored or editable preference,
 * no member record is changed and nothing is written anywhere. A suggestion
 * is NOT an order: it never redeems, never touches an entitlement, never
 * creates a transaction or a handoff confirmation, and the staff app must
 * still submit it through the normal redemption flow, which re-validates
 * everything.
 *
 * Algorithm (deterministic; every parameter PROVISIONAL, see EVC_Usual_Coffee_Policy):
 *  1. Authorise: capability + a server-side proof that THIS staff session
 *     just looked up THIS member (QR / phone). The shared staff account is
 *     never permission to enumerate members.
 *  2. Read the member's committed redemptions in the lookback window
 *     (Europe/Athens business dates, server clock).
 *  3. Keep only intact v2 records of THIS member with exactly one verified
 *     snapshot (EVC_Handoff_Service::record_problem); drop v1 rows, damaged,
 *     duplicated, foreign or out-of-window rows. Free text cannot exist in a
 *     verified snapshot.
 *  4. Sort by recency (business date, redeemed_at, request id) and keep the
 *     most recent `max_considered`; recent preference changes therefore win.
 *  5. Group by the COMPLETE observed preparation (coffee + every modifier +
 *     every quick note; codes only, catalogue version excluded). Never mix
 *     modifiers from different redemptions.
 *  6. Winner: highest count; ties -> most recent occurrence -> byte order of
 *     the preparation key. Needs `min_valid` considered redemptions,
 *     `min_occurrences` and `min_share_percent`, else no suggestion.
 *  7. The winner must validate EXACTLY against the current authorised
 *     catalogue (EVC_Prep_Validator). Retired coffee, removed option or a
 *     changed rule -> no actionable suggestion. Never substituted, never a
 *     fallback to another preparation. Labels come from the CURRENT
 *     catalogue (renames), codes from history.
 */
final class EVC_Usual_Coffee_Policy {
    /** @var int */
    public $lookback_days;
    /** @var int */
    public $max_considered;
    /** @var int */
    public $min_valid;
    /** @var int */
    public $min_occurrences;
    /** @var int */
    public $min_share_percent;

    public function __construct(int $lookback_days, int $max_considered, int $min_valid, int $min_occurrences, int $min_share_percent) {
        if ($lookback_days < 1 || $lookback_days > 366 || $max_considered < 1 || $max_considered > 100
            || $min_valid < 1 || $min_valid > $max_considered || $min_occurrences < 1 || $min_occurrences > $max_considered
            || $min_share_percent < 1 || $min_share_percent > 100) {
            throw new InvalidArgumentException('Invalid usual-coffee policy.');
        }
        $this->lookback_days = $lookback_days;
        $this->max_considered = $max_considered;
        $this->min_valid = $min_valid;
        $this->min_occurrences = $min_occurrences;
        $this->min_share_percent = $min_share_percent;
    }

    /**
     * PROVISIONAL defaults, NOT owner-approved: the last 90 Athens days, the
     * 10 most recent valid redemptions, at least 3 of them, and a preparation
     * seen at least twice and in at least half of them.
     */
    public static function provisional(): self {
        return new self(90, 10, 3, 2, 50);
    }
}

/** Read-only source of a member's committed redemption records (future SQL reader). */
interface EVC_Redemption_History_Source {
    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string}|null */
    public function find_member_by_public_id(string $member_public_id): ?array;

    /**
     * Committed redemptions of ONE internal member with an Athens business
     * date in [from, to], each as {ledger (with member_public_id), snapshots[], handoffs[]},
     * in ANY order. Must never return other members' rows (they are also
     * filtered out defensively).
     */
    public function member_redemptions(int $member_id, string $from_business_date, string $to_business_date): array;
}

/**
 * Server-side proof that this staff session performed a lookup (QR scan or
 * phone search) of exactly this member a moment ago. Only test fakes exist.
 */
interface EVC_Member_Context_Verifier {
    /** @param mixed $lookup_ref opaque reference issued by the lookup endpoint */
    public function verify(EVC_Staff_Actor $actor, string $member_public_id, $lookup_ref): bool;
}

final class EVC_Usual_Coffee_Result {
    const SUGGESTED = 'suggested';
    const NO_SUGGESTION = 'no_suggestion';
    const FORBIDDEN = 'forbidden';
    const INVALID_REQUEST = 'invalid_request';
    const MEMBER_NOT_FOUND = 'member_not_found';
    const SERVER_ERROR = 'server_error';

    const REASON_INSUFFICIENT_HISTORY = 'insufficient_history';
    const REASON_NO_CLEAR_USUAL = 'no_clear_usual';
    const REASON_NOT_OFFERABLE = 'not_offerable_with_current_catalogue';
    const REASON_CATALOGUE_NOT_AUTHORISED = 'catalogue_not_authorised';

    /** @var string */
    private $kind;
    /** @var string|null */
    private $reason;
    /** @var array|null */
    private $suggestion;

    private function __construct(string $kind, ?string $reason, ?array $suggestion) {
        $this->kind = $kind;
        $this->reason = $reason;
        $this->suggestion = $suggestion;
    }

    public static function suggested(array $suggestion): self {
        return new self(self::SUGGESTED, null, $suggestion);
    }

    public static function none(string $reason): self {
        return new self(self::NO_SUGGESTION, $reason, null);
    }

    public static function refused(string $kind): self {
        if ($kind === self::SUGGESTED || $kind === self::NO_SUGGESTION) {
            throw new InvalidArgumentException('Not a refusal.');
        }
        return new self($kind, null, null);
    }

    public function kind(): string {
        return $this->kind;
    }

    public function reason(): ?string {
        return $this->reason;
    }

    /**
     * The proposed preparation (current catalogue labels), or null. Keys:
     * catalogue_version, coffee_code, coffee_label, modifiers[{group,option,group_label,label}],
     * quick_notes[{id,label}], basis{occurrences,considered}.
     */
    public function suggestion(): ?array {
        return $this->suggestion;
    }

    /** Always false: a suggestion must be reviewed, changed if needed and submitted by staff. */
    public function is_confirmed_order(): bool {
        return false;
    }
}

final class EVC_Usual_Coffee_Service {
    const CAPABILITY = 'evc_view_usual_coffee';
    /** Opt-in for tests only: the illustrative fixture is not an approved menu (D3). */
    const ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS = 'allow-illustrative-catalogue-for-tests';

    /** @var EVC_Redemption_History_Source */
    private $history;
    /** @var EVC_Prep_Catalogue_Authority */
    private $authority;
    /** @var EVC_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Member_Context_Verifier */
    private $context;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var EVC_Usual_Coffee_Policy */
    private $policy;
    /** @var string|null */
    private $illustrative_opt_in;
    /** @var callable|null */
    private $error_reporter;

    public function __construct(
        EVC_Redemption_History_Source $history,
        EVC_Prep_Catalogue_Authority $authority,
        EVC_Club_Staff_Authorizer $authorizer,
        EVC_Member_Context_Verifier $context,
        EVC_Clock_Source $clock,
        EVC_Usual_Coffee_Policy $policy,
        ?string $illustrative_opt_in = null,
        ?callable $error_reporter = null
    ) {
        $this->history = $history;
        $this->authority = $authority;
        $this->authorizer = $authorizer;
        $this->context = $context;
        $this->clock = $clock;
        $this->policy = $policy;
        $this->illustrative_opt_in = $illustrative_opt_in;
        $this->error_reporter = $error_reporter;
    }

    /**
     * @param mixed $member_public_id from the URL of the current lookup
     * @param mixed $lookup_ref       server-issued reference of that lookup
     */
    public function suggest(EVC_Staff_Actor $actor, $member_public_id, $lookup_ref): EVC_Usual_Coffee_Result {
        try {
            return $this->suggest_or_throw($actor, $member_public_id, $lookup_ref);
        } catch (Throwable $e) {
            // Class name only: never a message, a member or a preparation.
            $this->report($e);
            return EVC_Usual_Coffee_Result::refused(EVC_Usual_Coffee_Result::SERVER_ERROR);
        }
    }

    private function suggest_or_throw(EVC_Staff_Actor $actor, $member_public_id, $lookup_ref): EVC_Usual_Coffee_Result {
        // 1. Permission and member context before any read.
        if (!$this->authorizer->allows($actor, self::CAPABILITY)) {
            return EVC_Usual_Coffee_Result::refused(EVC_Usual_Coffee_Result::FORBIDDEN);
        }
        if (!EVC_Member_Id::is_valid_format($member_public_id)) {
            return EVC_Usual_Coffee_Result::refused(EVC_Usual_Coffee_Result::INVALID_REQUEST);
        }
        if (!$this->context->verify($actor, $member_public_id, $lookup_ref)) {
            return EVC_Usual_Coffee_Result::refused(EVC_Usual_Coffee_Result::FORBIDDEN);
        }
        $member = $this->history->find_member_by_public_id($member_public_id);
        if ($member === null || $member['status'] !== 'enabled') {
            return EVC_Usual_Coffee_Result::refused(EVC_Usual_Coffee_Result::MEMBER_NOT_FOUND);
        }

        // 2. Lookback window in Europe/Athens business dates (server clock).
        $today = EVC_Clock::business_date(EVC_Clock::to_utc($this->clock->now()));
        $from = self::shift_date($today, -($this->policy->lookback_days - 1));
        $rows = $this->history->member_redemptions($member['id'], $from, $today);

        // 3.-4. Valid records of this member, most recent first.
        $valid = self::valid_records($rows, $member, $from, $today);
        usort($valid, function (array $a, array $b): int {
            return strcmp($b['sort'], $a['sort']);
        });
        $considered = array_slice($valid, 0, $this->policy->max_considered);
        if (count($considered) < $this->policy->min_valid) {
            return EVC_Usual_Coffee_Result::none(EVC_Usual_Coffee_Result::REASON_INSUFFICIENT_HISTORY);
        }

        // 5.-6. Complete observed preparations, ranked deterministically.
        $winner = self::rank($considered);
        if ($winner['count'] < $this->policy->min_occurrences
            || $winner['count'] * 100 < $this->policy->min_share_percent * count($considered)) {
            return EVC_Usual_Coffee_Result::none(EVC_Usual_Coffee_Result::REASON_NO_CLEAR_USUAL);
        }

        // 7. Exactly offerable with the current authorised catalogue, or nothing.
        $catalogue = $this->authorised_catalogue();
        if ($catalogue === null) {
            return EVC_Usual_Coffee_Result::none(EVC_Usual_Coffee_Result::REASON_CATALOGUE_NOT_AUTHORISED);
        }
        try {
            $order = EVC_Prep_Validator::validate($catalogue, $winner['coffee_code'], $winner['modifiers'], $winner['quick_notes']);
        } catch (EVC_Prep_Rejection $r) {
            return EVC_Usual_Coffee_Result::none(EVC_Usual_Coffee_Result::REASON_NOT_OFFERABLE);
        }
        return EVC_Usual_Coffee_Result::suggested(array(
            'catalogue_version' => $order->catalogue_version(),
            'coffee_code' => $order->coffee_code(),
            'coffee_label' => $order->coffee_label(),
            'modifiers' => $order->modifiers(),
            'quick_notes' => $order->quick_notes(),
            'basis' => array('occurrences' => $winner['count'], 'considered' => count($considered)),
        ));
    }

    /**
     * Intact v2 redemptions of this member inside the window. A request id
     * seen more than once is conflicting and dropped entirely.
     *
     * @return array<int,array{sort:string,request_id:string,coffee_code:string,modifiers:array<string,string>,quick_notes:string[],key:string}>
     */
    private static function valid_records(array $rows, array $member, string $from, string $today): array {
        $by_request = array();
        $seen = array();
        foreach ($rows as $stored) {
            if (!is_array($stored) || !isset($stored['ledger'], $stored['snapshots'], $stored['handoffs'])
                || !is_array($stored['ledger']) || !is_array($stored['snapshots']) || !is_array($stored['handoffs'])) {
                continue;
            }
            $ledger = $stored['ledger'];
            if (($ledger['member_id'] ?? null) !== $member['id'] || ($ledger['member_public_id'] ?? null) !== $member['member_public_id']) {
                continue; // never another member's history
            }
            $request_id = isset($ledger['request_id']) && is_string($ledger['request_id']) ? $ledger['request_id'] : null;
            if ($request_id === null) {
                continue;
            }
            $seen[$request_id] = ($seen[$request_id] ?? 0) + 1;
            if (($ledger['fingerprint_version'] ?? null) !== EVC_Prep_Canonical::FINGERPRINT_VERSION) {
                continue; // v1 rows have no preparation
            }
            try {
                if (EVC_Handoff_Service::record_problem($stored) !== null) {
                    continue; // damaged, incomplete or conflicting record
                }
            } catch (Throwable $e) {
                continue;
            }
            $date = (string) $ledger['business_date'];
            if (strcmp($date, $from) < 0 || strcmp($date, $today) > 0) {
                continue;
            }
            $snapshot = $stored['snapshots'][0];
            $modifiers = array();
            foreach ($snapshot['modifiers'] as $m) {
                $modifiers[$m['group']] = $m['option'];
            }
            $quick = array_column($snapshot['quick_notes'], 'id');
            $by_request[$request_id] = array(
                'sort' => $date . ' ' . $ledger['redeemed_at_utc'] . ' ' . $request_id,
                'request_id' => $request_id,
                'coffee_code' => $snapshot['coffee_code'],
                'modifiers' => $modifiers,
                'quick_notes' => $quick,
                // Codes only, catalogue version excluded: the same preparation across versions is one habit.
                'key' => EVC_Prep_Canonical::canonical(1, $snapshot['coffee_code'], $modifiers, $quick),
            );
        }
        $out = array();
        foreach ($by_request as $request_id => $record) {
            if ($seen[$request_id] === 1) {
                $out[] = $record;
            }
        }
        return $out;
    }

    /**
     * Highest count; ties -> most recent occurrence; then byte order of the key.
     *
     * @return array{count:int,coffee_code:string,modifiers:array<string,string>,quick_notes:string[]}
     */
    private static function rank(array $considered): array {
        $groups = array();
        foreach ($considered as $r) {
            if (!isset($groups[$r['key']])) {
                $groups[$r['key']] = array('count' => 0, 'latest' => '', 'key' => $r['key'], 'coffee_code' => $r['coffee_code'], 'modifiers' => $r['modifiers'], 'quick_notes' => $r['quick_notes']);
            }
            $groups[$r['key']]['count']++;
            if (strcmp($r['sort'], $groups[$r['key']]['latest']) > 0) {
                $groups[$r['key']]['latest'] = $r['sort'];
            }
        }
        $groups = array_values($groups);
        usort($groups, function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }
            $recency = strcmp($b['latest'], $a['latest']);
            return $recency !== 0 ? $recency : strcmp($a['key'], $b['key']);
        });
        return $groups[0];
    }

    /** Same server-side authorisation as the redemption service: status AND pinned owner digest. */
    private function authorised_catalogue(): ?EVC_Prep_Catalogue {
        $data = $this->authority->active_catalogue_data();
        try {
            $catalogue = EVC_Prep_Catalogue::from_array($data);
        } catch (InvalidArgumentException $e) {
            return null;
        }
        if (!$catalogue->is_approved()) {
            return $this->illustrative_opt_in === self::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS ? $catalogue : null;
        }
        $approved = $this->authority->approved_digest($catalogue->version());
        return $approved !== null && hash_equals($approved, EVC_Prep_Catalogue_Digest::of($data)) ? $catalogue : null;
    }

    /** Calendar-date arithmetic on Y-m-d (UTC noon: no DST effect on whole days). */
    private static function shift_date(string $date, int $days): string {
        return (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function report(Throwable $e): void {
        if ($this->error_reporter === null) {
            return;
        }
        try {
            call_user_func($this->error_reporter, array('stage' => 'usual_coffee', 'exception' => get_class($e)));
        } catch (Throwable $ignored) {
            // A broken reporter must not change the result.
        }
    }
}
