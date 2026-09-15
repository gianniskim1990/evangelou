<?php
/**
 * All business-date / ISO-8601 timestamp math funnels through here, using
 * the fixed Europe/Athens business timezone (EVC_BUSINESS_TIMEZONE) —
 * never the server's OS timezone, never UTC, never anything the client
 * sends. See docs/evangelou-club-api.md §11 (in the React repo).
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Timezone {

	private static ?\DateTimeZone $tz = null;

	private static function tz(): \DateTimeZone {
		if ( null === self::$tz ) {
			self::$tz = new \DateTimeZone( EVC_BUSINESS_TIMEZONE );
		}
		return self::$tz;
	}

	/** The current moment, in the Athens timezone. */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', self::tz() );
	}

	/**
	 * Today's business_date as YYYY-MM-DD, computed from the Athens wall
	 * clock — this is what "one coffee per day" actually counts against.
	 */
	public static function today(): string {
		return self::now()->format( 'Y-m-d' );
	}

	/**
	 * Formats any timestamp as ISO 8601 with the correct Athens UTC offset
	 * for that instant — +03:00 in summer (EEST), +02:00 in winter (EET).
	 * Never hardcode either offset; PHP's tz database handles the DST
	 * transition correctly as long as the datetime is converted through
	 * this timezone object rather than string-concatenated.
	 */
	public static function to_iso8601( \DateTimeInterface $dt ): string {
		$dt = ( $dt instanceof \DateTimeImmutable ) ? $dt : \DateTimeImmutable::createFromInterface( $dt );
		return $dt->setTimezone( self::tz() )->format( \DateTimeInterface::ATOM );
	}

	/**
	 * Parses a MySQL DATETIME string (assumed already in Athens local
	 * time, which is how this plugin writes redeemed_at/created_at) into
	 * an Athens-zoned DateTimeImmutable.
	 */
	public static function from_mysql_datetime( string $mysql_datetime ): ?\DateTimeImmutable {
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, self::tz() );
		return false !== $dt ? $dt : null;
	}

	/** Current moment formatted for a MySQL DATETIME column, Athens local time. */
	public static function now_for_mysql(): string {
		return self::now()->format( 'Y-m-d H:i:s' );
	}
}
