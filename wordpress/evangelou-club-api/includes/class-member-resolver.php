<?php
/**
 * Resolves "a phone number" or "a QR token" down to a WordPress user,
 * without the rest of the plugin ever needing to know whether the match
 * came from FluentCRM or a WooCommerce billing fallback. See the API
 * doc's §9 "source-of-truth mapping" — this class IS that boundary.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

/** Plain data holder — deliberately not a REST DTO; class-rest-controller.php shapes the public response. */
class Resolved_Member {
	public int $wp_user_id;
	public string $display_name;
	public string $canonical_phone;
	/** Which system the match came from — 'fluentcrm' | 'woocommerce' | 'qr'. Diagnostics-only; never returned by the public API. */
	public string $source;

	public function __construct( int $wp_user_id, string $display_name, string $canonical_phone, string $source ) {
		$this->wp_user_id      = $wp_user_id;
		$this->display_name    = $display_name;
		$this->canonical_phone = $canonical_phone;
		$this->source          = $source;
	}
}

class Member_Resolver {

	const STATUS_FOUND     = 'found';
	const STATUS_NOT_FOUND = 'not_found';
	const STATUS_AMBIGUOUS = 'ambiguous';

	/**
	 * @return array{status: string, member: ?Resolved_Member, source: ?string, candidate_count: int}
	 */
	public static function resolve_by_phone( string $canonical_phone ): array {
		$user_ids = self::find_fluentcrm_candidate_user_ids( $canonical_phone );
		$source   = 'fluentcrm';

		if ( empty( $user_ids ) ) {
			$user_ids = self::find_woocommerce_candidate_user_ids( $canonical_phone );
			$source   = 'woocommerce';
		}

		return self::decide( $user_ids, $canonical_phone, $source );
	}

	/** @return array{status: string, member: ?Resolved_Member, source: ?string, candidate_count: int} */
	public static function resolve_by_qr_token( string $raw_token ): array {
		$wp_user_id = Identifiers::resolve_user_from_qr_token( $raw_token );
		if ( null === $wp_user_id ) {
			return self::not_found_result();
		}
		return self::found_result( $wp_user_id, 'qr' );
	}

	// -----------------------------------------------------------------
	// Decision
	// -----------------------------------------------------------------

	private static function decide( array $user_ids, string $canonical_phone, string $source ): array {
		$distinct = array_values( array_unique( $user_ids ) );

		if ( empty( $distinct ) ) {
			return self::not_found_result();
		}

		if ( count( $distinct ) > 1 ) {
			// Ambiguity rule: never silently pick one. Log just enough for
			// an administrator to investigate — masked phone, a count —
			// never the full number or which specific accounts collided.
			Logger::warning(
				'Ambiguous phone lookup: multiple WordPress users share the same canonical phone number.',
				array(
					'phone_masked'    => Identifiers::mask_phone( $canonical_phone ),
					'candidate_count' => count( $distinct ),
					'source'          => $source,
				)
			);
			return array(
				'status'          => self::STATUS_AMBIGUOUS,
				'member'          => null,
				'source'          => $source,
				'candidate_count' => count( $distinct ),
			);
		}

		return self::found_result( $distinct[0], $source );
	}

	private static function found_result( int $wp_user_id, string $source ): array {
		$member = new Resolved_Member(
			$wp_user_id,
			self::display_name_for_user( $wp_user_id ),
			self::get_phone_for_user( $wp_user_id ) ?? '',
			$source
		);

		return array(
			'status'          => self::STATUS_FOUND,
			'member'          => $member,
			'source'          => $source,
			'candidate_count' => 1,
		);
	}

	private static function not_found_result(): array {
		return array(
			'status'          => self::STATUS_NOT_FOUND,
			'member'          => null,
			'source'          => null,
			'candidate_count' => 0,
		);
	}

	// -----------------------------------------------------------------
	// FluentCRM candidates
	// -----------------------------------------------------------------

