<?php
/**
 * Checks that do not need WordPress.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'VOYBIT_FOR_WOOCOMMERCE_TEST' ) ) {
	return;
}

require_once dirname( __DIR__ ) . '/includes/class-voybit-for-woocommerce-amount.php';
require_once dirname( __DIR__ ) . '/includes/class-voybit-for-woocommerce-checkout.php';
require_once dirname( __DIR__ ) . '/includes/class-voybit-for-woocommerce-signature.php';
require_once dirname( __DIR__ ) . '/includes/class-voybit-for-woocommerce-request.php';
require_once dirname( __DIR__ ) . '/includes/class-voybit-for-woocommerce-api.php';

/**
 * Stop the run when a check fails.
 *
 * @param bool   $condition Result.
 * @param string $message   Failure text.
 */
function voybit_for_woocommerce_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$voybit_for_woocommerce_usd = Voybit_For_WooCommerce_Amount::from( '25.00', 'usd' );
voybit_for_woocommerce_assert( '25.00' === $voybit_for_woocommerce_usd['fiat_amount'], 'usd amount' );
voybit_for_woocommerce_assert( 'USD' === $voybit_for_woocommerce_usd['fiat_currency'], 'usd code' );

$voybit_for_woocommerce_jpy = Voybit_For_WooCommerce_Amount::from( '25', 'JPY' );
voybit_for_woocommerce_assert( '25' === $voybit_for_woocommerce_jpy['fiat_amount'], 'jpy amount' );

$voybit_for_woocommerce_bhd = Voybit_For_WooCommerce_Amount::from( '1.234', 'BHD' );
voybit_for_woocommerce_assert( '1.234' === $voybit_for_woocommerce_bhd['fiat_amount'], 'bhd amount' );

$voybit_for_woocommerce_zero_tail = Voybit_For_WooCommerce_Amount::from( '25.5000', 'USD' );
voybit_for_woocommerce_assert( '25.50' === $voybit_for_woocommerce_zero_tail['fiat_amount'], 'trailing zero amount' );

foreach ( array( '25.501', '0.00', '0', '-1.00', 'USD', '25.00' ) as $voybit_for_woocommerce_bad ) {
	$voybit_for_woocommerce_threw = false;
	try {
		if ( 'USD' === $voybit_for_woocommerce_bad ) {
			Voybit_For_WooCommerce_Amount::from( '10.00', 'US' );
		} elseif ( '25.00' === $voybit_for_woocommerce_bad ) {
			Voybit_For_WooCommerce_Amount::from( '25.00', 'usd1' );
		} else {
			Voybit_For_WooCommerce_Amount::from( $voybit_for_woocommerce_bad, 'USD' );
		}
	} catch ( InvalidArgumentException $voybit_for_woocommerce_error ) {
		$voybit_for_woocommerce_threw = true;
		unset( $voybit_for_woocommerce_error );
	}
	voybit_for_woocommerce_assert( $voybit_for_woocommerce_threw, 'rejected ' . $voybit_for_woocommerce_bad );
}

$voybit_for_woocommerce_id = 'nYVvXxsYGr5LZk8Dn7hU0Q';
voybit_for_woocommerce_assert(
	'https://voybit.com/pay/' . $voybit_for_woocommerce_id === Voybit_For_WooCommerce_Checkout::canonical( 'https://voybit.com/pay/' . $voybit_for_woocommerce_id . '/' ),
	'trailing slash'
);
foreach ( array(
	'http://voybit.com/pay/' . $voybit_for_woocommerce_id,
	'https://user:pass@voybit.com/pay/' . $voybit_for_woocommerce_id,
	'https://voybit.com/pay/' . $voybit_for_woocommerce_id . '?x=1',
	'https://voybit.com/pay/' . $voybit_for_woocommerce_id . '#pay',
	'https://voybit.com:443/pay/' . $voybit_for_woocommerce_id,
	'https://evil.example/pay/' . $voybit_for_woocommerce_id,
	'https://voybit.com.evil.example/pay/' . $voybit_for_woocommerce_id,
) as $voybit_for_woocommerce_url ) {
	voybit_for_woocommerce_assert( '' === Voybit_For_WooCommerce_Checkout::canonical( $voybit_for_woocommerce_url ), 'rejected url ' . $voybit_for_woocommerce_url );
}

$voybit_for_woocommerce_secret    = 'whsec_example';
$voybit_for_woocommerce_delivery  = 'delivery-1';
$voybit_for_woocommerce_timestamp = '1700000000';
$voybit_for_woocommerce_body      = '{"type":"payment.paid","status":"paid"}';
$voybit_for_woocommerce_signature = 'v1=' . hash_hmac( 'sha256', $voybit_for_woocommerce_delivery . '.' . $voybit_for_woocommerce_timestamp . '.' . $voybit_for_woocommerce_body, $voybit_for_woocommerce_secret );
voybit_for_woocommerce_assert(
	'' === Voybit_For_WooCommerce_Signature::error( $voybit_for_woocommerce_secret, $voybit_for_woocommerce_delivery, $voybit_for_woocommerce_timestamp, $voybit_for_woocommerce_signature, $voybit_for_woocommerce_body, 1700000000 ),
	'signature'
);
voybit_for_woocommerce_assert(
	'mismatch' === Voybit_For_WooCommerce_Signature::error( $voybit_for_woocommerce_secret, $voybit_for_woocommerce_delivery, $voybit_for_woocommerce_timestamp, $voybit_for_woocommerce_signature, $voybit_for_woocommerce_body . ' ', 1700000000 ),
	'tampered body'
);
voybit_for_woocommerce_assert(
	'expired' === Voybit_For_WooCommerce_Signature::error( $voybit_for_woocommerce_secret, $voybit_for_woocommerce_delivery, $voybit_for_woocommerce_timestamp, $voybit_for_woocommerce_signature, $voybit_for_woocommerce_body, 1700000401 ),
	'expired signature'
);
voybit_for_woocommerce_assert(
	'invalid' === Voybit_For_WooCommerce_Signature::error( $voybit_for_woocommerce_secret, $voybit_for_woocommerce_delivery, $voybit_for_woocommerce_timestamp, 'v1=abcd', $voybit_for_woocommerce_body, 1700000000 ),
	'short signature'
);

voybit_for_woocommerce_assert( 'woocommerce:12' === Voybit_For_WooCommerce_Request::for_order( 12 ), 'idempotency' );
voybit_for_woocommerce_assert( '' === Voybit_For_WooCommerce_Request::for_order( 0 ), 'idempotency zero' );
voybit_for_woocommerce_assert( '' === Voybit_For_WooCommerce_Request::for_order( -1 ), 'idempotency negative' );
voybit_for_woocommerce_assert(
	'https://api.voybit.com/api' === Voybit_For_WooCommerce_Api::normalize_base( 'https://API.VOYBIT.COM/api/' ),
	'api base'
);
voybit_for_woocommerce_assert( '' === Voybit_For_WooCommerce_Api::normalize_base( 'http://api.voybit.com/api' ), 'api base https' );
voybit_for_woocommerce_assert( '' === Voybit_For_WooCommerce_Api::normalize_base( 'https://user@api.voybit.com/api' ), 'api base credentials' );
