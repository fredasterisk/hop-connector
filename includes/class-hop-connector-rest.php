<?php
/**
 * REST routes exposed to the platform. Explicit and minimal: there is no
 * generic execution, file or SQL endpoint.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration and callbacks.
 */
class Hop_Connector_Rest {

	const REST_NAMESPACE = 'hop/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/handshake',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handshake' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check_handshake' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/inventory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'inventory' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/login-token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'login_token' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Confirm the pairing: the pending key becomes the active key.
	 *
	 * @return WP_REST_Response
	 */
	public static function handshake() {
		Hop_Connector_Keys::promote_pending();

		return self::respond( Hop_Connector_Inventory::status() );
	}

	/**
	 * Site status.
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		return self::respond( Hop_Connector_Inventory::status() );
	}

	/**
	 * Core, plugins and themes.
	 *
	 * @return WP_REST_Response
	 */
	public static function inventory() {
		return self::respond( Hop_Connector_Inventory::inventory() );
	}

	/**
	 * Issue a single-use login URL.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login_token( WP_REST_Request $request ) {
		$url = Hop_Connector_Login::issue( (int) $request->get_param( 'user_id' ) );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return self::respond(
			array(
				'url'        => $url,
				'expires_in' => Hop_Connector_Login::TTL,
			)
		);
	}

	/**
	 * Response that no cache layer may store.
	 *
	 * @param  array<string,mixed> $data  Payload.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data ) {
		$response = new WP_REST_Response( $data );

		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		return $response;
	}
}
