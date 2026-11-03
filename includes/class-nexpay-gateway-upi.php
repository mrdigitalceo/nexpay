<?php
/**
 * NexPay — UPI gateway (India).
 *
 * UPI is an intent-based system: there is no merchant server API to charge
 * the customer. This gateway collects the customer's UPI ID (VPA) at
 * checkout, generates a standard UPI intent / deep link —
 *
 *     upi://pay?pa=<merchant VPA>&pn=<payee name>&am=<amount>&cu=INR&tr=<ref>
 *
 * — puts the order on-hold, and shows the intent link on the thank-you page
 * so the customer can open it and pay with any UPI app (BHIM, GPay,
 * PhonePe, Paytm…). There is NO automatic confirmation: the store owner
 * verifies the payment against the UPI transaction reference and marks
 * the order complete manually (see docs/SETUP.md).
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * UPI intent gateway.
 */
class NexPay_Gateway_UPI extends NexPay_Gateway {

	/**
	 * Merchant UPI ID (VPA) and payee name.
	 *
	 * @var string
	 */
	protected $merchant_vpa = '';
	protected $payee_name = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nexpay_upi';
		$this->method_title       = __( 'NexPay — UPI', 'nexpay' );
		$this->method_description = __( 'Accept UPI payments (India) via NexPay using a UPI intent deep link.', 'nexpay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->brand_label        = 'UPI';
		$this->supported_currencies = array( 'INR' );
		$this->blocks_fields      = array(
			'nexpay_upi_vpa' => '_nexpay_upi_customer_vpa',
		);

		$this->init_form_fields();
		$this->init_settings();
		$this->load_common_settings();

		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->merchant_vpa = $this->get_option( 'merchant_vpa' );
		$this->payee_name   = $this->get_option( 'payee_name' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_instructions' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array_merge(
			$this->standard_form_fields(
				__( 'UPI', 'nexpay' ),
				__( 'Pay with any UPI app (BHIM, Google Pay, PhonePe, Paytm) using a UPI intent link. Your order stays on hold until the store verifies your payment — it is not confirmed automatically.', 'nexpay' )
			),
			array(
				'merchant_vpa' => array(
					'title'       => __( 'Merchant UPI ID (VPA)', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'The UPI ID that receives payments, e.g. yourstore@okhdfcbank.', 'nexpay' ),
					'desc_tip'    => true,
					'placeholder' => 'yourstore@okbank',
				),
				'payee_name'   => array(
					'title'       => __( 'Payee Name', 'nexpay' ),
					'type'        => 'text',
					'description' => __( 'The name shown to the customer in their UPI app (usually your store / business name).', 'nexpay' ),
					'desc_tip'    => true,
				),
			)
		);
	}

	/**
	 * Validate a UPI ID (VPA): local part of letters, digits, dots, dashes
	 * and underscores, then "@" and a PSP handle of letters.
	 *
	 * @param string $vpa Candidate VPA.
	 * @return bool
	 */
	public static function is_valid_vpa( $vpa ) {
		return (bool) preg_match( '/^[\w.\-]{2,}@[a-zA-Z]{2,}[a-zA-Z0-9]*$/', trim( (string) $vpa ) );
	}

	/**
	 * Credentials required before this gateway may be enabled.
	 *
	 * @return array
	 */
	protected function required_settings_fields() {
		return array(
			'merchant_vpa' => __( 'Merchant UPI ID (VPA)', 'nexpay' ),
			'payee_name'   => __( 'Payee Name', 'nexpay' ),
		);
	}

	/**
	 * Checkout field: the customer's UPI ID.
	 */
	protected function render_checkout_fields() {
		$this->field_input( 'nexpay_upi_vpa', __( 'Your UPI ID (VPA)', 'nexpay' ), 'text', 'yourname@okbank' );
	}

