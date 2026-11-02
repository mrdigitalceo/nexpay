<?php
/**
 * Plugin Name:       NexPay — Multi-Country Payment Gateways for WooCommerce
 * Plugin URI:        https://github.com/mrdigitalceo/nexpay
 * Description:       Free, open-source multi-country payment gateways for WooCommerce. Accept JazzCash &amp; Easypaisa (Pakistan), bKash &amp; Nagad (Bangladesh), UPI (India) and M-Pesa (Kenya) — with live/sandbox test modes and secure, hash-verified callbacks.
 * Version:           1.0.0
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

define( 'NEXPAY_VERSION', '1.0.0' );
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

	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-jazzcash.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-easypaisa.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-bkash.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-nagad.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-upi.php';
	require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-gateway-mpesa.php';

	add_filter( 'woocommerce_payment_gateways', 'nexpay_register_gateways' );

	if ( is_admin() ) {
		require_once NEXPAY_PLUGIN_PATH . 'includes/class-nexpay-admin.php';
		NexPay_Admin::init();
	}
}
add_action( 'plugins_loaded', 'nexpay_init' );

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
