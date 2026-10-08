<?php
/**
 * Checkout block integration.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Voybit for the WooCommerce checkout block.
 */
class Voybit_For_WooCommerce_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected $name = 'voybit';

	/**
	 * Load saved settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_voybit_settings', array() );
	}

	/**
	 * Show the method only when the classic gateway would.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['voybit'] ) || ! $gateways['voybit'] instanceof Voybit_For_WooCommerce_Gateway ) {
			return false;
		}
		return $gateways['voybit']->is_available();
	}

	/**
	 * Local script that registers the payment method. It does not contain the API key.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'voybit-for-woocommerce-blocks';
		wp_register_script(
			$handle,
			VOYBIT_FOR_WOOCOMMERCE_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			VOYBIT_FOR_WOOCOMMERCE_VERSION,
			true
		);
		wp_register_style(
			'voybit-for-woocommerce-checkout',
			VOYBIT_FOR_WOOCOMMERCE_URL . 'assets/css/checkout.css',
			array(),
			VOYBIT_FOR_WOOCOMMERCE_VERSION
		);
		wp_enqueue_style( 'voybit-for-woocommerce-checkout' );
		return array( $handle );
	}

	/**
	 * Data passed to the checkout script.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Voybit', 'voybit-for-woocommerce' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'icon'        => VOYBIT_FOR_WOOCOMMERCE_URL . 'assets/icon.svg',
			'supports'    => array(
				'features' => array( 'products' ),
			),
		);
	}
}
