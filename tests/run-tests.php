<?php
/**
 * NexPay — dependency-free unit test runner.
 *
 * Usage: php tests/run-tests.php
 * Prints one PASS/FAIL line per test plus a summary; exits 0 when all
 * tests pass, 1 otherwise. Uses the minimal WP/WC stubs in stubs.php —
 * no WordPress install, no network, no real credentials.
 *
 * @package NexPay
 */

error_reporting( E_ALL );

$root = dirname( __DIR__ );

require __DIR__ . '/stubs.php';
require $root . '/includes/class-nexpay-logger.php';
require $root . '/includes/class-nexpay-gateway.php';
require $root . '/includes/class-nexpay-gateway-jazzcash.php';
require $root . '/includes/class-nexpay-gateway-easypaisa.php';
require $root . '/includes/class-nexpay-gateway-bkash.php';
require $root . '/includes/class-nexpay-gateway-nagad.php';
require $root . '/includes/class-nexpay-gateway-upi.php';
require $root . '/includes/class-nexpay-gateway-mpesa.php';

// ------------------------------------------------------------ harness ---
$nexpay_tests_run    = 0;
$nexpay_tests_failed = 0;

function nexpay_test( $name, $fn ) {
	global $nexpay_tests_run, $nexpay_tests_failed;
	$nexpay_tests_run++;
	try {
		$fn();
		echo "[PASS] {$name}\n";
	} catch ( Exception $e ) {
		$nexpay_tests_failed++;
		echo "[FAIL] {$name} — {$e->getMessage()}\n";
	}
}

