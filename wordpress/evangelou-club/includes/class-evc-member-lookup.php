<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-04C — QR tokens, secure member lookup and lookup-context proof
 * (pure PHP, definitions only, NOT wired: no SQL, REST route, hook, key or UI).
 *
 * A QR token is a MEMBER LOOKUP handle only. It is not staff
 * authentication, not payment confirmation, not an entitlement and not
 * permission to redeem. A successful lookup establishes WHICH member is at
 * the counter; eligibility (verified payment, active month, today's coffee)
 * is decided solely by the redemption engine, which a lookup never touches.
 *
 * Tokens: EVC_Qr_Token (192 random bits, "evc_" + 48 hex), stored ONLY as
 * SHA-256 digests, at most one active per member, rotated atomically with
 * compare-and-set; responses never contain a hash.
 *
 * Lookup: QR or canonical phone; capability `evc_lookup_member` and a
 * session-bound staff actor are checked BEFORE any read; server-side rate
 * limiting is consulted before any read and fails closed; unknown, malformed,
 * revoked, rotated, expired and disabled all answer the same `not_found`;
 * several members with one phone answer `ambiguous` without any data.
 *
 * Lookup context: a successful lookup returns an HMAC-SHA256 signed proof
 * bound to staff actor + exact session + member + purpose + issue/expiry
 * time. EVC_Signed_Member_Context_Verifier implements
 * EVC_Member_Context_Verifier with it. Keys are injected (EVC_Lookup_Key_Ring);
 * the TTL is injected (EVC_Lookup_Context_Policy) and is NOT owner-approved.
 */

/** Server-held keys for lookup proofs and phone pseudonyms. No key exists in code. */
final class EVC_Lookup_Key_Ring {
    const KEY_ID_PATTERN = '/^[a-z0-9]{1,16}$/D';
    const MIN_SECRET_BYTES = 32;

    /** @var array<string,string> key id => secret */
    private $secrets;
    /** @var string */
    private $active_id;

    /** @param array<string,string> $secrets key id => raw secret (>= 32 bytes); older keys verify only */
    public function __construct(array $secrets, string $active_id) {
        foreach ($secrets as $id => $secret) {
            if (!is_string($id) || !preg_match(self::KEY_ID_PATTERN, $id) || !is_string($secret) || strlen($secret) < self::MIN_SECRET_BYTES) {
                throw new InvalidArgumentException('Invalid lookup key ring.');
            }
        }
        if (!isset($secrets[$active_id])) {
            throw new InvalidArgumentException('Invalid lookup key ring.');
        }
        $this->secrets = $secrets;
        $this->active_id = $active_id;
    }

    public function active_id(): string {
        return $this->active_id;
    }

    /** Domain-separated subkey of one key, or null for an unknown key id. */
    public function subkey(string $key_id, string $purpose): ?string {
        if (!isset($this->secrets[$key_id])) {
            return null;
        }
        return hash_hmac('sha256', 'evc-lookup-subkey-v1|' . $purpose, $this->secrets[$key_id], true);
    }

    /** Keys never appear in var_dump/print_r output. */
    public function __debugInfo(): array {
        return array('active_id' => $this->active_id, 'key_ids' => array_keys($this->secrets));
    }
}

/** Lifetime of a lookup proof: an OPEN owner decision, always injected (no default). */
final class EVC_Lookup_Context_Policy {
    const MAX_TTL_SECONDS = 3600;

    /** @var int */
    private $ttl_seconds;

    public function __construct(int $ttl_seconds) {
        if ($ttl_seconds < 1 || $ttl_seconds > self::MAX_TTL_SECONDS) {
            throw new InvalidArgumentException('Invalid lookup context policy.');
        }
        $this->ttl_seconds = $ttl_seconds;
    }

    public function ttl_seconds(): int {
        return $this->ttl_seconds;
    }
}

/**
 * Signed lookup proof:
 *   "lkp1." key_id "." base64url(payload JSON) "." base64url(HMAC-SHA256)
 * payload = {v:1, staff, session, member, purpose, iat, exp, nonce}; the MAC
 * covers the domain tag, the key id and the encoded payload.
 */
final class EVC_Lookup_Context {
    const PREFIX = 'lkp1';
    const DOMAIN = "evc-lookup-context-v1\n";
    const SUBKEY = 'lookup-context';
    const PURPOSE_PATTERN = '/^[a-z][a-z_]{2,31}$/D';
    const MAX_LENGTH = 512;
    const PAYLOAD_KEYS = array('v', 'staff', 'session', 'member', 'purpose', 'iat', 'exp', 'nonce');

    public static function issue(EVC_Lookup_Key_Ring $keys, EVC_Lookup_Context_Policy $policy, EVC_Staff_Actor $actor, string $member_public_id, string $purpose, int $now, string $nonce): array {
        if ($actor->session_ref() === null || !EVC_Member_Id::is_valid_format($member_public_id)
            || !preg_match(self::PURPOSE_PATTERN, $purpose) || !preg_match('/^[0-9a-f]{32}$/D', $nonce)) {
            throw new InvalidArgumentException('Invalid lookup context.');
        }
        $payload = self::b64(json_encode(array(
            'v' => 1,
            'staff' => $actor->wp_user_id(),
            'session' => $actor->session_ref(),
            'member' => $member_public_id,
            'purpose' => $purpose,
            'iat' => $now,
            'exp' => $now + $policy->ttl_seconds(),
            'nonce' => $nonce,
        ), JSON_THROW_ON_ERROR));
        $key_id = $keys->active_id();
        $mac = self::b64(hash_hmac('sha256', self::DOMAIN . $key_id . "\n" . $payload, (string) $keys->subkey($key_id, self::SUBKEY), true));
        return array('ref' => self::PREFIX . '.' . $key_id . '.' . $payload . '.' . $mac, 'expires_at' => $now + $policy->ttl_seconds());
    }

    /**
     * The payload of an authentic, unexpired proof bound to exactly this
     * actor, session, member and purpose; null otherwise (never says why).
     *
     * @param mixed $ref
     */
    public static function verify(EVC_Lookup_Key_Ring $keys, EVC_Lookup_Context_Policy $policy, EVC_Staff_Actor $actor, string $member_public_id, string $purpose, $ref, int $now): ?array {
        if (!is_string($ref) || strlen($ref) > self::MAX_LENGTH || $actor->session_ref() === null) {
            return null;
        }
        $parts = explode('.', $ref);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || !preg_match(EVC_Lookup_Key_Ring::KEY_ID_PATTERN, $parts[1])
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $parts[2]) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $parts[3])) {
            return null;
        }
        $subkey = $keys->subkey($parts[1], self::SUBKEY);
        if ($subkey === null) {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', self::DOMAIN . $parts[1] . "\n" . $parts[2], $subkey, true));
        if (!hash_equals($expected, $parts[3])) {
            return null;
        }
        $json = self::unb64($parts[2]);
        $p = $json === null ? null : json_decode($json, true);
        if (!is_array($p) || array_keys($p) !== self::PAYLOAD_KEYS || $p['v'] !== 1
            || !is_int($p['staff']) || !is_string($p['session']) || !is_string($p['member']) || !is_string($p['purpose'])
            || !is_int($p['iat']) || !is_int($p['exp'])) {
            return null;
        }
        if ($p['staff'] !== $actor->wp_user_id() || !hash_equals($p['session'], (string) $actor->session_ref())
            || !hash_equals($p['member'], $member_public_id) || $p['purpose'] !== $purpose) {
            return null;
        }
        // Not before issue, strictly before expiry, and never longer-lived than the CURRENT policy allows.
        if ($now < $p['iat'] || $now >= $p['exp'] || $p['exp'] - $p['iat'] > $policy->ttl_seconds() || $p['exp'] <= $p['iat']) {
            return null;
        }
        return $p;
    }

    private static function b64(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }
}

