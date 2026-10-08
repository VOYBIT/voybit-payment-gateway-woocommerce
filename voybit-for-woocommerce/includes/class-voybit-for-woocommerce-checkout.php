<?php
/**
 * Hosted checkout URL checks.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts only the canonical Voybit hosted checkout URL.
 */
class Voybit_For_WooCommerce_Checkout {

	/**
	 * Return the canonical checkout URL, or an empty string when it is not ours.
	 *
	 * @param string $url URL returned by the payment API.
	 * @return string
	 */
	public static function canonical( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '~\Ahttps://([^/?#]+)/pay/([A-Za-z0-9_-]{22})/?\z~', $url, $match ) ) {
			return '';
		}
		if ( 0 !== strcasecmp( $match[1], 'voybit.com' ) ) {
			return '';
		}
		return 'https://voybit.com/pay/' . $match[2];
	}
}
