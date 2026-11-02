<?php
/**
 * NexPay — bKash gateway (Bangladesh).
 *
 * Implements the bKash Tokenized Checkout API:
 *  1. POST {base}/token/grant   — exchange app credentials for an id_token.
 *  2. POST {base}/create        — create a payment, redirect the customer
 *                                 to the returned bkashURL.
 *  3. Callback (wc-api=nexpay_bkash) — POST {base}/execute to capture the
 *                                 payment, then complete the order.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * bKash tokenized checkout gateway.
 */
class NexPay_Gateway_BKash extends NexPay_Gateway {

	const LIVE_BASE    = 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';
	const SANDBOX_BASE = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout';

	/**
	 * API credentials.
	 *
	 * @var string
	 */
	protected $app_key = '';
	protected $app_secret = '';
	protected $username = '';
	protected $bkash_password = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nexpay_bkash';
		$this->method_title       = __( 'NexPay — bKash', 'nexpay' );
		$this->method_description = __( 'Accept bKash mobile wallet payments (Bangladesh) via NexPay, using the bKash tokenized checkout.', 'nexpay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->brand_label        = 'bKash';
		$this->callback_slug      = 'nexpay_bkash';
		$this->supported_currencies = array( 'BDT' );

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->app_key        = $this->get_option( 'app_key' );
		$this->app_secret     = $this->get_option( 'app_secret' );
		$this->username       = $this->get_option( 'username' );
		$this->bkash_password = $this->get_option( 'password' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_nexpay_bkash', array( $this, 'handle_callback' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array_merge(
			$this->standard_form_fields(
				__( 'bKash', 'nexpay' ),
				__( 'Pay securely with your bKash mobile wallet.', 'nexpay' )
			),
			array(
				'app_key'    => array(
					'title' => __( 'App Key', 'nexpay' ),
					'type'  => 'text',
				),
				'app_secret' => array(
					'title' => __( 'App Secret', 'nexpay' ),
					'type'  => 'password',
				),
				'username'   => array(
					'title' => __( 'Username', 'nexpay' ),
					'type'  => 'text',
				),
				'password'   => array(
					'title' => __( 'Password', 'nexpay' ),
					'type'  => 'password',
				),
			)
		);
	}

	/**
	 * Checkout field: bKash mobile number.
	 */
	protected function render_checkout_fields() {
		$this->field_input( 'nexpay_bkash_mobile', __( 'bKash Mobile Number', 'nexpay' ), 'tel', '01XXXXXXXXX', 'tel' );
	}

	/**
	 * Validate the checkout fields (Bangladeshi mobile: 11 digits, starts 01).
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_bkash_mobile' ) );

		if ( ! preg_match( '/^01[3-9]\d{8}$/', $mobile ) ) {
			wc_add_notice( __( 'Please enter a valid bKash mobile number (e.g. 01XXXXXXXXX).', 'nexpay' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Base URL for the current mode.
	 *
	 * @return string
	 */
	protected function base_url() {
		return $this->endpoint( self::LIVE_BASE, self::SANDBOX_BASE );
	}

