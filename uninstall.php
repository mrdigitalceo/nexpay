<?php
/**
 * NexPay uninstaller.
 *
 * Removes every setting NexPay stored: the WooCommerce gateway settings
 * (woocommerce_nexpay_*_settings) and the cached gateway access tokens.
 * Orders and their payment meta are intentionally left untouched.
 *
 * @package NexPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$nexpay_gateways = array(
	'nexpay_jazzcash',
	'nexpay_easypaisa',
	'nexpay_bkash',
	'nexpay_nagad',
	'nexpay_upi',
	'nexpay_mpesa',
);

foreach ( $nexpay_gateways as $nexpay_gateway_id ) {
	delete_option( 'woocommerce_' . $nexpay_gateway_id . '_settings' );
}

// Cached bKash / M-Pesa access tokens.
delete_transient( 'nexpay_bkash_token_sandbox' );
delete_transient( 'nexpay_bkash_token_live' );
delete_transient( 'nexpay_mpesa_token_sandbox' );
delete_transient( 'nexpay_mpesa_token_live' );
