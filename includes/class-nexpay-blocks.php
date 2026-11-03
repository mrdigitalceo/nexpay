<?php
/**
 * NexPay — WooCommerce Blocks (Cart/Checkout) payment method integration.
 *
 * Registers every NexPay gateway as a Blocks payment method. The PHP side
 * is parameterized: one class instance per gateway id, fed by the
 * gateway's own WooCommerce settings. The shared frontend script
 * (assets/js/blocks.js) reads the per-method data registered here —
 * title, description and the gateway's checkout fields — and renders the
 * field inputs inside the block checkout, returning their values as
 * paymentMethodData. nexpay.php copies that data onto the order via the
 * woocommerce_store_api_checkout_update_order_from_request hook, so the
 * gateway process_payment() works unchanged for block orders.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Parameterized Blocks payment method for one NexPay gateway.
 */
final class NexPay_Blocks_Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Payment method name (= WooCommerce gateway id), set in constructor.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Whether the shared frontend script has been registered already.
	 *
	 * @var bool
	 */
	protected static $script_registered = false;

	/**
	 * Constructor.
	 *
	 * @param string $gateway_id WooCommerce gateway id, e.g. "nexpay_jazzcash".
	 */
	public function __construct( $gateway_id ) {
		$this->name = $gateway_id;
	}

	/**
	 * Checkout field definitions per gateway, rendered by blocks.js and
	 * returned as paymentMethodData keys. Names match the classic
	 * checkout field names exactly.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return array
	 */
	public static function fields_for_gateway( $gateway_id ) {
		$mobile_pk = array(
			'name'        => '',
			'label'       => __( 'Mobile Number', 'nexpay' ),
			'type'        => 'tel',
			'placeholder' => '03XX XXXXXXX',
		);
		$mobile_bd = array(
			'name'        => '',
			'label'       => __( 'Mobile Number', 'nexpay' ),
			'type'        => 'tel',
			'placeholder' => '01XXXXXXXXX',
		);

		switch ( $gateway_id ) {
			case 'nexpay_jazzcash':
				$mobile_pk['name'] = 'nexpay_jazzcash_mobile';
				return array(
					$mobile_pk,
					array(
						'name'        => 'nexpay_jazzcash_cnic',
						'label'       => __( 'CNIC (last 6 digits)', 'nexpay' ),
						'type'        => 'text',
						'placeholder' => '123456',
					),
				);
			case 'nexpay_easypaisa':
				$mobile_pk['name'] = 'nexpay_easypaisa_mobile';
				return array( $mobile_pk );
			case 'nexpay_bkash':
				$mobile_bd['name']    = 'nexpay_bkash_mobile';
				$mobile_bd['label']   = __( 'bKash Mobile Number', 'nexpay' );
				return array( $mobile_bd );
			case 'nexpay_nagad':
				$mobile_bd['name']  = 'nexpay_nagad_mobile';
				$mobile_bd['label'] = __( 'Nagad Mobile Number', 'nexpay' );
				return array( $mobile_bd );
			case 'nexpay_upi':
				return array(
					array(
						'name'        => 'nexpay_upi_vpa',
						'label'       => __( 'Your UPI ID (VPA)', 'nexpay' ),
						'type'        => 'text',
						'placeholder' => 'yourname@okbank',
					),
				);
			case 'nexpay_mpesa':
				return array(
					array(
						'name'        => 'nexpay_mpesa_phone',
						'label'       => __( 'M-Pesa Phone Number', 'nexpay' ),
						'type'        => 'tel',
						'placeholder' => '07XX XXX XXX',
					),
				);
		}

		return array();
	}

	/**
	 * Load settings and register the shared frontend script.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );

		if ( ! self::$script_registered ) {
			wp_register_script(
				'nexpay-blocks',
				NEXPAY_PLUGIN_URL . 'assets/js/blocks.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n' ),
				NEXPAY_VERSION,
				true
			);
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'nexpay-blocks', 'nexpay', NEXPAY_PLUGIN_PATH . 'languages' );
			}
			self::$script_registered = true;
		}
	}

	/**
	 * The live gateway instance for this method, if WooCommerce has one.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	protected function gateway() {
		if ( function_exists( 'nexpay_get_gateway_instance' ) ) {
			return nexpay_get_gateway_instance( $this->name );
		}
		return null;
	}

	/**
	 * Active only when the gateway is enabled and the store currency is
	 * the gateway's settlement currency.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}

		$map = NexPay_Gateway::currency_map();
		if ( isset( $map[ $this->name ] ) && function_exists( 'get_woocommerce_currency' ) ) {
			return get_woocommerce_currency() === $map[ $this->name ];
		}

		return true;
	}

	/**
	 * Script handles to enqueue for this payment method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		return array( 'nexpay-blocks' );
	}

	/**
	 * Data handed to blocks.js for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		$meta    = function_exists( 'nexpay_gateway_meta' ) ? nexpay_gateway_meta() : array();
		$map     = NexPay_Gateway::currency_map();

		$title       = $gateway ? $gateway->get_option( 'title' ) : ( isset( $meta[ $this->name ]['title'] ) ? $meta[ $this->name ]['title'] : $this->name );
		$description = $gateway ? $gateway->get_option( 'description' ) : '';

		return array(
			'title'         => $title,
			'description'   => $description,
			'supports'      => array(
				'features' => array( 'products' ),
			),
			'currency'      => isset( $map[ $this->name ] ) ? $map[ $this->name ] : '',
			'storeCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'fields'        => self::fields_for_gateway( $this->name ),
		);
	}
}
