<?php
/**
 * Restrained, privacy-conscious logging.
 *
 * Never spams error_log(): only writes when explicitly enabled (WP_DEBUG,
 * or the dedicated EVC_DEBUG_LOG constant), and only accepts pre-masked
 * strings for anything phone-shaped — callers are responsible for masking
 * before calling this (see Identifiers::mask_phone()). Raw QR tokens must
 * never be passed here at all.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Logger {

	/**
	 * Whether logging is enabled at all. Defaults to WP_DEBUG so a normal
	 * production site stays quiet; a site owner can force it on/off with
	 * the EVC_DEBUG_LOG constant regardless of WP_DEBUG.
	 */
	private static function enabled(): bool {
		if ( defined( 'EVC_DEBUG_LOG' ) ) {
			return (bool) EVC_DEBUG_LOG;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * @param string               $message Human-readable, no PII beyond what's already masked.
	 * @param array<string, mixed> $context Small structured context — values are logged as-is, so
	 *                                       never put a raw phone/email/QR token in here directly.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::write( 'INFO', $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::write( 'WARNING', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::write( 'ERROR', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$line = sprintf( '[evangelou-club-api] [%s] %s', $level, $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated by self::enabled().
		error_log( $line );
	}
}
