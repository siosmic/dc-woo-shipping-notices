<?php
/**
 * Uninstall — runs when the plugin is deleted via the WordPress admin.
 *
 * Removes all plugin options and transients from the database.
 *
 * @package DC_Woo_Shipping_Notices
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Plugin options.
delete_option( 'dcsn_rules' );
delete_option( 'dcsn_fallback_to_billing' );
delete_option( 'dcsn_enable_modal' );

// Transients used by the rule editor.
delete_transient( 'dcsn_form_data' );
delete_transient( 'dcsn_form_errors' );