/** The first real EVC_Member_Context_Verifier (definitions only; constructed nowhere in shipped code). */
final class EVC_Signed_Member_Context_Verifier implements EVC_Member_Context_Verifier {
    /** @var EVC_Lookup_Key_Ring */
    private $keys;
    /** @var EVC_Lookup_Context_Policy */
    private $policy;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var string */
    private $purpose;

    public function __construct(EVC_Lookup_Key_Ring $keys, EVC_Lookup_Context_Policy $policy, EVC_Clock_Source $clock, string $purpose) {
        if (!preg_match(EVC_Lookup_Context::PURPOSE_PATTERN, $purpose)) {
            throw new InvalidArgumentException('Invalid lookup purpose.');
        }
        $this->keys = $keys;
        $this->policy = $policy;
        $this->clock = $clock;
        $this->purpose = $purpose;
    }

    public function verify(EVC_Staff_Actor $actor, string $member_public_id, $lookup_ref): bool {
        $now = EVC_Clock::to_utc($this->clock->now())->getTimestamp();
        return EVC_Lookup_Context::verify($this->keys, $this->policy, $actor, $member_public_id, $this->purpose, $lookup_ref, $now) !== null;
    }
}

/**
 * Greek phone canonicalisation (the only accepted numbers until the owner
 * decides otherwise): spaces, dots, dashes and parentheses are ignored; an
 * optional +30 / 0030 prefix; then exactly 10 digits, a mobile (69…) or a
 * landline (2…). Canonical form: "+30" + 10 digits. Anything else is invalid.
 */
