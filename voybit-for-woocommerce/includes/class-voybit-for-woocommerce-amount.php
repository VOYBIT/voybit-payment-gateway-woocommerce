<?php
/**
 * Order total conversion.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a store total into the fiat minor units and crypto amount Voybit expects.
 */
class Voybit_For_WooCommerce_Amount {

	/**
	 * Currencies with no minor unit.
	 *
	 * @var string[]
	 */
	const ZERO_DECIMAL = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * Currencies with three minor digits.
	 *
	 * @var string[]
	 */
	const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Largest minor amount Voybit accepts.
	 */
	const MAX_MINOR = '9000000000000000';

	/**
	 * Build the payment amounts for an order total.
	 *
	 * @param string $amount   Decimal total, without thousands separators.
	 * @param string $currency Three-letter currency code.
	 * @return array{amount_minor: int, crypto_amount: string, fiat_currency: string}
	 * @throws InvalidArgumentException When the total cannot be sent.
	 */
	public static function from( $amount, $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		$amount   = trim( (string) $amount );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! preg_match( '/^(?:0|[1-9]\d*)(?:\.(\d+))?$/', $amount, $match ) ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		$exponent = self::exponent( $currency );
		if ( $exponent > 4 ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		$parts    = explode( '.', $amount, 2 );
		$whole    = $parts[0];
		$fraction = isset( $match[1] ) ? $match[1] : '';
		if ( strlen( $fraction ) > $exponent && preg_match( '/[1-9]/', substr( $fraction, $exponent ) ) ) {
			throw new InvalidArgumentException( 'order total has more decimal places than the currency allows' );
		}

		$fraction = str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' );
		$minor    = ltrim( $whole . $fraction, '0' );
		if ( '' === $minor || strlen( $minor ) > 16 || ( 16 === strlen( $minor ) && strcmp( $minor, self::MAX_MINOR ) > 0 ) ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		$value = (int) $minor;
		if ( $value < 1 || (string) $value !== $minor ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		return array(
			'amount_minor'   => $value,
			'crypto_amount'  => 0 === $exponent ? $whole : $whole . '.' . $fraction,
			'fiat_currency'  => $currency,
		);
	}

	/**
	 * Minor-unit exponent for a currency.
	 *
	 * @param string $currency Uppercase currency code.
	 * @return int
	 */
	private static function exponent( $currency ) {
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return 3;
		}
		return 2;
	}
}
