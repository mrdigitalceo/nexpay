<?php
/**
 * NexPay — Nagad gateway (Bangladesh).
 *
 * Implements the Nagad online checkout API:
 *  1. POST {base}check-out/initialize/{merchantId}/{orderId}
 *     The "sensitive data" (merchant, order, amount, challenge) is encrypted
 *     with Nagad's RSA public key and signed with the merchant private key.
 *  2. POST {base}check-out/complete/{paymentReferenceId}
 *     Returns the Nagad checkout URL to redirect the customer to.
 *  3. Callback — the payment is verified server-to-server with
 *     GET {base}verify/payment/{paymentReferenceId} before the order is
 *     completed, so a forged browser return cannot fake a payment.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nagad checkout gateway.
 */
class NexPay_Gateway_Nagad extends NexPay_Gateway {

	const LIVE_BASE    = 'https://api.mynagad.com/api/dfs/';
	const SANDBOX_BASE = 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs/';

	/**
	 * Merchant credentials & keys.
	 *
	 * @var string
	 */
	protected $merchant_id = '';
	protected $merchant_number = '';
	protected $nagad_public_key = '';
	protected $merchant_private_key = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nexpay_nagad';
		$this->method_title       = __( 'NexPay — Nagad', 'nexpay' );
		$this->method_description = __( 'Accept Nagad mobile wallet payments (Bangladesh) via NexPay.', 'nexpay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->brand_label        = 'Nagad';
		$this->callback_slug      = 'nexpay_nagad';
		$this->supported_currencies = array( 'BDT' );

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title                = $this->get_option( 'title' );
		$this->description          = $this->get_option( 'description' );
		$this->merchant_id          = $this->get_option( 'merchant_id' );
		$this->merchant_number      = $this->get_option( 'merchant_number' );
		$this->nagad_public_key     = $this->get_option( 'public_key' );
		$this->merchant_private_key = $this->get_option( 'private_key' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_nexpay_nagad', array( $this, 'handle_callback' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array_merge(
			$this->standard_form_fields(
				__( 'Nagad', 'nexpay' ),
				__( 'Pay securely with your Nagad mobile wallet.', 'nexpay' )
			),
			array(
				'merchant_id'     => array(
					'title' => __( 'Merchant ID', 'nexpay' ),
					'type'  => 'text',
				),
				'merchant_number' => array(
					'title'       => __( 'Merchant Number', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'Your Nagad merchant account number.', 'nexpay' ),
					'desc_tip'    => true,
				),
				'public_key'      => array(
					'title'       => __( 'Nagad Public Key', 'nexpay' ),
					'type'        => 'textarea',
					'description' => __( 'The Nagad PGW public key (PEM or base64 body), used to encrypt the sensitive checkout data.', 'nexpay' ),
					'desc_tip'    => true,
					'css'         => 'min-height: 90px;',
				),
				'private_key'     => array(
					'title'       => __( 'Merchant Private Key', 'nexpay' ),
					'type'        => 'textarea',
					'description' => __( 'Your merchant RSA private key (PEM or base64 body), used to sign requests and decrypt responses. Keep it secret.', 'nexpay' ),
					'desc_tip'    => true,
					'css'         => 'min-height: 90px;',
				),
			)
		);
	}

	/**
	 * Checkout field: Nagad mobile number.
	 */
	protected function render_checkout_fields() {
		$this->field_input( 'nexpay_nagad_mobile', __( 'Nagad Mobile Number', 'nexpay' ), 'tel', '01XXXXXXXXX', 'tel' );
	}

	/**
	 * Validate the checkout fields (Bangladeshi mobile: 11 digits, starts 01).
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_nagad_mobile' ) );

		if ( ! preg_match( '/^01[3-9]\d{8}$/', $mobile ) ) {
			wc_add_notice( __( 'Please enter a valid Nagad mobile number (e.g. 01XXXXXXXXX).', 'nexpay' ), 'error' );
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
	 * Wrap a raw/base64 key body into a PEM block if needed.
	 *
	 * @param string $key     Key material as stored in settings.
	 * @param string $type    "PUBLIC KEY" or "PRIVATE KEY".
	 * @return string PEM string.
	 */
	protected function pem( $key, $type ) {
		$key = trim( (string) $key );
		if ( false !== strpos( $key, '-----BEGIN' ) ) {
			return $key;
		}
		return "-----BEGIN {$type}-----\n" . chunk_split( preg_replace( '/\s+/', '', $key ), 64, "\n" ) . "-----END {$type}-----\n";
	}

