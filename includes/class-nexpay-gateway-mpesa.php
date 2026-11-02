<?php
/**
 * NexPay — M-Pesa gateway (Kenya), via the Safaricom Daraja API.
 *
 * Flow:
 *  1. GET  {base}/oauth/v1/generate?grant_type=client_credentials
 *     (Basic auth with the app Consumer Key / Secret) → access_token.
 *  2. POST {base}/mpesa/stkpush/v1/processrequest  (STK Push / "Lipa na
 *     M-Pesa Online") — the customer approves by entering their M-Pesa PIN
 *     on their phone. Password = base64(Shortcode + Passkey + Timestamp).
 *  3. Daraja posts the result to the callback URL (wc-api=nexpay_mpesa);
 *     ResultCode 0 means success and the order is completed with the
 *     M-Pesa receipt number.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * M-Pesa Daraja STK Push gateway.
 */
class NexPay_Gateway_Mpesa extends NexPay_Gateway {

	const LIVE_BASE    = 'https://api.safaricom.co.ke';
	const SANDBOX_BASE = 'https://sandbox.safaricom.co.ke';

	/**
	 * Daraja app credentials.
	 *
	 * @var string
	 */
	protected $consumer_key = '';
	protected $consumer_secret = '';
	protected $shortcode = '';
	protected $passkey = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nexpay_mpesa';
		$this->method_title       = __( 'NexPay — M-Pesa', 'nexpay' );
		$this->method_description = __( 'Accept M-Pesa payments (Kenya) via NexPay, using Safaricom Daraja STK Push.', 'nexpay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->brand_label        = 'M-PESA';
		$this->callback_slug      = 'nexpay_mpesa';
		$this->supported_currencies = array( 'KES' );

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->consumer_key    = $this->get_option( 'consumer_key' );
		$this->consumer_secret = $this->get_option( 'consumer_secret' );
		$this->shortcode       = $this->get_option( 'shortcode' );
		$this->passkey         = $this->get_option( 'passkey' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_nexpay_mpesa', array( $this, 'handle_callback' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array_merge(
			$this->standard_form_fields(
				__( 'M-Pesa', 'nexpay' ),
				__( 'You will receive an M-Pesa prompt on your phone to enter your PIN and pay.', 'nexpay' )
			),
			array(
				'consumer_key'    => array(
					'title' => __( 'Consumer Key', 'nexpay' ),
					'type'  => 'text',
				),
				'consumer_secret' => array(
					'title' => __( 'Consumer Secret', 'nexpay' ),
					'type'  => 'password',
				),
				'shortcode'       => array(
					'title'       => __( 'Business Shortcode', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'Your Lipa na M-Pesa shortcode (Paybill or Till number). Sandbox default: 174379.', 'nexpay' ),
					'desc_tip'    => true,
				),
				'passkey'         => array(
					'title' => __( 'Passkey', 'nexpay' ),
					'type'  => 'password',
				),
			)
		);
	}

	/**
	 * Checkout field: M-Pesa phone number.
	 */
	protected function render_checkout_fields() {
		$this->field_input( 'nexpay_mpesa_phone', __( 'M-Pesa Phone Number', 'nexpay' ), 'tel', '07XX XXX XXX', 'tel' );
	}

	/**
	 * Normalize a Kenyan phone number to the 2547XXXXXXXX format Daraja
	 * expects. Accepts 07…, 01…, +254… and 254… inputs.
	 *
	 * @param string $phone Raw input.
	 * @return string Normalized number, or empty string when invalid.
	 */
	public static function normalize_phone( $phone ) {
		$phone = preg_replace( '/\D+/', '', (string) $phone );

		if ( preg_match( '/^0([17]\d{8})$/', $phone, $m ) ) {
			return '254' . $m[1];
		}
		if ( preg_match( '/^254([17]\d{8})$/', $phone, $m ) ) {
			return '254' . $m[1];
		}

		return '';
	}