	/**
	 * Validate the checkout fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( ! self::is_valid_vpa( $this->posted_field( 'nexpay_upi_vpa' ) ) ) {
			wc_add_notice( __( 'Please enter a valid UPI ID (e.g. yourname@okbank).', 'nexpay' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Build a UPI intent URI from raw parts. The merchant VPA and payee
	 * name come from the gateway settings; the amount, reference and note
	 * come from the order — every value is RFC 3986 encoded.
	 * Static so it can be unit tested.
	 *
	 * @param string $merchant_vpa Merchant UPI ID (pa).
	 * @param string $payee_name   Payee name (pn).
	 * @param string $amount       Exact amount (am), decimal string.
	 * @param string $reference    Transaction reference (tr).
	 * @param string $note         Transaction note (tn).
	 * @return string upi://pay?... URI.
	 */
	public static function build_upi_uri( $merchant_vpa, $payee_name, $amount, $reference, $note ) {
		$params = array(
			'pa' => (string) $merchant_vpa,
			'pn' => (string) $payee_name,
			'am' => self::format_amount( $amount ),
			'cu' => 'INR',
			'tr' => (string) $reference,
			'tn' => (string) $note,
		);

		return 'upi://pay?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Build the UPI intent link for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string upi://pay?... URL.
	 */
	public function build_upi_link( WC_Order $order ) {
		return self::build_upi_uri(
			$this->merchant_vpa,
			'' !== $this->payee_name ? $this->payee_name : get_bloginfo( 'name' ),
			$order->get_total(),
			'NX' . $order->get_id(),
			sprintf( 'Order #%s', $order->get_order_number() )
		);
	}

	/**
	 * Process the payment: store the customer's VPA, generate the intent
	 * link and place the order on-hold awaiting the UPI payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_failure( __( 'Order not found.', 'nexpay' ) );
		}

		if ( ! self::is_valid_vpa( $this->merchant_vpa ) ) {
			$this->log( 'Payment aborted: merchant UPI ID is missing/invalid.', 'error' );
			return $this->payment_failure( __( 'UPI is not configured correctly. Please contact the store owner.', 'nexpay' ) );
		}

		$customer_vpa = $this->posted_field( 'nexpay_upi_vpa' );
		if ( ! self::is_valid_vpa( $customer_vpa ) ) {
			return $this->payment_failure( __( 'Please enter a valid UPI ID (e.g. yourname@okbank).', 'nexpay' ) );
		}
		$upi_link = $this->build_upi_link( $order );

		$order->update_meta_data( '_nexpay_upi_customer_vpa', $customer_vpa );
		$order->update_meta_data( '_nexpay_upi_link', $upi_link );
		$order->update_status(
			'on-hold',
			sprintf( __( 'Awaiting UPI payment from %s. Verify the payment in your UPI app, then mark this order as Processing/Completed.', 'nexpay' ), $customer_vpa )
		);
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Show the pay button + intent link on the thank-you page while the
	 * order is still awaiting payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_instructions( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$upi_link = $order->get_meta( '_nexpay_upi_link' );
		if ( '' === $upi_link ) {
			$upi_link = $this->build_upi_link( $order );
		}

		echo '<section class="nexpay-upi-box">';
		echo '<h2>' . esc_html__( 'Complete your UPI payment', 'nexpay' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tap the button below to open your UPI app and pay. Your payment is not confirmed automatically — your order stays on hold until the store verifies the payment against the UPI transaction reference.', 'nexpay' ) . '</p>';
		printf(
			'<p><a class="button nexpay-upi-pay-button" href="%s">%s</a></p>',
			esc_url( $upi_link ),
			esc_html( sprintf( __( 'Pay %s via UPI', 'nexpay' ), $order->get_formatted_order_total() ) )
		);
		printf( '<p class="nexpay-upi-payload"><code>%s</code></p>', esc_html( $upi_link ) );
		echo '<p>' . esc_html__( 'Your order will be confirmed once we verify the payment.', 'nexpay' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Add the payment instructions to the customer email for on-hold orders.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email is for the admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || $this->id !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}

		$upi_link = $order->get_meta( '_nexpay_upi_link' );
		if ( '' === $upi_link ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Pay via UPI:', 'nexpay' ) . ' ' . esc_html( $upi_link ) . "\n";
		} else {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Complete your payment with any UPI app:', 'nexpay' ),
				esc_url( $upi_link ),
				esc_html__( 'Pay via UPI', 'nexpay' )
			);
		}
	}
}
