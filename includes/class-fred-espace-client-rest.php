<?php
/**
 * REST routes exposed to the platform. Explicit and minimal: there is no
 * generic execution or SQL endpoint, and nothing writes a file it is given.
 *
 * The backups/pull routes let the platform read the site to back it up: a
 * copy of the database and the files of the WordPress folder, nothing above.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration and callbacks.
 */
class Fred_Espace_Client_Rest {

	const REST_NAMESPACE = 'fred-espace-client/v1';

	/**
	 * The address of the connector's former name, which the platform still
	 * calls until it learns the new one.
	 */
	const LEGACY_NAMESPACE = 'hop/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		foreach ( array( self::REST_NAMESPACE, self::LEGACY_NAMESPACE ) as $namespace ) {
			register_rest_route(
				$namespace,
				'/handshake',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handshake' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check_handshake' ),
				)
			);

			register_rest_route(
				$namespace,
				'/status',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'status' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/inventory',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'inventory' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/login-token',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'login_token' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						// Checked by the login class: an invalid address must be
						// refused, not emptied into "no address given".
						'email' => array(
							'type'    => 'string',
							'default' => '',
						),
						'name'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/updates/refresh',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'updates_refresh' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/updates/apply',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'updates_apply' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
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
				$namespace,
				'/plugins/state',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'plugin_state' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
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
				$namespace,
				'/backups/database',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'database_snapshot' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/database/start',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'database_start' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/database/continue',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'database_continue' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'backup_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/database/restore',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'database_restore' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'backup_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/database/restore/start',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'database_restore_start' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'backup_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/database/restore/continue',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'database_restore_continue' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'restore_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/pull/database',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_database' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/pull/database/start',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_database_start' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/pull/database/continue',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_database_continue' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'backup_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/pull/chunk',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_chunk' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'backup_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'offset'    => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
						),
						'length'    => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/pull/done',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_done' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'backup_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			// Paths are checked by the pull class: sanitizing one would read another file.
			register_rest_route(
				$namespace,
				'/backups/pull/files',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_files' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'after' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/backups/pull/fetch',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'pull_fetch' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
					'args'                => array(
						'items' => array(
							'required' => true,
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/rollback',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rollback' ),
					'permission_callback' => array( 'Fred_Espace_Client_Auth', 'check' ),
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
	}

	/**
	 * Check for updates now and return the fresh inventory.
	 *
	 * @return WP_REST_Response
	 */
	public static function updates_refresh() {
		Fred_Espace_Client_Updater::refresh();

		return self::respond( Fred_Espace_Client_Inventory::inventory() );
	}

	/**
	 * Apply one update.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function updates_apply( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Updater::apply(
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
		$result = Fred_Espace_Client_Updater::change_plugin(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'action' )
		);

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Copy the database aside before updates.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function database_snapshot() {
		$result = Fred_Espace_Client_Database::snapshot();

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Begin the copy of the database taken before updates, in steps.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function database_start() {
		$result = Fred_Espace_Client_Database::start( 'db' );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Write the next part of the copy of the database taken before updates.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function database_continue( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Database::advance( (string) $request->get_param( 'backup_id' ), 'db' );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Put the database back as it was in a snapshot.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function database_restore( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Database::restore( (string) $request->get_param( 'backup_id' ) );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Begin putting the database back as it was in a snapshot, in steps.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function database_restore_start( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Database::start_restore( (string) $request->get_param( 'backup_id' ) );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Run the next statements of that restoration.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function database_restore_continue( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Database::advance_restore( (string) $request->get_param( 'restore_id' ) );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Copy the database for the platform to take away.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pull_database() {
		$result = Fred_Espace_Client_Database::snapshot( 'pull' );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Begin the copy of the database the platform takes away, in steps.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pull_database_start() {
		$result = Fred_Espace_Client_Database::start( 'pull' );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Write the next part of the copy of the database the platform takes away.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pull_database_continue( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Database::advance( (string) $request->get_param( 'backup_id' ), 'pull' );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Send a piece of that copy, as raw bytes.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_Error|void
	 */
	public static function pull_chunk( WP_REST_Request $request ) {
		$bytes = Fred_Espace_Client_Database::chunk(
			(string) $request->get_param( 'backup_id' ),
			(int) $request->get_param( 'offset' ),
			(int) $request->get_param( 'length' )
		);

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		Fred_Espace_Client_Pull::send( $bytes );
	}

	/**
	 * Delete that copy, once the platform holds it.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response
	 */
	public static function pull_done( WP_REST_Request $request ) {
		return self::respond( Fred_Espace_Client_Database::discard( (string) $request->get_param( 'backup_id' ) ) );
	}

	/**
	 * List a page of the files of the WordPress folder.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function pull_files( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Pull::files( (string) $request->get_param( 'after' ) );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Send the files asked for, as raw bytes.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_Error|void
	 */
	public static function pull_fetch( WP_REST_Request $request ) {
		return Fred_Espace_Client_Pull::fetch( $request->get_param( 'items' ) );
	}

	/**
	 * Restore the backup taken before an update.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rollback( WP_REST_Request $request ) {
		$result = Fred_Espace_Client_Updater::rollback( (string) $request->get_param( 'backup_id' ) );

		return is_wp_error( $result ) ? $result : self::respond( $result );
	}

	/**
	 * Confirm the pairing: the pending key becomes the active key, replaced by
	 * the platform's public key.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handshake( WP_REST_Request $request ) {
		$pending = Fred_Espace_Client_Keys::pending();
		$rotated = false;

		// Only the pending key being confirmed may choose its replacement, and
		// it must: whoever saw the token pass (a command history, the process
		// list of a shared server) cannot keep the site's key alongside Hop.
		if ( $pending && hash_equals( (string) $pending['key_id'], (string) $request->get_header( 'x_hop_key_id' ) ) ) {
			if ( ! Fred_Espace_Client_Keys::promote_pending( $request->get_param( 'public_key' ) ) ) {
				return new WP_Error( 'hop_public_key_required', __( 'La confirmation doit fournir la clé publique de la plateforme.', 'fred-espace-client' ), array( 'status' => 400 ) );
			}

			$rotated = true;
		}

		return self::respond( array_merge( Fred_Espace_Client_Inventory::status(), array( 'rotated' => $rotated ) ) );
	}

	/**
	 * Site status.
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		return self::respond( Fred_Espace_Client_Inventory::status() );
	}

	/**
	 * Core, plugins and themes.
	 *
	 * @return WP_REST_Response
	 */
	public static function inventory() {
		return self::respond( Fred_Espace_Client_Inventory::inventory() );
	}

	/**
	 * Issue a single-use login URL, for the account of the agency member who
	 * asked, found or created by email address.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login_token( WP_REST_Request $request ) {
		$issued = Fred_Espace_Client_Login::issue(
			(string) $request->get_param( 'email' ),
			(string) $request->get_param( 'name' )
		);

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		return self::respond(
			array(
				'url'        => $issued['url'],
				'expires_in' => Fred_Espace_Client_Login::TTL,
				'user'       => $issued['user'],
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
