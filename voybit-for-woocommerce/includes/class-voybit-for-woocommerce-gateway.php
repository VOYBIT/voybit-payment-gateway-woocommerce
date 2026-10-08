<?php
/**
 * WooCommerce payment method.
 *
 * @package Voybit_For_WooCommerce
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic checkout gateway. The API key is saved in WooCommerce settings and is never sent to the browser.
 */
class Voybit_For_WooCommerce_Gateway extends WC_Payment_Gateway {

	/**
	 * Register the gateway and its return route.
	 */
	public function __construct() {
		$this->id                 = 'voybit';
		$this->icon               = VOYBIT_FOR_WOOCOMMERCE_URL . 'assets/icon.svg';
		$this->has_fields         = false;
		$this->method_title       = __( 'Voybit', 'voybit-for-woocommerce' );
		$this->method_description = __( 'Customers pay on the Voybit page. Enter the API key, webhook secret, and asset ID from the Voybit dashboard. If setup does not match this page, contact Voybit support at https://voybit.com/contact.', 'voybit-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_voybit_return', array( $this, 'return_to_store' ) );
	}

	/**
	 * Settings shown under WooCommerce, Settings, Payments, Voybit.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'       => __( 'Enable Voybit', 'voybit-for-woocommerce' ),
				'label'       => __( 'Show Voybit on checkout', 'voybit-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'The WordPress site address must use HTTPS. Voybit stays hidden at checkout until the API key, webhook secret, and asset ID are saved.', 'voybit-for-woocommerce' ),
				'default'     => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'voybit-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Name customers see at checkout.', 'voybit-for-woocommerce' ),
				'default'     => __( 'Voybit', 'voybit-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'voybit-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Short note customers see under the payment method.', 'voybit-for-woocommerce' ),
				'default'     => __( 'You pay on the Voybit page. The store confirms the order when the payment arrives.', 'voybit-for-woocommerce' ),
			),
			'api_key'         => array(
				'title'       => __( 'API key', 'voybit-for-woocommerce' ),
				'type'        => 'voybit_secret',
				'description' => __( 'Secret key from the Voybit dashboard, API keys. Leave this blank to keep the saved key.', 'voybit-for-woocommerce' ),
				'default'     => '',
				'placeholder' => 'vb_live_',
			),
			'webhook_secret'  => array(
				'title'       => __( 'Webhook secret', 'voybit-for-woocommerce' ),
				'type'        => 'voybit_secret',
				'description' => __( 'Secret shown once when you create the gateway. Leave this blank to keep the saved secret.', 'voybit-for-woocommerce' ),
				'default'     => '',
			),
			'asset_id'        => array(
				'title'       => __( 'Asset ID', 'voybit-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Asset ID from the same Voybit gateway. A USD store should use a stablecoin such as USDT. The order total is the amount of that asset.', 'voybit-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_url'     => array(
				'title'       => __( 'Webhook URL', 'voybit-for-woocommerce' ),
				'type'        => 'title',
				'description' => $this->endpoint_note(),
			),
		);
	}

	/**
	 * Show the URLs the merchant copies into the Voybit dashboard.
	 *
	 * @return string
	 */
	private function endpoint_note() {
		$webhook = esc_url( get_rest_url( null, 'voybit/v1/webhook' ) );
		$return  = function_exists( 'WC' ) ? esc_url( WC()->api_request_url( 'voybit_return' ) ) : '';
		return sprintf(
			/* translators: 1: webhook URL, 2: return URL */
			__( 'Copy the webhook URL into the Voybit gateway: %1$s. Copy the return URL into the same gateway: %2$s. Both addresses must use HTTPS.', 'voybit-for-woocommerce' ),
			'<code>' . $webhook . '</code>',
			'<code>' . $return . '</code>'
		);
	}