	/**
	 * Encrypt data with Nagad's public key (RSA, PKCS#1 padding) → base64.
	 *
	 * @param string $plain Plaintext (JSON).
	 * @return string|false
	 */
	protected function encrypt_for_nagad( $plain ) {
		$encrypted = '';
		if ( ! openssl_public_encrypt( $plain, $encrypted, $this->pem( $this->nagad_public_key, 'PUBLIC KEY' ), OPENSSL_PKCS1_PADDING ) ) {
			return false;
		}
		return base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt data with the merchant private key → plaintext.
	 *
	 * @param string $encoded Base64 ciphertext.
	 * @return string|false
	 */
	protected function decrypt_from_nagad( $encoded ) {
		$plain = '';
		$data  = base64_decode( $encoded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $data || ! openssl_private_decrypt( $data, $plain, $this->pem( $this->merchant_private_key, 'PRIVATE KEY' ), OPENSSL_PKCS1_PADDING ) ) {
			return false;
		}
		return $plain;
	}

	/**
	 * Sign data with the merchant private key (SHA256withRSA) → base64.
	 *
	 * @param string $data Data to sign.
	 * @return string|false
	 */
	protected function sign( $data ) {
		$signature = '';
		if ( ! openssl_sign( $data, $signature, $this->pem( $this->merchant_private_key, 'PRIVATE KEY' ), OPENSSL_ALGO_SHA256 ) ) {
			return false;
		}
		return base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Random challenge string Nagad expects in every sensitive payload.
	 *
	 * @return string
	 */
	protected function challenge() {
		return wp_generate_password( 40, false );
	}

	/**
	 * JSON POST helper.
	 *
	 * @param string $url  URL.
	 * @param array  $data Body.
	 * @return array|WP_Error
	 */
	protected function api_post( $url, array $data ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'X-KM-IP-V4'   => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1',
					'X-KM-Client-Type' => 'PC_WEB',
					'X-KM-Api-Version' => 'v-0.2.0',
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
	 * Process the payment: initialize + complete the Nagad checkout and
	 * redirect the customer to the returned Nagad payment page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_failure( __( 'Order not found.', 'nexpay' ) );
		}

		if ( '' === $this->merchant_id || '' === $this->merchant_number || '' === $this->nagad_public_key || '' === $this->merchant_private_key ) {
			$this->log( 'Payment aborted: Nagad credentials/keys are not configured.', 'error' );
			return $this->payment_failure( __( 'Nagad is not configured correctly. Please contact the store owner.', 'nexpay' ) );
		}

		$mobile    = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_nagad_mobile' ) );
		$nagad_ref = 'NX' . $order_id . time();
		$challenge = $this->challenge();
		$amount    = number_format( (float) $order->get_total(), 2, '.', '' );

		$order->update_meta_data( '_nexpay_nagad_mobile', $mobile );
		$order->update_meta_data( '_nexpay_nagad_order_ref', $nagad_ref );

		// ---- Step 1: Initialize -------------------------------------------
		$sensitive = wp_json_encode(
			array(
				'merchantId'   => $this->merchant_id,
				'orderId'      => $nagad_ref,
				'amount'       => $amount,
				'currencyCode' => '050', // ISO numeric code for BDT.
				'challenge'    => $challenge,
			)
		);

		$encrypted = $this->encrypt_for_nagad( $sensitive );
		$signature = $this->sign( $sensitive );
		if ( false === $encrypted || false === $signature ) {
			$this->log( 'Could not encrypt/sign the Nagad initialize payload (check the keys).', 'error' );
			return $this->payment_failure( __( 'Nagad payment could not be initialized. Please try again.', 'nexpay' ) );
		}

		$init = $this->api_post(
			$this->base_url() . 'check-out/initialize/' . $this->merchant_id . '/' . $nagad_ref,
			array(
				'accountNumber' => $this->merchant_number,
				'dateTime'      => current_time( 'YmdHis' ),
				'sensitiveData' => $encrypted,
				'signature'     => $signature,
			)
		);

		if ( is_wp_error( $init ) || empty( $init['sensitiveData'] ) ) {
			$this->log( 'Initialize failed for order #' . $order_id . ': ' . ( is_wp_error( $init ) ? $init->get_error_message() : wp_json_encode( $init ) ), 'error' );
			return $this->payment_failure( __( 'Nagad payment could not be initialized. Please try again.', 'nexpay' ) );
		}

		$init_plain = $this->decrypt_from_nagad( $init['sensitiveData'] );
		$init_data  = $init_plain ? json_decode( $init_plain, true ) : array();
		if ( empty( $init_data['paymentReferenceId'] ) ) {
			$this->log( 'Initialize response could not be decrypted for order #' . $order_id, 'error' );
			return $this->payment_failure( __( 'Nagad payment could not be initialized. Please try again.', 'nexpay' ) );
		}

		$payment_ref = $init_data['paymentReferenceId'];
		$order->update_meta_data( '_nexpay_nagad_payment_ref', $payment_ref );

		// ---- Step 2: Complete checkout ------------------------------------
		$sensitive2 = wp_json_encode(
			array(
				'merchantId'   => $this->merchant_id,
				'orderId'      => $nagad_ref,
				'amount'       => $amount,
				'currencyCode' => '050',
				'challenge'    => isset( $init_data['challenge'] ) ? $init_data['challenge'] : $challenge,
			)
		);

		$complete = $this->api_post(
			$this->base_url() . 'check-out/complete/' . $payment_ref,
			array(
				'sensitiveData'        => $this->encrypt_for_nagad( $sensitive2 ),
				'signature'            => $this->sign( $sensitive2 ),
				'merchantCallbackURL'  => $this->get_callback_url(),
				'additionalMerchantInfo' => wp_json_encode( array( 'order_id' => $order_id ) ),
			)
		);

		if ( is_wp_error( $complete ) || empty( $complete['callBackUrl'] ) ) {
			$this->log( 'Complete checkout failed for order #' . $order_id . ': ' . ( is_wp_error( $complete ) ? $complete->get_error_message() : wp_json_encode( $complete ) ), 'error' );
			return $this->payment_failure( __( 'Nagad checkout could not be completed. Please try again.', 'nexpay' ) );
		}

		$order->update_status( 'on-hold', __( 'Awaiting payment on Nagad.', 'nexpay' ) );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $complete['callBackUrl'],
		);
	}

