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
	 * Fulfil paid, overpaid, and completed orders. Other signed events are acknowledged.
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

		$status = isset( $event['status'] ) ? strtolower( (string) $event['status'] ) : '';
		$fulfil = in_array( $status, array( 'paid', 'overpaid', 'completed' ), true );
		if ( ! self::lock( $payment_id ) ) {
			return new WP_REST_Response( null, 503 );
		}

		try {
			$order = self::order_for_event( $event, $payment_id );
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
	 * Mark the order paid when the signed event matches its checkout session.
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

		$payment_id = isset( $event['payment_id'] ) ? strtolower( (string) $event['payment_id'] ) : '';
		if ( ! self::event_matches_order( $order, $event, $payment_id ) ) {
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

		$order->update_meta_data( '_voybit_payment_id', $payment_id );
		$order->payment_complete( $payment_id );
		$order->add_order_note( __( 'Voybit confirmed this payment.', 'voybit-for-woocommerce' ) );
		$order->save();
	}

	/**
	 * Find the order linked to a signed payment event.
	 *
	 * @param array<string, mixed> $event      Webhook event.
	 * @param string               $payment_id Voybit payment ID.
	 * @return WC_Order|false
	 */
	private static function order_for_event( $event, $payment_id ) {
		$order = self::order_for_meta( '_voybit_payment_id', $payment_id );
		if ( $order ) {
			return $order;
		}

		$session_id = self::session_id( $event );
		if ( self::valid_uuid( $session_id ) ) {
			$order = self::order_for_meta( '_voybit_session_id', $session_id );
			if ( $order ) {
				return $order;
			}
		}

		$metadata = isset( $event['metadata'] ) && is_array( $event['metadata'] ) ? $event['metadata'] : array();
		$order_id = isset( $metadata['order_id'] ) ? (string) $metadata['order_id'] : '';
		if ( preg_match( '/^[1-9][0-9]{0,17}$/', $order_id ) ) {
			$order = wc_get_order( (int) $order_id );
			return $order instanceof WC_Order ? $order : false;
		}
		return false;
	}

	/**
	 * Find one order by an internal Voybit metadata value.
	 *
	 * @param string $key   Metadata key.
	 * @param string $value Metadata value.
	 * @return WC_Order|false
	 */
	private static function order_for_meta( $key, $value ) {
		if ( '' === (string) $value ) {
			return false;
		}
		$orders = wc_get_orders(
			array(
				'limit'        => 1,
				'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
			)
		);
		return ! empty( $orders ) && $orders[0] instanceof WC_Order ? $orders[0] : false;
	}

	/**
	 * Verify that an event references the session stored on an order.
	 *
	 * @param WC_Order             $order      Order.
	 * @param array<string, mixed> $event      Webhook event.
	 * @param string               $payment_id Payment UUID.
	 * @return bool
	 */
	private static function event_matches_order( $order, $event, $payment_id ) {
		$legacy_payment = strtolower( (string) $order->get_meta( '_voybit_payment_id' ) );
		$stored_session = strtolower( (string) $order->get_meta( '_voybit_session_id' ) );
		$event_session  = self::session_id( $event );
		$stored_public  = (string) $order->get_meta( '_voybit_public_id' );
		$event_public   = isset( $event['checkout_public_id'] )
			? (string) $event['checkout_public_id']
			: ( isset( $event['public_id'] ) ? (string) $event['public_id'] : '' );

		$linked = self::same( $legacy_payment, $payment_id ) || self::same( $stored_session, $event_session );
		if ( ! $linked && '' !== $stored_public && '' !== $event_public ) {
			$linked = self::same( $stored_public, $event_public );
		}
		if ( ! $linked ) {
			return false;
		}
		return '' === $stored_public || '' === $event_public || self::same( $stored_public, $event_public );
	}

	/**
	 * Checkout session UUID carried by the webhook.
	 *
	 * @param array<string, mixed> $event Webhook event.
	 * @return string
	 */
	private static function session_id( $event ) {
		$value = isset( $event['session_id'] ) ? $event['session_id'] : ( isset( $event['checkout_session_id'] ) ? $event['checkout_session_id'] : '' );
		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Constant-time comparison for two non-empty identifiers.
	 *
	 * @param string $left  First value.
	 * @param string $right Second value.
	 * @return bool
	 */
	private static function same( $left, $right ) {
		return '' !== $left && strlen( $left ) === strlen( $right ) && hash_equals( $left, $right );
	}

	/**
	 * Whether a value is a UUID.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function valid_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $value );
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
