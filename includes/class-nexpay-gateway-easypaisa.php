<?php
/**
 * NexPay — Easypaisa gateway (Pakistan).
 *
 * Uses the Easypaisa hosted payment page ("Easypay"): the customer is sent
 * to Easypaisa with a signed payload (storeId, orderRefNum, amount,
 * postBackURL, merchantHashedReq) and Easypaisa posts the result back to
 * this gateway's callback endpoint (wc-api=nexpay_easypaisa).
 *
 * Security model (v1.1.0): merchantHashedReq is a KEYED SHA-256 hash —
 * the ordered request parameters combined with the merchant Hash Key
 * (see build_merchant_hash()). The postback handler recomputes the hash
 * from the stored order, compares it in constant time, and additionally
 * validates the store id, the order reference, the exact amount and the
 * currency before an order may be marked paid. If no Hash Key is
 * configured, postbacks fail closed (no order is ever paid).
 *
 * The same endpoint also renders the auto-submitting payment form when it
 * is visited with ?nexpay_action=pay&order_id=…&key=… right after checkout.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Easypaisa hosted-page gateway.
 */
class NexPay_Gateway_Easypaisa extends NexPay_Gateway {

	const LIVE_ENDPOINT    = 'https://easypay.easypaisa.com.pk/easypay/Index.jsf';
	const SANDBOX_ENDPOINT = 'https://easypaystg.easypaisa.com.pk/easypay/Index.jsf';