function nexpay_assert_same( $expected, $actual, $what = 'value' ) {
	if ( $expected !== $actual ) {
		throw new Exception( $what . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function nexpay_assert_true( $actual, $what = 'value' ) {
	if ( true !== $actual ) {
		throw new Exception( $what . ': expected true, got ' . var_export( $actual, true ) );
	}
}
function nexpay_assert_false( $actual, $what = 'value' ) {
	if ( false !== $actual ) {
		throw new Exception( $what . ': expected false, got ' . var_export( $actual, true ) );
	}
}
function nexpay_assert_contains( $needle, $haystack, $what = 'string' ) {
	if ( false === strpos( (string) $haystack, (string) $needle ) ) {
		throw new Exception( $what . ': ' . var_export( $haystack, true ) . ' does not contain ' . var_export( $needle, true ) );
	}
}
function nexpay_assert_not_contains( $needle, $haystack, $what = 'string' ) {
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
		throw new Exception( $what . ': ' . var_export( $haystack, true ) . ' must not contain ' . var_export( $needle, true ) );
	}
}

// ------------------------------------------------------------ JazzCash --
nexpay_test( 'JazzCash: pp_SecureHash matches the documented algorithm (known vector)', function () {
	$salt   = 'TestSalt123';
	$fields = array(
		'pp_Amount'        => '10000',
		'pp_BillReference' => 'order-7',
		'pp_MerchantID'    => 'MERCH1',
		'pp_TxnCurrency'   => 'PKR',
		'pp_TxnRefNo'      => 'T202401011200007',
	);

	// Independent re-implementation of the documented algorithm.
	$sorted = $fields;
	ksort( $sorted );
	$to_hash = $salt;
	foreach ( $sorted as $value ) {
		if ( '' !== (string) $value ) {
			$to_hash .= '&' . $value;
		}
	}
	$expected = strtoupper( hash_hmac( 'sha256', $to_hash, $salt ) );

	nexpay_assert_same( $expected, NexPay_Gateway_JazzCash::build_secure_hash( $fields, $salt ), 'secure hash' );
} );

nexpay_test( 'JazzCash: tampered amount produces a different hash (IPN forgery rejected)', function () {
	$salt           = 'TestSalt123';
	$fields         = array( 'pp_Amount' => '10000', 'pp_TxnRefNo' => 'T1' );
	$tampered       = array( 'pp_Amount' => '100', 'pp_TxnRefNo' => 'T1' );
	$original_hash  = NexPay_Gateway_JazzCash::build_secure_hash( $fields, $salt );
	if ( $original_hash === NexPay_Gateway_JazzCash::build_secure_hash( $tampered, $salt ) ) {
		throw new Exception( 'tampered fields must not produce the original hash' );
	}
	// Empty values and pp_SecureHash itself must be excluded.
	$with_noise          = $fields;
	$with_noise['pp_SecureHash'] = 'DEADBEEF';
	$with_noise['pp_Extra']      = '';
	nexpay_assert_same( $original_hash, NexPay_Gateway_JazzCash::build_secure_hash( $with_noise, $salt ), 'hash with noise fields' );
} );

// ----------------------------------------------------------- Easypaisa --
nexpay_test( 'Easypaisa: keyed merchant hash matches known vector', function () {
	$fields = array(
		'storeId'     => 'S1',
		'orderRefNum' => '42',
		'amount'      => '1500.00',
		'postBackURL' => 'https://example.test/?wc-api=nexpay_easypaisa',
		'accountNum'  => '03001234567',
	);
	$expected = strtoupper( hash( 'sha256', 'S1&42&1500.00&https://example.test/?wc-api=nexpay_easypaisa&03001234567&KEY123' ) );
	nexpay_assert_same( $expected, NexPay_Gateway_Easypaisa::build_merchant_hash( $fields, 'KEY123' ), 'keyed hash' );
	nexpay_assert_same( '', NexPay_Gateway_Easypaisa::build_merchant_hash( $fields, '' ), 'hash without key must be empty' );
} );

function nexpay_easypaisa_fixture() {
	$expected = array(
		'storeId'     => 'S1',
		'accountNum'  => '03001234567',
		'amount'      => '1500.00',
		'orderRefNum' => '42',
		'postBackURL' => 'https://example.test/?wc-api=nexpay_easypaisa',
	);
	$posted   = $expected;
	unset( $posted['postBackURL'] );
	$posted['status']            = '0000';
	$posted['transactionId']     = 'EP-TXN-1';
	$posted['merchantHashedReq'] = NexPay_Gateway_Easypaisa::build_merchant_hash( $expected, 'KEY123' );
	return array( $posted, $expected );
}

nexpay_test( 'Easypaisa: valid keyed postback is accepted', function () {
	list( $posted, $expected ) = nexpay_easypaisa_fixture();
	nexpay_assert_true( NexPay_Gateway_Easypaisa::verify_postback_data( $posted, $expected, 'KEY123' ), 'verify result' );
} );

nexpay_test( 'Easypaisa: forged UNKEYED postback (v1.0.0 scheme) is rejected', function () {
	list( $posted, $expected ) = nexpay_easypaisa_fixture();
	// The old scheme: SHA-256 over the fields with no key at all.
	$parts                       = NexPay_Gateway_Easypaisa::hash_field_values( $expected );
	$posted['merchantHashedReq'] = strtoupper( hash( 'sha256', implode( '&', $parts ) ) );
	nexpay_assert_same( 'hash_mismatch', NexPay_Gateway_Easypaisa::verify_postback_data( $posted, $expected, 'KEY123' ), 'verify result' );
} );

nexpay_test( 'Easypaisa: wrong amount is rejected', function () {
	list( $posted, $expected ) = nexpay_easypaisa_fixture();
	$posted['amount'] = '1.00';
	nexpay_assert_same( 'amount_mismatch', NexPay_Gateway_Easypaisa::verify_postback_data( $posted, $expected, 'KEY123' ), 'verify result' );
} );

nexpay_test( 'Easypaisa: wrong order reference is rejected', function () {
	list( $posted, $expected ) = nexpay_easypaisa_fixture();
	$posted['orderRefNum'] = '99';
	nexpay_assert_same( 'order_mismatch', NexPay_Gateway_Easypaisa::verify_postback_data( $posted, $expected, 'KEY123' ), 'verify result' );
} );

nexpay_test( 'Easypaisa: wrong store / currency / missing key are rejected', function () {
	list( $posted, $expected ) = nexpay_easypaisa_fixture();

	$bad_store            = $posted;
	$bad_store['storeId'] = 'OTHER';
	nexpay_assert_same( 'store_mismatch', NexPay_Gateway_Easypaisa::verify_postback_data( $bad_store, $expected, 'KEY123' ), 'store check' );

	$bad_ccy              = $posted;
	$bad_ccy['currency']  = 'USD';
	nexpay_assert_same( 'currency_mismatch', NexPay_Gateway_Easypaisa::verify_postback_data( $bad_ccy, $expected, 'KEY123' ), 'currency check' );

	nexpay_assert_same( 'missing_hash_key', NexPay_Gateway_Easypaisa::verify_postback_data( $posted, $expected, '' ), 'missing key check' );

	$no_hash = $posted;
	unset( $no_hash['merchantHashedReq'] );
	nexpay_assert_same( 'missing_fields', NexPay_Gateway_Easypaisa::verify_postback_data( $no_hash, $expected, 'KEY123' ), 'missing fields check' );
} );

nexpay_test( 'Easypaisa: payload hash equals hash of expected fields (integration)', function () {
	$GLOBALS['nexpay_test_settings']['nexpay_easypaisa'] = array(
		'enabled'        => 'yes',
		'testmode'       => 'yes',
		'title'          => 'Easypaisa',
		'description'    => '',
		'store_id'       => 'S1',
		'account_number' => '03001234567',
		'hash_key'       => 'KEY123',
	);
	$gateway = new NexPay_Gateway_Easypaisa();
	$order   = new WC_Order( 42, '1500.00' );

	$method = new ReflectionMethod( $gateway, 'build_payload' );
	$method->setAccessible( true );
	$payload = $method->invoke( $gateway, $order );

	$expected_fields = array(
		'storeId'     => 'S1',
		'accountNum'  => '03001234567',
		'amount'      => '1500.00',
		'orderRefNum' => '42',
		'postBackURL' => 'https://example.test/?wc-api=nexpay_easypaisa',
	);
	nexpay_assert_same(
		NexPay_Gateway_Easypaisa::build_merchant_hash( $expected_fields, 'KEY123' ),
		$payload['merchantHashedReq'],
		'payload merchantHashedReq'
	);
} );

// ------------------------------------------------------------- amounts --
nexpay_test( 'Amounts: minor-unit conversion and formatting', function () {
	nexpay_assert_same( 12345, NexPay_Gateway::amount_in_minor_units( '123.45' ), 'paisa conversion' );
	nexpay_assert_same( 10000, NexPay_Gateway::amount_in_minor_units( 100 ), 'whole amount conversion' );
	nexpay_assert_same( '100.00', NexPay_Gateway::format_amount( 100 ), 'format whole' );
	nexpay_assert_same( '99.90', NexPay_Gateway::format_amount( '99.9' ), 'format one decimal' );
} );

nexpay_test( 'Currency map: every gateway settles in its home currency', function () {
	nexpay_assert_same(
		array(
			'nexpay_jazzcash'  => 'PKR',
			'nexpay_easypaisa' => 'PKR',
			'nexpay_bkash'     => 'BDT',
			'nexpay_nagad'     => 'BDT',
			'nexpay_upi'       => 'INR',
			'nexpay_mpesa'     => 'KES',
		),
		NexPay_Gateway::currency_map(),
		'currency map'
	);
} );

// ---------------------------------------------------------- validation --
nexpay_test( 'Validation: PK / BD mobile and CNIC formats', function () {
	nexpay_assert_true( NexPay_Gateway::is_valid_pk_mobile( '03001234567' ), 'PK local' );
	nexpay_assert_true( NexPay_Gateway::is_valid_pk_mobile( '+92 300 1234567' ), 'PK intl' );
	nexpay_assert_false( NexPay_Gateway::is_valid_pk_mobile( '0300123456' ), 'PK too short' );
	nexpay_assert_false( NexPay_Gateway::is_valid_pk_mobile( '04235781234' ), 'PK landline prefix' );

	nexpay_assert_true( NexPay_Gateway::is_valid_bd_mobile( '01712345678' ), 'BD valid' );
	nexpay_assert_false( NexPay_Gateway::is_valid_bd_mobile( '01212345678' ), 'BD invalid prefix' );
	nexpay_assert_false( NexPay_Gateway::is_valid_bd_mobile( '0171234567' ), 'BD too short' );

	nexpay_assert_true( NexPay_Gateway::is_valid_cnic_last6( '123456' ), 'CNIC 6 digits' );
	nexpay_assert_false( NexPay_Gateway::is_valid_cnic_last6( '12345' ), 'CNIC 5 digits' );
	nexpay_assert_false( NexPay_Gateway::is_valid_cnic_last6( '1234567' ), 'CNIC 7 digits' );
} );

// ---------------------------------------------------------------- UPI ---
nexpay_test( 'UPI: intent URI encodes pa/pn/am/cu/tr exactly', function () {
	$uri = NexPay_Gateway_UPI::build_upi_uri( 'store@okbank', 'My Store', '499', 'NX42', 'Order #42' );
	nexpay_assert_contains( 'upi://pay?', $uri, 'URI scheme' );

	parse_str( parse_url( $uri, PHP_URL_QUERY ), $q );
	nexpay_assert_same( 'store@okbank', $q['pa'], 'pa' );
	nexpay_assert_same( 'My Store', $q['pn'], 'pn' );
	nexpay_assert_same( '499.00', $q['am'], 'am' );
	nexpay_assert_same( 'INR', $q['cu'], 'cu' );
	nexpay_assert_same( 'NX42', $q['tr'], 'tr' );
	nexpay_assert_contains( 'My%20Store', $uri, 'RFC3986 space encoding' );
	nexpay_assert_true( NexPay_Gateway_UPI::is_valid_vpa( 'yourname@okbank' ), 'valid VPA' );
	nexpay_assert_false( NexPay_Gateway_UPI::is_valid_vpa( 'not-a-vpa' ), 'invalid VPA' );
} );

// -------------------------------------------------------------- M-Pesa --
nexpay_test( 'M-Pesa: phone normalization', function () {
	nexpay_assert_same( '254712345678', NexPay_Gateway_Mpesa::normalize_phone( '0712345678' ), '07 format' );
	nexpay_assert_same( '254712345678', NexPay_Gateway_Mpesa::normalize_phone( '+254712345678' ), '+254 format' );
	nexpay_assert_same( '254712345678', NexPay_Gateway_Mpesa::normalize_phone( '254712345678' ), '254 format' );
	nexpay_assert_same( '254112345678', NexPay_Gateway_Mpesa::normalize_phone( '0112345678' ), '01 format' );
	nexpay_assert_same( '', NexPay_Gateway_Mpesa::normalize_phone( '12345' ), 'too short' );
	nexpay_assert_same( '', NexPay_Gateway_Mpesa::normalize_phone( '0212345678' ), 'landline rejected' );
} );

// --------------------------------------------------------------- Nagad --
nexpay_test( 'Nagad: all endpoints use https', function () {
	nexpay_assert_contains( 'https://', NexPay_Gateway_Nagad::LIVE_BASE, 'live base' );
	nexpay_assert_same( 0, strpos( NexPay_Gateway_Nagad::SANDBOX_BASE, 'https://' ), 'sandbox base scheme' );
} );

nexpay_test( 'Nagad: verification response must match merchant, order ref and amount', function () {
	$good = array(
		'status'     => 'Success',
		'merchantId' => 'M123',
		'orderId'    => 'NX7',
		'amount'     => '250.00',
	);
	nexpay_assert_true( NexPay_Gateway_Nagad::verification_matches( $good, 'M123', 'NX7', '250' ), 'exact match' );

	$bad              = $good;
	$bad['amount']    = '25.00';
	nexpay_assert_false( NexPay_Gateway_Nagad::verification_matches( $bad, 'M123', 'NX7', '250' ), 'wrong amount' );

	$bad                 = $good;
	$bad['merchantId']   = 'OTHER';
	nexpay_assert_false( NexPay_Gateway_Nagad::verification_matches( $bad, 'M123', 'NX7', '250' ), 'wrong merchant' );

	$bad              = $good;
	$bad['orderId']   = 'NX8';
	nexpay_assert_false( NexPay_Gateway_Nagad::verification_matches( $bad, 'M123', 'NX7', '250' ), 'wrong order ref' );

	$bad             = $good;
	$bad['status']   = 'Failed';
	nexpay_assert_false( NexPay_Gateway_Nagad::verification_matches( $bad, 'M123', 'NX7', '250' ), 'failed status' );

	$bad = $good;
	unset( $bad['amount'] );
	nexpay_assert_false( NexPay_Gateway_Nagad::verification_matches( $bad, 'M123', 'NX7', '250' ), 'missing amount fails closed' );
} );

// --------------------------------------------------------------- bKash --
nexpay_test( 'bKash: execute response verification', function () {
	$good = array(
		'statusCode'        => '0000',
		'transactionStatus' => 'Completed',
		'trxID'             => 'TRX123',
		'amount'            => '750.00',
		'currency'          => 'BDT',
	);
	nexpay_assert_true( NexPay_Gateway_BKash::execute_response_is_valid( $good, '750' ), 'valid execute' );

	$bad           = $good;
	$bad['amount'] = '75.00';
	nexpay_assert_false( NexPay_Gateway_BKash::execute_response_is_valid( $bad, '750' ), 'wrong amount' );

	$bad = $good;
	unset( $bad['trxID'] );
	nexpay_assert_false( NexPay_Gateway_BKash::execute_response_is_valid( $bad, '750' ), 'missing trxID' );

	$bad              = $good;
	$bad['currency']  = 'USD';
	nexpay_assert_false( NexPay_Gateway_BKash::execute_response_is_valid( $bad, '750' ), 'wrong currency' );

	$bad                        = $good;
	$bad['transactionStatus']   = 'Initiated';
	nexpay_assert_false( NexPay_Gateway_BKash::execute_response_is_valid( $bad, '750' ), 'not completed' );
} );

// -------------------------------------------------------------- Logger --
nexpay_test( 'Logger: secrets are redacted from arrays and strings', function () {
	$redacted = NexPay_Logger::redact(
		array(
			'merchant_id'   => 'M1',
			'password'      => 'hunter2',
			'integrity_salt' => 'saltvalue',
			'nested'        => array( 'app_secret' => 'shhh', 'note' => 'ok' ),
			'access_token'  => 'tok123',
		)
	);
	nexpay_assert_same( 'M1', $redacted['merchant_id'], 'non-secret kept' );
	nexpay_assert_same( '***redacted***', $redacted['password'], 'password redacted' );
	nexpay_assert_same( '***redacted***', $redacted['integrity_salt'], 'salt redacted' );
	nexpay_assert_same( '***redacted***', $redacted['nested']['app_secret'], 'nested secret redacted' );
	nexpay_assert_same( 'ok', $redacted['nested']['note'], 'nested note kept' );
	nexpay_assert_same( '***redacted***', $redacted['access_token'], 'token redacted' );

	$string = NexPay_Logger::stringify( 'request failed: password=hunter2&store=S1' );
	nexpay_assert_not_contains( 'hunter2', $string, 'string redaction' );
	nexpay_assert_contains( 'store=S1', $string, 'string keeps non-secrets' );
} );

// ------------------------------------------------------------- summary --
echo str_repeat( '-', 60 ) . "\n";
echo sprintf( "Tests run: %d, Passed: %d, Failed: %d\n", $nexpay_tests_run, $nexpay_tests_run - $nexpay_tests_failed, $nexpay_tests_failed );
exit( $nexpay_tests_failed > 0 ? 1 : 0 );
