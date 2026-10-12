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
 * Validates and normalizes a store total for a fixed-fiat checkout session.
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
	 * Build the fixed fiat amount for an order total.
	 *
	 * @param string $amount   Decimal total, without thousands separators.
	 * @param string $currency Three-letter currency code.
	 * @return array{fiat_amount: string, fiat_currency: string}
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
			'fiat_amount'   => 0 === $exponent ? $whole : $whole . '.' . $fraction,
			'fiat_currency' => $currency,
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

	/**
	 * How many times to multiply an Iranian total before dividing by the rial rate.
	 *
	 * Automatic treats IRR as rials and IRT as tomans. One toman is 10 rials.
	 * An empty result means the currency is not Iranian.
	 *
	 * @param string $currency   Store currency code.
	 * @param string $preference auto, rial, or toman.
	 * @return string 1, 10, or empty.
	 */
	public static function iranian_times( $currency, $preference ) {
		$currency   = strtoupper( trim( (string) $currency ) );
		$preference = strtolower( trim( (string) $preference ) );
		if ( ! in_array( $currency, array( 'IRR', 'IRT' ), true ) ) {
			return '';
		}
		if ( 'toman' === $preference ) {
			return '10';
		}
		if ( 'rial' === $preference ) {
			return '1';
		}
		return 'IRT' === $currency ? '10' : '1';
	}

	/**
	 * Whether a rial-per-dollar rate is inside the accepted market range.
	 *
	 * @param string $rate Rials per one US dollar.
	 * @return bool
	 */
	public static function iranian_rate_in_range( $rate ) {
		try {
			list( $int, $scale ) = self::decimal_parts( $rate, 8 );
		} catch ( InvalidArgumentException $error ) {
			unset( $error );
			return false;
		}
		if ( self::cmp( $int, '0' ) <= 0 ) {
			return false;
		}
		$minimum = self::mul( '100000', self::pow10( $scale ) );
		$maximum = self::mul( '100000000', self::pow10( $scale ) );
		return self::cmp( $int, $minimum ) >= 0 && self::cmp( $int, $maximum ) <= 0;
	}

	/**
	 * Whether a decimal is strictly positive.
	 *
	 * @param string $value Decimal.
	 * @return bool
	 */
	public static function is_positive_decimal( $value ) {
		try {
			list( $int, $scale ) = self::decimal_parts( $value, 8 );
			unset( $scale );
		} catch ( InvalidArgumentException $error ) {
			unset( $error );
			return false;
		}
		return self::cmp( $int, '0' ) > 0;
	}

	/**
	 * Stable decimal text with at most $max_scale fractional digits.
	 *
	 * @param string $value     Decimal.
	 * @param int    $max_scale Maximum fractional digits.
	 * @return string
	 * @throws InvalidArgumentException When the value is not a positive decimal.
	 */
	public static function canonical_decimal( $value, $max_scale ) {
		list( $int, $scale ) = self::decimal_parts( $value, $max_scale );
		if ( self::cmp( $int, '0' ) <= 0 ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}
		return self::format_scaled( $int, $scale, true );
	}

	/**
	 * Convert a non-USD total into a US dollar amount Voybit can charge.
	 *
	 * $units_per_usd is how many source units equal one US dollar. For toman
	 * prices pass the rial rate and $amount_times of 10.
	 *
	 * @param string $amount        Store total.
	 * @param string $units_per_usd Source units per one US dollar.
	 * @param string $amount_times  1, or 10 when the total is in tomans.
	 * @return array{fiat_amount: string, fiat_currency: string, per_usd: string}
	 * @throws InvalidArgumentException When the total cannot be priced in US dollars.
	 */
	public static function to_usd( $amount, $units_per_usd, $amount_times = '1' ) {
		$amount_times = (string) $amount_times;
		if ( '1' !== $amount_times && '10' !== $amount_times ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		list( $amount_int, $amount_scale ) = self::decimal_parts( $amount, 8 );
		list( $rate_int, $rate_scale )     = self::decimal_parts( $units_per_usd, 8 );
		if ( self::cmp( $amount_int, '0' ) <= 0 || self::cmp( $rate_int, '0' ) <= 0 ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}
		if ( '10' === $amount_times ) {
			$amount_int = self::mul( $amount_int, '10' );
		}

		$numerator   = self::mul( self::mul( $amount_int, '100' ), self::pow10( $rate_scale ) );
		$denominator = self::mul( self::pow10( $amount_scale ), $rate_int );
		$cents       = self::div_round_half_up( $numerator, $denominator );
		if ( self::cmp( $cents, '1' ) < 0 ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		$usd     = self::from( self::cents_to_usd( $cents ), 'USD' );
		$per_usd = self::format_scaled( $rate_int, $rate_scale + ( '10' === $amount_times ? 1 : 0 ), true );
		return array(
			'fiat_amount'   => $usd['fiat_amount'],
			'fiat_currency' => 'USD',
			'per_usd'       => $per_usd,
		);
	}

	/**
	 * Split a positive decimal into an integer and its scale.
	 *
	 * Extra fractional digits are rounded half up.
	 *
	 * @param string $value     Decimal text.
	 * @param int    $max_scale Maximum scale to keep.
	 * @return array{0: string, 1: int}
	 * @throws InvalidArgumentException When the text is not a decimal.
	 */
	private static function decimal_parts( $value, $max_scale ) {
		$value = trim( (string) $value );
		if ( $max_scale < 0 || $max_scale > 8 || ! preg_match( '/^(?:0|[1-9]\d*)(?:\.(\d+))?$/', $value ) ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}

		$pieces   = explode( '.', $value, 2 );
		$whole    = ltrim( $pieces[0], '0' );
		$fraction = isset( $pieces[1] ) ? $pieces[1] : '';
		if ( '' === $whole ) {
			$whole = '0';
		}
		if ( strlen( $fraction ) > $max_scale ) {
			$keep = substr( $fraction, 0, $max_scale );
			$next = substr( $fraction, $max_scale, 1 );
			if ( '' !== $next && $next >= '5' ) {
				$scaled = self::add( $whole . str_pad( $keep, $max_scale, '0' ), '1' );
				return array( $scaled, $max_scale );
			}
			$fraction = $keep;
		}

		$int = ltrim( $whole . $fraction, '0' );
		return array( '' === $int ? '0' : $int, strlen( $fraction ) );
	}

	/**
	 * Format an integer that has $scale fractional digits.
	 *
	 * @param string $int   Integer digits.
	 * @param int    $scale Fractional digits.
	 * @param bool   $trim  Drop trailing fractional zeros.
	 * @return string
	 */
	private static function format_scaled( $int, $scale, $trim ) {
		$int = ltrim( (string) $int, '0' );
		if ( '' === $int ) {
			$int = '0';
		}
		if ( $scale < 1 ) {
			return $int;
		}
		if ( strlen( $int ) <= $scale ) {
			$int = str_pad( $int, $scale + 1, '0', STR_PAD_LEFT );
		}
		$whole    = substr( $int, 0, strlen( $int ) - $scale );
		$fraction = substr( $int, -$scale );
		if ( $trim ) {
			$fraction = rtrim( $fraction, '0' );
		}
		if ( '' === $fraction ) {
			return $whole;
		}
		return $whole . '.' . $fraction;
	}

	/**
	 * Format a cent total as a two-decimal US dollar amount.
	 *
	 * @param string $cents Positive cent count.
	 * @return string
	 */
	private static function cents_to_usd( $cents ) {
		if ( strlen( $cents ) <= 2 ) {
			$cents = str_pad( $cents, 3, '0', STR_PAD_LEFT );
		}
		$whole = ltrim( substr( $cents, 0, -2 ), '0' );
		return ( '' === $whole ? '0' : $whole ) . '.' . substr( $cents, -2 );
	}

	/**
	 * Compare two non-negative integers.
	 *
	 * @param string $left  Integer.
	 * @param string $right Integer.
	 * @return int
	 */
	private static function cmp( $left, $right ) {
		$left  = ltrim( (string) $left, '0' );
		$right = ltrim( (string) $right, '0' );
		if ( '' === $left ) {
			$left = '0';
		}
		if ( '' === $right ) {
			$right = '0';
		}
		if ( strlen( $left ) !== strlen( $right ) ) {
			return strlen( $left ) < strlen( $right ) ? -1 : 1;
		}
		if ( $left === $right ) {
			return 0;
		}
		return $left < $right ? -1 : 1;
	}

	/**
	 * Add two non-negative integers.
	 *
	 * @param string $left  Integer.
	 * @param string $right Integer.
	 * @return string
	 */
	private static function add( $left, $right ) {
		$carry = 0;
		$i     = strlen( (string) $left ) - 1;
		$j     = strlen( (string) $right ) - 1;
		$out   = '';
		while ( $i >= 0 || $j >= 0 || $carry > 0 ) {
			$number = $carry;
			if ( $i >= 0 ) {
				$number += (int) $left[ $i ];
				--$i;
			}
			if ( $j >= 0 ) {
				$number += (int) $right[ $j ];
				--$j;
			}
			$out   = (string) ( $number % 10 ) . $out;
			$carry = intdiv( $number, 10 );
		}
		$out = ltrim( $out, '0' );
		return '' === $out ? '0' : $out;
	}

	/**
	 * Subtract a smaller non-negative integer from a larger one.
	 *
	 * @param string $left  Integer.
	 * @param string $right Integer no larger than $left.
	 * @return string
	 */
	private static function sub( $left, $right ) {
		$borrow = 0;
		$i      = strlen( (string) $left ) - 1;
		$j      = strlen( (string) $right ) - 1;
		$out    = '';
		while ( $i >= 0 ) {
			$number = (int) $left[ $i ] - $borrow - ( $j >= 0 ? (int) $right[ $j ] : 0 );
			if ( $number < 0 ) {
				$number += 10;
				$borrow  = 1;
			} else {
				$borrow = 0;
			}
			$out = (string) $number . $out;
			--$i;
			--$j;
		}
		$out = ltrim( $out, '0' );
		return '' === $out ? '0' : $out;
	}

	/**
	 * Multiply two non-negative integers.
	 *
	 * @param string $left  Integer.
	 * @param string $right Integer.
	 * @return string
	 */
	private static function mul( $left, $right ) {
		$left  = ltrim( (string) $left, '0' );
		$right = ltrim( (string) $right, '0' );
		if ( '' === $left || '' === $right ) {
			return '0';
		}
		$result = '0';
		$shift  = '';
		for ( $i = strlen( $right ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $right[ $i ];
			if ( 0 !== $digit ) {
				$result = self::add( $result, self::mul_digit( $left, $digit ) . $shift );
			}
			$shift .= '0';
		}
		return $result;
	}

	/**
	 * Multiply an integer by one digit.
	 *
	 * @param string $value Integer.
	 * @param int    $digit Digit from 1 to 9.
	 * @return string
	 */
	private static function mul_digit( $value, $digit ) {
		$carry = 0;
		$out   = '';
		for ( $i = strlen( $value ) - 1; $i >= 0; $i-- ) {
			$number = ( (int) $value[ $i ] * $digit ) + $carry;
			$out    = (string) ( $number % 10 ) . $out;
			$carry  = intdiv( $number, 10 );
		}
		if ( $carry > 0 ) {
			$out = (string) $carry . $out;
		}
		return $out;
	}

	/**
	 * Divide and round half up.
	 *
	 * @param string $numerator   Integer.
	 * @param string $denominator Positive integer.
	 * @return string
	 * @throws InvalidArgumentException When the denominator is zero.
	 */
	private static function div_round_half_up( $numerator, $denominator ) {
		if ( self::cmp( $denominator, '0' ) <= 0 ) {
			throw new InvalidArgumentException( 'order total is not a valid amount' );
		}
		list( $quotient, $remainder ) = self::divmod( $numerator, $denominator );
		if ( self::cmp( self::mul( $remainder, '2' ), $denominator ) >= 0 ) {
			$quotient = self::add( $quotient, '1' );
		}
		return $quotient;
	}

	/**
	 * Integer division.
	 *
	 * @param string $numerator   Integer.
	 * @param string $denominator Positive integer.
	 * @return array{0: string, 1: string}
	 */
	private static function divmod( $numerator, $denominator ) {
		if ( self::cmp( $numerator, $denominator ) < 0 ) {
			return array( '0', ltrim( (string) $numerator, '0' ) === '' ? '0' : ltrim( (string) $numerator, '0' ) );
		}
		$quotient = '';
		$rest     = '0';
		$length   = strlen( (string) $numerator );
		for ( $i = 0; $i < $length; $i++ ) {
			$rest = ltrim( $rest, '0' ) . $numerator[ $i ];
			if ( '' === $rest ) {
				$rest = '0';
			}
			$digit = 0;
			while ( self::cmp( $rest, $denominator ) >= 0 && $digit < 10 ) {
				$rest = self::sub( $rest, $denominator );
				++$digit;
			}
			$quotient .= (string) $digit;
		}
		$quotient = ltrim( $quotient, '0' );
		$rest     = ltrim( $rest, '0' );
		return array( '' === $quotient ? '0' : $quotient, '' === $rest ? '0' : $rest );
	}

	/**
	 * Power of ten as an integer string.
	 *
	 * @param int $scale Exponent from 0 to 12.
	 * @return string
	 */
	private static function pow10( $scale ) {
		$scale = (int) $scale;
		if ( $scale < 1 ) {
			return '1';
		}
		return '1' . str_repeat( '0', $scale );
	}
}