	/**
	 * Validate the checkout fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( '' === self::normalize_phone( $this->posted_field( 'nexpay_mpesa_phone' ) ) ) {
			wc_add_notice( __( 'Please enter a valid M-Pesa phone number (e.g. 0712 345 678).', 'nexpay' ), 'error' );
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
	 * Get a Daraja OAuth access token (cached in a transient for 50 minutes).
	 *
	 * @return string|WP_Error
	 */
	protected function get_access_token() {
		$cache_key = 'nexpay_mpesa_token_' . ( $this->testmode ? 'sandbox' : 'live' );
		$token     = get_transient( $cache_key );
		if ( $token ) {
			return $token;
		}

		$response = wp_remote_get(
			$this->base_url() . '/oauth/v1/generate?grant_type=client_credentials',
			array(
				'timeout' => 30,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required Basic auth encoding for Daraja.
					'Authorization' => 'Basic ' . base64_encode( $this->consumer_key . ':' . $this->consumer_secret ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$this->log( 'OAuth token request failed: ' . wp_remote_retrieve_body( $response ), 'error' );
			return new WP_Error( 'nexpay_mpesa_token', __( 'Could not authenticate with M-Pesa.', 'nexpay' ) );
		}

		set_transient( $cache_key, $body['access_token'], 50 * MINUTE_IN_SECONDS );

		return $body['access_token'];
	}

	/**
	 * Process the payment: send the STK Push and put the order on-hold
	 * until Daraja calls back with the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_failure( __( 'Order not found.', 'nexpay' ) );
		}

		if ( '' === $this->consumer_key || '' === $this->consumer_secret || '' === $this->shortcode || '' === $this->passkey ) {
			$this->log( 'Payment aborted: M-Pesa credentials are not configured.', 'error' );
			return $this->payment_failure( __( 'M-Pesa is not configured correctly. Please contact the store owner.', 'nexpay' ) );
		}

		$phone = self::normalize_phone( $this->posted_field( 'nexpay_mpesa_phone' ) );
		if ( '' === $phone ) {
			return $this->payment_failure( __( 'Please enter a valid M-Pesa phone number.', 'nexpay' ) );
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $this->payment_failure( __( 'Could not connect to M-Pesa. Please try again.', 'nexpay' ) );
		}

		$timestamp = current_time( 'YmdHis' );
		// Daraja password: base64( BusinessShortCode + Passkey + Timestamp ).
		$password = base64_encode( $this->shortcode . $this->passkey . $timestamp ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$payload = array(
			'BusinessShortCode' => $this->shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'CustomerPayBillOnline',
			'Amount'            => (int) round( $order->get_total() ),
			'PartyA'            => $phone,
			'PartyB'            => $this->shortcode,
			'PhoneNumber'       => $phone,
			'CallBackURL'       => $this->get_callback_url(),
			'AccountReference'  => (string) $order->get_id(),
			'TransactionDesc'   => sprintf( 'Order #%s', $order->get_order_number() ),
		);

		$response = wp_remote_post(
			$this->base_url() . '/mpesa/stkpush/v1/processrequest',
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'STK Push failed for order #' . $order_id . ': ' . $response->get_error_message(), 'error' );
			return $this->payment_failure( __( 'Could not send the M-Pesa prompt. Please try again.', 'nexpay' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['ResponseCode'] ) || '0' !== (string) $body['ResponseCode'] ) {
			$this->log( 'STK Push rejected for order #' . $order_id . ': ' . wp_remote_retrieve_body( $response ), 'warning' );
			$msg = isset( $body['errorMessage'] ) ? $body['errorMessage'] : ( isset( $body['ResponseDescription'] ) ? $body['ResponseDescription'] : '' );
			return $this->payment_failure(
				$msg ? sprintf( __( 'M-Pesa error: %s', 'nexpay' ), $msg ) : __( 'M-Pesa could not process the payment. Please try again.', 'nexpay' )
			);
		}

		$order->update_meta_data( '_nexpay_mpesa_phone', $phone );
		$order->update_meta_data( '_nexpay_mpesa_checkout_request_id', isset( $body['CheckoutRequestID'] ) ? $body['CheckoutRequestID'] : '' );
		$order->update_meta_data( '_nexpay_mpesa_merchant_request_id', isset( $body['MerchantRequestID'] ) ? $body['MerchantRequestID'] : '' );
		$order->update_status( 'on-hold', __( 'STK Push sent. Waiting for the customer to enter their M-Pesa PIN.', 'nexpay' ) );
		$order->save();

		WC()->cart->empty_cart();

		wc_add_notice( __( 'Please check your phone and enter your M-Pesa PIN to complete the payment.', 'nexpay' ), 'success' );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * STK Push callback handler (wc-api=nexpay_mpesa).
	 *
	 * Daraja posts JSON: Body.stkCallback { MerchantRequestID,
	 * CheckoutRequestID, ResultCode, ResultDesc, CallbackMetadata }.
	 */
	public function handle_callback() {
		$raw  = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the Daraja JSON callback body.
		$data = json_decode( $raw, true );

		$callback = isset( $data['Body']['stkCallback'] ) ? $data['Body']['stkCallback'] : null;
		if ( ! is_array( $callback ) || empty( $callback['CheckoutRequestID'] ) ) {
			$this->log( 'Callback received with unexpected payload: ' . $raw, 'warning' );
			status_header( 400 );
			exit;
		}

		$order = $this->get_order_by_meta( '_nexpay_mpesa_checkout_request_id', $callback['CheckoutRequestID'] );
		if ( ! $order ) {
			$this->log( 'Callback for unknown CheckoutRequestID: ' . $callback['CheckoutRequestID'], 'warning' );
			status_header( 404 );
			exit;
		}

		// Daraja always expects a 200 acknowledgement.
		status_header( 200 );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ) );

		if ( $order->is_paid() ) {
			exit;
		}

		$result_code = isset( $callback['ResultCode'] ) ? (int) $callback['ResultCode'] : -1;

		if ( 0 === $result_code ) {
			$receipt = '';
			if ( ! empty( $callback['CallbackMetadata']['Item'] ) && is_array( $callback['CallbackMetadata']['Item'] ) ) {
				foreach ( $callback['CallbackMetadata']['Item'] as $item ) {
					if ( isset( $item['Name'] ) && 'MpesaReceiptNumber' === $item['Name'] && isset( $item['Value'] ) ) {
						$receipt = (string) $item['Value'];
						break;
					}
				}
			}

			$order->payment_complete( '' !== $receipt ? $receipt : $callback['CheckoutRequestID'] );
			$order->add_order_note( sprintf( 'M-Pesa payment confirmed (receipt: %s).', '' !== $receipt ? $receipt : 'n/a' ) );
			$this->log( 'Callback confirmed payment for order #' . $order->get_id() );
		} else {
			$desc = isset( $callback['ResultDesc'] ) ? $callback['ResultDesc'] : 'Unknown error';
			$order->update_status( 'failed', sprintf( 'M-Pesa payment failed: %s', $desc ) );
			$this->log( 'Callback marked order #' . $order->get_id() . ' as failed: ' . $desc, 'warning' );
		}

		exit;
	}
}
