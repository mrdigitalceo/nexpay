<?php
/**
 * Plugin Name:       NexPay — Multi-Country Payment Gateways for WooCommerce
 * Plugin URI:        https://github.com/mrdigitalceo/nexpay
 * Description:       Free, open-source multi-country payment gateways for WooCommerce. Accept JazzCash &amp; Easypaisa (Pakistan), bKash &amp; Nagad (Bangladesh), UPI (India) and M-Pesa (Kenya) — with live/sandbox test modes and provider-verified callbacks.
 * Version:           1.1.0
 * Author:            Mr Arslan CEO
 * Author URI:        https://mrarslanceo.site
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexpay
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.3
 *
 * NexPay is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXPAY_VERSION', '1.1.0' );
define( 'NEXPAY_PLUGIN_FILE', __FILE__ );
define( 'NEXPAY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEXPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce features:
 *  - HPOS (High-Performance Order Storage / custom order tables)
 *  - Cart & Checkout blocks
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Gateway class names registered by NexPay, in display order.
 *
 * @return string[]
 */
function nexpay_gateway_classes() {
	return array(
		'NexPay_Gateway_JazzCash',
		'NexPay_Gateway_Easypaisa',
		'NexPay_Gateway_BKash',
		'NexPay_Gateway_Nagad',
		'NexPay_Gateway_UPI',
		'NexPay_Gateway_Mpesa',
	);
}

/**
 * Static metadata for every NexPay gateway (used by the admin overview page).
 *
 * @return array[]
 */
function nexpay_gateway_meta() {
	return array(
		'nexpay_jazzcash'  => array(
			'title'   => 'JazzCash',
			'country' => 'Pakistan',
			'code'    => 'PK',
		),
		'nexpay_easypaisa' => array(
			'title'   => 'Easypaisa',
			'country' => 'Pakistan',
			'code'    => 'PK',
		),
		'nexpay_bkash'     => array(
			'title'   => 'bKash',
			'country' => 'Bangladesh',
			'code'    => 'BD',
		),
		'nexpay_nagad'     => array(
			'title'   => 'Nagad',
			'country' => 'Bangladesh',
			'code'    => 'BD',
		),
		'nexpay_upi'       => array(
			'title'   => 'UPI',
			'country' => 'India',
			'code'    => 'IN',
		),
		'nexpay_mpesa'     => array(
			'title'   => 'M-Pesa',
			'country' => 'Kenya',
			'code'    => 'KE',
		),
	);
}

/**
 * Bootstrap the plugin once all plugins (incl. WooCommerce) are loaded.
 */
function nexpay_init() {
	load_plugin_textdomain( 'nexpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'nexpay_woocommerce_missing_notice' );
		return;
	}

	if ( is_admin() && ! extension_loaded( 'openssl' ) ) {
		// Nagad's RSA encryption/signing needs OpenSSL. Warn, never fatal.
		add_action( 'admin_notices', 'nexpay_openssl_missing_notice' );
	}

	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-logger.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-jazzcash.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-easypaisa.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-bkash.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-nagad.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-upi.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-mpesa.php';

	add_filter( 'woocommerce_payment_gateways', 'nexpay_register_gateways' );

	// WooCommerce Blocks (Cart/Checkout) payment method registration.
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'nexpay_register_blocks_methods' );
	// Copy Blocks paymentMethodData onto the order before payment runs.
	add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'nexpay_store_api_save_checkout_data', 10, 2 );

	if ( is_admin() ) {
		require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-admin.php';
		NexPay_Admin::init();
	}
}
add_action( 'plugins_loaded', 'nexpay_init' );

/**
 * Register all six NexPay gateways as WooCommerce Blocks payment methods.
 *
 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Blocks registry.
 */
function nexpay_register_blocks_methods( $registry ) {
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-blocks.php';

	foreach ( array_keys( nexpay_gateway_meta() ) as $gateway_id ) {
		$registry->register( new NexPay_Blocks_Payment_Method( $gateway_id ) );
	}
}

