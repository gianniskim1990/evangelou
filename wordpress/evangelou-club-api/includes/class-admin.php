<?php
/**
 * Settings → Evangelou Club: configure which PMPro level(s) count as Club
 * membership, see dependency/diagnostic status, and run an admin-only
 * member lookup test. No level ID is hardcoded anywhere — this screen is
 * the only place they're chosen, and only from what PMPro itself reports.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGE_SLUG       = 'evangelou-club';
	const SETTINGS_ACTION = 'evc_settings_save';
	const LOOKUP_ACTION   = 'evc_lookup_test';

	public function register_menu(): void {
		add_options_page(
			__( 'Evangelou Club', 'evangelou-club-api' ),
			__( 'Evangelou Club', 'evangelou-club-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'evangelou-club-api' ) );
		}

		$this->maybe_handle_settings_save();
		$lookup_result = $this->maybe_handle_lookup_test();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Evangelou Club', 'evangelou-club-api' ) . '</h1>';

		$this->render_settings_form();
		$this->render_diagnostics();
		$this->render_lookup_tool( $lookup_result );

		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Club level settings
	// -----------------------------------------------------------------

	private function maybe_handle_settings_save(): void {
		if ( ! isset( $_POST['evc_settings_submit'] ) ) {
			return;
		}
		check_admin_referer( self::SETTINGS_ACTION );

		$levels = array();
		if ( isset( $_POST['evc_club_levels'] ) && is_array( $_POST['evc_club_levels'] ) ) {
			$levels = array_map( 'absint', wp_unslash( $_POST['evc_club_levels'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}
		Settings::update_club_levels( $levels );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'evangelou-club-api' ) . '</p></div>';
	}

	private function render_settings_form(): void {
		echo '<h2>' . esc_html__( 'Επίπεδο συνδρομής Evangelou Club', 'evangelou-club-api' ) . '</h2>';

		if ( ! Plugin::is_pmpro_active() ) {
			echo '<p>' . esc_html__( 'Το Paid Memberships Pro δεν εντοπίστηκε — δεν είναι δυνατή η επιλογή επιπέδου συνδρομής μέχρι να ενεργοποιηθεί.', 'evangelou-club-api' ) . '</p>';
			return;
		}

		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			echo '<p>' . esc_html__( 'Η συνάρτηση pmpro_getAllLevels() δεν είναι διαθέσιμη σε αυτή την έκδοση του Paid Memberships Pro.', 'evangelou-club-api' ) . '</p>';
			return;
		}

		$levels = pmpro_getAllLevels( true, true );
		if ( empty( $levels ) ) {
			echo '<p>' . esc_html__( 'Δεν βρέθηκαν επίπεδα συνδρομής στο Paid Memberships Pro.', 'evangelou-club-api' ) . '</p>';
			return;
		}

		$selected = Settings::get_club_levels();

		echo '<form method="post">';
		wp_nonce_field( self::SETTINGS_ACTION );
		echo '<p>' . esc_html__( 'Επίλεξε ποιο/ποια επίπεδα συνδρομής του Paid Memberships Pro θεωρούνται συνδρομή Evangelou Club (μπορείς να επιλέξεις παραπάνω από ένα, κρατώντας Ctrl/Cmd).', 'evangelou-club-api' ) . '</p>';
		echo '<select name="evc_club_levels[]" multiple size="6" style="min-width:320px">';
		foreach ( $levels as $level ) {
			$id = isset( $level->id ) ? (int) $level->id : 0;
			if ( $id <= 0 ) {
				continue;
			}
			printf(
				'<option value="%d"%s>%s</option>',
				$id,
				in_array( $id, $selected, true ) ? ' selected="selected"' : '',
				esc_html( isset( $level->name ) ? (string) $level->name : ( '#' . $id ) )
			);
		}
		echo '</select>';
		echo '<p><button type="submit" name="evc_settings_submit" value="1" class="button button-primary">' . esc_html__( 'Αποθήκευση', 'evangelou-club-api' ) . '</button></p>';
		echo '</form>';
	}

	// -----------------------------------------------------------------
	// Diagnostics
	// -----------------------------------------------------------------

	private function render_diagnostics(): void {
		$d        = Plugin::diagnostics();
		$levels   = Settings::get_club_levels();
		$names    = $this->level_names( $levels );

		echo '<h2>' . esc_html__( 'Διαγνωστικά', 'evangelou-club-api' ) . '</h2>';
		echo '<table class="widefat" style="max-width:640px"><tbody>';

		$this->diagnostic_row( 'Paid Memberships Pro', $d['pmpro'], __( 'Εντοπίστηκε', 'evangelou-club-api' ), __( 'Δεν εντοπίστηκε', 'evangelou-club-api' ) );
		$this->diagnostic_row( 'FluentCRM', $d['fluentcrm'], __( 'Εντοπίστηκε', 'evangelou-club-api' ), __( 'Δεν εντοπίστηκε (προαιρετικό)', 'evangelou-club-api' ) );
		$this->diagnostic_row( 'WooCommerce', $d['woocommerce'], __( 'Εντοπίστηκε', 'evangelou-club-api' ), __( 'Δεν εντοπίστηκε (προαιρετικό)', 'evangelou-club-api' ) );
		$this->diagnostic_row( __( 'Πίνακας καταχωρήσεων', 'evangelou-club-api' ), $d['table'], __( 'Έτοιμος', 'evangelou-club-api' ), __( 'Δεν βρέθηκε', 'evangelou-club-api' ) );
		$this->diagnostic_row( __( 'Επίπεδο συνδρομής Club', 'evangelou-club-api' ), $d['levels_configured'], __( 'Ρυθμισμένο', 'evangelou-club-api' ), __( 'Δεν έχει ρυθμιστεί', 'evangelou-club-api' ) );
		$this->diagnostic_row( 'REST API', true, __( 'Έτοιμο', 'evangelou-club-api' ), '' );

		echo '</tbody></table>';

		echo '<table class="widefat" style="max-width:640px;margin-top:12px"><tbody>';
		$this->info_row( __( 'Έκδοση plugin', 'evangelou-club-api' ), EVC_VERSION );
		$this->info_row( __( 'REST namespace', 'evangelou-club-api' ), EVC_REST_NAMESPACE );
		$this->info_row( __( 'Ρυθμισμένα επίπεδα Club', 'evangelou-club-api' ), empty( $names ) ? '—' : implode( ', ', $names ) );
		$this->info_row( __( 'Ζώνη ώρας επιχείρησης', 'evangelou-club-api' ), EVC_BUSINESS_TIMEZONE );
		echo '</tbody></table>';
	}

	/** @return string[] */
	private function level_names( array $level_ids ): array {
		if ( empty( $level_ids ) || ! function_exists( 'pmpro_getLevel' ) ) {
			return array_map(
				static function ( $id ) {
					return '#' . $id;
				},
				$level_ids
			);
		}

		$names = array();
		foreach ( $level_ids as $id ) {
			$level   = pmpro_getLevel( $id );
			$names[] = ( $level && isset( $level->name ) ) ? (string) $level->name : ( '#' . $id );
		}
		return $names;
	}

	private function diagnostic_row( string $label, bool $ok, string $ok_text, string $warn_text ): void {
		$icon  = $ok ? '&#10003;' : '&#9888;';
		$color = $ok ? '#2a7d3f' : '#b45309';
		$text  = $ok ? $ok_text : $warn_text;
		printf(
			'<tr><td>%s</td><td style="color:%s;font-weight:600">%s %s</td></tr>',
			esc_html( $label ),
			esc_attr( $color ),
			wp_kses( $icon, array() ),
			esc_html( $text )
		);
	}

	private function info_row( string $label, string $value ): void {
		printf( '<tr><td>%s</td><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	// -----------------------------------------------------------------
	// Member lookup test tool
	// -----------------------------------------------------------------

	/** @return array<string, mixed>|null */
	private function maybe_handle_lookup_test(): ?array {
		if ( ! isset( $_POST['evc_lookup_submit'] ) ) {
			return null;
		}
		check_admin_referer( self::LOOKUP_ACTION );

		$phone = isset( $_POST['evc_lookup_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['evc_lookup_phone'] ) ) : '';

		$canonical = Identifiers::normalize_phone( $phone );
		if ( null === $canonical ) {
			return array( 'error' => __( 'Μη έγκυρος αριθμός τηλεφώνου.', 'evangelou-club-api' ) );
		}

		$result = Member_Resolver::resolve_by_phone( $canonical );

		if ( Member_Resolver::STATUS_AMBIGUOUS === $result['status'] ) {
			return array(
				'ambiguous'       => true,
				'candidate_count' => $result['candidate_count'],
			);
		}

		if ( Member_Resolver::STATUS_NOT_FOUND === $result['status'] ) {
			return array( 'not_found' => true );
		}

		$member     = $result['member'];
		$membership = Membership_Adapter::get_membership_info( $member->wp_user_id );

		return array(
			'found'              => true,
			'source'             => $result['source'],
			'wp_user_id'         => $member->wp_user_id,
			'display_name'       => $member->display_name,
			'membership_status'  => $membership['status'],
			'pmpro_level_name'   => $membership['pmpro_level_id'] ? implode( ', ', $this->level_names( array( $membership['pmpro_level_id'] ) ) ) : '—',
			'valid_until'        => $membership['valid_until'] ? Timezone::to_iso8601( $membership['valid_until'] ) : null,
		);
	}

	/** @param array<string, mixed>|null $result */
	private function render_lookup_tool( ?array $result ): void {
		echo '<h2>' . esc_html__( 'Δοκιμαστικός έλεγχος μέλους', 'evangelou-club-api' ) . '</h2>';
		echo '<p>' . esc_html__( 'Μόνο για διαχειριστές — δοκιμάζει την ίδια λογική εντοπισμού μέλους με το REST API, χωρίς να χρειάζεται η εφαρμογή /club.', 'evangelou-club-api' ) . '</p>';

		echo '<form method="post" style="margin-bottom:16px">';
		wp_nonce_field( self::LOOKUP_ACTION );
		echo '<input type="tel" name="evc_lookup_phone" placeholder="69XXXXXXXX" value="" style="min-width:220px" /> ';
		echo '<button type="submit" name="evc_lookup_submit" value="1" class="button">' . esc_html__( 'Έλεγχος μέλους', 'evangelou-club-api' ) . '</button>';
		echo '</form>';

		if ( null === $result ) {
			return;
		}

		if ( isset( $result['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result['error'] ) );
			return;
		}

		if ( isset( $result['not_found'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Δεν βρέθηκε μέλος για αυτό το τηλέφωνο.', 'evangelou-club-api' ) . '</p></div>';
			return;
		}

		if ( isset( $result['ambiguous'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of matching accounts */
						__( 'Ασαφές αποτέλεσμα: %d διαφορετικοί λογαριασμοί χρησιμοποιούν αυτό το τηλέφωνο. Χρειάζεται χειροκίνητος έλεγχος (βλ. logs).', 'evangelou-club-api' ),
						(int) $result['candidate_count']
					)
				)
			);
			return;
		}

		echo '<table class="widefat" style="max-width:640px"><tbody>';
		$this->info_row( __( 'Βρέθηκε άτομο', 'evangelou-club-api' ), __( 'Ναι', 'evangelou-club-api' ) );
		$this->info_row(
			__( 'Πηγή ταυτοποίησης', 'evangelou-club-api' ),
			array(
				'fluentcrm'   => 'FluentCRM',
				'woocommerce' => 'WooCommerce (billing phone)',
			)[ $result['source'] ] ?? (string) $result['source']
		);
		$this->info_row( __( 'Σύνδεση με χρήστη WordPress', 'evangelou-club-api' ), __( 'Ναι', 'evangelou-club-api' ) . ' (ID ' . (int) $result['wp_user_id'] . ')' );
		$this->info_row( __( 'Όνομα', 'evangelou-club-api' ), (string) $result['display_name'] );
		$this->info_row( __( 'Κατάσταση συνδρομής Club', 'evangelou-club-api' ), (string) $result['membership_status'] );
		$this->info_row( __( 'Επίπεδο PMPro', 'evangelou-club-api' ), (string) $result['pmpro_level_name'] );
		$this->info_row( __( 'Ισχύς έως', 'evangelou-club-api' ), $result['valid_until'] ?? __( '(χωρίς λήξη)', 'evangelou-club-api' ) );
		echo '</tbody></table>';
	}
}
