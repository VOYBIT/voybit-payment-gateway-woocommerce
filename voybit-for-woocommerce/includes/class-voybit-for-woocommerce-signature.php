<?php
/**
 * Webhook signature verification.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies a Voybit webhook without reading the body as JSON first.
 */
class Voybit_For_WooCommerce_Signature {

	const TOLERANCE = 300;

	/**
	 * Return an empty string when the signature matches, otherwise a reason code.
	 *
	 * @param string   $secret    Webhook secret.
	 * @param string   $id        Voybit-Webhook-Id.
	 * @param string   $timestamp Voybit-Webhook-Timestamp.
	 * @param string   $signature Voybit-Webhook-Signature.
	 * @param string   $raw_body  Raw request body.
	 * @param int|null $now       Unix time, for tests.
	 * @return string
	 */
	public static function error( $secret, $id, $timestamp, $signature, $raw_body, $now = null ) {
		$hex = 0 === strpos( (string) $signature, 'v1=' ) ? substr( (string) $signature, 3 ) : '';
		if ( '' === (string) $secret || '' === (string) $id || ! ctype_digit( (string) $timestamp ) || ! preg_match( '/^[0-9a-f]{64}$/i', $hex ) ) {
			return 'invalid';
		}

		$supplied = hex2bin( $hex );
		$current  = null === $now ? time() : (int) $now;
		if ( ! is_string( $supplied ) || 32 !== strlen( $supplied ) ) {
			return 'invalid';
		}
		if ( abs( $current - (int) $timestamp ) > self::TOLERANCE ) {
			return 'expired';
		}

		$expected = hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $raw_body, $secret, true );
		if ( ! is_string( $expected ) || 32 !== strlen( $expected ) || ! hash_equals( $expected, $supplied ) ) {
			return 'mismatch';
		}
		return '';
	}
}
