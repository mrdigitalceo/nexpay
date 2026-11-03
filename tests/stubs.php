<?php
/**
 * NexPay tests — minimal WordPress / WooCommerce stubs.
 *
 * Just enough of the WP/WC surface for the gateway classes to load and
 * for their pure/static helpers (plus a light Easypaisa integration
 * check) to run without a WordPress install. NOT for production use.
 *
 * @package NexPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['nexpay_test_settings'] = array();
$GLOBALS['nexpay_test_currency'] = 'PKR';

// ---------------------------------------------------------------- i18n --
function __( $text, $domain = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_attr__( $text, $domain = null ) {
	return $text;
}

// --------------------------------------------------------------- hooks --
function add_action() {
	return true;
}
function add_filter() {
	return true;
}
function apply_filters( $tag, $value = null ) {
	return $value;
}
function do_action() {}

// ------------------------------------------------------------ escaping --
function sanitize_text_field( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'sanitize_text_field', $value );
	}
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return (string) $url;
}
function wp_kses_post( $html ) {
	return $html;
}
function wpautop( $text ) {
	return $text;
}
function wptexturize( $text ) {
	return $text;
}

// --------------------------------------------------------------- misc ---
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}
function add_query_arg( ...$args ) {
	if ( isset( $args[0] ) && is_string( $args[0] ) ) {
		// add_query_arg( 'key', 'value', 'url' ) form.
		$params = array( $args[0] => $args[1] );
		$url    = isset( $args[2] ) ? $args[2] : '';
	} else {
		$params = (array) $args[0];
		$url    = isset( $args[1] ) ? $args[1] : '';
	}
	$query = array();
	foreach ( $params as $key => $value ) {
		$query[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $query );
}
function current_time( $type ) {
	return time();
}
function get_bloginfo( $show = '' ) {
	return 'Test Store';
}
function wp_generate_password( $length = 12, $special = true ) {
	return str_repeat( 'a', $length );
}
function get_woocommerce_currency() {
	return $GLOBALS['nexpay_test_currency'];
}
function get_transient( $key ) {
	return isset( $GLOBALS['nexpay_test_transients'][ $key ] ) ? $GLOBALS['nexpay_test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['nexpay_test_transients'][ $key ] = $value;
	return true;
}
function status_header( $code ) {
	$GLOBALS['nexpay_test_status_header'] = $code;
}
function is_admin() {
	return false;
}
function is_checkout() {
	return true;
}

// ------------------------------------------------------ WooCommerce ---
class WC_Payment_Gateway {

	public $id = '';
	public $title = '';
	public $description = '';
	public $method_title = '';
	public $method_description = '';
	public $has_fields = false;
	public $supports = array();
	public $settings = array();
	public $form_fields = array();

	public function init_settings() {
		$this->settings = isset( $GLOBALS['nexpay_test_settings'][ $this->id ] )
			? $GLOBALS['nexpay_test_settings'][ $this->id ]
			: array();
	}

	public function get_option( $key, $empty_value = null ) {
		return isset( $this->settings[ $key ] ) && '' !== $this->settings[ $key ]
			? $this->settings[ $key ]
			: $empty_value;
	}

	public function init_form_fields() {}

	public function get_title() {
		return $this->title;
	}

	public function is_available() {
		return true;
	}
}

class WC_Order {

	private $meta = array();
	private $id;
	private $total;
	private $paid = false;

	public function __construct( $id = 0, $total = '0.00' ) {
		$this->id    = $id;
		$this->total = $total;
	}

	public function get_id() {
		return $this->id;
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_billing_email() {
		return 'customer@example.test';
	}
	public function get_order_key() {
		return 'wc_order_testkey';
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
		return true;
	}
	public function is_paid() {
		return $this->paid;
	}
}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wc_get_order( $id ) {
	return isset( $GLOBALS['nexpay_test_orders'][ $id ] ) ? $GLOBALS['nexpay_test_orders'][ $id ] : null;
}
function wc_get_orders( $args = array() ) {
	return array();
}
function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['nexpay_test_notices'][] = array( $type, $message );
}
function wc_get_logger() {
	return new class() {
		public $entries = array();
		public function log( $level, $message, $context = array() ) {
			$GLOBALS['nexpay_test_log'][] = array( $level, $message, $context );
		}
	};
}
