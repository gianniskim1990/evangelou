<?php
/**
 * Plugin settings storage — currently just "which PMPro level(s) count as
 * Evangelou Club membership." No level ID is ever hardcoded anywhere else
 * in this plugin; everything reads through here.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_KEY = 'evc_settings';

	/** @return array{club_levels: int[]} */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'club_levels' => isset( $stored['club_levels'] ) && is_array( $stored['club_levels'] )
				? array_values( array_unique( array_map( 'intval', $stored['club_levels'] ) ) )
				: array(),
		);
	}

	/** @return int[] The configured Paid Memberships Pro level IDs that count as Club membership. */
	public static function get_club_levels(): array {
		return self::get_settings()['club_levels'];
	}

	public static function is_club_level( int $pmpro_level_id ): bool {
		return in_array( $pmpro_level_id, self::get_club_levels(), true );
	}

	public static function has_club_levels_configured(): bool {
		return ! empty( self::get_club_levels() );
	}

	/**
	 * @param int[] $level_ids Raw POSTed values — sanitized to a de-duplicated list of positive ints.
	 */
	public static function update_club_levels( array $level_ids ): void {
		$clean = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $level_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		update_option( self::OPTION_KEY, array( 'club_levels' => $clean ) );
	}
}
