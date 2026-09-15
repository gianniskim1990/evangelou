<?php
/**
 * Everything identity-shaped that must never leak a raw WordPress ID or a
 * raw secret to the outside world: phone normalization/masking, opaque
 * member_id minting, and QR token hashing.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Identifiers {

	const MEMBER_ID_META_KEY    = 'evc_member_id';
	const QR_TOKEN_HASH_META_KEY = 'evc_qr_token_hash';

	// -----------------------------------------------------------------
	// Phone
	// -----------------------------------------------------------------

	/**
	 * Normalizes a Greek mobile number to its bare 10-digit canonical form
	 * ("69XXXXXXXX"). This is the SERVER-authoritative normalizer — the
	 * frontend's own normalizeGreekPhone() is UX-only and must never be
	 * trusted as validation.
	 *
	 * Accepts: "6912345678", "691 234 5678", "+30 6912345678",
	 * "0030 6912345678", and reasonable punctuation variants of those.
	 *
	 * @return string|null Canonical "69XXXXXXXX", or null if the input
	 *                      doesn't normalize to a plausible Greek mobile.
	 */
	public static function normalize_phone( string $raw ): ?string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( null === $digits ) {
			return null;
		}

		if ( 0 === strpos( $digits, '0030' ) ) {
			$digits = substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '30' ) && strlen( $digits ) > 10 ) {
			$digits = substr( $digits, 2 );
		}

		return self::is_valid_greek_mobile( $digits ) ? $digits : null;
	}

	/**
	 * Greek mobile numbers are 10 digits starting with "69" under the
	 * national numbering plan (all mobile operators share the 69x range).
	 */
	public static function is_valid_greek_mobile( string $canonical ): bool {
		return (bool) preg_match( '/^69\d{8}$/', $canonical );
	}

	/** "6900000001" -> "69••••••01" — the only phone shape the public API ever returns. */
	public static function mask_phone( string $canonical ): string {
		if ( strlen( $canonical ) <= 4 ) {
			return $canonical;
		}
		$start = substr( $canonical, 0, 2 );
		$end   = substr( $canonical, -2 );
		return $start . str_repeat( '•', strlen( $canonical ) - 4 ) . $end;
	}

	/** Same masking rule, safe to hand to Logger — shows nothing beyond what phone_masked already exposes. */
	public static function mask_phone_for_log( string $raw_or_canonical ): string {
		$normalized = self::normalize_phone( $raw_or_canonical );
		return $normalized ? self::mask_phone( $normalized ) : '(unparseable)';
	}

	// -----------------------------------------------------------------
	// Opaque member_id
	// -----------------------------------------------------------------

	/**
	 * Generates a new opaque member id. Deliberately NOT derived from the
	 * WordPress user ID (no arithmetic relationship an attacker could
	 * invert) — cryptographically random instead.
	 */
	public static function generate_member_id(): string {
		return 'mem_' . bin2hex( random_bytes( 12 ) );
	}

	/**
	 * Returns this user's opaque member id, minting one on first use.
	 *
	 * Concurrency note: two simultaneous first-lookups for the same user
	 * both attempt add_user_meta(..., $unique = true), which WordPress
	 * only accepts for the first writer; both callers then re-read the
	 * meta value rather than trusting the id they generated themselves,
	 * so they converge on the same stored id either way. This is the
	 * standard WordPress-native safeguard (usermeta has no true
	 * database-level UNIQUE constraint to lean on the way the
	 * redemptions table does) — good enough for "two tills look up the
	 * same brand-new member in the same instant," which is the realistic
	 * concurrency case here.
	 */
	public static function get_or_create_member_id( int $wp_user_id ): string {
		$existing = get_user_meta( $wp_user_id, self::MEMBER_ID_META_KEY, true );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}

		$candidate = self::generate_member_id();
		add_user_meta( $wp_user_id, self::MEMBER_ID_META_KEY, $candidate, true );

		// Re-read regardless of whether our add_user_meta call "won" —
		// see concurrency note above.
		$final = get_user_meta( $wp_user_id, self::MEMBER_ID_META_KEY, true );
		return is_string( $final ) && '' !== $final ? $final : $candidate;
	}

	/** Resolves an opaque member id back to a WordPress user id, or null. */
	public static function get_user_id_for_member_id( string $member_id ): ?int {
		$users = get_users(
			array(
				'meta_key'   => self::MEMBER_ID_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $member_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return ! empty( $users ) ? (int) $users[0] : null;
	}

	// -----------------------------------------------------------------
	// QR token
	// -----------------------------------------------------------------

	/** Only a hash is ever stored — the raw token is not retrievable after generation. */
	public static function hash_qr_token( string $raw_token ): string {
		return hash( 'sha256', $raw_token );
	}

	/**
	 * Generates a fresh high-entropy QR token for a user and stores only
	 * its hash. Returns the RAW token exactly once, for the (out-of-scope
	 * here) process that prints/delivers the physical or digital card —
	 * it cannot be recovered from the database afterward.
	 *
	 * Issuing a new token implicitly revokes any previous one, since only
	 * one hash is stored per user.
	 */
	public static function generate_qr_token_for_user( int $wp_user_id ): string {
		$raw = 'evc_qr_' . bin2hex( random_bytes( 20 ) );
		update_user_meta( $wp_user_id, self::QR_TOKEN_HASH_META_KEY, self::hash_qr_token( $raw ) );
		return $raw;
	}

	/** Resolves a raw scanned/submitted QR token to a WordPress user id, or null. */
	public static function resolve_user_from_qr_token( string $raw_token ): ?int {
		if ( '' === trim( $raw_token ) ) {
			return null;
		}

		$hash  = self::hash_qr_token( $raw_token );
		$users = get_users(
			array(
				'meta_key'   => self::QR_TOKEN_HASH_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return ! empty( $users ) ? (int) $users[0] : null;
	}

	/** Invalidates a user's current QR token (lost card, compromised token, etc). */
	public static function revoke_qr_token( int $wp_user_id ): void {
		delete_user_meta( $wp_user_id, self::QR_TOKEN_HASH_META_KEY );
	}

	/** A syntactically plausible QR token, before attempting to resolve it — cheap 400 vs a DB round-trip. */
	public static function looks_like_qr_token( string $token ): bool {
		return (bool) preg_match( '/^evc_qr_[a-f0-9]{16,64}$/', $token );
	}
}
