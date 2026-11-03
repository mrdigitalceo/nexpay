<?php
/**
 * NexPay — secret-safe logger.
 *
 * Wraps the WooCommerce logger and redacts anything that looks like a
 * credential before it is written: array/object keys named like
 * password, secret, salt, hash_key, token, integrity, passkey or
 * private_key, and "key=value" / "key":"value" pairs inside strings.
 *
 * @package NexPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logging helper with credential redaction.
 */
class NexPay_Logger {

	/**
	 * Key fragments that mark a value as secret.
	 *
	 * @var string[]
	 */
	protected static $secret_key_patterns = array(
		'password',
		'secret',
		'salt',
		'hash_key',
		'hashkey',
		'token',
		'integrity',
		'passkey',
		'private_key',
		'privatekey',
		'app_secret',
		'consumer_secret',
		'authorization',
	);

	/**
	 * Whether an array key names a secret.
	 *
	 * @param string $key Array key.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		$key = strtolower( (string) $key );
		foreach ( self::$secret_key_patterns as $pattern ) {
			if ( false !== strpos( $key, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively redact secrets from a value (array, object or string).
	 *
	 * @param mixed $data Data to redact.
	 * @return mixed Redacted copy.
	 */
	public static function redact( $data ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$out[ $key ] = self::is_secret_key( $key ) ? '***redacted***' : self::redact( $value );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			$copy = clone $data;
			foreach ( get_object_vars( $copy ) as $key => $value ) {
				$copy->$key = self::is_secret_key( $key ) ? '***redacted***' : self::redact( $value );
			}
			return $copy;
		}

		if ( is_string( $data ) ) {
			// Redact "key=value", "key: value" and JSON-style "key":"value" pairs.
			foreach ( self::$secret_key_patterns as $pattern ) {
				$data = preg_replace(
					'/("?' . preg_quote( $pattern, '/' ) . '"?\s*[:=]\s*"?)([^"&\s,\]]+)/i',
					'$1***redacted***',
					$data
				);
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Convert a mixed log payload (string|array|object) into a safe string.
	 *
	 * @param mixed $data Payload.
	 * @return string
	 */
	public static function stringify( $data ) {
		if ( is_string( $data ) ) {
			return self::redact( $data );
		}
		if ( is_array( $data ) || is_object( $data ) ) {
			$json = wp_json_encode( self::redact( $data ) );
			return false !== $json ? $json : '[unserializable log data]';
		}
		if ( is_bool( $data ) ) {
			return $data ? 'true' : 'false';
		}
		if ( null === $data ) {
			return '';
		}
		return (string) $data;
	}

	/**
	 * Write a redacted message to the WooCommerce log.
	 *
	 * @param string $source  Log source, e.g. "nexpay-nexpay_jazzcash".
	 * @param mixed  $message String, array or object payload.
	 * @param string $level   PSR-3 level.
	 */
	public static function log( $source, $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, self::stringify( $message ), array( 'source' => $source ) );
		}
	}
}
