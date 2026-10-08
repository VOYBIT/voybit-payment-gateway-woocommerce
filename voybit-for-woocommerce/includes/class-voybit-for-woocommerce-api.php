<?php
/**
 * Server-side calls to the Voybit payment API.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a hosted checkout for a WooCommerce order.
 */
class Voybit_For_WooCommerce_Api {

	const DEFAULT_API_BASE       = 'https://api.voybit.com/api';
	const PAYMENT_WINDOW_SECONDS = 900;

	/**
	 * Configure the gateway callback URLs and return the rotated webhook secret.
	 *
	 * @param string $api_key     Gateway API key.
	 * @param string $api_base    API base URL.
	 * @param string $webhook_url Public webhook callback.
	 * @param string $return_url  Customer return URL.
	 * @return string|WP_Error Webhook secret.
	 */
	public static function configure( $api_key, $api_base, $webhook_url, $return_url ) {
		$base = self::normalize_base( $api_base );
		if ( '' === $base || ! self::valid_https_url( $webhook_url ) || ! self::valid_https_url( $return_url ) ) {
			return new WP_Error( 'voybit_configuration_url', __( 'Voybit needs valid HTTPS webhook and return URLs.', 'voybit-for-woocommerce' ) );
		}

		$body = wp_json_encode(
			array(
				'webhook_url' => (string) $webhook_url,
				'return_url'  => (string) $return_url,
			)
		);
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'voybit_configuration_request', __( 'Voybit integration setup could not be prepared.', 'voybit-for-woocommerce' ) );
		}

		$response = self::post( $base . '/v1/gateway/integration/configure', $body, $api_key, '' );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'voybit_configuration_transport', __( 'Voybit could not be reached. Check the API base URL and try saving again.', 'voybit-for-woocommerce' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$error_code = ( is_array( $data ) && isset( $data['error']['code'] ) ) ? sanitize_key( (string) $data['error']['code'] ) : 'http_' . $code;
			return new WP_Error(
				'voybit_configuration_api',
				sprintf(
					/* translators: %s: safe API error code. */
					__( 'Voybit could not configure this store (%s). Check the API key and API base URL, then save again.', 'voybit-for-woocommerce' ),
					substr( $error_code ? $error_code : 'unknown', 0, 64 )
				)
			);
		}

		$secret = isset( $data['webhook_secret'] ) ? trim( (string) $data['webhook_secret'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,256}$/', $secret ) ) {
			return new WP_Error( 'voybit_configuration_response', __( 'Voybit configured the store but did not return a valid webhook secret. Save again or contact Voybit support.', 'voybit-for-woocommerce' ) );
		}
		return $secret;
	}

	/**
	 * Open checkout for an order, or reuse a checkout session that is still valid.
	 *
	 * @param WC_Order                         $order   Order being paid.
	 * @param Voybit_For_WooCommerce_Gateway $gateway Gateway settings.
	 * @return string|WP_Error Checkout URL.
	 */
	public static function checkout_url( $order, $gateway ) {
		$order_id = (int) $order->get_id();
		$key      = Voybit_For_WooCommerce_Request::for_order( $order_id );
		if ( '' === $key ) {
			return new WP_Error(
				'voybit_order',
				__( 'Voybit could not open checkout. Try again, or choose another payment method.', 'voybit-for-woocommerce' )
			);
		}

		if ( ! self::lock( $order_id ) ) {
			return new WP_Error(
				'voybit_lock',
				__( 'Voybit checkout is already starting. Wait a moment and try again.', 'voybit-for-woocommerce' )
			);
		}

		try {
			$existing = self::reusable_url( $order );
			if ( '' !== $existing ) {
				return $existing;
			}

			$total = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
			try {
				$priced = Voybit_For_WooCommerce_Amount::from( $total, $order->get_currency() );
			} catch ( InvalidArgumentException $error ) {
				unset( $error );
				return new WP_Error(
					'voybit_amount',
					__( 'This order total cannot be sent to Voybit.', 'voybit-for-woocommerce' )
				);
			}

			// Kept in English so a retry sends the same body as the first request.
			$description = substr( 'Order ' . $order->get_order_number(), 0, 500 );

			$body = wp_json_encode(
				array(
					'fiat_amount'            => $priced['fiat_amount'],
					'fiat_currency'          => $priced['fiat_currency'],
					'description'            => $description,
					'metadata'               => array(
						'cms'      => 'woocommerce',
						'order_id' => (string) $order_id,
					),
					'payment_window_seconds' => self::PAYMENT_WINDOW_SECONDS,
				)
			);
			if ( ! is_string( $body ) || '' === $body ) {
				return new WP_Error(
					'voybit_request',
					__( 'Voybit could not open checkout. Try again, or choose another payment method.', 'voybit-for-woocommerce' )
				);
			}

			$base = self::normalize_base( $gateway->api_base_url() );
			if ( '' === $base ) {
				return new WP_Error(
					'voybit_api_base',
					__( 'Voybit is not configured correctly. Ask the store administrator to check the API base URL.', 'voybit-for-woocommerce' )
				);
			}

			$response = self::post( $base . '/v1/gateway/checkout-sessions', $body, $gateway->api_key(), $key );
			if ( is_wp_error( $response ) ) {
				self::log( $order_id, 'transport' );
				return new WP_Error(
					'voybit_transport',
					__( 'Voybit could not open checkout. Try again, or choose another payment method.', 'voybit-for-woocommerce' )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
				$error_code = ( is_array( $data ) && isset( $data['error']['code'] ) ) ? (string) $data['error']['code'] : 'http_' . $code;
				self::log( $order_id, $error_code );
				$safe_code = substr( sanitize_key( $error_code ), 0, 64 );
				if ( '' === $safe_code ) {
					$safe_code = 'unknown';
				}
				$order->add_order_note(
					sprintf(
						/* translators: %s: error code */
						__( 'Voybit could not open checkout (%s).', 'voybit-for-woocommerce' ),
						$safe_code
					)
				);
				$order->save();
				return new WP_Error(
					'voybit_api',
					__( 'Voybit could not open checkout. Try again, or choose another payment method.', 'voybit-for-woocommerce' )
				);
			}

			$session_id   = isset( $data['session_id'] ) ? strtolower( (string) $data['session_id'] ) : '';
			$checkout_url = Voybit_For_WooCommerce_Checkout::canonical( isset( $data['checkout_url'] ) ? (string) $data['checkout_url'] : '' );
			$public_id    = isset( $data['public_id'] ) ? (string) $data['public_id'] : '';
			$from_url     = '' !== $checkout_url ? substr( $checkout_url, strlen( 'https://voybit.com/pay/' ) ) : '';
			if ( '' === $public_id ) {
				$public_id = $from_url;
			}
			$ids_match    = '' !== $from_url && strlen( $from_url ) === strlen( $public_id ) && hash_equals( $from_url, $public_id );
			if ( ! self::valid_uuid( $session_id ) || ! $ids_match ) {
				self::log( $order_id, 'invalid_checkout' );
				return new WP_Error(
					'voybit_checkout',
					__( 'Voybit could not open checkout. Try again, or choose another payment method.', 'voybit-for-woocommerce' )
				);
			}

			$expires = isset( $data['expires_at'] ) ? strtotime( (string) $data['expires_at'] ) : false;
			if ( ! is_int( $expires ) || $expires <= time() ) {
				$expires = time() + self::PAYMENT_WINDOW_SECONDS;
			}

			$order->update_meta_data( '_voybit_session_id', $session_id );
			$order->update_meta_data( '_voybit_public_id', $public_id );
			$order->update_meta_data( '_voybit_checkout_url', $checkout_url );
			$order->update_meta_data( '_voybit_expires_at', (string) $expires );
			$order->add_order_note( __( 'Voybit checkout is open.', 'voybit-for-woocommerce' ) );
			$order->save();
			return $checkout_url;
		} finally {
			self::unlock( $order_id );
		}
	}

	/**
	 * Checkout URL already stored on the order, when it is still inside its window.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function reusable_url( $order ) {
		$stored  = Voybit_For_WooCommerce_Checkout::canonical( (string) $order->get_meta( '_voybit_checkout_url' ) );
		$expires = (int) $order->get_meta( '_voybit_expires_at' );
		if ( '' === $stored || $expires <= time() + 30 ) {
			return '';
		}
		return $stored;
	}

	/**
	 * Normalize an HTTPS API base URL.
	 *
	 * @param string $url Candidate base URL.
	 * @return string
	 */
	public static function normalize_base( $url ) {
		$url   = trim( (string) $url );
		$parts = parse_url( $url );
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== strtolower( (string) $parts['scheme'] )
			|| '' === (string) $parts['host']
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( $port < 0 || $port > 65535 ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		if ( preg_match( '/[\x00-\x20\x7f]/', $path ) ) {
			return '';
		}
		return 'https://' . strtolower( (string) $parts['host'] ) . ( $port ? ':' . $port : '' ) . $path;
	}

	/**
	 * POST JSON, retrying only temporary failures.
	 *
	 * @param string $endpoint        Absolute endpoint URL.
	 * @param string $body            JSON body.
	 * @param string $api_key         Gateway API key.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error Raw HTTP response.
	 */
	private static function post( $endpoint, $body, $api_key, $idempotency_key ) {
		$last = null;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$headers = array(
				'X-Voybit-Api-Key' => $api_key,
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
				'User-Agent'       => 'voybit-for-woocommerce/' . VOYBIT_FOR_WOOCOMMERCE_VERSION,
			);
			if ( '' !== $idempotency_key ) {
				$headers['Idempotency-Key'] = $idempotency_key;
			}
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'headers'     => $headers,
					'body'        => $body,
				)
			);
			if ( is_wp_error( $response ) ) {
				$last = $response;
				if ( 3 === $attempt ) {
					return $response;
				}
				usleep( (int) min( 500 * ( 2 ** $attempt ), 8000 ) * 1000 );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( ( $code >= 200 && $code < 300 ) || ! in_array( $code, array( 408, 429, 500, 502, 503, 504 ), true ) || 3 === $attempt ) {
				return $response;
			}

			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $retry < 1 ) {
				$retry = (int) ceil( min( 500 * ( 2 ** $attempt ), 8000 ) / 1000 );
			}
			if ( $retry > 30 ) {
				$retry = 30;
			}
			if ( $retry < 1 ) {
				$retry = 1;
			}
			sleep( $retry );
			$last = $response;
		}
		return $last ? $last : new WP_Error( 'voybit_transport', 'transport' );
	}

	/**
	 * Whether a callback URL is absolute HTTPS.
	 *
	 * @param string $url Callback URL.
	 * @return bool
	 */
	private static function valid_https_url( $url ) {
		$parts = parse_url( trim( (string) $url ) );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( (string) $parts['scheme'] )
			&& '' !== (string) $parts['host']
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}

	/**
	 * Whether a string is a UUID.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function valid_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $value );
	}

	/**
	 * Record an error code without the API key or response body.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $error_code Stable error code.
	 */
	private static function log( $order_id, $error_code ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->warning(
			'Voybit checkout was not created.',
			array(
				'source' => 'voybit-for-woocommerce',
				'order'  => $order_id,
				'code'   => $error_code,
			)
		);
	}

	/**
	 * Stop two checkout requests for the same order from creating two payments.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private static function lock( $order_id ) {
		$name = 'voybit_for_woocommerce_order_' . $order_id;
		if ( add_option( $name, (string) time(), '', false ) ) {
			return true;
		}
		$existing = (int) get_option( $name, 0 );
		if ( $existing > 0 && $existing < time() - 60 ) {
			update_option( $name, (string) time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Release the order lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function unlock( $order_id ) {
		delete_option( 'voybit_for_woocommerce_order_' . $order_id );
	}
}