final class EVC_Phone_Canonical {
    const MAX_INPUT = 32;

    /** @param mixed $input */
    public static function canonical($input): ?string {
        if (!is_string($input) || strlen($input) > self::MAX_INPUT || !preg_match('/^[0-9+ .\-()]+$/D', $input)) {
            return null;
        }
        $compact = preg_replace('/[ .\-()]/', '', $input);
        if (strpos($compact, '+30') === 0) {
            $digits = substr($compact, 3);
        } elseif (strpos($compact, '0030') === 0) {
            $digits = substr($compact, 4);
        } else {
            $digits = $compact;
        }
        if (!preg_match('/^(69[0-9]{8}|2[0-9]{9})$/D', $digits)) {
            return null;
        }
        return '+30' . $digits;
    }

    /** Keyed pseudonym of a canonical phone (rate-limit keys, audit): never the number itself. */
    public static function pseudonym(EVC_Lookup_Key_Ring $keys, string $canonical): string {
        return substr(hash_hmac('sha256', $canonical, (string) $keys->subkey($keys->active_id(), 'phone-pseudonym')), 0, 32);
    }
}

/** QR token store (future SQL on evc_members + evc_qr_tokens). Only test fakes implement it. */
interface EVC_Qr_Token_Store {
    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string}|null */
    public function find_member_by_public_id(string $member_public_id): ?array;

    /** @return array{id:int,member_public_id:string,wp_user_id:int,status:string}|null */
    public function find_member_by_id(int $member_id): ?array;

    /** @return array{member_id:int,status:string,issued_at_utc:string,revoked_at_utc:?string}|null */
    public function find_token_by_hash(string $token_hash): ?array;

    /** SHA-256 hash of the member's active token, or null. Internal only: never returned to a caller. */
    public function active_token_hash(int $member_id): ?string;

    /**
     * ONE transaction: if the member's active token hash still equals
     * $expected_active_hash (null = none), revoke it and insert $new_hash as
     * the active token; otherwise change nothing and throw.
     *
     * @throws EVC_Qr_Rotation_Conflict        the active token changed meanwhile (compare-and-set failed)
     * @throws EVC_Db_Duplicate_Key_Exception  hash collision / uq_qr_tokens_one_active (rolled back)
     * @throws EVC_Db_Commit_Unknown_Exception COMMIT outcome unknown
     */
    public function rotate(int $member_id, ?string $expected_active_hash, string $new_hash, string $now_utc): void;

    /** Revoke the active token if it still equals $expected_active_hash; same exceptions as rotate(). */
    public function revoke(int $member_id, string $expected_active_hash, string $now_utc): void;
}

final class EVC_Qr_Rotation_Conflict extends RuntimeException {
}

/** Members by canonical phone (future: WordPress/WooCommerce data mapped to evc_members). Never assumed unique. */
interface EVC_Member_Phone_Directory {
    /** @return int[] internal Club member ids whose verified phone equals the canonical number */
    public function member_ids_for_phone(string $canonical_phone): array;
}

/**
 * Server-side rate limiting. allow() CONSUMES one attempt for every key and
 * returns false when any key is over its limit; record_failure() adds a
 * failed attempt. Thresholds belong to the adapter (open decision). Any
 * exception means "unavailable" and the caller fails closed.
 */
