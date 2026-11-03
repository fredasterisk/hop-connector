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
		register_rest_route(
			self::REST_NAMESPACE,
			'/updates/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'updates_refresh' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/updates/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'updates_apply' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
				'args'                => array(
					'type'          => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'plugin', 'theme', 'core' ),
					),
					'slug'          => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'expected_from' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'expected_to'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/plugins/state',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'plugin_state' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
				'args'                => array(
					'slug'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'activate', 'deactivate', 'delete' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rollback' ),
				'permission_callback' => array( 'Hop_Connector_Auth', 'check' ),
				'args'                => array(
					'backup_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Check for updates now and return the fresh inventory.
	 *
	 * @return WP_REST_Response
	 */
	public static function updates_refresh() {
		Hop_Connector_Updater::refresh();

		return self::respond( Hop_Connector_Inventory::inventory() );
	}

	/**
	 * Apply one update.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function updates_apply( WP_REST_Request $request ) {
		$result = Hop_Connector_Updater::apply(
			(string) $request->get_param( 'type' ),
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'expected_from' ),
			(string) $request->get_param( 'expected_to' )
		);

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Activate, deactivate or delete one plugin.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function plugin_state( WP_REST_Request $request ) {
		$result = Hop_Connector_Updater::change_plugin(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'action' )
		);

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Restore the backup taken before an update.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rollback( WP_REST_Request $request ) {
		$result = Hop_Connector_Updater::rollback( (string) $request->get_param( 'backup_id' ) );

		return is_wp_error( $result ) ? $result : self::respond( $result );
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
