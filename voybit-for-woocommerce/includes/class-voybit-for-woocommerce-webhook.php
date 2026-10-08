<?php
/**
 * Signed webhook that marks an order paid.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives Voybit webhooks. Voybit signs the raw body and does not send a WordPress nonce.
 */
class Voybit_For_WooCommerce_Webhook {

	/**
	 * Register the public REST route.
	 */
	public static function register() {
		register_rest_route(
			'voybit/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Allow the request only when the webhook signature matches.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > 65536 ) {
			return new WP_Error(
				'voybit_body',
				__( 'Webhook body is too large.', 'voybit-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$settings = get_option( 'woocommerce_voybit_settings', array() );
		$secret   = ( is_array( $settings ) && isset( $settings['webhook_secret'] ) ) ? (string) $settings['webhook_secret'] : '';
		$error    = Voybit_For_WooCommerce_Signature::error(
			$secret,
			self::header( $request, 'voybit-webhook-id' ),
			self::header( $request, 'voybit-webhook-timestamp' ),
			self::header( $request, 'voybit-webhook-signature' ),
			$body
		);
		if ( '' !== $error ) {
			return new WP_Error(
				'voybit_signature',
				__( 'Webhook signature is invalid.', 'voybit-for-woocommerce' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Fulfil paid and overpaid orders. Other signed events are acknowledged.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$webhook_id = self::header( $request, 'voybit-webhook-id' );
		$event      = json_decode( (string) $request->get_body(), true );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,64}$/', $webhook_id ) || ! is_array( $event ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$payment_id = isset( $event['payment_id'] ) ? strtolower( (string) $event['payment_id'] ) : '';
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $payment_id ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$status = isset( $event['status'] ) ? (string) $event['status'] : '';
		$fulfil = ( 'paid' === $status || 'overpaid' === $status );
		if ( ! self::lock( $payment_id ) ) {
			return new WP_REST_Response( null, 503 );
		}

		try {
			$order = self::order_for_payment( $payment_id );
			if ( ! $order && $fulfil ) {
				return new WP_REST_Response( null, 503 );
			}
			if ( $order && self::already_seen( $order, $webhook_id ) ) {
				return new WP_REST_Response( null, 204 );
			}
			if ( $order && $fulfil ) {
				self::fulfil( $order, $event );
			}
			if ( $order ) {
				self::remember( $order, $webhook_id );
			}
			return new WP_REST_Response( null, 204 );
		} finally {
			self::unlock( $payment_id );
		}
	}

	/**
	 * Mark the order paid when the public ID matches.
	 *
	 * @param WC_Order             $order Order found by payment ID.
	 * @param array<string, mixed> $event Webhook event.
	 */
	private static function fulfil( $order, $event ) {
		if ( 'voybit' !== $order->get_payment_method() ) {
			$order->add_order_note( __( 'Voybit webhook did not match this order payment method.', 'voybit-for-woocommerce' ) );
			$order->save();
			return;
		}

		$public_id = isset( $event['public_id'] ) ? (string) $event['public_id'] : '';
		$stored    = (string) $order->get_meta( '_voybit_public_id' );
		if ( '' === $public_id || strlen( $public_id ) !== strlen( $stored ) || ! hash_equals( $stored, $public_id ) ) {
			$order->add_order_note( __( 'Voybit webhook did not match this payment.', 'voybit-for-woocommerce' ) );
			$order->save();
			return;
		}

		if ( $order->has_status( 'cancelled' ) ) {
			$order->add_order_note( __( 'Voybit reported a confirmed payment after this order was canceled. Review it before shipping.', 'voybit-for-woocommerce' ) );
			$order->save();
			return;
		}
		if ( $order->has_status( array( 'processing', 'completed', 'refunded' ) ) ) {
			return;
		}

		$order->payment_complete( $public_id );
		$order->add_order_note( __( 'Voybit confirmed this payment.', 'voybit-for-woocommerce' ) );
		$order->save();
	}

	/**
	 * Find the order that stored this payment ID.
	 *
	 * @param string $payment_id Voybit payment ID.
	 * @return WC_Order|false
	 */
	private static function order_for_payment( $payment_id ) {
		$orders = wc_get_orders(
			array(
				'limit'        => 1,
				'meta_key'     => '_voybit_payment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $payment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
			)
		);
		if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
			return false;
		}
		return $orders[0];
	}

	/**
	 * Whether this delivery was already applied.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $webhook_id Delivery ID.
	 * @return bool
	 */
	private static function already_seen( $order, $webhook_id ) {
		$seen = $order->get_meta( '_voybit_webhook_ids' );
		return is_array( $seen ) && in_array( $webhook_id, $seen, true );
	}

	/**
	 * Remember a delivery so a repeat does not invoice the order twice.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $webhook_id Delivery ID.
	 */
	private static function remember( $order, $webhook_id ) {
		$seen = $order->get_meta( '_voybit_webhook_ids' );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		$seen[] = $webhook_id;
		if ( count( $seen ) > 30 ) {
			$seen = array_slice( $seen, -30 );
		}
		$order->update_meta_data( '_voybit_webhook_ids', $seen );
		$order->save();
	}

	/**
	 * Read one request header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Header name.
	 * @return string
	 */
	private static function header( $request, $name ) {
		$value = $request->get_header( $name );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Serialize webhook handling for one payment.
	 *
	 * @param string $payment_id Payment ID.
	 * @return bool
	 */
	private static function lock( $payment_id ) {
		$name = 'voybit_for_woocommerce_payment_' . $payment_id;
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
	 * Release the payment lock.
	 *
	 * @param string $payment_id Payment ID.
	 */
	private static function unlock( $payment_id ) {
		delete_option( 'voybit_for_woocommerce_payment_' . $payment_id );
	}
}
