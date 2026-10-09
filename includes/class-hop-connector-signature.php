<?php
/**
 * Request signature (HMAC-SHA256), protocol version 1.
 *
 * This class has no WordPress dependency on purpose: the platform test suite
 * loads it to prove both sides compute the same signature.
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