interface EVC_Lookup_Rate_Limiter {
    /** @param string[] $keys */
    public function allow(array $keys, string $action): bool;

    /** @param string[] $keys */
    public function record_failure(array $keys, string $action): void;
}

/** Optional maximum token age: an OPEN decision; tests inject it. */
final class EVC_Qr_Token_Policy {
    /** @var int|null */
    private $max_age_days;

    private function __construct(?int $max_age_days) {
        if ($max_age_days !== null && ($max_age_days < 1 || $max_age_days > 3660)) {
            throw new InvalidArgumentException('Invalid QR token policy.');
        }
        $this->max_age_days = $max_age_days;
    }

    public static function without_expiry(): self {
        return new self(null);
    }

    public static function max_age_days(int $days): self {
        return new self($days);
    }

    /** True when a token issued at $issued_at_utc is too old at $now. */
    public function is_expired(string $issued_at_utc, DateTimeImmutable $now): bool {
        if ($this->max_age_days === null) {
            return false;
        }
        $issued = new DateTimeImmutable($issued_at_utc, new DateTimeZone('UTC'));
        return $now >= $issued->modify('+' . $this->max_age_days . ' days');
    }
}

/** Who asks for a token: the member themself (server-side WP user) or an authorised administrator. */
final class EVC_Qr_Issuance_Requester {
    const MEMBER_SELF = 'member_self';
    const ADMINISTRATOR = 'administrator';

    /** @var string */
    private $kind;
    /** @var int|null */
    private $wp_user_id;
    /** @var EVC_Staff_Actor|null */
    private $actor;

    private function __construct(string $kind, ?int $wp_user_id, ?EVC_Staff_Actor $actor) {
        $this->kind = $kind;
        $this->wp_user_id = $wp_user_id;
        $this->actor = $actor;
    }

    /** From the authenticated WordPress session of the member (never from a request body). */
    public static function member_self(int $wp_user_id): self {
        if ($wp_user_id < 1) {
            throw new InvalidArgumentException('Invalid requester.');
        }
        return new self(self::MEMBER_SELF, $wp_user_id, null);
    }

    public static function administrator(EVC_Staff_Actor $actor): self {
        return new self(self::ADMINISTRATOR, null, $actor);
    }

    public function kind(): string {
        return $this->kind;
    }

    public function wp_user_id(): ?int {
        return $this->wp_user_id;
    }

    public function actor(): ?EVC_Staff_Actor {
        return $this->actor;
    }
}

final class EVC_Qr_Token_Result {
    const ISSUED = 'issued';
    const REVOKED = 'revoked';
    const NOTHING_TO_REVOKE = 'nothing_to_revoke';
    const FORBIDDEN = 'forbidden';
    const INVALID_REQUEST = 'invalid_request';
    const MEMBER_NOT_FOUND = 'member_not_found';
    const MEMBER_DISABLED = 'member_disabled';
    const CONCURRENT_CHANGE = 'concurrent_change';
    const OUTCOME_UNKNOWN = 'outcome_unknown';
    const SERVER_ERROR = 'server_error';

    /** @var string */
    private $kind;
    /** @var string|null */
    private $token;
    /** @var string|null */
    private $issued_at_utc;

    private function __construct(string $kind, ?string $token, ?string $issued_at_utc) {
        $this->kind = $kind;
        $this->token = $token;
        $this->issued_at_utc = $issued_at_utc;
    }

    public static function issued(string $token, string $issued_at_utc): self {
        if (!EVC_Qr_Token::is_valid_format($token)) {
            throw new InvalidArgumentException('Not a QR token.');
        }
        return new self(self::ISSUED, $token, $issued_at_utc);
    }

    public static function of(string $kind): self {
        if ($kind === self::ISSUED) {
            throw new InvalidArgumentException('An issued result needs its token.');
        }
        return new self($kind, null, null);
    }

    public function kind(): string {
        return $this->kind;
    }

    /** The plaintext token, returned ONCE at issuance only (it is never stored). */
    public function token(): ?string {
        return $this->token;
    }

    public function issued_at_utc(): ?string {
        return $this->issued_at_utc;
    }
}

final class EVC_Qr_Token_Service {
    const CAPABILITY_MANAGE = 'evc_manage_club';

