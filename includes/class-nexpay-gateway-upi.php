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
 * — puts the order on-hold, and shows the link (plus the raw QR payload) on
 * the thank-you page so the customer can pay with any UPI app (BHIM, GPay,
 * PhonePe, Paytm…). The store owner verifies the payment against the UPI
 * reference and marks the order complete.
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
				__( 'Pay instantly with any UPI app (BHIM, Google Pay, PhonePe, Paytm).', 'nexpay' )
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
	 * Build the UPI intent link / QR payload for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string upi://pay?... URL.
	 */
	public function build_upi_link( WC_Order $order ) {
		$params = array(
			'pa' => $this->merchant_vpa,                                     // Payee address (merchant VPA).
			'pn' => '' !== $this->payee_name ? $this->payee_name : get_bloginfo( 'name' ), // Payee name.
			'am' => number_format( (float) $order->get_total(), 2, '.', '' ), // Amount.
			'cu' => 'INR',                                                    // Currency.
			'tr' => 'NX' . $order->get_id(),                                  // Transaction reference.
			'tn' => sprintf( 'Order #%s', $order->get_order_number() ),       // Transaction note.
		);

		return 'upi://pay?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
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
		$upi_link     = $this->build_upi_link( $order );

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
	 * Show the pay button + QR payload on the thank-you page while the
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
		echo '<p>' . esc_html__( 'Tap the button below (on mobile) to pay with any UPI app, or scan a QR code of the payload with your UPI app:', 'nexpay' ) . '</p>';
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
