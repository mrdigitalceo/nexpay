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
	 * Write a message to the WooCommerce log, tagged with this gateway.
	 *
	 * @param string $message Message to log.
	 * @param string $level   PSR-3 log level (debug, info, notice, warning, error...).
	 */
	protected function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'nexpay-' . $this->id ) );
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
	 * Read and sanitize one posted checkout field. WooCommerce's checkout
	 * nonce has already been verified by the time gateway fields are read.
	 *
	 * @param string $name Field name.
	 * @return string Sanitized value, or empty string when not posted.
	 */
	protected function posted_field( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce checkout.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
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
