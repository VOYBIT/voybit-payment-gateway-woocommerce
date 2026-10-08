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
		$this->method_description = __( 'Customers choose an enabled asset and pay on the Voybit hosted checkout page. Saving the API key securely configures this store with Voybit.', 'voybit-for-woocommerce' );
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
				'description' => __( 'The WordPress site address must use HTTPS. Voybit stays hidden until the API key has configured this store successfully.', 'voybit-for-woocommerce' ),
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
			'api_base_url'    => array(
				'title'       => __( 'API base URL (advanced)', 'voybit-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Keep the default unless Voybit support gives you another HTTPS API base URL.', 'voybit-for-woocommerce' ),
				'default'     => Voybit_For_WooCommerce_Api::DEFAULT_API_BASE,
				'desc_tip'    => true,
			),
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
			WC_Admin_Settings::add_error( __( 'The API key contains characters Voybit does not use. Paste the key from the Voybit dashboard.', 'voybit-for-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $value;
	}

	/**
	 * API base must be an absolute HTTPS URL.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public function validate_api_base_url_field( $key, $value ) {
		unset( $key );
		$value = is_scalar( $value ) ? trim( (string) wc_clean( (string) $value ) ) : '';
		$base  = Voybit_For_WooCommerce_Api::normalize_base( $value );
		if ( '' === $base ) {
			WC_Admin_Settings::add_error( __( 'Enter a valid HTTPS Voybit API base URL.', 'voybit-for-woocommerce' ) );
			return $this->api_base_url();
		}
		return $base;
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
	 * Save settings and automatically configure the Voybit integration.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$previous_key    = (string) $this->get_option( 'api_key' );
		$previous_secret = (string) $this->get_option( 'webhook_secret' );
		$previous_base   = $this->api_base_url();
		$saved           = parent::process_admin_options();

		if ( '' === (string) $this->get_option( 'api_key' ) && '' !== $previous_key ) {
			$this->update_option( 'api_key', $previous_key );
		}

		$key  = $this->api_key();
		$base = $this->api_base_url();
		if ( '' !== $key && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$secret = Voybit_For_WooCommerce_Api::configure( $key, $base, $this->webhook_url(), $this->return_url() );
			if ( is_wp_error( $secret ) ) {
				WC_Admin_Settings::add_error( $secret->get_error_message() );
				if ( '' !== $previous_key && '' !== $previous_secret && ( $key !== $previous_key || $base !== $previous_base ) ) {
					$this->update_option( 'api_key', $previous_key );
					$this->update_option( 'api_base_url', $previous_base );
					$this->update_option( 'webhook_secret', $previous_secret );
					WC_Admin_Settings::add_error( __( 'The previous working Voybit configuration was kept.', 'voybit-for-woocommerce' ) );
				} elseif ( '' === $previous_secret ) {
					$this->update_option( 'webhook_secret', '' );
				}
			} else {
				$this->update_option( 'webhook_secret', $secret );
				delete_option( 'voybit_for_woocommerce_configuration_notice' );
			}
		}

		$settings = get_option( 'woocommerce_voybit_settings', array() );
		if ( is_array( $settings ) && isset( $settings['asset_id'] ) ) {
			unset( $settings['asset_id'] );
			update_option( 'woocommerce_voybit_settings', $settings );
			$this->settings = $settings;
		}

		if ( 'yes' === $this->get_option( 'enabled' ) ) {
			if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
				WC_Admin_Settings::add_error( __( 'Voybit needs the store address to use HTTPS. Update the WordPress site address, then enable Voybit.', 'voybit-for-woocommerce' ) );
				$this->update_option( 'enabled', 'no' );
			} elseif ( '' === $this->api_key() || '' === $this->webhook_secret() ) {
				WC_Admin_Settings::add_error( __( 'Save a valid API key so Voybit can configure the webhook before enabling this payment method.', 'voybit-for-woocommerce' ) );
				$this->update_option( 'enabled', 'no' );
			}
		}

		return $saved;
	}

	/**
	 * Hide Voybit until the store is on HTTPS and automatic setup has completed.
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
		return '' !== $this->api_key() && '' !== $this->webhook_secret();
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
	 * Saved API base URL.
	 *
	 * @return string
	 */
	public function api_base_url() {
		$base = Voybit_For_WooCommerce_Api::normalize_base( (string) $this->get_option( 'api_base_url', Voybit_For_WooCommerce_Api::DEFAULT_API_BASE ) );
		return '' !== $base ? $base : Voybit_For_WooCommerce_Api::DEFAULT_API_BASE;
	}

	/**
	 * Public webhook callback URL.
	 *
	 * @return string
	 */
	private function webhook_url() {
		return get_rest_url( null, 'voybit/v1/webhook' );
	}

	/**
	 * Customer return URL.
	 *
	 * @return string
	 */
	private function return_url() {
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'api_request_url' ) ) {
			return WC()->api_request_url( 'voybit_return' );
		}
		return add_query_arg( 'wc-api', 'voybit_return', home_url( '/' ) );
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

}