	/**
	 * Store credentials.
	 *
	 * @var string
	 */
	protected $store_id = '';
	protected $account_number = '';
	protected $hash_key = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nexpay_easypaisa';
		$this->method_title       = __( 'NexPay — Easypaisa', 'nexpay' );
		$this->method_description = __( 'Accept Easypaisa mobile wallet payments (Pakistan) via NexPay, using the Easypaisa hosted payment page.', 'nexpay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->brand_label        = 'easypaisa';
		$this->callback_slug      = 'nexpay_easypaisa';
		$this->supported_currencies = array( 'PKR' );
		$this->blocks_fields      = array(
			'nexpay_easypaisa_mobile' => '_nexpay_easypaisa_mobile',
		);

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->store_id       = $this->get_option( 'store_id' );
		$this->account_number = $this->get_option( 'account_number' );
		$this->hash_key       = $this->get_option( 'hash_key' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_nexpay_easypaisa', array( $this, 'handle_request' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array_merge(
			$this->standard_form_fields(
				__( 'Easypaisa', 'nexpay' ),
				__( 'Pay securely with your Easypaisa mobile wallet.', 'nexpay' )
			),
			array(
				'store_id'       => array(
					'title'       => __( 'Store ID', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'Your Easypaisa merchant Store ID.', 'nexpay' ),
					'desc_tip'    => true,
				),
				'account_number' => array(
					'title'       => __( 'Account Number', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'Your Easypaisa merchant account number (where payments settle).', 'nexpay' ),
					'desc_tip'    => true,
				),
				'hash_key'       => array(
					'title'       => __( 'Merchant Hash Key', 'nexpay' ),
					'type'        => 'password',
					'description' => __( 'The Easypaisa merchant Hash Key used to sign requests and verify postbacks (keyed SHA-256). Required — without it, postbacks are rejected and no order can be marked paid.', 'nexpay' ),
					'desc_tip'    => true,
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
			'store_id'       => __( 'Store ID', 'nexpay' ),
			'account_number' => __( 'Account Number', 'nexpay' ),
			'hash_key'       => __( 'Merchant Hash Key', 'nexpay' ),
		);
	}

	/**
	 * Checkout field: mobile number.
	 */
	protected function render_checkout_fields() {
		$this->field_input( 'nexpay_easypaisa_mobile', __( 'Mobile Number', 'nexpay' ), 'tel', '03XX XXXXXXX', 'tel' );
	}

	/**
	 * Validate the checkout fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( ! self::is_valid_pk_mobile( $this->posted_field( 'nexpay_easypaisa_mobile' ) ) ) {
			wc_add_notice( __( 'Please enter a valid Easypaisa mobile number (e.g. 03XX XXXXXXX).', 'nexpay' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * The ordered field values that go into the Easypay hash, per the
	 * Easypaisa hosted-page protocol: storeId, orderRefNum, amount,
	 * postBackURL, accountNum.
	 *
	 * @param array $fields Core transaction fields.
	 * @return string[]
	 */
	public static function hash_field_values( array $fields ) {
		$parts = array(
			isset( $fields['storeId'] ) ? (string) $fields['storeId'] : '',
			isset( $fields['orderRefNum'] ) ? (string) $fields['orderRefNum'] : '',
			isset( $fields['amount'] ) ? (string) $fields['amount'] : '',
			isset( $fields['postBackURL'] ) ? (string) $fields['postBackURL'] : '',
			isset( $fields['accountNum'] ) ? (string) $fields['accountNum'] : '',
		);

		return apply_filters( 'nexpay_easypaisa_hash_fields', $parts, $fields );
	}

	/**
	 * Build the keyed Easypay "merchantHashedReq" value: SHA-256 over the
	 * ordered request parameters combined with the merchant Hash Key.
	 *
	 * @param array  $fields   Core transaction fields.
	 * @param string $hash_key Merchant Hash Key (secret).
	 * @return string Uppercase hex digest, or empty string without a key.
	 */
	public static function build_merchant_hash( array $fields, $hash_key ) {
		if ( '' === (string) $hash_key ) {
			return '';
		}

		$parts   = self::hash_field_values( $fields );
		$parts[] = (string) $hash_key;

		return strtoupper( hash( 'sha256', implode( '&', $parts ) ) );
	}

	/**
	 * Verify an Easypaisa postback against the expected (server-side)
	 * transaction data. Pure/static so it can be unit tested.
	 *
	 * @param array  $posted   Postback fields (storeId, orderRefNum, amount,
	 *                         optional currency, merchantHashedReq).
	 * @param array  $expected Expected fields built from the stored order
	 *                         and the gateway settings.
	 * @param string $hash_key Merchant Hash Key.
	 * @return true|string True when valid, otherwise an error code:
	 *                     missing_hash_key, missing_fields, store_mismatch,
	 *                     order_mismatch, amount_mismatch, currency_mismatch,
	 *                     hash_mismatch.
	 */
	public static function verify_postback_data( array $posted, array $expected, $hash_key ) {
		if ( '' === (string) $hash_key ) {
			return 'missing_hash_key';
		}

		foreach ( array( 'orderRefNum', 'merchantHashedReq' ) as $required ) {
			if ( ! isset( $posted[ $required ] ) || '' === (string) $posted[ $required ] ) {
				return 'missing_fields';
			}
		}

		if ( ! isset( $posted['storeId'] ) || (string) $posted['storeId'] !== (string) $expected['storeId'] ) {
			return 'store_mismatch';
		}

		if ( (string) $posted['orderRefNum'] !== (string) $expected['orderRefNum'] ) {
			return 'order_mismatch';
		}

		$posted_amount = '';
		if ( isset( $posted['amount'] ) && '' !== (string) $posted['amount'] ) {
			$posted_amount = (string) $posted['amount'];
		} elseif ( isset( $posted['transactionAmount'] ) ) {
			$posted_amount = (string) $posted['transactionAmount'];
		}
		if ( '' === $posted_amount || self::format_amount( $posted_amount ) !== self::format_amount( $expected['amount'] ) ) {
			return 'amount_mismatch';
		}

		if ( isset( $posted['currency'] ) && '' !== (string) $posted['currency'] && 'PKR' !== strtoupper( (string) $posted['currency'] ) ) {
			return 'currency_mismatch';
		}

		$expected_hash = self::build_merchant_hash( $expected, $hash_key );
		if ( ! hash_equals( $expected_hash, strtoupper( (string) $posted['merchantHashedReq'] ) ) ) {
			return 'hash_mismatch';
		}

		return true;
	}

	/**
	 * Build the expected transaction fields for an order from the gateway
	 * settings + the stored order (server-side source of truth).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected function expected_fields( WC_Order $order ) {
		return array(
			'storeId'     => $this->store_id,
			'accountNum'  => $this->account_number,
			'amount'      => self::format_amount( $order->get_total() ),
			'orderRefNum' => (string) $order->get_id(),
			'postBackURL' => $this->get_callback_url(),
		);
	}

	/**
	 * Build the hosted-page payload for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected function build_payload( WC_Order $order ) {
		$expiry = gmdate( 'Ymd His', current_time( 'timestamp' ) + DAY_IN_SECONDS );

		$payload = array_merge(
			$this->expected_fields( $order ),
			array(
				'expiryDate'    => $expiry,
				'autoRedirect'  => '1',
				'paymentMethod' => 'MA_PAYMENT_METHOD',
				'emailAddress'  => $order->get_billing_email(),
				'mobileNum'     => $order->get_meta( '_nexpay_easypaisa_mobile' ),
			)
		);
		$payload['merchantHashedReq'] = self::build_merchant_hash( $payload, $this->hash_key );

		return $payload;
	}

	/**
	 * Process the payment: place the order on-hold and redirect the customer
	 * to this gateway's endpoint, which auto-posts the signed payload to the
	 * Easypaisa hosted payment page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_failure( __( 'Order not found.', 'nexpay' ) );
		}

		if ( '' === $this->store_id || '' === $this->account_number || '' === $this->hash_key ) {
			$this->log( 'Payment aborted: Easypaisa credentials (incl. Hash Key) are not configured.', 'error' );
			return $this->payment_failure( __( 'Easypaisa is not configured correctly. Please contact the store owner.', 'nexpay' ) );
		}

		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_easypaisa_mobile' ) );
		$order->update_meta_data( '_nexpay_easypaisa_mobile', $mobile );
		$order->update_status( 'on-hold', __( 'Awaiting payment on the Easypaisa hosted page.', 'nexpay' ) );
		$order->save();

		WC()->cart->empty_cart();

		$redirect = add_query_arg(
			array(
				'nexpay_action' => 'pay',
				'order_id'      => $order_id,
				'key'           => $order->get_order_key(),
			),
			$this->get_callback_url()
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Single endpoint for both halves of the hosted flow:
	 *  - GET  ?nexpay_action=pay → render the auto-submitting payment form.
	 *  - POST (Easypaisa postback) → verify the hash and update the order.
	 */
	public function handle_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action flag only; payment data is hash-verified below.
		$action = isset( $_GET['nexpay_action'] ) ? sanitize_text_field( wp_unslash( $_GET['nexpay_action'] ) ) : '';

		if ( 'pay' === $action ) {
			$this->render_payment_form();
		}

		$this->handle_postback();
	}

	/**
	 * Render an auto-submitting form that posts the signed payload to the
	 * Easypaisa hosted payment page.
	 */
	protected function render_payment_form() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order key checked below acts as the capability token.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = wc_get_order( $order_id );

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'Invalid payment link.', 'nexpay' ), esc_html__( 'NexPay', 'nexpay' ), array( 'response' => 403 ) );
		}

		$payload  = $this->build_payload( $order );
		$endpoint = $this->endpoint( self::LIVE_ENDPOINT, self::SANDBOX_ENDPOINT );

		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head><meta charset="<?php bloginfo( 'charset' ); ?>"><title><?php esc_html_e( 'Redirecting to Easypaisa…', 'nexpay' ); ?></title></head>
		<body onload="document.getElementById('nexpay-easypaisa-form').submit();">
			<p><?php esc_html_e( 'You are being redirected to Easypaisa to complete your payment…', 'nexpay' ); ?></p>
			<form id="nexpay-easypaisa-form" method="post" action="<?php echo esc_url( $endpoint ); ?>">
				<?php foreach ( $payload as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endforeach; ?>
				<noscript><button type="submit"><?php esc_html_e( 'Continue to Easypaisa', 'nexpay' ); ?></button></noscript>
			</form>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Handle the Easypaisa postback. The order is only marked paid after
	 * the keyed hash, store id, order reference, exact amount and currency
	 * all verify against the stored order. Processing is idempotent: a
	 * repeated postback with the same provider transaction reference is
	 * acknowledged without completing the order twice.
	 */
	protected function handle_postback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via the keyed merchantHashedReq below.
		$raw = wp_unslash( $_POST );

		$posted = array();
		foreach ( array( 'storeId', 'orderRefNum', 'amount', 'transactionAmount', 'currency', 'status', 'transactionId', 'merchantHashedReq' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$posted[ $key ] = sanitize_text_field( $raw[ $key ] );
			}
		}

		$order_ref = isset( $posted['orderRefNum'] ) ? $posted['orderRefNum'] : '';
		$order     = $order_ref ? wc_get_order( absint( $order_ref ) ) : null;
		if ( ! $order ) {
			$this->log( 'Postback for unknown order ref: ' . $order_ref, 'warning' );
			status_header( 404 );
			exit;
		}

		$check = self::verify_postback_data( $posted, $this->expected_fields( $order ), $this->hash_key );
		if ( true !== $check ) {
			$this->log(
				sprintf( 'Postback verification FAILED for order #%s (%s).', $order->get_id(), $check ),
				'error'
			);
			status_header( 400 );
			exit;
		}

		$status = isset( $posted['status'] ) ? $posted['status'] : '';
		$txn_id = isset( $posted['transactionId'] ) && '' !== $posted['transactionId'] ? $posted['transactionId'] : $order_ref;

		// Idempotency: this provider transaction was already processed.
		$processed_txn = (string) $order->get_meta( '_nexpay_easypaisa_txn_id' );
		if ( '' !== $processed_txn && $processed_txn === $txn_id && $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// A transaction reference may never be reused across orders.
		if ( '' !== $txn_id ) {
			$other = $this->get_order_by_meta( '_nexpay_easypaisa_txn_id', $txn_id );
			if ( $other && $other->get_id() !== $order->get_id() ) {
				$this->log( 'Postback transaction reference already used by another order; rejected for order #' . $order->get_id(), 'error' );
				status_header( 400 );
				exit;
			}
		}

		if ( ! $order->is_paid() ) {
			if ( in_array( $status, array( '0000', 'SUCCESS', 'success' ), true ) ) {
				$order->update_meta_data( '_nexpay_easypaisa_txn_id', $txn_id );
				$order->save();
				$order->payment_complete( $txn_id );
				$order->add_order_note( __( 'Easypaisa postback verified (keyed hash, amount, store): payment confirmed.', 'nexpay' ) );
				$this->log( 'Postback confirmed payment for order #' . $order->get_id() );
			} else {
				$order->update_status( 'failed', __( 'Easypaisa reported a failed payment.', 'nexpay' ) );
				$this->log( 'Postback marked order #' . $order->get_id() . ' as failed (status: ' . $status . ').', 'warning' );
			}
		}

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}
}
