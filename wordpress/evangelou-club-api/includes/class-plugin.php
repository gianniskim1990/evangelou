<?php
/**
 * Bootstraps the plugin: dependency detection, capability/role setup,
 * wiring the REST controller and admin screen. Nothing here talks to
 * PMPro/FluentCRM/WooCommerce directly — that's Membership_Adapter and
 * Member_Resolver's job; this class only detects whether they're present.
 *
 * @package EvangelouClub
 */

namespace EvangelouClub;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const CAPABILITY = 'use_evangelou_club';
	const STAFF_ROLE = 'evangelou_club_staff';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function init(): void {
		$rest_controller = new Rest_Controller();
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );

		$admin = new Admin();
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );

		add_action( 'admin_notices', array( $this, 'render_dependency_notices' ) );
	}

	// -----------------------------------------------------------------
	// Activation / deactivation
	// -----------------------------------------------------------------

	public static function activate(): void {
		Redemption_Repository::install();

		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( self::CAPABILITY ) ) {
			$admin_role->add_cap( self::CAPABILITY );
		}

		if ( ! get_role( self::STAFF_ROLE ) ) {
			// Deliberately minimal — read access plus our one capability,
			// no WordPress administrative capabilities of any kind.
			add_role(
				self::STAFF_ROLE,
				__( 'Evangelou Club Staff', 'evangelou-club-api' ),
				array(
					'read'          => true,
					self::CAPABILITY => true,
				)
			);
		}
	}

	public static function deactivate(): void {
		// Deliberately does nothing destructive: no data, capability, or
		// role removal on deactivation — see uninstall.php for the
		// (opt-in only) data-removal path. Deactivating should be safely
		// reversible by reactivating.
	}

	// -----------------------------------------------------------------
	// Dependency detection
	// -----------------------------------------------------------------

	public static function is_pmpro_active(): bool {
		return Membership_Adapter::is_pmpro_active();
	}

	public static function is_fluentcrm_active(): bool {
		return function_exists( 'FluentCrmApi' );
	}

	public static function is_woocommerce_active(): bool {
		return class_exists( '\\WooCommerce' );
	}

	/** @return array{pmpro: bool, fluentcrm: bool, woocommerce: bool, table: bool, levels_configured: bool} */
	public static function diagnostics(): array {
		return array(
			'pmpro'              => self::is_pmpro_active(),
			'fluentcrm'          => self::is_fluentcrm_active(),
			'woocommerce'        => self::is_woocommerce_active(),
			'table'              => Redemption_Repository::table_exists(),
			'levels_configured'  => Settings::has_club_levels_configured(),
		);
	}

	/**
	 * Restrained: one notice for the hard requirement (PMPro), one for
	 * the preferred-but-optional integrations — never blocks WordPress
	 * from loading, just tells the administrator what's missing and
	 * where to check it.
	 */
	public function render_dependency_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=evangelou-club' );

		if ( ! self::is_pmpro_active() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: settings page URL */
						__( '<strong>Evangelou Club API:</strong> Το Paid Memberships Pro δεν εντοπίστηκε. Η επαλήθευση συνδρομής δεν θα λειτουργεί μέχρι να ενεργοποιηθεί. Δείτε <a href="%s">Ρυθμίσεις → Evangelou Club</a>.', 'evangelou-club-api' ),
						esc_url( $settings_url )
					)
				)
			);
		}

		if ( self::is_pmpro_active() && ! Settings::has_club_levels_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: settings page URL */
						__( '<strong>Evangelou Club API:</strong> Δεν έχει οριστεί επίπεδο συνδρομής Club. Ρυθμίστε το στο <a href="%s">Ρυθμίσεις → Evangelou Club</a> πριν συνδεθεί η εφαρμογή.', 'evangelou-club-api' ),
						esc_url( $settings_url )
					)
				)
			);
		}
	}
}
