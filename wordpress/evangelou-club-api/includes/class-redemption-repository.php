<?php
/**
 * Owns the evc_redemptions table — the plugin's own data, never mixed
 * into FluentCRM or PMPro storage (see the API doc's "source-of-truth
 * mapping" section). This is the ONLY place that enforces "one benefit
 * per member per business day," and it does so with a database-level
 * UNIQUE constraint rather than a check-then-insert race.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Redemption_Repository {

	const DB_VERSION_OPTION = 'evc_db_version';

	/** Named so a duplicate-key MySQL error message can be matched back to which rule was violated. */
	const UNIQUE_KEY_MEMBER_BENEFIT_DATE = 'evc_member_benefit_date';
	const UNIQUE_KEY_REQUEST_ID          = 'evc_request_id';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'evc_redemptions';
	}

	/**
	 * Creates (or upgrades) the redemptions table via dbDelta, WordPress's
	 * normal activation-migration mechanism. Safe to call on every
	 * activation and version bump — dbDelta only applies the diff.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky about formatting: one column per line, exactly
		// two spaces after "PRIMARY KEY".
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_ref varchar(64) NOT NULL,
  benefit_type varchar(32) NOT NULL,
  business_date date NOT NULL,
  redeemed_at datetime NOT NULL,
  request_id varchar(64) NOT NULL,
  staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY " . self::UNIQUE_KEY_MEMBER_BENEFIT_DATE . " (member_ref,benefit_type,business_date),
  UNIQUE KEY " . self::UNIQUE_KEY_REQUEST_ID . " (request_id)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, EVC_DB_VERSION );
	}

	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** @return array<string, mixed>|null */
	public static function find_by_request_id( string $request_id ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed identifier, prepare() covers the value.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s", $request_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null The redemption already on record for this member+benefit+day, if any. */
	public static function find_today( string $member_ref, string $benefit_type, string $business_date ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed identifier, prepare() covers the values.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE member_ref = %s AND benefit_type = %s AND business_date = %s",
				$member_ref,
				$benefit_type,
				$business_date
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Attempts the redemption insert. Never does a prior SELECT-then-decide
	 * — it just tries the INSERT and lets the two UNIQUE constraints be
	 * authoritative, which is what makes this safe against two tills
	 * racing (see class doc comment).
	 *
	 * @return array{status: string, row: array<string, mixed>|null} status is one of:
	 *   'inserted'                      — this call created the row.
	 *   'duplicate_request_id'          — request_id already existed (idempotent replay).
	 *   'duplicate_member_benefit_date' — a DIFFERENT request already redeemed this member+benefit today.
	 *   'error'                         — unexpected DB failure, not a constraint hit.
	 */
	public static function insert_redemption(
		string $member_ref,
		string $benefit_type,
		string $business_date,
		string $redeemed_at_mysql,
		string $request_id,
		int $staff_user_id
	): array {
		global $wpdb;
		$table = self::table_name();

		// Never let a stray SQL warning leak HTML into what must stay a
		// clean JSON REST response — we inspect last_error ourselves below.
		$prev_suppress = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'member_ref'    => $member_ref,
				'benefit_type'  => $benefit_type,
				'business_date' => $business_date,
				'redeemed_at'   => $redeemed_at_mysql,
				'request_id'    => $request_id,
				'staff_user_id' => $staff_user_id,
				'created_at'    => Timezone::now_for_mysql(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$last_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $prev_suppress );

		if ( false !== $inserted ) {
			return array(
				'status' => 'inserted',
				'row'    => self::find_by_request_id( $request_id ),
			);
		}

		if ( false !== strpos( $last_error, self::UNIQUE_KEY_REQUEST_ID ) ) {
			return array(
				'status' => 'duplicate_request_id',
				'row'    => self::find_by_request_id( $request_id ),
			);
		}

		if ( false !== strpos( $last_error, self::UNIQUE_KEY_MEMBER_BENEFIT_DATE ) ) {
			return array(
				'status' => 'duplicate_member_benefit_date',
				'row'    => self::find_today( $member_ref, $benefit_type, $business_date ),
			);
		}

		Logger::error( 'Redemption insert failed unexpectedly.', array( 'db_error' => $last_error ) );
		return array(
			'status' => 'error',
			'row'    => null,
		);
	}
}
