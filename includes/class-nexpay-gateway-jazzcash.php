<?php
/**
 * NexPay — JazzCash gateway (Pakistan).
 *
 * Implements the JazzCash Mobile Wallet (MWALLET) REST API:
 *  - POST /ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction
 *  - Every request/response is signed with an HMAC-SHA256 "pp_SecureHash"
 *    built from the sorted field values and the merchant Integrity Salt.
 *  - A WooCommerce API endpoint (wc-api=nexpay_jazzcash) receives the IPN /
 *    return data and only updates the order after the hash verifies.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * JazzCash mobile wallet gateway.
 */
class NexPay_Gateway_JazzCash extends NexPay_Gateway {

	const LIVE_ENDPOINT    = 'https://www.jazzcash.com.pk/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction';
	const SANDBOX_ENDPOINT = 'https://sandbox.jazzcash.com.pk/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction';

	/**
	 * Merchant credentials.
	 *
	 * @var string
	 */
	protected $merchant_id = '';
	protected $password = '';
	protected $integrity_salt = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nexpay_jazzcash';
		$this->method_title       = __( 'NexPay — JazzCash', 'nexpay' );
		$this->method_description = __( 'Accept JazzCash mobile account payments (Pakistan) via NexPay.', 'nexpay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->brand_label        = 'JazzCash';
		$this->callback_slug      = 'nexpay_jazzcash';
		$this->supported_currencies = array( 'PKR' );

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->merchant_id    = $this->get_option( 'merchant_id' );
		$this->password       = $this->get_option( 'password' );
		$this->integrity_salt = $this->get_option( 'integrity_salt' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_nexpay_jazzcash', array( $this, 'handle_ipn' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array_merge(
			$this->standard_form_fields(
				__( 'JazzCash', 'nexpay' ),
				__( 'Pay securely with your JazzCash mobile account.', 'nexpay' )
			),
			array(
				'merchant_id'    => array(
					'title'       => __( 'Merchant ID', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'Your JazzCash merchant ID, from the JazzCash merchant portal.', 'nexpay' ),
					'desc_tip'    => true,
				),
				'password'       => array(
					'title'       => __( 'Password', 'nexpay' ),
					'type'        => 'password',
					'description' => __( 'Your JazzCash merchant password.', 'nexpay' ),
					'desc_tip'    => true,
				),
				'integrity_salt' => array(
					'title'       => __( 'Integrity Salt', 'nexpay' ),
					'type'        => 'password',
					'description' => __( 'Used to build the HMAC-SHA256 secure hash for every request and IPN. Keep it secret.', 'nexpay' ),
					'desc_tip'    => true,
				),
			)
		);
	}

	/**
	 * Checkout fields: mobile number + CNIC (last 6 digits), as in the design.
	 */
	protected function render_checkout_fields() {
		$this->field_input( 'nexpay_jazzcash_mobile', __( 'Mobile Number', 'nexpay' ), 'tel', '03XX XXXXXXX', 'tel' );
		$this->field_input( 'nexpay_jazzcash_cnic', __( 'CNIC (last 6 digits)', 'nexpay' ), 'text', '123456', 'numeric' );
	}

	/**
	 * Validate the checkout fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_jazzcash_mobile' ) );
		$cnic   = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_jazzcash_cnic' ) );

		if ( strlen( $mobile ) < 10 || strlen( $mobile ) > 13 ) {
			wc_add_notice( __( 'Please enter a valid JazzCash mobile number.', 'nexpay' ), 'error' );
			return false;
		}

		if ( 6 !== strlen( $cnic ) ) {
			wc_add_notice( __( 'Please enter the last 6 digits of your CNIC.', 'nexpay' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Build the JazzCash secure hash for a set of pp_* fields.
	 *
	 * Algorithm (per the JazzCash integration guide): sort the fields by key,
	 * then hash "IntegritySalt&value1&value2&..." with HMAC-SHA256, keyed with
	 * the Integrity Salt. Empty values and pp_SecureHash itself are excluded.
	 *
	 * @param array $fields Field map (without pp_SecureHash).
	 * @return string Uppercase hex digest.
	 */
	protected function secure_hash( array $fields ) {
		ksort( $fields );

		$hash_string = $this->integrity_salt;
		foreach ( $fields as $key => $value ) {
			if ( 'pp_SecureHash' === $key || '' === (string) $value || null === $value ) {
				continue;
			}
			$hash_string .= '&' . $value;
		}

		return strtoupper( $this->hmac_sha256( $hash_string, $this->integrity_salt ) );
	}