	/**
	 * Callback handler (wc-api=nexpay_nagad). Nagad returns the customer
	 * with a payment reference; we verify it server-to-server before
	 * completing the order.
	 */
	public function handle_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- payment re-verified server-to-server below.
		$payment_ref = isset( $_GET['payment_ref_id'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_ref_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		$order = $payment_ref ? $this->get_order_by_meta( '_nexpay_nagad_payment_ref', $payment_ref ) : null;
		if ( ! $order ) {
			$this->log( 'Callback for unknown payment ref: ' . $payment_ref, 'warning' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		if ( 'Success' !== $status ) {
			$order->update_status( 'failed', __( 'Nagad payment was not completed.', 'nexpay' ) );
			wc_add_notice( __( 'The Nagad payment was not completed. Please try again.', 'nexpay' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		// Server-to-server verification is the real source of truth.
		$response = wp_remote_get( $this->base_url() . 'verify/payment/' . $payment_ref, array( 'timeout' => 30 ) );
		$verified = false;
		$issuer_ref = '';

		if ( ! is_wp_error( $response ) ) {
			$body       = json_decode( wp_remote_retrieve_body( $response ), true );
			$verified   = is_array( $body ) && isset( $body['status'] ) && 'Success' === $body['status'];
			$issuer_ref = isset( $body['issuerPaymentRefNo'] ) ? $body['issuerPaymentRefNo'] : '';
		}

		if ( $verified ) {
			$order->payment_complete( '' !== $issuer_ref ? $issuer_ref : $payment_ref );
			$order->add_order_note( __( 'Nagad payment verified via the Payment Verification API.', 'nexpay' ) );
			$this->log( 'Payment verified for order #' . $order->get_id() );
			wp_safe_redirect( $this->get_return_url( $order ) );
		} else {
			$this->log( 'Verification failed for order #' . $order->get_id(), 'error' );
			$order->update_status( 'on-hold', __( 'Nagad payment could not be verified yet.', 'nexpay' ) );
			wc_add_notice( __( 'Your Nagad payment is being verified. We will update your order shortly.', 'nexpay' ), 'notice' );
			wp_safe_redirect( $this->get_return_url( $order ) );
		}
		exit;
	}
}
