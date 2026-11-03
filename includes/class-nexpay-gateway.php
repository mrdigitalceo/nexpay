<?php
/**
 * NexPay base gateway class.
 *
 * Shared behaviour for every NexPay gateway: test-mode handling, endpoint
 * selection, checkout field rendering, field validation helpers, logging and
 * cryptographic helpers (HMAC-SHA256 signing and timing-safe comparisons).
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all NexPay payment gateways.
 */
abstract class NexPay_Gateway extends WC_Payment_Gateway {

	/**
	 * Whether the gateway is in sandbox / test mode.
	 *
	 * @var bool
	 */
	protected $testmode = false;

	/**
	 * Currencies this gateway can process (ISO 4217 codes).
	 *
	 * @var string[]
	 */
	protected $supported_currencies = array();

	/**
	 * Brand label shown as a badge next to the gateway title at checkout
	 * (e.g. "JazzCash"). Leave empty for no badge.
	 *
	 * @var string
	 */
	protected $brand_label = '';

	/**
	 * The wc-api callback slug for this gateway, e.g. "nexpay_jazzcash"
	 * (URL: /?wc-api=nexpay_jazzcash). Empty when the gateway has no callback.
	 *
	 * @var string
	 */
	protected $callback_slug = '';

	/**
	 * Common form fields every gateway starts with: enabled, title,
	 * description and the test-mode toggle. Gateways append their own
	 * credential fields to this list.
	 *
	 * @param string $default_title   Default checkout title.
	 * @param string $default_desc    Default checkout description.
	 * @return array
	 */
	protected function standard_form_fields( $default_title, $default_desc = '' ) {
		return array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'nexpay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment gateway', 'nexpay' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'nexpay' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'nexpay' ),
				'default'     => $default_title,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'nexpay' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'nexpay' ),
				'default'     => $default_desc,
				'desc_tip'    => true,
			),
			'testmode'    => array(
				'title'       => __( 'Test mode', 'nexpay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable sandbox / test mode', 'nexpay' ),
				'default'     => 'yes',
				'description' => __( 'Use the gateway sandbox environment for safe testing. No real money moves while test mode is on.', 'nexpay' ),
			),
		);
	}

	/**
	 * Read the shared options after init_settings() has run.
	 */
	protected function load_common_settings() {
		$this->testmode = 'yes' === $this->get_option( 'testmode', 'yes' );
	}

	/**
	 * Whether this gateway is running in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return $this->testmode;
	}

	/**
	 * Pick the live or sandbox endpoint based on the test-mode flag.
	 *
	 * @param string $live    Production endpoint URL.
	 * @param string $sandbox Sandbox endpoint URL.
	 * @return string
	 */
	protected function endpoint( $live, $sandbox ) {
		return $this->testmode ? $sandbox : $live;
	}

	/**
	 * Absolute URL of this gateway's WooCommerce API callback endpoint.
	 *
	 * @return string
	 */
	public function get_callback_url() {
		return add_query_arg( 'wc-api', $this->callback_slug, home_url( '/' ) );
	}

	/**
	 * Checkout data captured from the WooCommerce Blocks (Store API)
	 * checkout, keyed by gateway id then field name. The Store API hook in
	 * nexpay.php fills this in before process_payment() runs, so
	 * posted_field() works for block-checkout orders too.
	 *
	 * @var array
	 */
	protected static $blocks_request_data = array();

	/**
	 * Map of Blocks paymentMethodData keys => order meta keys for this
	 * gateway. Gateways override this. Used by the Store API hook to copy
	 * block-checkout field values onto the order.
	 *
	 * @var array
	 */
	protected $blocks_fields = array();

	/**
	 * Gateway id => settlement currency map for all NexPay gateways.
	 *
	 * @return array
	 */
	public static function currency_map() {
		return array(
			'nexpay_jazzcash'  => 'PKR',
			'nexpay_easypaisa' => 'PKR',
			'nexpay_bkash'     => 'BDT',
			'nexpay_nagad'     => 'BDT',
			'nexpay_upi'       => 'INR',
			'nexpay_mpesa'     => 'KES',
		);
	}

	/**
	 * Convert a decimal amount into minor units (e.g. paisa for PKR).
	 *
	 * @param float|string $amount Decimal amount.
	 * @return int
	 */
	public static function amount_in_minor_units( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Format an amount the way providers expect it: 2 decimals, dot
	 * separator, no thousands separator.
	 *
	 * @param float|string $amount Decimal amount.
	 * @return string
	 */
	public static function format_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Pakistani mobile number: 03XXXXXXXXX (11 digits) or the same number
	 * in +92 / 92 international form.
	 *
	 * @param string $mobile Raw input.
	 * @return bool
	 */
	public static function is_valid_pk_mobile( $mobile ) {
		$mobile = preg_replace( '/[\s\-]+/', '', (string) $mobile );
		return (bool) preg_match( '/^(?:\+?92|0)(3\d{9})$/', $mobile );
	}

	/**
	 * Bangladeshi mobile number: 01[3-9]XXXXXXXX (11 digits).
	 *
	 * @param string $mobile Raw input.
	 * @return bool
	 */
	public static function is_valid_bd_mobile( $mobile ) {
		$mobile = preg_replace( '/\D+/', '', (string) $mobile );
		return (bool) preg_match( '/^01[3-9]\d{8}$/', $mobile );
	}

	/**
	 * CNIC last-6 fragment: exactly 6 digits.
	 *
	 * @param string $cnic Raw input.
	 * @return bool
	 */
	public static function is_valid_cnic_last6( $cnic ) {
		return (bool) preg_match( '/^\d{6}$/', preg_replace( '/\D+/', '', (string) $cnic ) );
	}

	/**
	 * Write a message to the WooCommerce log, tagged with this gateway.
	 * All payloads pass through the redacting NexPay_Logger, so secrets
	 * are never written to the log.
	 *
	 * @param mixed  $message Message or structured payload to log.
	 * @param string $level   PSR-3 log level (debug, info, notice, warning, error...).
	 */
	protected function log( $message, $level = 'info' ) {
		if ( class_exists( 'NexPay_Logger' ) ) {
			NexPay_Logger::log( 'nexpay-' . $this->id, $message, $level );
		} elseif ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, is_string( $message ) ? $message : wp_json_encode( $message ), array( 'source' => 'nexpay-' . $this->id ) );
		}
	}

	/**
	 * HMAC-SHA256 signature helper.
	 *
	 * @param string $data Data to sign.
	 * @param string $key  Secret key.
	 * @return string Lowercase hex digest.
	 */
	protected function hmac_sha256( $data, $key ) {
		return hash_hmac( 'sha256', $data, $key );
	}

	/**
	 * Timing-safe string comparison (used when checking hashes/signatures).
	 *
	 * @param string $known   Known (locally computed) string.
	 * @param string $unknown String received from the remote party.
	 * @return bool
	 */
	protected function hash_matches( $known, $unknown ) {
		return hash_equals( (string) $known, (string) $unknown );
	}

	/**
	 * Append a brand badge to the gateway title on the checkout page,
	 * matching the NexPay checkout design (brand-coloured pill on the right).
	 *
	 * @return string
	 */
	public function get_title() {
		$title = parent::get_title();

		if ( '' !== $this->brand_label && function_exists( 'is_checkout' ) && is_checkout() && ! is_admin() ) {
			$title .= sprintf(
				' <span class="nexpay-badge nexpay-badge--%1$s">%2$s</span>',
				esc_attr( $this->id ),
				esc_html( $this->brand_label )
			);
		}

		return $title;
	}

	/**
	 * Restrict the gateway to the currencies it can actually settle in.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! empty( $this->supported_currencies ) && function_exists( 'get_woocommerce_currency' ) ) {
			return in_array( get_woocommerce_currency(), $this->supported_currencies, true );
		}

		return true;
	}

	/**
	 * Render the gateway description followed by its checkout fields.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( $this->testmode ) {
			echo '<p class="nexpay-testmode-note">' . esc_html__( 'TEST MODE — no real payment will be taken.', 'nexpay' ) . '</p>';
		}

		$this->render_checkout_fields();
	}

	/**
	 * Gateways override this to print their checkout input fields.
	 */
	protected function render_checkout_fields() {}

	/**
	 * Print one labelled text input in the NexPay checkout style.
	 *
	 * @param string $name        Field name (also used as the element id).
	 * @param string $label       Visible label.
	 * @param string $type        Input type (tel, text...).
	 * @param string $placeholder Placeholder text.
	 * @param string $inputmode   Optional inputmode attribute for mobile keyboards.
	 */
	protected function field_input( $name, $label, $type = 'tel', $placeholder = '', $inputmode = '' ) {
		printf(
			'<p class="form-row form-row-wide nexpay-field"><label for="%1$s">%2$s&nbsp;<span class="required" aria-hidden="true">*</span></label><input type="%3$s" class="input-text nexpay-input" name="%4$s" id="%1$s" placeholder="%5$s" value="" autocomplete="off"%6$s /></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $placeholder ),
			'' !== $inputmode ? ' inputmode="' . esc_attr( $inputmode ) . '"' : ''
		);
	}

	/**
	 * Store Blocks (Store API) checkout data for a gateway so that
	 * posted_field() can read it during process_payment(). Called by the
	 * woocommerce_store_api_checkout_update_order_from_request hook.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param array  $data       Field name => raw value.
	 */
	public static function set_blocks_request_data( $gateway_id, array $data ) {
		self::$blocks_request_data[ $gateway_id ] = $data;
	}

	/**
	 * The Blocks field map for this gateway (data key => order meta key).
	 *
	 * @return array
	 */
	public function get_blocks_fields() {
		return $this->blocks_fields;
	}

	/**
	 * Copy Blocks checkout field values onto the order (as meta) and into
	 * the request-scoped store. Called from the Store API hook.
	 *
	 * @param WC_Order $order Order being checked out.
	 * @param array    $data  paymentMethodData (field name => raw value).
	 */
	public function save_blocks_checkout_data( $order, array $data ) {
		$clean = array();
		foreach ( $this->blocks_fields as $field => $meta_key ) {
			if ( isset( $data[ $field ] ) ) {
				$value = sanitize_text_field( wp_unslash( $data[ $field ] ) );
				$clean[ $field ] = $value;
				$order->update_meta_data( $meta_key, $value );
			}
		}
		self::set_blocks_request_data( $this->id, $clean );
	}

	/**
	 * Read and sanitize one posted checkout field. WooCommerce's checkout
	 * nonce has already been verified by the time gateway fields are read.
	 * Falls back to the Blocks (Store API) request data when the value did
	 * not arrive via $_POST.
	 *
	 * @param string $name Field name.
	 * @return string Sanitized value, or empty string when not posted.
	 */
	protected function posted_field( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce checkout.
		if ( isset( $_POST[ $name ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
		}

		if ( isset( self::$blocks_request_data[ $this->id ][ $name ] ) ) {
			return sanitize_text_field( self::$blocks_request_data[ $this->id ][ $name ] );
		}

		return '';
	}

	/**
	 * Settings keys that must be filled in before this gateway may be
	 * enabled (key => human label). Gateways override.
	 *
	 * @return array
	 */
	protected function required_settings_fields() {
		return array();
	}

	/**
	 * After settings are saved, warn the admin (without exposing secrets)
	 * when the gateway is enabled but required credentials are missing.
	 */
	public function process_admin_options() {
		parent::process_admin_options();

		if ( 'yes' !== $this->get_option( 'enabled', 'no' ) ) {
			return;
		}

		$missing = array();
		foreach ( $this->required_settings_fields() as $key => $label ) {
			if ( '' === trim( (string) $this->get_option( $key, '' ) ) ) {
				$missing[] = $label;
			}
		}

		if ( ! empty( $missing ) && class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: gateway title, 2: comma-separated missing field labels */
					__( '%1$s is enabled but these required settings are empty: %2$s. Payments will fail until they are filled in.', 'nexpay' ),
					$this->method_title,
					implode( ', ', $missing )
				)
			);
		}
	}

	/**
	 * Convenience wrapper for a failed process_payment() result.
	 *
	 * @param string $message Customer-facing error message.
	 * @return array
	 */
	protected function payment_failure( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Find an order by one of its meta values (used by callback handlers to
	 * map a gateway transaction reference back to the WooCommerce order).
	 *
	 * @param string $meta_key   Order meta key.
	 * @param string $meta_value Value to match.
	 * @return WC_Order|null
	 */
	protected function get_order_by_meta( $meta_key, $meta_value ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $orders ) ? $orders[0] : null;
	}
}