    /** @var EVC_Qr_Token_Store */
    private $store;
    /** @var EVC_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable function (): string */
    private $mint;
    /** @var callable|null */
    private $error_reporter;

    public function __construct(EVC_Qr_Token_Store $store, EVC_Club_Staff_Authorizer $authorizer, EVC_Clock_Source $clock, ?callable $mint = null, ?callable $error_reporter = null) {
        $this->store = $store;
        $this->authorizer = $authorizer;
        $this->clock = $clock;
        $this->mint = $mint ?? array('EVC_Qr_Token', 'mint');
        $this->error_reporter = $error_reporter;
    }

    /**
     * First issuance and re-issuance are the same operation: a ROTATION. Any
     * previous token stops working in the same transaction. After an unknown
     * outcome the safe recovery is simply to issue again (it rotates whatever
     * was stored); the plaintext of an uncertain token is never returned.
     *
     * @param mixed $member_public_id
     */
    public function issue(EVC_Qr_Issuance_Requester $requester, $member_public_id): EVC_Qr_Token_Result {
        try {
            $member = $this->authorised_member($requester, $member_public_id);
            if ($member instanceof EVC_Qr_Token_Result) {
                return $member;
            }
            if ($member['status'] !== 'enabled') {
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::MEMBER_DISABLED);
            }
            $token = call_user_func($this->mint);
            if (!EVC_Qr_Token::is_valid_format($token)) {
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::SERVER_ERROR);
            }
            $now = EVC_Clock::to_utc($this->clock->now())->format('Y-m-d H:i:s.u');
            $expected = $this->store->active_token_hash($member['id']);
            try {
                $this->store->rotate($member['id'], $expected, EVC_Qr_Token::digest($token), $now);
            } catch (EVC_Qr_Rotation_Conflict $e) {
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::CONCURRENT_CHANGE);
            } catch (EVC_Db_Duplicate_Key_Exception $e) {
                // A concurrent first issuance won the one-active-token key (or a 2^-192 collision).
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::CONCURRENT_CHANGE);
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                $this->report($e);
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::OUTCOME_UNKNOWN);
            }
            return EVC_Qr_Token_Result::issued($token, $now);
        } catch (Throwable $e) {
            $this->report($e);
            return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::SERVER_ERROR);
        }
    }

    /** @param mixed $member_public_id */
    public function revoke(EVC_Qr_Issuance_Requester $requester, $member_public_id): EVC_Qr_Token_Result {
        try {
            $member = $this->authorised_member($requester, $member_public_id);
            if ($member instanceof EVC_Qr_Token_Result) {
                return $member;
            }
            $expected = $this->store->active_token_hash($member['id']);
            if ($expected === null) {
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::NOTHING_TO_REVOKE);
            }
            try {
                $this->store->revoke($member['id'], $expected, EVC_Clock::to_utc($this->clock->now())->format('Y-m-d H:i:s.u'));
            } catch (EVC_Qr_Rotation_Conflict $e) {
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::CONCURRENT_CHANGE);
            } catch (EVC_Db_Commit_Unknown_Exception $e) {
                $this->report($e);
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::OUTCOME_UNKNOWN);
            }
            return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::REVOKED);
        } catch (Throwable $e) {
            $this->report($e);
            return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::SERVER_ERROR);
        }
    }

    /**
     * The member record the requester may manage, or a refusal. A member may
     * act only on their OWN record (any other id: forbidden, so existence is
     * never revealed); an administrator needs `evc_manage_club` before any read.
     *
     * @param mixed $member_public_id
     * @return array|EVC_Qr_Token_Result
     */
    private function authorised_member(EVC_Qr_Issuance_Requester $requester, $member_public_id) {
        if ($requester->kind() === EVC_Qr_Issuance_Requester::ADMINISTRATOR
            && !$this->authorizer->allows($requester->actor(), self::CAPABILITY_MANAGE)) {
            return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::FORBIDDEN);
        }
        if (!EVC_Member_Id::is_valid_format($member_public_id)) {
            return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::INVALID_REQUEST);
        }
        $member = $this->store->find_member_by_public_id($member_public_id);
        if ($requester->kind() === EVC_Qr_Issuance_Requester::MEMBER_SELF) {
            if ($member === null || $member['wp_user_id'] !== $requester->wp_user_id()) {
                return EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::FORBIDDEN);
            }
            return $member;
        }
        return $member === null ? EVC_Qr_Token_Result::of(EVC_Qr_Token_Result::MEMBER_NOT_FOUND) : $member;
    }

    private function report(Throwable $e): void {
        if ($this->error_reporter === null) {
            return;
        }
        try {
            call_user_func($this->error_reporter, array('stage' => 'qr_token', 'exception' => get_class($e)));
        } catch (Throwable $ignored) {
            // never changes the result
        }
    }
}