	/**
	 * Process the payment: build the signed MWALLET request, send it, and
	 * complete / fail the order from the synchronous response. The IPN handler
	 * below remains the source of truth for asynchronous confirmations.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_failure( __( 'Order not found.', 'nexpay' ) );
		}

		if ( '' === $this->merchant_id || '' === $this->password || '' === $this->integrity_salt ) {
			$this->log( 'Payment aborted: JazzCash credentials are not configured.', 'error' );
			return $this->payment_failure( __( 'JazzCash is not configured correctly. Please contact the store owner.', 'nexpay' ) );
		}

		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_jazzcash_mobile' ) );
		$cnic   = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_jazzcash_cnic' ) );

		$order->update_meta_data( '_nexpay_jazzcash_mobile', $mobile );
		$order->update_meta_data( '_nexpay_jazzcash_cnic_last6', $cnic );

		$txn_ref   = sprintf( 'T%s%d', current_time( 'YmdHis' ), $order_id );
		$txn_date  = current_time( 'YmdHis' );
		$expiry    = gmdate( 'YmdHis', current_time( 'timestamp' ) + DAY_IN_SECONDS );
		$amount    = (int) round( $order->get_total() * 100 ); // JazzCash expects paisa.

		$request = array(
			'pp_Language'         => 'EN',
			'pp_MerchantID'       => $this->merchant_id,
			'pp_Password'         => $this->password,
			'pp_TxnType'          => 'MWALLET',
			'pp_Version'          => '2.0',
			'pp_TxnRefNo'         => $txn_ref,
			'pp_Amount'           => (string) $amount,
			'pp_TxnCurrency'      => 'PKR',
			'pp_TxnDateTime'      => $txn_date,
			'pp_TxnExpiryDateTime' => $expiry,
			'pp_BillReference'    => 'order-' . $order_id,
			'pp_Description'      => sprintf( 'Order #%s at %s', $order->get_order_number(), get_bloginfo( 'name' ) ),
			'pp_ReturnURL'        => $this->get_callback_url(),
			// MWALLET custom fields: 1 = mobile number, 2 = CNIC last 6 digits.
			'ppmpf_1'             => $mobile,
			'ppmpf_2'             => $cnic,
		);
		$request['pp_SecureHash'] = $this->secure_hash( $request );

		$order->update_meta_data( '_nexpay_txn_ref', $txn_ref );
		$order->save();

		$endpoint = $this->endpoint( self::LIVE_ENDPOINT, self::SANDBOX_ENDPOINT );
		$this->log( sprintf( 'MWALLET request for order #%s (ref %s) to %s', $order_id, $txn_ref, $endpoint ) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $request ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'MWALLET request failed: ' . $response->get_error_message(), 'error' );
			$order->update_status( 'failed', __( 'JazzCash payment request could not be sent.', 'nexpay' ) );
			return $this->payment_failure( __( 'Could not connect to JazzCash. Please try again.', 'nexpay' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['pp_ResponseCode'] ) ) {
			$this->log( 'Unexpected MWALLET response: ' . wp_remote_retrieve_body( $response ), 'error' );
			return $this->payment_failure( __( 'Unexpected response from JazzCash. Please try again.', 'nexpay' ) );
		}

		$response_code = (string) $body['pp_ResponseCode'];
		$message       = isset( $body['pp_ResponseMessage'] ) ? $body['pp_ResponseMessage'] : '';

		if ( '000' === $response_code ) {
			$order->payment_complete( isset( $body['pp_RetreivalReferenceNo'] ) ? $body['pp_RetreivalReferenceNo'] : $txn_ref );
			$order->add_order_note( sprintf( 'JazzCash payment approved (ref: %s).', $txn_ref ) );
			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$this->log( sprintf( 'MWALLET declined for order #%s: %s %s', $order_id, $response_code, $message ), 'warning' );
		$order->update_status( 'failed', sprintf( 'JazzCash payment failed (%s): %s', $response_code, $message ) );

		return $this->payment_failure(
			sprintf( __( 'JazzCash payment failed: %s', 'nexpay' ), $message ? $message : __( 'Transaction declined.', 'nexpay' ) )
		);
	}

	/**
	 * IPN / return handler (wc-api=nexpay_jazzcash).
	 *
	 * Verifies pp_SecureHash against every pp_* field JazzCash posted back
	 * before touching the order — unsigned or tampered notifications are
	 * logged and discarded.
	 */
	public function handle_ipn() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via pp_SecureHash HMAC below.
		$raw = wp_unslash( $_POST );

		$params = array();
		foreach ( $raw as $key => $value ) {
			if ( 0 === strpos( $key, 'pp_' ) || 0 === strpos( $key, 'ppmpf_' ) ) {
				$params[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
			}
		}

		if ( empty( $params['pp_SecureHash'] ) || empty( $params['pp_TxnRefNo'] ) ) {
			$this->log( 'IPN received without hash or reference; ignored.', 'warning' );
			status_header( 400 );
			exit;
		}

		$received_hash = strtoupper( $params['pp_SecureHash'] );
		$expected_hash = $this->secure_hash( $params );

		if ( ! $this->hash_matches( $expected_hash, $received_hash ) ) {
			$this->log( 'IPN hash verification FAILED for ref ' . $params['pp_TxnRefNo'], 'error' );
			status_header( 400 );
			exit;
		}

		$order = $this->get_order_by_meta( '_nexpay_txn_ref', $params['pp_TxnRefNo'] );
		if ( ! $order ) {
			$this->log( 'IPN for unknown transaction ref ' . $params['pp_TxnRefNo'], 'warning' );
			status_header( 404 );
			exit;
		}

		if ( $order->is_paid() ) {
			status_header( 200 );
			exit; // Already processed — acknowledge and stop.
		}

		if ( isset( $params['pp_ResponseCode'] ) && '000' === (string) $params['pp_ResponseCode'] ) {
			$order->payment_complete( isset( $params['pp_RetreivalReferenceNo'] ) ? $params['pp_RetreivalReferenceNo'] : $params['pp_TxnRefNo'] );
			$order->add_order_note( __( 'JazzCash IPN verified: payment confirmed.', 'nexpay' ) );
			$this->log( 'IPN confirmed payment for order #' . $order->get_id() );
		} else {
			$order->update_status( 'failed', __( 'JazzCash IPN reported a failed transaction.', 'nexpay' ) );
			$this->log( 'IPN marked order #' . $order->get_id() . ' as failed.', 'warning' );
		}

		status_header( 200 );
		exit;
	}
}