	/** @return int[] Distinct WordPress user ids of FluentCRM contacts whose (normalized) phone matches. */
	private static function find_fluentcrm_candidate_user_ids( string $canonical_phone ): array {
		if ( ! Plugin::is_fluentcrm_active() ) {
			return array();
		}

		// FluentCRM's officially documented PHP API (FluentCrmApi('contacts'))
		// covers lookups by user id, not by phone, so a phone search has to
		// go through the underlying Subscriber model directly — this is the
		// one integration point that most needs re-verification against the
		// real FluentCRM version installed on Evangelou's site (see README
		// "Requires live verification").
		$model_class = '\\FluentCrm\\App\\Models\\Subscriber';
		if ( ! class_exists( $model_class ) ) {
			return array();
		}

		try {
			// Narrow with a LIKE on the last 8 digits first (cheap, avoids
			// scanning every contact), then normalize + exact-compare every
			// candidate in PHP — historical phone values are not guaranteed
			// to already be stored in canonical "69XXXXXXXX" form.
			$suffix = substr( $canonical_phone, -8 );
			$rows   = $model_class::where( 'phone', 'LIKE', '%' . $suffix )->limit( 25 )->get();
		} catch ( \Throwable $e ) {
			Logger::warning( 'FluentCRM phone lookup failed — treating as no match.', array( 'exception' => $e->getMessage() ) );
			return array();
		}

		$user_ids = array();
		foreach ( $rows as $row ) {
			$phone = is_object( $row ) ? ( $row->phone ?? '' ) : '';
			if ( Identifiers::normalize_phone( (string) $phone ) !== $canonical_phone ) {
				continue;
			}

			$user_id = is_object( $row ) ? ( $row->user_id ?? null ) : null;
			// A FluentCRM contact with no linked WordPress user can never
			// hold a PMPro membership, so it can never be a Club member —
			// skip rather than treat as a dead-end match.
			if ( empty( $user_id ) ) {
				continue;
			}

			$user_ids[] = (int) $user_id;
		}

		return array_values( array_unique( $user_ids ) );
	}

	// -----------------------------------------------------------------
	// WooCommerce fallback
	// -----------------------------------------------------------------

	/** @return int[] Distinct WordPress user ids whose WooCommerce billing_phone (normalized) matches. */
	private static function find_woocommerce_candidate_user_ids( string $canonical_phone ): array {
		if ( ! Plugin::is_woocommerce_active() ) {
			return array();
		}

		$suffix    = substr( $canonical_phone, -8 );
		$candidate_ids = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'billing_phone',
						'value'   => $suffix,
						'compare' => 'LIKE',
					),
				),
				'fields'     => 'ID',
				'number'     => 25,
			)
		);

		$user_ids = array();
		foreach ( $candidate_ids as $uid ) {
			$raw = get_user_meta( (int) $uid, 'billing_phone', true );
			if ( Identifiers::normalize_phone( (string) $raw ) === $canonical_phone ) {
				$user_ids[] = (int) $uid;
			}
		}

		return array_values( array_unique( $user_ids ) );
	}

	// -----------------------------------------------------------------
	// Shared lookups
	// -----------------------------------------------------------------

	/** Best-effort phone for display purposes (masking) — tries FluentCRM first, then WooCommerce billing_phone. */
	private static function get_phone_for_user( int $wp_user_id ): ?string {
		if ( Plugin::is_fluentcrm_active() && class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
			try {
				$model_class = '\\FluentCrm\\App\\Models\\Subscriber';
				$contact     = $model_class::where( 'user_id', $wp_user_id )->first();
				if ( $contact && ! empty( $contact->phone ) ) {
					$normalized = Identifiers::normalize_phone( (string) $contact->phone );
					if ( null !== $normalized ) {
						return $normalized;
					}
				}
			} catch ( \Throwable $e ) {
				Logger::warning( 'FluentCRM phone fetch failed.', array( 'exception' => $e->getMessage() ) );
			}
		}

		$billing = get_user_meta( $wp_user_id, 'billing_phone', true );
		if ( ! empty( $billing ) ) {
			return Identifiers::normalize_phone( (string) $billing );
		}

		return null;
	}

	private static function display_name_for_user( int $wp_user_id ): string {
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return 'Μέλος';
		}

		$billing_first = get_user_meta( $wp_user_id, 'billing_first_name', true );
		$billing_last  = get_user_meta( $wp_user_id, 'billing_last_name', true );
		$billing_name  = trim( (string) $billing_first . ' ' . (string) $billing_last );

		if ( '' !== $billing_name ) {
			return $billing_name;
		}

		return $user->display_name ?: $user->user_login;
	}
}
