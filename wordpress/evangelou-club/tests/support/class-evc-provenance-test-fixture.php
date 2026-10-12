<?php
/**
 * TEST-ONLY synthetic provenance facts and store/recorder builders shared by
 * the PHPUnit suites and tests/bin/provenance-worker.php (Task 1D-G). Never
 * shipped (the packager rejects this class). Synthetic members and opaque
 * references only.
 */
final class EVC_Provenance_Test_Fixture {
    const USER = 42;

    public static function ref(string $label): string {
        return EVC_Pmpro_Fixture::ref($label);
    }

    /**
     * Canonical id mv:<label>, order anchor wc_order:order:<label>, Viva alias
     * viva:<label>, EUR 20.00, live, Club level 7, PMPro row row1.
     *
     * @param array<string,mixed> $o overrides: id, env, user, level, row, amount, currency, aliases, authority, kind, state
     */
    public static function movement(string $label, string $at_athens, array $o = array()): EVC_Verified_Movement {
        return new EVC_Verified_Movement(
            $o['id'] ?? self::ref('mv:' . $label),
            $o['env'] ?? 'live',
            $o['user'] ?? self::USER,
            $o['level'] ?? EVC_Pmpro_Fixture::CLUB_LEVEL,
            $o['row'] ?? self::ref('row1'),
            EVC_Pmpro_Fixture::athens($at_athens),
            $o['amount'] ?? 2000,
            $o['currency'] ?? 'EUR',
            $o['aliases'] ?? array(
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:' . $label)),
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:' . $label)),
            ),
            $o['authority'] ?? EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            $o['kind'] ?? EVC_Payment_Evidence::KIND_WC_ORDER,
            $o['state'] ?? EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    public static function correction(string $label, string $movement_label, string $kind, string $at_athens = '2026-10-20 10:00:00'): EVC_Movement_Correction {
        return new EVC_Movement_Correction(self::ref('corr:' . $label), self::ref('mv:' . $movement_label), $kind, EVC_Verified_Movement::AUTHORITY_GATEWAY_API, EVC_Pmpro_Fixture::athens($at_athens));
    }

    public static function recorder(EVC_Provenance_Store $store): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder($store, EVC_Pmpro_Fixture::config(), 'live', 2000, 'EUR');
    }

    /** A SQL store on its own connection to the given disposable database. */
    public static function sql_store(EVC_Db_Config $config, int $lock_wait_seconds = 5, ?EVC_Club_Db $db = null, ?callable $reconnect = null): EVC_Sql_Provenance_Store {
        $reconnect = $reconnect ?? function () use ($config) {
            return EVC_Club_Db::connect($config);
        };
        return new EVC_Sql_Provenance_Store($db ?? EVC_Club_Db::connect($config), $reconnect, 'live', $lock_wait_seconds);
    }

    /** Builds a write with the period the recorder would compute (for direct store tests). */
    public static function new_movement_write(EVC_Verified_Movement $m, ?EVC_Funded_Period $latest, array $expected_unresolved = array()): EVC_Provenance_Write {
        $next = EVC_Membership_Calendar::next_period($latest === null ? null : $latest->end_utc(), $m->verified_at_utc());
        $period = new EVC_Funded_Period(
            $m->movement_id(),
            $m->wp_user_id(),
            $m->level_id(),
            $m->pmpro_row_ref(),
            $next['start'],
            $next['end'],
            $latest === null ? EVC_Funded_Period::KIND_INITIAL : $next['renewal'],
            $latest === null ? null : $latest->movement_id()
        );
        return EVC_Provenance_Write::new_movement($m, $period, $expected_unresolved);
    }
}