final class EVC_Member_Lookup_Result {
    const FOUND = 'found';
    /** Unknown, malformed, revoked, rotated, expired token / unknown phone / disabled member: one answer. */
    const NOT_FOUND = 'not_found';
    /** Several members share the phone: no data at all; use the member's QR. */
    const AMBIGUOUS = 'ambiguous';
    const INVALID_PHONE = 'invalid_phone';
    const FORBIDDEN = 'forbidden';
    const RATE_LIMITED = 'rate_limited';
    const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $kind;
    /** @var array|null */
    private $member;

    private function __construct(string $kind, ?array $member) {
        $this->kind = $kind;
        $this->member = $member;
    }

    public static function found(array $member): self {
        return new self(self::FOUND, $member);
    }

    public static function of(string $kind): self {
        if ($kind === self::FOUND) {
            throw new InvalidArgumentException('A found result needs its member.');
        }
        return new self($kind, null);
    }

    public function kind(): string {
        return $this->kind;
    }

    /**
     * {member_public_id, lookup_ref, lookup_ref_expires_at_utc} or null. Identity
     * only: no name, phone, payment, membership or eligibility, no history.
     */
    public function member(): ?array {
        return $this->member;
    }

    /** Always false: identity is not eligibility; the redemption engine decides. */
    public function grants_entitlement(): bool {
        return false;
    }
}

final class EVC_Member_Lookup_Service {
    const CAPABILITY = 'evc_lookup_member';
    const ACTION_QR = 'qr_lookup';
    const ACTION_PHONE = 'phone_lookup';

    /** @var EVC_Qr_Token_Store */
    private $tokens;
    /** @var EVC_Member_Phone_Directory */
    private $phones;
    /** @var EVC_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Lookup_Rate_Limiter */
    private $limiter;
    /** @var EVC_Lookup_Key_Ring */
    private $keys;
    /** @var EVC_Lookup_Context_Policy */
    private $context_policy;
    /** @var EVC_Qr_Token_Policy */
    private $token_policy;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var string */
    private $purpose;
    /** @var callable function (): string 32 hex */
    private $nonce;
    /** @var callable|null */
    private $error_reporter;

    public function __construct(
        EVC_Qr_Token_Store $tokens,
        EVC_Member_Phone_Directory $phones,
        EVC_Club_Staff_Authorizer $authorizer,
        EVC_Lookup_Rate_Limiter $limiter,
        EVC_Lookup_Key_Ring $keys,
        EVC_Lookup_Context_Policy $context_policy,
        EVC_Qr_Token_Policy $token_policy,
        EVC_Clock_Source $clock,
        string $purpose,
        ?callable $nonce = null,
        ?callable $error_reporter = null
    ) {
        if (!preg_match(EVC_Lookup_Context::PURPOSE_PATTERN, $purpose)) {
            throw new InvalidArgumentException('Invalid lookup purpose.');
        }
        $this->tokens = $tokens;
        $this->phones = $phones;
        $this->authorizer = $authorizer;
        $this->limiter = $limiter;
        $this->keys = $keys;
        $this->context_policy = $context_policy;
        $this->token_policy = $token_policy;
        $this->clock = $clock;
        $this->purpose = $purpose;
        $this->nonce = $nonce ?? function (): string {
            return bin2hex(random_bytes(16));
        };
        $this->error_reporter = $error_reporter;
    }

