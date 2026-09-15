<?php
/**
 * Translates Paid Memberships Pro's membership concepts into our stable,
 * documented 4-state contract (active/expired/cancelled/inactive). PMPro
 * internals never leak past this class — no raw PMPro level object is
 * ever handed back to a caller.
 *
 * Everything PMPro-specific lives in this one file by design, per the API
 * doc's "source-of-truth mapping": Paid Memberships Pro is authoritative
 * for eligibility, but the *shape* callers see is ours.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Membership_Adapter {

	/**
	 * @return array{status: string, started_at: ?\DateTimeImmutable, valid_until: ?\DateTimeImmutable, pmpro_level_id: ?int}
	 */
	public static function get_membership_info( int $wp_user_id ): array {
		if ( ! self::is_pmpro_active() ) {
			Logger::warning( 'Membership lookup attempted with PMPro unavailable.' );
			return self::empty_result( 'inactive' );
		}

		$live = self::get_live_club_level( $wp_user_id );
		if ( null !== $live ) {
			return array(
				'status'         => 'active',
				'started_at'     => $live['started_at'],
				'valid_until'    => $live['valid_until'],
				'pmpro_level_id' => $live['pmpro_level_id'],
			);
		}

		// No currently-active Club-level membership. Paid Memberships
		// Pro's live-lookup functions only ever describe the *current*
		// state, so to tell "expired" apart from "never was a member" we
		// consult PMPro's own membership history table. This is the one
		// place this plugin reads a PMPro table directly instead of a
		// public function, because PMPro doesn't expose a supported PHP
		// API for "most recent historical membership row for this user,"
		// and that distinction is genuinely useful cashier-facing
		// information (an expired member sees a specific date; someone
		// who was never a Club member doesn't).
		return self::get_historical_status( $wp_user_id );
	}

	public static function is_active( int $wp_user_id ): bool {
		return 'active' === self::get_membership_info( $wp_user_id )['status'];
	}

	// -----------------------------------------------------------------
	// Live check
	// -----------------------------------------------------------------

	/**
	 * @return array{started_at: ?\DateTimeImmutable, valid_until: ?\DateTimeImmutable, pmpro_level_id: int}|null
	 *   Null if the user has no CURRENTLY ACTIVE membership at one of the
	 *   configured Club levels (they may still have an active membership
	 *   at some *other*, non-Club PMPro level — that's still null here).
	 */
	private static function get_live_club_level( int $wp_user_id ): ?array {
		if ( ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
			return null;
		}

		// A user can hold more than one active level at once in PMPro;
		// check all of them against our configured Club levels rather
		// than assuming pmpro_getMembershipLevelForUser()'s single
		// "primary" level is the relevant one.
		$levels = pmpro_getMembershipLevelsForUser( $wp_user_id );
		if ( empty( $levels ) || ! is_array( $levels ) ) {
			return null;
		}

		foreach ( $levels as $level ) {
			$level_id = isset( $level->id ) ? (int) $level->id : 0;
			if ( $level_id > 0 && Settings::is_club_level( $level_id ) ) {
				return array(
					'pmpro_level_id' => $level_id,
					'started_at'     => self::parse_pmpro_date( $level->startdate ?? null ),
					// PMPro represents "no fixed end date" (an active
					// recurring membership) as an empty/zero enddate —
					// never invent a date here, this must stay null.
					'valid_until'    => self::parse_pmpro_date( $level->enddate ?? null ),
				);
			}
		}

		return null;
	}

	// -----------------------------------------------------------------
	// Historical fallback
	// -----------------------------------------------------------------

	/**
	 * @return array{status: string, started_at: ?\DateTimeImmutable, valid_until: ?\DateTimeImmutable, pmpro_level_id: ?int}
	 */
	private static function get_historical_status( int $wp_user_id ): array {
		global $wpdb;

		$club_levels = Settings::get_club_levels();
		if ( empty( $club_levels ) ) {
			// Nothing configured yet — we can't say this user ever held a
			// Club-specific level, only that PMPro exists.
			return self::empty_result( 'inactive' );
		}

		$table = $wpdb->prefix . 'pmpro_memberships_users';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- PMPro's own history table, no public API covers this query; see class doc comment.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return self::empty_result( 'inactive' );
		}

		$placeholders = implode( ',', array_fill( 0, count( $club_levels ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed identifier; $placeholders is a %d list, values are bound via prepare() below.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, startdate, enddate, membership_id FROM {$table}
				 WHERE user_id = %d AND membership_id IN ({$placeholders})
				 ORDER BY id DESC LIMIT 1",
				array_merge( array( $wp_user_id ), $club_levels )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return self::empty_result( 'inactive' );
		}

		return array(
			'status'         => self::map_pmpro_status( (string) $row['status'] ),
			'started_at'     => self::parse_pmpro_date( $row['startdate'] ?? null ),
			'valid_until'    => self::parse_pmpro_date( $row['enddate'] ?? null ),
			'pmpro_level_id' => isset( $row['membership_id'] ) ? (int) $row['membership_id'] : null,
		);
	}

	/**
	 * The one place PMPro's own (more granular) historical status values
	 * map to our documented 4-state contract. PMPro's
	 * pmpro_memberships_users.status column is known to use: active,
	 * expired, cancelled, admin_cancelled, changed, admin_changed,
	 * inactive — kept here, and only here, so the mapping can't drift
	 * between call sites.
	 */
	private static function map_pmpro_status( string $pmpro_status ): string {
		switch ( $pmpro_status ) {
			case 'active':
				// Reaching this branch means the live check above already
				// found nothing active — an 'active' history row here is
				// stale/inconsistent data. Trust the live check's "no",
				// but don't claim "expired" (we don't know an end date).
				return 'inactive';
			case 'expired':
				return 'expired';
			case 'cancelled':
			case 'admin_cancelled':
			case 'changed':
			case 'admin_changed':
				return 'cancelled';
			case 'inactive':
			default:
				return 'inactive';
		}
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * PMPro dates are typically "Y-m-d H:i:s" strings, empty, or the
	 * MySQL zero-date — all three "no date" spellings must become null,
	 * never an invented date.
	 */
	private static function parse_pmpro_date( $value ): ?\DateTimeImmutable {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value || '0000-00-00' === $value ) {
			return null;
		}

		if ( is_numeric( $value ) ) {
			$dt = ( new \DateTimeImmutable( '@' . $value ) )->setTimezone( new \DateTimeZone( EVC_BUSINESS_TIMEZONE ) );
			return $dt;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $value, new \DateTimeZone( EVC_BUSINESS_TIMEZONE ) );
		if ( false === $dt ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $value, new \DateTimeZone( EVC_BUSINESS_TIMEZONE ) );
		}
		return false !== $dt ? $dt : null;
	}

	private static function empty_result( string $status ): array {
		return array(
			'status'         => $status,
			'started_at'     => null,
			'valid_until'    => null,
			'pmpro_level_id' => null,
		);
	}

	public static function is_pmpro_active(): bool {
		return function_exists( 'pmpro_getMembershipLevelsForUser' );
	}
}
