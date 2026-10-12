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

	/**
	 * Idempotency key for an order whose total was converted to US dollars.
	 *
	 * The dollar amount is part of the key, so a later rate creates a new
	 * checkout instead of replaying the previous one.
	 *
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $usd_amount Two-decimal US dollar total.
	 * @param int    $attempt    Attempt number from 1 to 40.
	 * @return string Empty when the key cannot be used.
	 */
	public static function for_priced_order( $order_id, $usd_amount, $attempt ) {
		$base = self::for_order( $order_id );
		$attempt = (int) $attempt;
		if ( '' === $base || $attempt < 1 || $attempt > 40 || ! preg_match( '/^(?:0|[1-9]\d*)\.\d{2}$/', (string) $usd_amount ) ) {
			return '';
		}
		$key = $base . ':usd:' . $usd_amount . ':' . $attempt;
		if ( strlen( $key ) > 128 || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $key ) ) {
			return '';
		}
		return $key;
	}
}
