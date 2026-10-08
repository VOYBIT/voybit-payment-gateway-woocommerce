<?php
/**
 * Idempotency keys for WooCommerce orders.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a stable idempotency key from the WooCommerce order ID.
 */
class Voybit_For_WooCommerce_Request {

	/**
	 * Idempotency key for one order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string Empty when the ID cannot be used.
	 */
	public static function for_order( $order_id ) {
		$order_id = (string) $order_id;
		if ( ! preg_match( '/^[1-9][0-9]{0,17}$/', $order_id ) ) {
			return '';
		}
		$key = 'woocommerce:' . $order_id;
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $key ) ) {
			return '';
		}
		return $key;
	}
}