    /** @param mixed $token the scanned QR text */
    public function by_qr(EVC_Staff_Actor $actor, $token): EVC_Member_Lookup_Result {
        return $this->guarded($actor, self::ACTION_QR, array(), function (array $keys) use ($actor, $token): EVC_Member_Lookup_Result {
            if (!EVC_Qr_Token::is_valid_format($token)) {
                return $this->failed($keys, self::ACTION_QR);
            }
            $row = $this->tokens->find_token_by_hash(EVC_Qr_Token::digest($token));
            $now = EVC_Clock::to_utc($this->clock->now());
            if ($row === null || $row['status'] !== 'active' || $this->token_policy->is_expired($row['issued_at_utc'], $now)) {
                return $this->failed($keys, self::ACTION_QR);
            }
            $member = $this->tokens->find_member_by_id($row['member_id']);
            if ($member === null || $member['status'] !== 'enabled') {
                return $this->failed($keys, self::ACTION_QR);
            }
            return $this->found($actor, $member['member_public_id'], $now);
        });
    }

    /** @param mixed $phone free staff input; never logged or stored in plaintext */
    public function by_phone(EVC_Staff_Actor $actor, $phone): EVC_Member_Lookup_Result {
        $canonical = EVC_Phone_Canonical::canonical($phone);
        $extra = $canonical === null ? array() : array('phone:' . EVC_Phone_Canonical::pseudonym($this->keys, $canonical));
        return $this->guarded($actor, self::ACTION_PHONE, $extra, function (array $keys) use ($actor, $canonical): EVC_Member_Lookup_Result {
            if ($canonical === null) {
                $this->failed($keys, self::ACTION_PHONE);
                return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::INVALID_PHONE);
            }
            $members = array();
            foreach (array_unique($this->phones->member_ids_for_phone($canonical)) as $id) {
                $member = is_int($id) ? $this->tokens->find_member_by_id($id) : null;
                if ($member !== null && $member['status'] === 'enabled') {
                    $members[] = $member;
                }
            }
            if (count($members) === 0) {
                return $this->failed($keys, self::ACTION_PHONE);
            }
            if (count($members) > 1) {
                // Never pick one and never list them: identify by QR instead.
                $this->failed($keys, self::ACTION_PHONE);
                return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::AMBIGUOUS);
            }
            return $this->found($actor, $members[0]['member_public_id'], EVC_Clock::to_utc($this->clock->now()));
        });
    }

    /**
     * Permission, session binding and rate limit, all BEFORE any read; any
     * limiter or store failure fails closed.
     */
    private function guarded(EVC_Staff_Actor $actor, string $action, array $extra_keys, callable $lookup): EVC_Member_Lookup_Result {
        if (!$this->authorizer->allows($actor, self::CAPABILITY) || $actor->session_ref() === null) {
            return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::FORBIDDEN);
        }
        $keys = array_merge(array('actor:' . $actor->wp_user_id(), 'session:' . $actor->session_ref()), $extra_keys);
        try {
            if (!$this->limiter->allow($keys, $action)) {
                return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::RATE_LIMITED);
            }
        } catch (Throwable $e) {
            $this->report($e);
            return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::UNAVAILABLE);
        }
        try {
            return $lookup($keys);
        } catch (Throwable $e) {
            $this->report($e);
            return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::UNAVAILABLE);
        }
    }

    private function failed(array $keys, string $action): EVC_Member_Lookup_Result {
        try {
            $this->limiter->record_failure($keys, $action);
        } catch (Throwable $e) {
            $this->report($e);
        }
        return EVC_Member_Lookup_Result::of(EVC_Member_Lookup_Result::NOT_FOUND);
    }

    private function found(EVC_Staff_Actor $actor, string $member_public_id, DateTimeImmutable $now): EVC_Member_Lookup_Result {
        $nonce = call_user_func($this->nonce);
        $context = EVC_Lookup_Context::issue($this->keys, $this->context_policy, $actor, $member_public_id, $this->purpose, $now->getTimestamp(), $nonce);
        return EVC_Member_Lookup_Result::found(array(
            'member_public_id' => $member_public_id,
            'lookup_ref' => $context['ref'],
            'lookup_ref_expires_at_utc' => (new DateTimeImmutable('@' . $context['expires_at']))->format('Y-m-d H:i:s') . '.000000',
        ));
    }

    /** Exception class only: never a token, phone, member or key. */
    private function report(Throwable $e): void {
        if ($this->error_reporter === null) {
            return;
        }
        try {
            call_user_func($this->error_reporter, array('stage' => 'member_lookup', 'exception' => get_class($e)));
        } catch (Throwable $ignored) {
            // never changes the result
        }
    }
}
