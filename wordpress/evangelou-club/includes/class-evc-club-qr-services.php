<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-05C: the ONE construction site of the QR pieces (packager rule set qrProblemsFor):
 *   EVC_Sql_Qr_Token_Store          in store()
 *   EVC_Wp_Club_Staff_Authorizer    in authorizer()
 *   EVC_Qr_Token_Service, EVC_First_Issue_Only_Qr_Store   in token_service()
 *   EVC_Member_Lookup_Service, EVC_Sql_Lookup_Rate_Limiter, EVC_Lookup_Key_Ring,
 *   EVC_Lookup_Context_Policy, EVC_No_Member_Phone_Directory   in lookup_service()
 * Only EVC_Customer_Qr_Backend (behind EVC_CLUB_CUSTOMER_ENABLED + EVC_CLUB_PAYMENT_PROVENANCE) and
 * EVC_Club_Lookup_Backend (behind the staff flag + EVC_CLUB_REDEMPTION_SOURCE) call it, each after its
 * own gates, on a Club DB connection it opened per request.
 *
 * Fixed technical parameters (not business rules):
 *   - LOOKUP_CONTEXT_TTL_SECONDS: the UI-04C lookup proof is computed by the reviewed service but is
 *     NOT returned by the v1 lookup route and NOT consumed by the v1 redeem route; its lifetime has no
 *     effect today (decide it with the v2 prepared redemption);
 *   - the lookup key id 'k1' for the single EVC_CLUB_LOOKUP_KEY secret.
 * Phone lookup is NOT offered (no route); the phone directory is the always-empty one.
 */
final class EVC_Club_Qr_Services {
    const LOOKUP_CONTEXT_TTL_SECONDS = 300;
    const LOOKUP_PURPOSE = 'member_lookup';
    const LOOKUP_KEY_ID = 'k1';
    const LOOKUP_KEY_CONSTANT = 'EVC_CLUB_LOOKUP_KEY';
    /** Row-lock waits on these paths give up quickly (same as the STAGE-05A / 05B connections). */
    const LOCK_WAIT_SECONDS = 5;

    /** The server secret for lookup proofs (wp-config.php), or null when missing / shorter than 32 bytes. */
    public static function lookup_secret_from_constants(): ?string {
        if (!defined(self::LOOKUP_KEY_CONSTANT)) {
            return null;
        }
        $secret = constant(self::LOOKUP_KEY_CONSTANT);
        return self::is_lookup_secret($secret) ? $secret : null;
    }

    /** @param mixed $secret a usable lookup-proof secret: a string of at least 32 bytes */
    public static function is_lookup_secret($secret): bool {
        return is_string($secret) && strlen($secret) >= EVC_Lookup_Key_Ring::MIN_SECRET_BYTES;
    }

    /** Sets the short session lock wait on a connection used by the QR paths. */
    public static function prepare_connection(EVC_Club_Db $db): void {
        $db->exec_raw('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
    }

    public static function store(EVC_Club_Db $db): EVC_Sql_Qr_Token_Store {
        return new EVC_Sql_Qr_Token_Store($db);
    }

    public static function authorizer(): EVC_Club_Staff_Authorizer {
        return new EVC_Wp_Club_Staff_Authorizer();
    }

    /**
     * $first_issue_only: the service may only create a FIRST token (none active); if one is active by
     * the time it writes, the compare-and-set fails (concurrent_change) and nothing is replaced (D6).
     *
     * @param callable(array): void $report receives only {stage, exception} (UI-04C)
     */
    public static function token_service(EVC_Club_Db $db, EVC_Clock_Source $clock, callable $report, bool $first_issue_only): EVC_Qr_Token_Service {
        $store = self::store($db);
        return new EVC_Qr_Token_Service($first_issue_only ? new EVC_First_Issue_Only_Qr_Store($store) : $store, self::authorizer(), $clock, null, $report);
    }

    /** @param callable(array): void $report receives only {stage, exception} (UI-04C) */
    public static function lookup_service(EVC_Club_Db $db, string $secret, EVC_Clock_Source $clock, callable $report): EVC_Member_Lookup_Service {
        return new EVC_Member_Lookup_Service(
            self::store($db),
            new EVC_No_Member_Phone_Directory(),
            self::authorizer(),
            new EVC_Sql_Lookup_Rate_Limiter($db, $clock),
            new EVC_Lookup_Key_Ring(array(self::LOOKUP_KEY_ID => $secret), self::LOOKUP_KEY_ID),
            new EVC_Lookup_Context_Policy(self::LOOKUP_CONTEXT_TTL_SECONDS),
            // Owner decision D6 (2026-11-04): a QR has no age limit; it changes only on request.
            EVC_Qr_Token_Policy::without_expiry(),
            $clock,
            self::LOOKUP_PURPOSE,
            null,
            $report
        );
    }
}

/**
 * WordPress capabilities for the two QR actions only: `evc_lookup_member` (the shared Club staff
 * role) and `evc_manage_club` (administrators). Every other capability — e.g. the v2 hand-off ones —
 * is refused, so this authorizer cannot widen any other (still unwired) service.
 */
final class EVC_Wp_Club_Staff_Authorizer implements EVC_Club_Staff_Authorizer {
    const ALLOWED = array('evc_lookup_member', 'evc_manage_club');

    public function allows(EVC_Staff_Actor $actor, string $capability): bool {
        return in_array($capability, self::ALLOWED, true) && user_can($actor->wp_user_id(), $capability);
    }
}

/** Phone lookup is not offered in STAGE-05C: no member is ever found by phone. */
final class EVC_No_Member_Phone_Directory implements EVC_Member_Phone_Directory {
    public function member_ids_for_phone(string $canonical_phone): array {
        return array();
    }
}

/**
 * Decorator for a FIRST issuance only: reports "no active token" to EVC_Qr_Token_Service, so the
 * service's rotation is rotate(member, expected = null, ...). The SQL store's compare-and-set then
 * refuses (EVC_Qr_Rotation_Conflict) if any token is active, so a request that did not ask for a
 * replacement can never replace a QR another device just received. Everything else delegates.
 */
final class EVC_First_Issue_Only_Qr_Store implements EVC_Qr_Token_Store {
    /** @var EVC_Qr_Token_Store */
    private $inner;

    public function __construct(EVC_Qr_Token_Store $inner) {
        $this->inner = $inner;
    }

    public function find_member_by_public_id(string $member_public_id): ?array {
        return $this->inner->find_member_by_public_id($member_public_id);
    }

    public function find_member_by_id(int $member_id): ?array {
        return $this->inner->find_member_by_id($member_id);
    }

    public function find_token_by_hash(string $token_hash): ?array {
        return $this->inner->find_token_by_hash($token_hash);
    }

    public function active_token_hash(int $member_id): ?string {
        return null;
    }

    public function rotate(int $member_id, ?string $expected_active_hash, string $new_hash, string $now_utc): void {
        $this->inner->rotate($member_id, null, $new_hash, $now_utc);
    }

    public function revoke(int $member_id, string $expected_active_hash, string $now_utc): void {
        throw new LogicException('A first-issuance store never revokes.');
    }
}