	/**
	 * Password field that never prints the saved secret.
	 *
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $data Field config.
	 * @return string
	 */
	public function generate_voybit_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
				'placeholder' => '',
			)
		);
		$placeholder = '' !== (string) $this->get_option( $key )
			? __( 'Saved. Enter a new value to replace it.', 'voybit-for-woocommerce' )
			: (string) $data['placeholder'];

		$html  = '<tr valign="top"><th scope="row" class="titledesc">';
		$html .= '<label for="' . esc_attr( $field_key ) . '">' . esc_html( $data['title'] ) . '</label>';
		$html .= '</th><td class="forminp"><fieldset>';
		$html .= '<legend class="screen-reader-text"><span>' . esc_html( $data['title'] ) . '</span></legend>';
		$html .= '<input class="input-text regular-input" type="password" name="' . esc_attr( $field_key ) . '" id="' . esc_attr( $field_key ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password" />';
		$html .= wp_kses_post( $this->get_description_html( $data ) );
		$html .= '</fieldset></td></tr>';
		return $html;
	}

	/**
	 * Accept a pasted secret, or an empty value that means "keep the saved one".
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public function validate_voybit_secret_field( $key, $value ) {
		$value = is_scalar( $value ) ? trim( (string) wc_clean( (string) $value ) ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]+$/', $value ) || strlen( $value ) > 256 ) {
			WC_Admin_Settings::add_error( __( 'The API key or webhook secret contains characters Voybit does not use. Paste the value from the Voybit dashboard.', 'voybit-for-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $value;
	}

	/**
	 * Asset ID must be a UUID.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public function validate_asset_id_field( $key, $value ) {
		unset( $key );
		$value = is_scalar( $value ) ? strtolower( trim( (string) wc_clean( (string) $value ) ) ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( ! self::valid_uuid( $value ) ) {
			WC_Admin_Settings::add_error( __( 'Enter the asset ID shown on the Voybit gateway. It looks like a UUID.', 'voybit-for-woocommerce' ) );
			return (string) $this->get_option( 'asset_id' );
		}
		return $value;
	}

	/**
	 * Keep the checkout title short.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public function validate_title_field( $key, $value ) {
		unset( $key );
		$value = is_scalar( $value ) ? trim( (string) wc_clean( (string) $value ) ) : '';
		if ( '' === $value ) {
			return __( 'Voybit', 'voybit-for-woocommerce' );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 80 ) : substr( $value, 0, 80 );
	}

	/**
	 * Keep the checkout description short, with no markup.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public function validate_description_field( $key, $value ) {
		unset( $key );
		$value = is_scalar( $value ) ? trim( (string) wc_clean( (string) $value ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 300 ) : substr( $value, 0, 300 );
	}

	/**
	 * Keep a blank secret, and refuse an asset ID that is not a UUID.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$previous_key    = (string) $this->get_option( 'api_key' );
		$previous_secret = (string) $this->get_option( 'webhook_secret' );
		$previous_asset  = (string) $this->get_option( 'asset_id' );
		$saved           = parent::process_admin_options();

		if ( '' === (string) $this->get_option( 'api_key' ) && '' !== $previous_key ) {
			$this->update_option( 'api_key', $previous_key );
		}
		if ( '' === (string) $this->get_option( 'webhook_secret' ) && '' !== $previous_secret ) {
			$this->update_option( 'webhook_secret', $previous_secret );
		}

		$asset = strtolower( trim( (string) $this->get_option( 'asset_id' ) ) );
		if ( '' !== $asset && ! self::valid_uuid( $asset ) ) {
			WC_Admin_Settings::add_error( __( 'Enter the asset ID shown on the Voybit gateway. It looks like a UUID.', 'voybit-for-woocommerce' ) );
			$this->update_option( 'asset_id', $previous_asset );
			$asset = $previous_asset;
		} elseif ( $asset !== (string) $this->get_option( 'asset_id' ) ) {
			$this->update_option( 'asset_id', $asset );
		}

		if ( 'yes' === $this->get_option( 'enabled' ) ) {
			if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
				WC_Admin_Settings::add_error( __( 'Voybit needs the store address to use HTTPS. Update the WordPress site address, then enable Voybit.', 'voybit-for-woocommerce' ) );
				$this->update_option( 'enabled', 'no' );
			} elseif ( '' === (string) $this->get_option( 'api_key' ) || '' === (string) $this->get_option( 'webhook_secret' ) || ! self::valid_uuid( $asset ) ) {
				WC_Admin_Settings::add_error( __( 'Enter the API key, webhook secret, and asset ID before enabling Voybit.', 'voybit-for-woocommerce' ) );
				$this->update_option( 'enabled', 'no' );
			}
		}

		return $saved;
	}

	/**
	 * Hide Voybit until the store is on HTTPS and the three values are saved.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return false;
		}
		return '' !== $this->api_key() && '' !== $this->webhook_secret() && self::valid_uuid( $this->asset_id() );
	}

	/**
	 * Saved API key.
	 *
	 * @return string
	 */
	public function api_key() {
		return trim( (string) $this->get_option( 'api_key' ) );
	}

	/**
	 * Saved webhook secret.
	 *
	 * @return string
	 */
	public function webhook_secret() {
		return trim( (string) $this->get_option( 'webhook_secret' ) );
	}

	/**
	 * Saved asset ID.
	 *
	 * @return string
	 */
	public function asset_id() {
		return strtolower( trim( (string) $this->get_option( 'asset_id' ) ) );
	}

	/**
	 * Create the Voybit payment and send the customer to hosted checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, string>|array{result: string, redirect?: string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Voybit could not open checkout. Try again, or choose another payment method.', 'voybit-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$checkout_url = Voybit_For_WooCommerce_Api::checkout_url( $order, $this );
		if ( is_wp_error( $checkout_url ) ) {
			wc_add_notice( $checkout_url->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'order_awaiting_payment', $order->get_id() );
		}

		add_filter( 'allowed_redirect_hosts', 'voybit_for_woocommerce_allow_redirect_host' );
		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	/**
	 * Send the customer back to the order-received page. Payment is confirmed only by the webhook.
	 */
	public function return_to_store() {
		$order = null;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$order = wc_get_order( WC()->session->get( 'order_awaiting_payment' ) );
		}
		if ( $order instanceof WC_Order && 'voybit' === $order->get_payment_method() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Whether a string is a UUID.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function valid_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $value );
	}
}
