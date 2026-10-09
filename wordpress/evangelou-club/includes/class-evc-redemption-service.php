<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Atomic daily-benefit redemption engine.
 *
 * Invariant (enforced by UNIQUE uq_redemptions_one_per_day in the database,
 * NOT by a prior SELECT):
 *   one member + one benefit type + one Europe/Athens business date
 *   = at most one successful redemption.
 *
 * Flow:
 *  1. Validate input types/formats; coffee must be in the allowlist.
 *  2. Idempotency: if request_id already succeeded, replay the stored result
 *     when the payload fingerprint matches, otherwise reject the reuse.
 *     (Done before member/membership checks so a committed success is always
 *     replayable, even after midnight or after the membership has lapsed.)
 *  3. Resolve the internal member from the public opaque id.
 *  4. Read the injected clock ONCE; ask the membership adapter about that
 *     instant; deny unless verified, active and unexpired.
 *  5. Business date = Europe/Athens day of that same instant.
 *  6. One transaction: INSERT ledger row (incl. coffee) + INSERT audit event.
 *     A duplicate-key error rolls back first; then fresh reads classify it as
 *     a concurrent replay, an idempotency conflict or "already redeemed".
 *     Deadlock / lock-wait errors retry the whole transaction (bounded).
 *  Any unexpected failure returns server_error (fail closed). A failed COMMIT
 *  has an unknown outcome; retrying the same request_id resolves it.
 *
 * This is local to the Club database: it is NOT atomic with PMPro or
 * WooCommerce, which use their own storage/transactions.
 */
final class EVC_Redemption_Service {
    const BENEFIT_FREE_COFFEE = 'free_coffee';
    const MAX_TRANSACTION_ATTEMPTS = 3;
    const SESSION_REF_PATTERN = '/^[0-9a-f]{32}$/D';
    const REQUEST_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /** @var EVC_Redemption_Store */
    private $store;
    /** @var EVC_Audit_Log */
    private $audit;
    /** @var EVC_Membership_Adapter */
    private $membership;
    /** @var EVC_Coffee_Catalog */
    private $catalog;
    /** @var EVC_Clock_Source */
    private $clock;
    /** @var callable|null function (array $diagnostic): void */
    private $error_reporter;
    /** @var string|null Session reference of the current call (audit only). */
    private $session_ref = null;

    public function __construct(
        EVC_Redemption_Store $store,
        EVC_Audit_Log $audit,
        EVC_Membership_Adapter $membership,
        EVC_Coffee_Catalog $catalog,
        EVC_Clock_Source $clock,
        ?callable $error_reporter = null
    ) {
        $this->store = $store;
        $this->audit = $audit;
        $this->membership = $membership;
        $this->catalog = $catalog;
        $this->clock = $clock;
        $this->error_reporter = $error_reporter;
    }

    public function redeem(EVC_Redemption_Request $request): EVC_Redemption_Result {
        try {
            return $this->redeem_or_throw($request);
        } catch (Throwable $e) {
            $this->report('redeem', $e);
            return EVC_Redemption_Result::failure(EVC_Redemption_Result::SERVER_ERROR);
        }
    }