/**
 * Find the live gateway instance for a gateway id.
 *
 * @param string $gateway_id Gateway id, e.g. "nexpay_jazzcash".
 * @return WC_Payment_Gateway|null
 */
function nexpay_get_gateway_instance( $gateway_id ) {
	if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->payment_gateways ) || ! WC()->payment_gateways ) {
		return null;
	}

	foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
		if ( isset( $gateway->id ) && $gateway->id === $gateway_id ) {
			return $gateway;
		}
	}

	return null;
}

/**
 * Store API checkout hook: copy the Blocks paymentMethodData for a NexPay
 * gateway onto the order (as meta) and into the gateway's request-scoped
 * store, so validate_fields()/process_payment() see the values exactly as
 * if they had been posted by the classic checkout form.
 *
 * @param WC_Order                                        $order   Order.
 * @param Automattic\WooCommerce\StoreApi\Utilities\OrderController|WP_REST_Request $request Request.
 */
function nexpay_store_api_save_checkout_data( $order, $request ) {
	$payment_method = method_exists( $request, 'get_param' ) ? $request->get_param( 'payment_method' ) : null;
	if ( ! is_string( $payment_method ) || 0 !== strpos( $payment_method, 'nexpay_' ) ) {
		return;
	}

	$payment_data = $request->get_param( 'payment_data' );
	$data         = array();
	if ( is_array( $payment_data ) ) {
		foreach ( $payment_data as $pair ) {
			if ( is_array( $pair ) && isset( $pair['key'] ) ) {
				$data[ $pair['key'] ] = isset( $pair['value'] ) ? $pair['value'] : '';
			} elseif ( is_array( $pair ) ) {
				$data = array_merge( $data, $pair );
			}
		}
	}
	// Some Blocks versions deliver an associative array directly.
	if ( empty( $data ) && is_array( $payment_data ) ) {
		$data = $payment_data;
	}
	if ( empty( $data ) ) {
		return;
	}

	$gateway = nexpay_get_gateway_instance( $payment_method );
	if ( $gateway && method_exists( $gateway, 'save_blocks_checkout_data' ) ) {
		$gateway->save_blocks_checkout_data( $order, $data );
		$order->save();
	}
}

/**
 * Register all NexPay gateways with WooCommerce.
 *
 * @param string[] $gateways Existing gateway class names.
 * @return string[]
 */
function nexpay_register_gateways( $gateways ) {
	return array_merge( $gateways, nexpay_gateway_classes() );
}

/**
 * Enqueue NexPay checkout styles/scripts on the checkout page only.
 */
function nexpay_enqueue_checkout_assets() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	wp_enqueue_style(
		'nexpay-checkout',
		NEXPAY_PLUGIN_URL . 'assets/css/checkout.css',
		array(),
		NEXPAY_VERSION
	);

	wp_enqueue_script(
		'nexpay-checkout',
		NEXPAY_PLUGIN_URL . 'assets/js/checkout.js',
		array( 'jquery' ),
		NEXPAY_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'nexpay_enqueue_checkout_assets' );

/**
 * Add a "Gateways overview" link next to the plugin on the Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function nexpay_plugin_action_links( $links ) {
	$url = admin_url( 'admin.php?page=nexpay' );
	array_unshift(
		$links,
		sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Gateways', 'nexpay' ) )
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'nexpay_plugin_action_links' );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function nexpay_woocommerce_missing_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'NexPay requires WooCommerce to be installed and active. Please install WooCommerce to accept payments with NexPay.', 'nexpay' )
	);
}

/**
 * Admin notice shown when the PHP OpenSSL extension is missing (Nagad
 * cannot encrypt/sign without it). A warning only — never a fatal error.
 */
function nexpay_openssl_missing_notice() {
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'NexPay: the PHP OpenSSL extension is not enabled on this server. The Nagad gateway cannot work without it — please ask your host to enable OpenSSL.', 'nexpay' )
	);
}
