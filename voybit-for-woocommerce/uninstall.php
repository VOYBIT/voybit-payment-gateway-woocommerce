<?php
/**
 * Remove plugin settings. Orders and their payment records are left in place.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the WooCommerce settings option for this plugin.
 */
function voybit_for_woocommerce_delete_settings() {
	delete_option( 'woocommerce_voybit_settings' );
	delete_option( 'voybit_for_woocommerce_configuration_notice' );
}

if ( is_multisite() ) {
	$voybit_for_woocommerce_sites = get_sites( array( 'number' => 0 ) );
	foreach ( $voybit_for_woocommerce_sites as $voybit_for_woocommerce_site ) {
		switch_to_blog( (int) $voybit_for_woocommerce_site->blog_id );
		voybit_for_woocommerce_delete_settings();
		restore_current_blog();
	}
} else {
	voybit_for_woocommerce_delete_settings();
}
