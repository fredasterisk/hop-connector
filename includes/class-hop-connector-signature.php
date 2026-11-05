<?php
/**
 * Request signature. Version 1: HMAC-SHA256 with a secret shared with the
 * platform. Version 2: Ed25519, where the site only holds the platform's
 * public key, which cannot sign anything.
 *
 * Both cover the same canonical string. This class has no WordPress
 * dependency on purpose: the platform test suite loads it to prove both
 * sides compute the same signature.
 *
 * @package Hop_Connector
 */

if ( ! class_exists( 'Hop_Connector_Signature' ) ) {

	/**
	 * Builds and verifies request signatures.
	 */
	class Hop_Connector_Signature {

		/**
		 * Maximum clock difference accepted between platform and site, in seconds.
		 */
		const MAX_SKEW = 60;

		/**
		 * Canonical string covered by the signature.
		 *
		 * @param  string $method  HTTP method.
		 * @param  string $route  REST route, e.g. /hop/v1/status.
		 * @param  string $timestamp  Unix timestamp sent by the platform.
		 * @param  string $nonce  Single-use nonce sent by the platform.
		 * @param  string $key_id  Key identifier.
		 * @param  string $body  Raw request body.
		 * @return string
		 */
		public static function canonical( $method, $route, $timestamp, $nonce, $key_id, $body ) {
			return implode(
				"\n",
				array(
					strtoupper( $method ),
					$route,
					$timestamp,
					$nonce,
					$key_id,
					hash( 'sha256', $body ),
				)
			);
		}

		/**
		 * Compute the hexadecimal signature of a request.
		 *
		 * @param  string $secret  Shared secret.
		 * @param  string $method  HTTP method.
		 * @param  string $route  REST route.
		 * @param  string $timestamp  Unix timestamp.
		 * @param  string $nonce  Nonce.
		 * @param  string $key_id  Key identifier.
		 * @param  string $body  Raw request body.
		 * @return string
		 */
		public static function sign( $secret, $method, $route, $timestamp, $nonce, $key_id, $body ) {
			return hash_hmac( 'sha256', self::canonical( $method, $route, $timestamp, $nonce, $key_id, $body ), $secret );
		}

		/**
		 * Constant-time signature check.
		 *
		 * @param  string $signature  Signature received.
		 * @param  string $secret  Shared secret.
		 * @param  string $method  HTTP method.
		 * @param  string $route  REST route.
		 * @param  string $timestamp  Unix timestamp.
		 * @param  string $nonce  Nonce.
		 * @param  string $key_id  Key identifier.
		 * @param  string $body  Raw request body.
		 * @return bool
		 */
		public static function verify( $signature, $secret, $method, $route, $timestamp, $nonce, $key_id, $body ) {
			if ( ! is_string( $signature ) || '' === $signature || '' === (string) $secret ) {
				return false;
			}

			return hash_equals( self::sign( $secret, $method, $route, $timestamp, $nonce, $key_id, $body ), $signature );
		}

		/**
		 * Check an Ed25519 signature against the platform's public key.
		 *
		 * WordPress provides the sodium functions even where PHP lacks the
		 * extension (sodium_compat, loaded by wp-includes/compat.php).
		 *
		 * @param  string $signature  Signature received, 128 hexadecimal characters.
		 * @param  string $public_key  Platform public key, 64 hexadecimal characters.
		 * @param  string $method  HTTP method.
		 * @param  string $route  REST route.
		 * @param  string $timestamp  Unix timestamp.
		 * @param  string $nonce  Nonce.
		 * @param  string $key_id  Key identifier.
		 * @param  string $body  Raw request body.
		 * @return bool
		 */
		public static function verify_ed25519( $signature, $public_key, $method, $route, $timestamp, $nonce, $key_id, $body ) {
			if ( ! is_string( $signature ) || 1 !== preg_match( '/^[a-f0-9]{128}$/', $signature )
				|| ! is_string( $public_key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $public_key )
				|| ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return false;
			}

			try {
				return sodium_crypto_sign_verify_detached(
					(string) hex2bin( $signature ),
					self::canonical( $method, $route, $timestamp, $nonce, $key_id, $body ),
					(string) hex2bin( $public_key )
				);
			} catch ( Throwable $exception ) {
				return false;
			}
		}

		/**
		 * Whether a timestamp is inside the accepted window.
		 *
		 * @param  string $timestamp  Unix timestamp received.
		 * @param  int    $now  Current Unix timestamp.
		 * @return bool
		 */
		public static function is_fresh( $timestamp, $now ) {
			if ( ! is_string( $timestamp ) || ! ctype_digit( $timestamp ) ) {
				return false;
			}

			return abs( $now - (int) $timestamp ) <= self::MAX_SKEW;
		}
	}
}
