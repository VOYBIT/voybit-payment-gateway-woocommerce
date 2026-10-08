<?php
/**
 * Plugin Name: Voybit for WooCommerce
 * Plugin URI: https://github.com/VOYBIT/voybit-payment-gateway-woocommerce
 * Description: Accept Voybit crypto payments in WooCommerce. Customers pay on the Voybit page, and the store confirms the order when Voybit reports the payment.
 * Version: 1.0.0
 * Author: Voybit
 * Author URI: https://voybit.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: voybit-for-woocommerce
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * WC tested up to: 11.2
 *
 * @package Voybit_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VOYBIT_FOR_WOOCOMMERCE_VERSION', '1.0.0' );
define( 'VOYBIT_FOR_WOOCOMMERCE_FILE', __FILE__ );
define( 'VOYBIT_FOR_WOOCOMMERCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'VOYBIT_FOR_WOOCOMMERCE_URL', plugin_dir_url( __FILE__ ) );

require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-amount.php';
require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-checkout.php';
require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-signature.php';
require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-request.php';

/**
 * Declare compatibility with WooCommerce order storage and the checkout block.
 */
function voybit_for_woocommerce_declare_compatibility() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VOYBIT_FOR_WOOCOMMERCE_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VOYBIT_FOR_WOOCOMMERCE_FILE, true );
}
add_action( 'before_woocommerce_init', 'voybit_for_woocommerce_declare_compatibility' );

/**
 * Allow the browser to leave the store for hosted checkout.
 *
 * @param string[] $hosts Allowed hosts.
 * @return string[]
 */
function voybit_for_woocommerce_allow_redirect_host( $hosts ) {
	$hosts[] = 'voybit.com';
	return $hosts;
}

/**
 * Load the payment method after WooCommerce.
 */
function voybit_for_woocommerce_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-api.php';
	require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-gateway.php';
	require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-webhook.php';
	add_filter( 'woocommerce_payment_gateways', 'voybit_for_woocommerce_register_gateway' );
	add_action( 'rest_api_init', array( 'Voybit_For_WooCommerce_Webhook', 'register' ) );
}
add_action( 'plugins_loaded', 'voybit_for_woocommerce_init' );

/**
 * Add Voybit to the gateway list.
 *
 * @param string[] $gateways Gateway class names.
 * @return string[]
 */
function voybit_for_woocommerce_register_gateway( $gateways ) {
	$gateways[] = 'Voybit_For_WooCommerce_Gateway';
	return $gateways;
}

/**
 * Register Voybit for the checkout block. The script does not receive the API key.
 */
function voybit_for_woocommerce_blocks() {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once VOYBIT_FOR_WOOCOMMERCE_DIR . 'includes/class-voybit-for-woocommerce-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'voybit_for_woocommerce_register_blocks' );
}
add_action( 'woocommerce_blocks_loaded', 'voybit_for_woocommerce_blocks' );

/**
 * Hand the block integration to WooCommerce.
 *
 * @param object $registry Payment method registry.
 */
function voybit_for_woocommerce_register_blocks( $registry ) {
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
		return;
	}
	$registry->register( new Voybit_For_WooCommerce_Blocks() );
}

/**
 * Style the checkout mark. Loaded only when Voybit can be used.
 */
function voybit_for_woocommerce_checkout_style() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	if ( empty( $gateways['voybit'] ) || ! $gateways['voybit'] instanceof Voybit_For_WooCommerce_Gateway || ! $gateways['voybit']->is_available() ) {
		return;
	}
	wp_enqueue_style(
		'voybit-for-woocommerce-checkout',
		VOYBIT_FOR_WOOCOMMERCE_URL . 'assets/css/checkout.css',
		array(),
		VOYBIT_FOR_WOOCOMMERCE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'voybit_for_woocommerce_checkout_style' );

/**
 * Link to the payment settings.
 *
 * @param string[] $links Plugin action links.
 * @return string[]
 */
function voybit_for_woocommerce_action_links( $links ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return $links;
	}
	$url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=voybit' );
	$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'voybit-for-woocommerce' ) . '</a>';
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( VOYBIT_FOR_WOOCOMMERCE_FILE ), 'voybit_for_woocommerce_action_links' );

/**
 * Ask an administrator to activate WooCommerce. Nothing is sent anywhere from this notice.
 */
function voybit_for_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || class_exists( 'WooCommerce' ) ) {
		return;
	}
	echo '<div class="notice notice-warning is-dismissible"><p>';
	echo esc_html__( 'Voybit for WooCommerce needs WooCommerce. Install and activate WooCommerce, then open WooCommerce, Settings, Payments, Voybit.', 'voybit-for-woocommerce' );
	if ( current_user_can( 'install_plugins' ) ) {
		$url = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'View WooCommerce in the plugin directory.', 'voybit-for-woocommerce' ) . '</a>';
	}
	echo '</p></div>';
}
add_action( 'admin_notices', 'voybit_for_woocommerce_missing_notice' );

/**
 * Explain what this store sends to Voybit when a customer pays.
 */
function voybit_for_woocommerce_privacy() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = __( 'When a customer pays with Voybit, this store sends the order total, currency, order number, and a short description to Voybit to open checkout. The API key and webhook secret stay on this store. Voybit privacy policy: https://voybit.com/privacy', 'voybit-for-woocommerce' );
	wp_add_privacy_policy_content( 'Voybit for WooCommerce', wp_kses_post( wpautop( $content ) ) );
}
add_action( 'admin_init', 'voybit_for_woocommerce_privacy' );
