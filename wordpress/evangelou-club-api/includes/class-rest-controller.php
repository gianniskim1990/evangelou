<?php
/**
 * Implements the documented evangelou-club/v1 REST routes. Every response
 * — success or failure — goes through Errors::response()/WP_REST_Response
 * so the wire shape always matches docs/evangelou-club-api.md exactly.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	public function register_routes(): void {
		register_rest_route(
			EVC_REST_NAMESPACE,
			'/members/lookup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_lookup' ),
				// Authorization is enforced as the FIRST step inside the
				// handler (see check_auth()) rather than via this slot, so
				// every failure — including "not logged in" — returns our
				// documented {"error":{...}} envelope with the right HTTP
				// status, instead of WordPress's default WP_Error shape
				// for a permission_callback rejection. The endpoint is
				// still fully gated; only WHERE the check happens differs.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			EVC_REST_NAMESPACE,
			'/members/(?P<member_id>[a-zA-Z0-9_\-]+)/benefits/(?P<benefit_type>[a-z_]+)/redeem',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_redeem' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'member_id'    => array( 'required' => true ),
					'benefit_type' => array( 'required' => true ),
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// POST /members/lookup
	// -----------------------------------------------------------------

	public function handle_lookup( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$auth_error = $this->check_auth();
			if ( null !== $auth_error ) {
				return $auth_error;
			}

			$rate_limit_error = $this->check_rate_limit( 'lookup', 60, MINUTE_IN_SECONDS );
			if ( null !== $rate_limit_error ) {
				return $rate_limit_error;
			}

			$body = $request->get_json_params();
			if ( ! is_array( $body ) ) {
				return Errors::response( 'invalid_request', 'Μη έγκυρο αίτημα.' );
			}

			$has_phone = isset( $body['method'], $body['phone'] ) && 'phone' === $body['method'];
			$has_qr    = isset( $body['method'], $body['qr_token'] ) && 'qr' === $body['method'];

			// Exactly one method, never both, never neither.
			if ( $has_phone === $has_qr ) {
				return Errors::response( 'invalid_request', 'Το αίτημα πρέπει να περιέχει είτε phone είτε qr_token.' );
			}

			if ( $has_phone ) {
				$canonical = Identifiers::normalize_phone( (string) $body['phone'] );
				if ( null === $canonical ) {
					return Errors::response( 'invalid_phone', 'Μη έγκυρος αριθμός τηλεφώνου.' );
				}
				$result = Member_Resolver::resolve_by_phone( $canonical );
			} else {
				$token = (string) $body['qr_token'];
				if ( ! Identifiers::looks_like_qr_token( $token ) ) {
					return Errors::response( 'invalid_qr', 'Μη έγκυρο QR token.' );
				}
				$result = Member_Resolver::resolve_by_qr_token( $token );
			}

			if ( Member_Resolver::STATUS_FOUND !== $result['status'] ) {
				// "not_found" and "ambiguous" both present as the same
				// generic, cashier-safe outcome publicly — an ambiguous
				// match is a data-integrity problem for an administrator
				// to investigate (Member_Resolver already logged it), not
				// something the till should be able to distinguish.
				return Errors::response( 'member_not_found', 'Δεν βρέθηκε μέλος.' );
			}

			return $this->build_lookup_response( $result['member'] );
		} catch ( \Throwable $e ) {
			return Errors::server_error( 'handle_lookup failed', $e );
		}
	}

	private function build_lookup_response( Resolved_Member $member ): \WP_REST_Response {
		$member_id  = Identifiers::get_or_create_member_id( $member->wp_user_id );
		$membership = Membership_Adapter::get_membership_info( $member->wp_user_id );
		$benefit    = $this->benefit_status_for( $member_id, $membership['status'] );

		return new \WP_REST_Response(
			array(
				'member'     => array(
					'member_id'    => $member_id,
					'display_name' => $member->display_name,
					'phone_masked' => $member->canonical_phone ? Identifiers::mask_phone( $member->canonical_phone ) : '',
				),
				'membership' => array(
					'status'      => $membership['status'],
					'started_at'  => $membership['started_at'] ? Timezone::to_iso8601( $membership['started_at'] ) : null,
					'valid_until' => $membership['valid_until'] ? Timezone::to_iso8601( $membership['valid_until'] ) : null,
				),
				'benefits'   => array(
					'free_coffee' => $benefit,
				),
			),
			200
		);
	}

	/** @return array<string, mixed> Shape matches docs §7 exactly — state/business_date/redeemed_at always present. */
	private function benefit_status_for( string $member_id, string $membership_status ): array {
		$business_date = Timezone::today();

		if ( 'active' !== $membership_status ) {
			return array(
				'state'         => 'unavailable',
				'business_date' => $business_date,
				'redeemed_at'   => null,
				'reason'        => 'membership_inactive',
			);
		}

		$existing = Redemption_Repository::find_today( $member_id, 'free_coffee', $business_date );
		if ( null !== $existing ) {
			return array(
				'state'         => 'used',
				'business_date' => $business_date,
				'redeemed_at'   => $this->format_redeemed_at( $existing['redeemed_at'] ),
			);
		}

		return array(
			'state'         => 'available',
			'business_date' => $business_date,
			'redeemed_at'   => null,
		);
	}

	// -----------------------------------------------------------------
	// POST /members/{member_id}/benefits/{benefit_type}/redeem
	// -----------------------------------------------------------------

	public function handle_redeem( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$auth_error = $this->check_auth();
			if ( null !== $auth_error ) {
				return $auth_error;
			}

			$rate_limit_error = $this->check_rate_limit( 'redeem', 20, MINUTE_IN_SECONDS );
			if ( null !== $rate_limit_error ) {
				return $rate_limit_error;
			}

			$member_id    = (string) $request->get_param( 'member_id' );
			$benefit_type = (string) $request->get_param( 'benefit_type' );

			if ( 'free_coffee' !== $benefit_type ) {
				return Errors::response( 'invalid_request', 'Μη υποστηριζόμενος τύπος παροχής.' );
			}

			$body       = $request->get_json_params();
			$request_id = ( is_array( $body ) && isset( $body['request_id'] ) ) ? (string) $body['request_id'] : '';
			if ( '' === $request_id || ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $request_id ) ) {
				return Errors::response( 'invalid_request', 'Λείπει ή μη έγκυρο request_id.' );
			}

			// 1. Idempotency check FIRST. A pure retry of an
			// already-succeeded request returns the original result
			// as-is — it must not re-validate membership, which could
			// have changed since, because the redemption already
			// legitimately happened.
			$existing = Redemption_Repository::find_by_request_id( $request_id );
			if ( null !== $existing ) {
				if ( $existing['member_ref'] === $member_id && $existing['benefit_type'] === $benefit_type ) {
					return $this->redeem_success_response( $existing );
				}
				// Same request_id reused for a different logical operation —
				// not a safe retry, refuse rather than guess.
				return Errors::response( 'invalid_request', 'Το request_id έχει ήδη χρησιμοποιηθεί για άλλη ενέργεια.' );
			}

			// 2. Resolve member_id -> WordPress user. Never trust an
			// earlier lookup response for this.
			$wp_user_id = Identifiers::get_user_id_for_member_id( $member_id );
			if ( null === $wp_user_id ) {
				return Errors::response( 'member_not_found', 'Δεν βρέθηκε μέλος.' );
			}

			// 3 & 4. Re-check PMPro membership LIVE, and that it's a
			// configured Club level — regardless of what a lookup moments
			// ago said, and regardless of whether the UI's button was
			// rendered enabled.
			$membership = Membership_Adapter::get_membership_info( $wp_user_id );
			if ( 'active' !== $membership['status'] ) {
				return Errors::response(
					'membership_inactive',
					'Η συνδρομή δεν είναι ενεργή.',
					array( 'status' => $membership['status'] )
				);
			}

			// 5. Athens business date/time.
			$business_date = Timezone::today();
			$redeemed_at   = Timezone::now_for_mysql();

			// 6. Attempt the insert. The database's UNIQUE constraints —
			// not this PHP code — are what actually decide a race between
			// two tills; see Redemption_Repository::insert_redemption().
			$result = Redemption_Repository::insert_redemption(
				$member_id,
				$benefit_type,
				$business_date,
				$redeemed_at,
				$request_id,
				get_current_user_id()
			);

			switch ( $result['status'] ) {
				case 'inserted':
				case 'duplicate_request_id':
					return $this->redeem_success_response( $result['row'] );

				case 'duplicate_member_benefit_date':
					$winning = $result['row'];
					return Errors::response(
						'benefit_already_redeemed',
						'Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί.',
						array(
							'business_date' => $winning['business_date'] ?? $business_date,
							'redeemed_at'   => $winning ? $this->format_redeemed_at( $winning['redeemed_at'] ) : null,
						)
					);

				default:
					return Errors::response( 'server_error', 'Η καταχώρηση απέτυχε. Δοκιμάστε ξανά.' );
			}
		} catch ( \Throwable $e ) {
			return Errors::server_error( 'handle_redeem failed', $e );
		}
	}

	/** @param array<string, mixed> $row */
	private function redeem_success_response( array $row ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'member_id'     => $row['member_ref'],
				'benefit_type'  => $row['benefit_type'],
				'state'         => 'used',
				'business_date' => $row['business_date'],
				'redeemed_at'   => $this->format_redeemed_at( $row['redeemed_at'] ),
			),
			200
		);
	}

	private function format_redeemed_at( string $mysql_datetime ): ?string {
		$dt = Timezone::from_mysql_datetime( $mysql_datetime );
		return $dt ? Timezone::to_iso8601( $dt ) : null;
	}

	// -----------------------------------------------------------------
	// Auth + rate limiting
	// -----------------------------------------------------------------

	private function check_auth(): ?\WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return Errors::response( 'unauthorized', 'Απαιτείται σύνδεση προσωπικού.' );
		}
		if ( ! current_user_can( 'use_evangelou_club' ) ) {
			return Errors::response( 'forbidden', 'Δεν έχετε δικαίωμα πρόσβασης.' );
		}
		return null;
	}

	/** Simple per-user transient counter — brute-force phone/QR guessing protection, not a full rate limiter. */
	private function check_rate_limit( string $bucket, int $max_attempts, int $window_seconds ): ?\WP_REST_Response {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null; // already rejected by check_auth(); defensive only.
		}

		$key   = 'evc_rl_' . $bucket . '_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= $max_attempts ) {
			return Errors::response( 'rate_limited', 'Πολλές προσπάθειες. Δοκιμάστε ξανά σε λίγο.' );
		}

		set_transient( $key, $count + 1, $window_seconds );
		return null;
	}
}
