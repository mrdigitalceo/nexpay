<?php
/**
 * NexPay — Easypaisa gateway (Pakistan).
 *
 * Uses the Easypaisa hosted payment page ("Easypay"): the customer is sent
 * to Easypaisa with a signed payload (storeId, orderRefNum, amount,
 * postBackURL, merchantHashedReq) and Easypaisa posts the result back to
 * this gateway's callback endpoint (wc-api=nexpay_easypaisa), where the
 * returned hash is verified before the order is updated.
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

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->store_id       = $this->get_option( 'store_id' );
		$this->account_number = $this->get_option( 'account_number' );

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
			)
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
		$mobile = preg_replace( '/\D+/', '', $this->posted_field( 'nexpay_easypaisa_mobile' ) );

		if ( strlen( $mobile ) < 10 || strlen( $mobile ) > 13 ) {
			wc_add_notice( __( 'Please enter a valid Easypaisa mobile number.', 'nexpay' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Build the Easypay "merchantHashedReq" value.
	 *
	 * The hashed request is a SHA-256 digest over the core transaction
	 * fields. Stores issued with an Easypaisa hash key should append it to
	 * the field list through the `nexpay_easypaisa_hash_fields` filter.
	 *
	 * @param array $fields Core transaction fields.
	 * @return string
	 */
	protected function merchant_hashed_req( array $fields ) {
		$parts = array(
			$fields['storeId'],
			$fields['orderRefNum'],
			$fields['amount'],
			$fields['postBackURL'],
			$fields['accountNum'],
		);

		$parts = apply_filters( 'nexpay_easypaisa_hash_fields', $parts, $fields );

		return strtoupper( hash( 'sha256', implode( '&', $parts ) ) );
	}

	/**
	 * Build the hosted-page payload for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected function build_payload( WC_Order $order ) {
		$expiry = gmdate( 'Ymd His', current_time( 'timestamp' ) + DAY_IN_SECONDS );

		$payload = array(
			'storeId'          => $this->store_id,
			'accountNum'       => $this->account_number,
			'amount'           => number_format( (float) $order->get_total(), 2, '.', '' ),
			'orderRefNum'      => (string) $order->get_id(),
			'postBackURL'      => $this->get_callback_url(),
			'expiryDate'       => $expiry,
			'autoRedirect'     => '1',
			'paymentMethod'    => 'MA_PAYMENT_METHOD',
			'emailAddress'     => $order->get_billing_email(),
			'mobileNum'        => $order->get_meta( '_nexpay_easypaisa_mobile' ),
		);
		$payload['merchantHashedReq'] = $this->merchant_hashed_req( $payload );

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

		if ( '' === $this->store_id || '' === $this->account_number ) {
			$this->log( 'Payment aborted: Easypaisa credentials are not configured.', 'error' );
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
	 * Handle the Easypaisa postback: verify the returned hash matches the
	 * order on file before marking it paid.
	 */
	protected function handle_postback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via merchantHashedReq below.
		$raw = wp_unslash( $_POST );

		$order_ref = isset( $raw['orderRefNum'] ) ? sanitize_text_field( $raw['orderRefNum'] ) : '';
		$status    = isset( $raw['status'] ) ? sanitize_text_field( $raw['status'] ) : '';
		$hash      = isset( $raw['merchantHashedReq'] ) ? strtoupper( sanitize_text_field( $raw['merchantHashedReq'] ) ) : '';

		$order = $order_ref ? wc_get_order( absint( $order_ref ) ) : null;
		if ( ! $order ) {
			$this->log( 'Postback for unknown order ref: ' . $order_ref, 'warning' );
			status_header( 404 );
			exit;
		}

		// Rebuild the expected hash from the stored order and compare it with
		// what Easypaisa sent back — a mismatch means a forged postback.
		$expected = $this->merchant_hashed_req( $this->build_payload( $order ) );
		if ( '' === $hash || ! $this->hash_matches( $expected, $hash ) ) {
			$this->log( 'Postback hash verification FAILED for order #' . $order->get_id(), 'error' );
			status_header( 400 );
			exit;
		}

		if ( ! $order->is_paid() ) {
			if ( in_array( $status, array( '0000', 'SUCCESS', 'success' ), true ) ) {
				$txn_id = isset( $raw['transactionId'] ) ? sanitize_text_field( $raw['transactionId'] ) : $order_ref;
				$order->payment_complete( $txn_id );
				$order->add_order_note( __( 'Easypaisa postback verified: payment confirmed.', 'nexpay' ) );
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
