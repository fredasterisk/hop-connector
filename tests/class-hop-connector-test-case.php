<?php
/**
 * Base test case: the platform's key pair, and signed REST requests as the
 * platform sends them.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * Shared helpers.
 */
abstract class Hop_Connector_Test_Case extends WP_UnitTestCase {

	/**
	 * Identifier of the platform's key in the tests.
	 */
	const KEY_ID = '0123456789abcdef';

	/**
	 * Platform key pair (Ed25519, raw bytes).
	 *
	 * @var string
	 */
	protected $platform_keypair;

	public function set_up() {
		parent::set_up();

		$this->platform_keypair = sodium_crypto_sign_keypair();

		rest_get_server();

		// The tests never reach the network: WordPress.org answers that it knows
		// of no update, and any other address fails.
		add_filter( 'pre_http_request', array( $this, 'no_network' ), 99, 3 );
	}

	/**
	 * Answer for every request no test answered.
	 *
	 * @param  false|array|WP_Error $preempt  Answer so far.
	 * @param  array                $args     Request arguments.
	 * @param  string               $url      Address.
	 * @return array|WP_Error
	 */
	public function no_network( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( 'api.wordpress.org' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => (string) wp_json_encode(
					array(
						'plugins'      => array(),
						'themes'       => array(),
						'translations' => array(),
						'no_update'    => array(),
						'offers'       => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		}

		return new WP_Error( 'http_request_failed', 'Pas de réseau dans les tests : ' . $url );
	}

	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tear_down();
	}

	/**
	 * Pair the site with the platform's public key, as a handshake would.
	 *
	 * @return void
	 */
	protected function pair() {
		Hop_Connector_Keys::activate( self::KEY_ID, $this->platform_public_key() );
	}

	/**
	 * Platform public key, hexadecimal.
	 *
	 * @return string
	 */
	protected function platform_public_key() {
		return bin2hex( sodium_crypto_sign_publickey( $this->platform_keypair ) );
	}

	/**
	 * A REST request signed like the platform signs it.
	 *
	 * @param  string               $method     HTTP method.
	 * @param  string               $route      Route, /hop/v1/...
	 * @param  array<string, mixed> $payload    JSON body; none for an empty array on GET.
	 * @param  array<string, mixed> $overrides  Header values to replace: key_id, timestamp, nonce, signature, secret.
	 * @return WP_REST_Request
	 */
	protected function signed_request( $method, $route, array $payload = array(), array $overrides = array() ) {
		$body      = array() === $payload && 'GET' === $method ? '' : (string) wp_json_encode( $payload );
		$key_id    = $overrides['key_id'] ?? self::KEY_ID;
		$timestamp = (string) ( $overrides['timestamp'] ?? time() );
		$nonce     = $overrides['nonce'] ?? bin2hex( random_bytes( 16 ) );
		$canonical = Hop_Connector_Signature::canonical( $method, $route, $timestamp, $nonce, $key_id, $body );

		if ( isset( $overrides['secret'] ) ) {
			$signature = hash_hmac( 'sha256', $canonical, $overrides['secret'] );
		} else {
			$signature = bin2hex( sodium_crypto_sign_detached( $canonical, sodium_crypto_sign_secretkey( $this->platform_keypair ) ) );
		}

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-Hop-Key-Id', $key_id );
		$request->set_header( 'X-Hop-Timestamp', $timestamp );
		$request->set_header( 'X-Hop-Nonce', $nonce );
		$request->set_header( 'X-Hop-Signature', $overrides['signature'] ?? $signature );

		if ( '' !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $body );
		}

		return $request;
	}

	/**
	 * Send a request through the REST server.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response
	 */
	protected function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}
}
