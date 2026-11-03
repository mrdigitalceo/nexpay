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
 *  3. Daraja posts the result to the callback URL (wc-api=nexpay_mpesa).
 *     The callback payload alone is NOT trusted: on a success callback the
 *     plugin confirms the final result server-to-server with the Daraja
 *     STK Push Query API, validates the amount against the order, and only
 *     then completes the order with the M-Pesa receipt number (which may
 *     never be reused on a second order).
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
		$this->blocks_fields      = array(
			'nexpay_mpesa_phone' => '_nexpay_mpesa_phone',
		);

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
					'type'  => 'password',
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
	 * Credentials required before this gateway may be enabled.
	 *
	 * @return array
	 */
	protected function required_settings_fields() {
		return array(
			'consumer_key'    => __( 'Consumer Key', 'nexpay' ),
			'consumer_secret' => __( 'Consumer Secret', 'nexpay' ),
			'shortcode'       => __( 'Business Shortcode', 'nexpay' ),
			'passkey'         => __( 'Passkey', 'nexpay' ),
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
				'timeout' => 30,
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
	 * Confirm an STK Push result server-to-server with the Daraja STK Push
	 * Query API.
	 *
	 * @param string $checkout_request_id CheckoutRequestID from the push.
	 * @return array|WP_Error Decoded query response.
	 */
	protected function query_stk_status( $checkout_request_id ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$timestamp = current_time( 'YmdHis' );
		$password  = base64_encode( $this->shortcode . $this->passkey . $timestamp ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Daraja password algorithm.

		$response = wp_remote_post(
			$this->base_url() . '/mpesa/stkpushquery/v1/query',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode(
					array(
						'BusinessShortCode' => $this->shortcode,
						'Password'          => $password,
						'Timestamp'         => $timestamp,
						'CheckoutRequestID' => $checkout_request_id,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : new WP_Error( 'nexpay_mpesa_query', __( 'Malformed STK query response.', 'nexpay' ) );
	}

	/**
	 * Extract one item from Daraja CallbackMetadata.
	 *
	 * @param array  $callback stkCallback payload.
	 * @param string $name     Metadata item name.
	 * @return string Value or empty string.
	 */
	protected function callback_meta_value( array $callback, $name ) {
		if ( ! empty( $callback['CallbackMetadata']['Item'] ) && is_array( $callback['CallbackMetadata']['Item'] ) ) {
			foreach ( $callback['CallbackMetadata']['Item'] as $item ) {
				if ( isset( $item['Name'] ) && $name === $item['Name'] && isset( $item['Value'] ) ) {
					return (string) $item['Value'];
				}
			}
		}
		return '';
	}

	/**
	 * Human-readable note for a failed/cancelled STK Push result code.
	 *
	 * @param int    $result_code Daraja ResultCode.
	 * @param string $desc        Daraja ResultDesc.
	 * @return string
	 */
	protected function failure_note( $result_code, $desc ) {
		$known = array(
			1    => __( 'M-Pesa payment failed: insufficient balance.', 'nexpay' ),
			1032 => __( 'M-Pesa payment cancelled by the customer.', 'nexpay' ),
			1037 => __( 'M-Pesa payment timed out: the phone could not be reached or the PIN was not entered in time.', 'nexpay' ),
			2001 => __( 'M-Pesa payment failed: invalid PIN or initiator details.', 'nexpay' ),
		);
		if ( isset( $known[ $result_code ] ) ) {
			return $known[ $result_code ];
		}
		return sprintf( __( 'M-Pesa payment failed (%1$d): %2$s', 'nexpay' ), $result_code, $desc );
	}

	/**
	 * STK Push callback handler (wc-api=nexpay_mpesa).
	 *
	 * Daraja posts JSON: Body.stkCallback { MerchantRequestID,
	 * CheckoutRequestID, ResultCode, ResultDesc, CallbackMetadata }.
	 *
	 * Trust model: the payload locates the order (via the CheckoutRequestID
	 * saved at push time) but never pays it by itself. A success callback is
	 * confirmed with the Daraja STK Push Query API; the amount must match
	 * the order total and the M-Pesa receipt must not already belong to a
	 * different order.
	 */
	public function handle_callback() {
		$raw  = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the Daraja JSON callback body.
		$data = json_decode( $raw, true );

		$callback = isset( $data['Body']['stkCallback'] ) ? $data['Body']['stkCallback'] : null;
		if ( ! is_array( $callback ) || empty( $callback['CheckoutRequestID'] ) ) {
			$this->log( 'Callback received with unexpected payload.', 'warning' );
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

		// Idempotent: duplicate callbacks for a paid order change nothing,
		// and a late failure callback never reverses a paid order.
		if ( $order->is_paid() ) {
			exit;
		}

		$result_code = isset( $callback['ResultCode'] ) ? (int) $callback['ResultCode'] : -1;
		$desc        = isset( $callback['ResultDesc'] ) ? (string) $callback['ResultDesc'] : 'Unknown error';

		if ( 0 !== $result_code ) {
			if ( ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
				$order->update_status( 'failed', $this->failure_note( $result_code, $desc ) );
				$order->add_order_note( $this->failure_note( $result_code, $desc ) );
			}
			$this->log( 'Callback marked order #' . $order->get_id() . ' as failed: ' . $desc, 'warning' );
			exit;
		}

		// Success claimed — confirm it server-to-server before paying.
		$query = $this->query_stk_status( $callback['CheckoutRequestID'] );
		if ( is_wp_error( $query ) || ! isset( $query['ResultCode'] ) || '0' !== (string) $query['ResultCode'] ) {
			$this->log(
				'STK query did not confirm success for order #' . $order->get_id() . '; order left unpaid.',
				'error'
			);
			$order->add_order_note( __( 'M-Pesa success callback received but the Daraja STK query did not confirm it yet. The order has NOT been marked paid.', 'nexpay' ) );
			exit;
		}

		// Amount must match the order total (KES has no minor unit here).
		$amount = $this->callback_meta_value( $callback, 'Amount' );
		if ( '' === $amount || (int) round( (float) $amount ) !== (int) round( (float) $order->get_total() ) ) {
			$this->log( 'Callback amount mismatch for order #' . $order->get_id() . '; rejected.', 'error' );
			$order->add_order_note( __( 'M-Pesa callback amount did not match the order total. The order has NOT been marked paid.', 'nexpay' ) );
			exit;
		}

		$receipt = $this->callback_meta_value( $callback, 'MpesaReceiptNumber' );
		if ( '' === $receipt ) {
			$this->log( 'Callback without MpesaReceiptNumber for order #' . $order->get_id() . '; rejected.', 'error' );
			exit;
		}

		// A receipt may only ever pay one order.
		$other = $this->get_order_by_meta( '_nexpay_mpesa_receipt', $receipt );
		if ( $other && $other->get_id() !== $order->get_id() ) {
			$this->log( 'M-Pesa receipt ' . $receipt . ' already used by order #' . $other->get_id() . '; rejected for order #' . $order->get_id(), 'error' );
			exit;
		}

		$order->update_meta_data( '_nexpay_mpesa_receipt', $receipt );
		$order->save();
		$order->payment_complete( $receipt );
		$order->add_order_note( sprintf( 'M-Pesa payment confirmed via Daraja STK query (receipt: %s).', $receipt ) );
		$this->log( 'Callback confirmed payment for order #' . $order->get_id() );

		exit;
	}
}
