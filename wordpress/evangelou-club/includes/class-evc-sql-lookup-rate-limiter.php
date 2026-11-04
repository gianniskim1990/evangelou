<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-05C: failed QR scan limit on the Club DB (migration 003 `evc_lookup_failures`).
 *
 * Owner decision D9 (2026-11-04): about 10 FAILED scans per 5 minutes, counted PER TABLET. In the
 * UI-04C lookup service each call carries the keys `actor:<staff user id>` (the ONE shared account,
 * i.e. every tablet together) and `session:<session_ref>` (one tablet's own WordPress session). Only
 * `session:` keys are counted and limited here, so one tablet's failures never block the others;
 * any other key is ignored. A call without a session key is refused (fail closed).
 *
 * - Fixed windows of WINDOW_SECONDS aligned to the epoch. allow() refuses while the current window
 *   of any session key already holds MAX_FAILURES or more failures (so at most about 10 per window;
 *   across a window boundary up to 2 × 10 - 1 in 5 minutes is possible, and scans of the SAME tablet
 *   running in parallel can each pass allow() before either records its failure, so a burst may exceed
 *   10 by the number of parallel scans: "about 10", as decided).
 * - A new WordPress login gives a new session ref (server-derived, EVC_Staff_Session::session_ref) and
 *   so a fresh count; how often that can happen is bounded only by the existing staff login throttle.
 *   Successful scans are not counted (the decision limits failures only); allow() consumes nothing.
 * - record_failure() is one atomic INSERT ... ON DUPLICATE KEY UPDATE per key (no read-then-write),
 *   then prunes at most PRUNE_LIMIT rows older than RETENTION_SECONDS.
 * - Stored key: SHA-256 of "evc-lookup-limit-v1|<action>|<key>" (the session_ref is already a keyed
 *   hash of the session token); nothing personal, no token.
 * - Any database error propagates; EVC_Member_Lookup_Service turns it into `unavailable` (fail closed).
 * Constructed only in EVC_Club_Qr_Services::lookup_service() (packager rule set qrProblemsFor).
 */
final class EVC_Sql_Lookup_Rate_Limiter implements EVC_Lookup_Rate_Limiter {
    const MAX_FAILURES = 10;
    const WINDOW_SECONDS = 300;
    const RETENTION_SECONDS = 86400;
    const PRUNE_LIMIT = 50;
    const COUNTED_PREFIX = 'session:';
    const DOMAIN = 'evc-lookup-limit-v1|';

    /** The least privilege the runtime Club DB user needs for this limiter. */
    const RUNTIME_GRANTS = array(
        'evc_lookup_failures' => 'SELECT, INSERT, UPDATE, DELETE',
    );

    /** @var EVC_Club_Db */
    private $db;
    /** @var EVC_Clock_Source */
    private $clock;

    public function __construct(EVC_Club_Db $db, EVC_Clock_Source $clock) {
        $this->db = $db;
        $this->clock = $clock;
    }

    public function allow(array $keys, string $action): bool {
        $counted = self::counted($keys, $action);
        if ($counted === array()) {
            return false;
        }
        $window = self::window_of($this->now());
        foreach ($counted as $key) {
            $row = $this->db->fetch_one(
                'SELECT failures FROM evc_lookup_failures WHERE limiter_key = ? AND window_start_utc = ?',
                array($key, $window)
            );
            if ($row !== null && (int) $row['failures'] >= self::MAX_FAILURES) {
                return false;
            }
        }
        return true;
    }

    public function record_failure(array $keys, string $action): void {
        $counted = self::counted($keys, $action);
        $at = $this->now(); // read the clock ONCE: window, timestamp and prune cutoff agree
        $window = self::window_of($at);
        $now = $at->format('Y-m-d H:i:s.u');
        foreach ($counted as $key) {
            $this->db->execute(
                'INSERT INTO evc_lookup_failures (limiter_key, window_start_utc, failures, updated_at_utc) VALUES (?, ?, 1, ?)'
                . ' ON DUPLICATE KEY UPDATE failures = failures + 1, updated_at_utc = ?',
                array($key, $window, $now, $now)
            );
        }
        $cutoff = $at->getTimestamp() - self::RETENTION_SECONDS;
        $this->db->execute(
            'DELETE FROM evc_lookup_failures WHERE window_start_utc < ? ORDER BY window_start_utc LIMIT ' . self::PRUNE_LIMIT,
            array(gmdate('Y-m-d H:i:s', $cutoff) . '.000000')
        );
    }

    /** Stored keys for the counted (`session:`) keys of this action; never the raw key. */
    private static function counted(array $keys, string $action): array {
        $out = array();
        foreach ($keys as $key) {
            if (is_string($key) && strpos($key, self::COUNTED_PREFIX) === 0 && strlen($key) > strlen(self::COUNTED_PREFIX)) {
                $out[] = hash('sha256', self::DOMAIN . $action . '|' . $key);
            }
        }
        return array_values(array_unique($out));
    }

    private function now(): DateTimeImmutable {
        return EVC_Clock::to_utc($this->clock->now());
    }

    private static function window_of(DateTimeImmutable $at): string {
        $t = $at->getTimestamp();
        return gmdate('Y-m-d H:i:s', $t - ($t % self::WINDOW_SECONDS)) . '.000000';
    }
}
