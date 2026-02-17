<?php
/**
 * Plugin Name: DC Woo Shipping Notices
 * Plugin URI:  https://github.com/dc
 * Description: Checkout notices based on shipping destination — warn or block orders by country/state.
 * Version:     1.2.0
 * Author:      DC
 * Text Domain: dc-woo-shipping-notices
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 6.6
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 * License: GPLv2 or later
 *
 * @package DC_Woo_Shipping_Notices
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/*  Constants                                                          */
/* ------------------------------------------------------------------ */
define( 'DCSN_VERSION', '1.2.0' );
define( 'DCSN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DCSN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DCSN_OPTION_KEY', 'dcsn_rules' );

/* ------------------------------------------------------------------ */
/*  WooCommerce feature compatibility                                   */
/* ------------------------------------------------------------------ */
add_action( 'before_woocommerce_init', function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
} );

/* ------------------------------------------------------------------ */
/*  Includes                                                           */
/* ------------------------------------------------------------------ */
require_once DCSN_PLUGIN_DIR . 'includes/helpers.php';
require_once DCSN_PLUGIN_DIR . 'includes/class-dcsn-rules.php';
require_once DCSN_PLUGIN_DIR . 'includes/class-dcsn-checkout.php';

/* ------------------------------------------------------------------ */
/*  Boot                                                               */
/* ------------------------------------------------------------------ */
/**
 * Initialize the plugin after plugins are loaded (WC must be active).
 */
function dcsn_init(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'dcsn_missing_wc_notice' );
		return;
	}

	// Migrate the fallback_to_billing setting to its own WC option (once).
	dcsn_maybe_migrate_settings();

	// Register the WooCommerce Settings tab (admin class loaded lazily).
	add_filter( 'woocommerce_get_settings_pages', 'dcsn_register_settings_page' );

	// Register translatable UI strings with WPML String Translation.
	dcsn_register_wpml_strings();

	new DCSN_Checkout();
}
add_action( 'plugins_loaded', 'dcsn_init' );

/**
 * Register modal UI strings with WPML String Translation.
 *
 * This makes them available under WPML > String Translation
 * (domain "dc-woo-shipping-notices") so the admin can provide
 * translations without .mo files.
 */
function dcsn_register_wpml_strings(): void {
	$strings = [
		'Modal title - block'           => 'Livraison impossible',
		'Modal title - allow'           => 'Information de livraison',
		'Modal button - continue'       => 'Continuer',
		'Modal button - change country' => 'Changer de pays',
	];

	foreach ( $strings as $name => $value ) {
		do_action( 'wpml_register_single_string', 'dc-woo-shipping-notices', $name, $value );
	}
}

/**
 * Register our settings page via WooCommerce's official filter.
 *
 * @param array $settings Existing settings page instances.
 * @return array
 */
function dcsn_register_settings_page( array $settings ): array {
	require_once DCSN_PLUGIN_DIR . 'includes/class-dcsn-admin.php';
	$settings[] = new DCSN_Admin();
	return $settings;
}

/**
 * One-time migration: move fallback_to_billing from the dcsn_rules option
 * to its own WooCommerce-managed option (dcsn_fallback_to_billing).
 */
function dcsn_maybe_migrate_settings(): void {
	if ( false !== get_option( 'dcsn_fallback_to_billing' ) ) {
		return; // Already migrated or set.
	}

	$old = get_option( DCSN_OPTION_KEY, [] );
	$val = $old['settings']['fallback_to_billing'] ?? true;
	update_option( 'dcsn_fallback_to_billing', $val ? 'yes' : 'no' );
}

/**
 * Admin notice when WooCommerce is not active.
 */
function dcsn_missing_wc_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'DC Woo Shipping Notices requires WooCommerce to be installed and active.', 'dc-woo-shipping-notices' )
	);
}

/* ------------------------------------------------------------------ */
/*  Activation — seed default rules                                    */
/* ------------------------------------------------------------------ */
register_activation_hook( __FILE__, 'dcsn_activate' );

function dcsn_activate(): void {
	$existing = get_option( DCSN_OPTION_KEY );

	// Only seed if the option doesn't exist yet.
	if ( false !== $existing ) {
		return;
	}

	$defaults = [
		'settings' => [
			'fallback_to_billing' => true,
		],
		'rules' => [
			[
				'id'            => dcsn_generate_id(),
				'enabled'       => true,
				'label'         => 'Blocage RU / UA',
				'countries'     => [ 'RU', 'UA' ],
				'states'        => [],
				'mode'          => 'BLOCK_WITH_MESSAGE',
				'notice_type'   => 'warning',
				'message'       => 'Nous ne pouvons plus livrer cette destination, veuillez nous en excuser.',
				'priority'      => 10,
				'stop_on_match' => false,
			],
			[
				'id'            => dcsn_generate_id(),
				'enabled'       => true,
				'label'         => 'Avertissement US',
				'countries'     => [ 'US' ],
				'states'        => [],
				'mode'          => 'ALLOW_WITH_MESSAGE',
				'notice_type'   => 'warning',
				'message'       => 'Attention : des taxes/droits d\'importation peuvent s\'appliquer (env. 15 % à 22 %).',
				'priority'      => 20,
				'stop_on_match' => false,
			],
		],
	];

	update_option( DCSN_OPTION_KEY, $defaults, false );
}