	/**
	 * Get a bKash id_token, granting (and caching) one when needed.
	 * Tokens are cached in a transient for 50 minutes (they live for 1 hour).
	 *
	 * @return string|WP_Error
	 */
	protected function get_token() {
		$cache_key = 'nexpay_bkash_token_' . ( $this->testmode ? 'sandbox' : 'live' );
		$token     = get_transient( $cache_key );
		if ( $token ) {
			return $token;
		}

		$response = wp_remote_post(
			$this->base_url() . '/token/grant',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'username'     => $this->username,
					'password'     => $this->bkash_password,
				),
				'body'    => wp_json_encode(
					array(
						'app_key'    => $this->app_key,
						'app_secret' => $this->app_secret,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id_token'] ) ) {
			$this->log( 'Token grant failed: ' . wp_remote_retrieve_body( $response ), 'error' );
			return new WP_Error( 'nexpay_bkash_token', __( 'Could not authenticate with bKash.', 'nexpay' ) );
		}

		set_transient( $cache_key, $body['id_token'], 50 * MINUTE_IN_SECONDS );

		return $body['id_token'];
	}

	/**
	 * Authenticated API request helper.
	 *
	 * @param string $path API path, e.g. "/create".
	 * @param array  $data JSON body.
	 * @return array|WP_Error Decoded response body.
	 */
	protected function api_request( $path, array $data ) {
		$token = $this->get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			$this->base_url() . $path,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => $token,
					'X-APP-Key'     => $this->app_key,
				),
				'body'    => wp_json_encode( $data ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Process the payment: create the bKash payment and redirect the
	 * customer to the bKash checkout URL.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_failure( __( 'Order not found.', 'nexpay' ) );
		}

		if ( '' === $this->app_key || '' === $this->app_secret || '' === $this->username || '' === $this->bkash_password ) {
			$this->log( 'Payment aborted: bKash credentials are not configured.', 'error' );
			return $this->payment_failure( __( 'bKash is not configured correctly. Please contact the store owner.', 'nexpay' ) );
		}

		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_bkash_mobile' ) );
		$order->update_meta_data( '_nexpay_bkash_mobile', $mobile );

		$result = $this->api_request(
			'/create',
			array(
				'mode'                  => '0011', // Wallet checkout mode.
				'payerReference'        => $mobile,
				'callbackURL'           => $this->get_callback_url(),
				'amount'                => number_format( (float) $order->get_total(), 2, '.', '' ),
				'currency'              => 'BDT',
				'intent'                => 'sale',
				'merchantInvoiceNumber' => (string) $order->get_order_number(),
			)
		);

		if ( is_wp_error( $result ) || empty( $result['bkashURL'] ) || empty( $result['paymentID'] ) ) {
			$this->log( 'Create payment failed for order #' . $order_id . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result ) ), 'error' );
			return $this->payment_failure( __( 'Could not start the bKash payment. Please try again.', 'nexpay' ) );
		}

		$order->update_meta_data( '_nexpay_bkash_payment_id', $result['paymentID'] );
		$order->update_status( 'on-hold', __( 'Awaiting payment on bKash.', 'nexpay' ) );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $result['bkashURL'],
		);
	}

	/**
	 * Callback handler (wc-api=nexpay_bkash): bKash returns the customer
	 * with ?paymentID=…&status=success|failure|cancel. On success we execute
	 * the payment server-to-server before completing the order.
	 */
	public function handle_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- values are re-verified server-to-server via /execute.
		$payment_id = isset( $_GET['paymentID'] ) ? sanitize_text_field( wp_unslash( $_GET['paymentID'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		$order = $payment_id ? $this->get_order_by_meta( '_nexpay_bkash_payment_id', $payment_id ) : null;
		if ( ! $order ) {
			$this->log( 'Callback for unknown paymentID: ' . $payment_id, 'warning' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( 'success' !== $status ) {
			$order->update_status( 'cancelled' === $status ? 'cancelled' : 'failed', __( 'bKash payment was not completed.', 'nexpay' ) );
			wc_add_notice( __( 'The bKash payment was not completed. Please try again.', 'nexpay' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		if ( ! $order->is_paid() ) {
			$result = $this->api_request( '/execute', array( 'paymentID' => $payment_id ) );

			$ok = is_array( $result )
				&& isset( $result['statusCode'] ) && '0000' === (string) $result['statusCode']
				&& isset( $result['transactionStatus'] ) && 'Completed' === $result['transactionStatus'];

			if ( $ok ) {
				$order->payment_complete( isset( $result['trxID'] ) ? $result['trxID'] : $payment_id );
				$order->add_order_note( sprintf( 'bKash payment executed (trxID: %s).', isset( $result['trxID'] ) ? $result['trxID'] : $payment_id ) );
				$this->log( 'Payment executed for order #' . $order->get_id() );
			} else {
				$this->log( 'Execute failed for order #' . $order->get_id() . ': ' . wp_json_encode( $result ), 'error' );
				$order->update_status( 'failed', __( 'bKash could not execute the payment.', 'nexpay' ) );
				wc_add_notice( __( 'bKash could not complete your payment. Please try again.', 'nexpay' ), 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}
		}

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}
}
