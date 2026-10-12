<?php
/**
 * Live US dollar rates for store currencies Voybit cannot charge directly.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches how many units of a store currency equal one US dollar.
 *
 * Iranian rial and toman use the public USDT/rial market. Every other
 * currency uses a public US dollar table. Neither request includes the order.
 */
class Voybit_For_WooCommerce_Rate {

	const NOBITEX_URL = 'https://apiv2.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls';
	const TABLE_URL   = 'https://open.er-api.com/v6/latest/USD';

	/**
	 * Source units per one US dollar.
	 *
	 * Iranian currencies return rials. The caller converts tomans.
	 *
	 * @param string $currency Three-letter store currency.
	 * @return string|WP_Error
	 */
	public static function per_usd( $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) || 'USD' === $currency ) {
			return new WP_Error( 'voybit_rate', 'currency' );
		}

		$fresh = self::cached( $currency, 600 );
		if ( '' !== $fresh ) {
			return $fresh;
		}

		$fetched = self::fetch( $currency );
		if ( '' !== $fetched ) {
			self::store( $currency, $fetched );
			return $fetched;
		}

		$stale = self::cached( $currency, 3600 );
		if ( '' !== $stale ) {
			return $stale;
		}
		return new WP_Error( 'voybit_rate', 'unavailable' );
	}

	/**
	 * Read the Nobitex USDT/rial latest price.
	 *
	 * @param string $body JSON body.
	 * @return string Rials per one US dollar.
	 * @throws InvalidArgumentException When the body has no usable market rate.
	 */
	public static function parse_nobitex_rials( $body ) {
		$body = (string) $body;
		if ( ! preg_match( '/"status"\s*:\s*"ok"/', $body ) || ! preg_match( '/"usdt-rls"\s*:\s*(\{.*?})/s', $body, $object ) ) {
			throw new InvalidArgumentException( 'rate' );
		}
		$market = $object[1];
		if (
			! preg_match( '/"latest"\s*:\s*"([0-9]+(?:\.[0-9]+)?)"/', $market, $match )
			&& ! preg_match( '/"latest"\s*:\s*([0-9]+(?:\.[0-9]+)?)(?![0-9.])/', $market, $match )
		) {
			throw new InvalidArgumentException( 'rate' );
		}
		if ( ! Voybit_For_WooCommerce_Amount::iranian_rate_in_range( $match[1] ) ) {
			throw new InvalidArgumentException( 'rate' );
		}
		return Voybit_For_WooCommerce_Amount::canonical_decimal( $match[1], 8 );
	}

	/**
	 * Read one currency from an open US dollar rate table.
	 *
	 * Iranian rial is intentionally rejected here. Its official table rate is
	 * not the USDT market rate used for checkout.
	 *
	 * @param string $body     JSON body.
	 * @param string $currency Three-letter currency code.
	 * @return string
	 * @throws InvalidArgumentException When that currency is not in the table.
	 */
	public static function parse_usd_table( $body, $currency ) {
		$body     = (string) $body;
		$currency = strtoupper( trim( (string) $currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) || 'USD' === $currency || in_array( $currency, array( 'IRR', 'IRT' ), true ) ) {
			throw new InvalidArgumentException( 'rate' );
		}
		if ( ! preg_match( '/"result"\s*:\s*"success"/', $body ) || ! preg_match( '/"base_code"\s*:\s*"USD"/', $body ) ) {
			throw new InvalidArgumentException( 'rate' );
		}
		if ( ! preg_match( '/"' . $currency . '"\s*:\s*([0-9]+(?:\.[0-9]+)?)/', $body, $match ) ) {
			throw new InvalidArgumentException( 'rate' );
		}
		if ( ! Voybit_For_WooCommerce_Amount::is_positive_decimal( $match[1] ) ) {
			throw new InvalidArgumentException( 'rate' );
		}
		return Voybit_For_WooCommerce_Amount::canonical_decimal( $match[1], 8 );
	}

	/**
	 * Cached rate, when it is still young enough.
	 *
	 * @param string $currency Store currency.
	 * @param int    $max_age  Maximum age in seconds.
	 * @return string
	 */
	private static function cached( $currency, $max_age ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return '';
		}
		$row = get_transient( self::cache_key( $currency ) );
		if ( ! is_array( $row ) || ! isset( $row['rate'], $row['at'] ) ) {
			return '';
		}
		$rate = (string) $row['rate'];
		$at   = (int) $row['at'];
		if ( '' === $rate || $at < 1 || $at > time() || ( time() - $at ) > $max_age ) {
			return '';
		}
		return $rate;
	}

	/**
	 * Remember a rate for at most one hour.
	 *
	 * @param string $currency Store currency.
	 * @param string $rate     Units per one US dollar.
	 */
	private static function store( $currency, $rate ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient(
			self::cache_key( $currency ),
			array(
				'rate' => $rate,
				'at'   => time(),
			),
			3600
		);
	}

	/**
	 * Download the current rate. An empty string means it was not usable.
	 *
	 * @param string $currency Store currency.
	 * @return string
	 */
	private static function fetch( $currency ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return '';
		}
		$iranian = in_array( $currency, array( 'IRR', 'IRT' ), true );
		$response = wp_remote_get(
			$iranian ? self::NOBITEX_URL : self::TABLE_URL,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'voybit-for-woocommerce/' . ( defined( 'VOYBIT_FOR_WOOCOMMERCE_VERSION' ) ? VOYBIT_FOR_WOOCOMMERCE_VERSION : '0' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code || strlen( $body ) < 2 || strlen( $body ) > 262144 ) {
			return '';
		}
		try {
			return $iranian ? self::parse_nobitex_rials( $body ) : self::parse_usd_table( $body, $currency );
		} catch ( InvalidArgumentException $error ) {
			unset( $error );
			return '';
		}
	}

	/**
	 * Transient name. Rial and toman share the rial market rate.
	 *
	 * @param string $currency Store currency.
	 * @return string
	 */
	private static function cache_key( $currency ) {
		$bucket = in_array( $currency, array( 'IRR', 'IRT' ), true ) ? 'irr' : strtolower( $currency );
		return 'voybit_fx_' . $bucket;
	}
}
