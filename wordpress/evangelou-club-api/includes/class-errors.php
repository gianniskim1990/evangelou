<?php
/**
 * The one error envelope shape the whole API uses — see
 * docs/evangelou-club-api.md §8 in the React repo. Every REST handler
 * failure path returns through here, so a raw PHP/SQL/PMPro/FluentCRM
 * error can never reach the client.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Errors {

	/** Canonical HTTP status for each documented error code. */
	const STATUS_MAP = array(
		'invalid_request'          => 400,
		'invalid_phone'            => 400,
		'invalid_qr'                => 400,
		'member_not_found'         => 404,
		'membership_inactive'      => 409,
		'benefit_already_redeemed' => 409,
		'benefit_not_available'    => 409,
		'unauthorized'             => 401,
		'forbidden'                => 403,
		'rate_limited'             => 429,
		'server_error'             => 500,
	);

	/**
	 * @param array<string, mixed> $details Omitted from the response entirely when empty, matching the doc's examples.
	 */
	public static function response( string $code, string $message, array $details = array() ): \WP_REST_Response {
		$status = self::STATUS_MAP[ $code ] ?? 500;

		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( ! empty( $details ) ) {
			$error['details'] = $details;
		}

		return new \WP_REST_Response( array( 'error' => $error ), $status );
	}

	/** For anything unexpected — never echoes the real exception message to the client. */
	public static function server_error( string $context, \Throwable $e ): \WP_REST_Response {
		Logger::error( $context, array( 'exception' => $e->getMessage() ) );
		return self::response( 'server_error', 'Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά.' );
	}
}