    private function redeem_or_throw(EVC_Redemption_Request $request): EVC_Redemption_Result {
        $member_public_id = $request->member_public_id();
        $benefit_type = $request->benefit_type();
        $coffee_code = $request->coffee_code();
        $request_id = $request->request_id();
        $staff_wp_user_id = $request->staff_wp_user_id();
        $session_ref = $request->session_ref();
        $this->session_ref = null;

        if (!EVC_Member_Id::is_valid_format($member_public_id)
            || $benefit_type !== self::BENEFIT_FREE_COFFEE
            || !is_string($request_id) || !preg_match(self::REQUEST_ID_PATTERN, $request_id)
            || !is_int($staff_wp_user_id) || $staff_wp_user_id < 1
            || !is_string($coffee_code)
            || ($session_ref !== null && !(is_string($session_ref) && preg_match(self::SESSION_REF_PATTERN, $session_ref)))) {
            return EVC_Redemption_Result::failure(EVC_Redemption_Result::INVALID_REQUEST);
        }
        $this->session_ref = $session_ref;
        if (!$this->catalog->contains($coffee_code)) {
            return EVC_Redemption_Result::failure(EVC_Redemption_Result::INVALID_COFFEE);
        }

        $fingerprint = EVC_Request_Fingerprint::compute($member_public_id, $benefit_type, $coffee_code, $staff_wp_user_id);

        $previous = $this->store->find_by_request_id($request_id);
        if ($previous !== null) {
            return $this->replay_or_conflict($previous, $fingerprint, $staff_wp_user_id);
        }

        $member = $this->store->find_member_by_public_id($member_public_id);
        if ($member === null || $member['status'] !== 'enabled') {
            return EVC_Redemption_Result::failure(EVC_Redemption_Result::MEMBER_NOT_FOUND);
        }

        $now = EVC_Clock::to_utc($this->clock->now());
        $now_sql = $now->format('Y-m-d H:i:s.u');

        $entitlement = $this->membership->entitlement_for($member['wp_user_id'], $now);
        $denial = $entitlement->denial_reason($now);
        if ($denial !== null) {
            $this->audit_best_effort(array(
                'event_type' => 'redemption',
                'outcome' => EVC_Redemption_Result::MEMBERSHIP_INACTIVE,
                'member_id' => $member['id'],
                'request_id' => $request_id,
                'staff_wp_user_id' => $staff_wp_user_id,
                'occurred_at_utc' => $now_sql,
                'details' => $this->audit_details(array('reason' => $denial, 'source' => $entitlement->source())),
            ));
            return EVC_Redemption_Result::failure(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, array(
                'status' => $entitlement->public_status($now),
                'reason' => $denial,
            ));
        }

        $business_date = EVC_Clock::business_date($now);
        $expires = $entitlement->expires_at_utc();
        $ledger_row = array(
            'member_id' => $member['id'],
            'benefit_type' => $benefit_type,
            'business_date' => $business_date,
            'coffee_code' => $coffee_code,
            'request_id' => $request_id,
            'request_fingerprint' => $fingerprint,
            'staff_wp_user_id' => $staff_wp_user_id,
            'membership_source' => $entitlement->source(),
            'membership_status' => $entitlement->status(),
            'membership_level_ref' => $entitlement->level_ref(),
            'membership_expires_at_utc' => $expires->format('Y-m-d H:i:s.u'),
            'redeemed_at_utc' => $now_sql,
        );

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->store->db()->transactional(function (EVC_Club_Db $db) use ($ledger_row) {
                    $redemption_id = $this->store->insert_redemption($db, $ledger_row);
                    $this->audit->record($db, array(
                        'event_type' => 'redemption',
                        'outcome' => EVC_Redemption_Result::REDEEMED,
                        'member_id' => $ledger_row['member_id'],
                        'redemption_id' => $redemption_id,
                        'request_id' => $ledger_row['request_id'],
                        'staff_wp_user_id' => $ledger_row['staff_wp_user_id'],
                        'occurred_at_utc' => $ledger_row['redeemed_at_utc'],
                        'details' => $this->audit_details(array(
                            'benefit_type' => $ledger_row['benefit_type'],
                            'coffee_code' => $ledger_row['coffee_code'],
                            'business_date' => $ledger_row['business_date'],
                        )),
                    ));
                    return $redemption_id;
                });
                return EVC_Redemption_Result::success(EVC_Redemption_Result::REDEEMED, array(
                    'member_public_id' => $member_public_id,
                    'benefit_type' => $benefit_type,
                    'coffee_code' => $coffee_code,
                    'business_date' => $business_date,
                    'redeemed_at_utc' => $now_sql,
                    'request_id' => $request_id,
                ));
            } catch (EVC_Db_Duplicate_Key_Exception $e) {
                // transactional() has already rolled back: the reads below are
                // fresh statements that see the committed winner.
                return $this->resolve_duplicate($request_id, $fingerprint, $member, $benefit_type, $business_date, $staff_wp_user_id, $now_sql);
            } catch (EVC_Db_Retryable_Exception $e) {
                if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function resolve_duplicate(
        string $request_id,
        string $fingerprint,
        array $member,
        string $benefit_type,
        string $business_date,
        int $staff_wp_user_id,
        string $now_sql
    ): EVC_Redemption_Result {
        $same_request = $this->store->find_by_request_id($request_id);
        if ($same_request !== null) {
            return $this->replay_or_conflict($same_request, $fingerprint, $staff_wp_user_id);
        }
        $winner = $this->store->find_for_day($member['id'], $benefit_type, $business_date);
        if ($winner !== null) {
            $this->audit_best_effort(array(
                'event_type' => 'redemption',
                'outcome' => EVC_Redemption_Result::ALREADY_REDEEMED,
                'member_id' => $member['id'],
                'request_id' => $request_id,
                'staff_wp_user_id' => $staff_wp_user_id,
                'occurred_at_utc' => $now_sql,
                'details' => $this->audit_details(array('business_date' => $business_date)),
            ));
            return EVC_Redemption_Result::failure(EVC_Redemption_Result::ALREADY_REDEEMED, array(
                'business_date' => $winner['business_date'],
                'redeemed_at_utc' => $winner['redeemed_at_utc'],
            ));
        }
        throw new RuntimeException('Duplicate key reported but no conflicting redemption is visible.');
    }

    /** Replays a stored success for an identical payload; never exposes another payload's data. */
    private function replay_or_conflict(array $stored, string $fingerprint, int $staff_wp_user_id): EVC_Redemption_Result {
        if (EVC_Request_Fingerprint::matches($stored['request_fingerprint'], $fingerprint)) {
            return EVC_Redemption_Result::success(EVC_Redemption_Result::REPLAYED, array(
                'member_public_id' => $stored['member_public_id'],
                'benefit_type' => $stored['benefit_type'],
                'coffee_code' => $stored['coffee_code'],
                'business_date' => $stored['business_date'],
                'redeemed_at_utc' => $stored['redeemed_at_utc'],
                'request_id' => $stored['request_id'],
            ));
        }
        $this->audit_best_effort(array(
            'event_type' => 'redemption',
            'outcome' => EVC_Redemption_Result::IDEMPOTENCY_CONFLICT,
            'request_id' => $stored['request_id'],
            'staff_wp_user_id' => $staff_wp_user_id,
            'occurred_at_utc' => EVC_Clock::to_utc($this->clock->now())->format('Y-m-d H:i:s.u'),
            'details' => $this->audit_details(array()),
        ));
        return EVC_Redemption_Result::failure(EVC_Redemption_Result::IDEMPOTENCY_CONFLICT);
    }

    /** Adds the optional session reference to audit details (never to the fingerprint). */
    private function audit_details(array $details): array {
        if ($this->session_ref !== null) {
            $details['session_ref'] = $this->session_ref;
        }
        return $details;
    }

    /**
     * Rejected attempts are audited outside any transaction. A failure to
     * audit a rejection is reported but never turns it into a success.
     */
    private function audit_best_effort(array $event): void {
        try {
            $this->audit->record($this->store->db(), $event);
        } catch (Throwable $e) {
            $this->report('audit_rejection', $e);
        }
    }

    /** Passes only the exception class and DB codes to the reporter: no messages. */
    private function report(string $stage, Throwable $e): void {
        if ($this->error_reporter === null) {
            return;
        }
        try {
            call_user_func($this->error_reporter, array(
                'stage' => $stage,
                'exception' => get_class($e),
                'sql_state' => $e instanceof EVC_Db_Exception ? $e->sql_state() : null,
                'driver_code' => $e instanceof EVC_Db_Exception ? $e->driver_code() : null,
            ));
        } catch (Throwable $ignored) {
            // A broken reporter must not change the redemption outcome.
        }
    }
}
