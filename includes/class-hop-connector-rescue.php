<?php
/**
 * Rescue endpoint, loaded from a must-use plugin.
 *
 * Must-use plugins load before regular plugins, so a rollback can still be
 * requested when the plugin that was just updated crashes the whole site,
 * REST API included.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Early request handler and fatal error recorder.
 */
class Hop_Connector_Rescue {

	const LOADER_FILE  = 'hop-connector-rescue.php';
	const QUERY_VAR    = 'hop_rescue';
	const OPTION_FATAL = 'hop_connector_last_fatal';

	/**
	 * Until when an update is replacing files: a Unix time, set by
	 * Hop_Connector_Updater around the copy only.
	 */
	const OPTION_COPYING = 'hop_connector_copying';

	/**
	 * Called by the must-use loader on every request.
	 *
	 * @return void
	 */
	public static function boot() {
		register_shutdown_function( array( __CLASS__, 'record_fatal' ) );

		// The cron ignores the maintenance mode: while an update replaces the
		// files of a plugin, it would load half of them. It runs again next
		// minute, on whole files.
		if ( self::is_cron( wp_doing_cron(), defined( 'WP_CLI' ) && WP_CLI, isset( $GLOBALS['argv'] ) ? (array) $GLOBALS['argv'] : array() ) && self::files_being_replaced() ) {
			if ( class_exists( 'WP_CLI' ) ) {
				WP_CLI::log( 'Une mise à jour remplace des fichiers : le cron attend son prochain passage.' );
			}

			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the request signature.
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the request signature.
			self::handle( sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) );
		}
	}

	/**
	 * Remember the last fatal error so the platform can tell an update broke the site.
	 *
	 * @return void
	 */
	public static function record_fatal() {
		$error = error_get_last();

		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		try {
			update_option(
				self::OPTION_FATAL,
				array(
					'message'         => substr( wp_strip_all_tags( (string) $error['message'] ), 0, 300 ),
					'file'            => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $error['file'] ) ),
					'line'            => (int) $error['line'],
					'time'            => time(),
					// When the crashed request began: one that began while an update was
					// replacing files may have seen half of them, without the new version
					// being broken. The platform compares it with copy_finished_at.
					'request_started' => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null,
					'sapi'            => PHP_SAPI,
					'request'         => self::request_label(),
				),
				false
			);
		} catch ( Throwable $exception ) {
			// Nothing more can be done from a shutdown handler.
			unset( $exception );
		}
	}

	/**
	 * Whether this process runs the cron: wp-cron.php, or « wp cron event run »
	 * (the command Forge runs every minute).
	 *
	 * @param  bool              $doing_cron  Whether wp-cron.php runs this process.
	 * @param  bool              $wp_cli      Whether WP-CLI runs this process.
	 * @param  array<int, mixed> $argv        Command-line arguments.
	 * @return bool
	 */
	public static function is_cron( $doing_cron, $wp_cli, array $argv ) {
		if ( $doing_cron ) {
			return true;
		}

		if ( ! $wp_cli ) {
			return false;
		}

		$words = array();

		foreach ( array_slice( $argv, 1 ) as $argument ) {
			if ( is_string( $argument ) && '' !== $argument && '-' !== $argument[0] ) {
				$words[] = $argument;
			}
		}

		return array( 'cron', 'event', 'run' ) === array_slice( $words, 0, 3 );
	}

	/**
	 * Whether an update is replacing files right now. A mark left by a request
	 * that died expires on its own.
	 *
	 * @return bool
	 */
	public static function files_being_replaced() {
		return (int) get_option( self::OPTION_COPYING, 0 ) >= time();
	}

	/**
	 * Method and path of the current request, without its query string, which
	 * may carry tokens. "cli" for WP-CLI and cron run from the command line.
	 *
	 * @return string
	 */
	private static function request_label() {
		if ( 'cli' === PHP_SAPI ) {
			return 'cli';
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Cleaned below, stored for the platform only.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( (string) $_SERVER['REQUEST_URI'], '?' ) : '';
		// phpcs:enable

		$label = preg_replace( '/[^\x20-\x7E]/', '', strtoupper( substr( $method, 0, 10 ) ) . ' ' . $path );

		return substr( trim( (string) $label ), 0, 200 );
	}

	/**
	 * Answer a signed rescue request, then stop before regular plugins load.
	 *
	 * @param  string $action  status or rollback.
	 * @return void
	 */
	private static function handle( $action ) {
		if ( ! in_array( $action, array( 'status', 'rollback' ), true ) ) {
			return;
		}

		$dir = dirname( __DIR__ ) . '/';

		if ( ! defined( 'HOP_CONNECTOR_VERSION' ) ) {
			define( 'HOP_CONNECTOR_VERSION', 'rescue' );
		}

		require_once $dir . 'includes/class-hop-connector-signature.php';
		require_once $dir . 'includes/class-hop-connector-log.php';
		require_once $dir . 'includes/class-hop-connector-guard.php';
		require_once $dir . 'includes/class-hop-connector-keys.php';
		require_once $dir . 'includes/class-hop-connector-auth.php';
		require_once $dir . 'includes/class-hop-connector-updater.php';

		$body = (string) file_get_contents( 'php://input' );

		$allowed = Hop_Connector_Auth::verify(
			isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'/hop/v1/rescue/' . $action,
			self::header( 'HTTP_X_HOP_KEY_ID' ),
			self::header( 'HTTP_X_HOP_TIMESTAMP' ),
			self::header( 'HTTP_X_HOP_NONCE' ),
			self::header( 'HTTP_X_HOP_SIGNATURE' ),
			$body
		);

		if ( is_wp_error( $allowed ) ) {
			$data = $allowed->get_error_data();

			// 401 for a request that does not come from the platform, 403 for
			// one the switches of wp-config.php refuse.
			self::respond(
				is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 401,
				array(
					'code'    => $allowed->get_error_code(),
					'message' => $allowed->get_error_message(),
				)
			);
		}

		if ( 'status' === $action ) {
			self::respond(
				200,
				array(
					'alive'       => true,
					'maintenance' => file_exists( ABSPATH . '.maintenance' ),
					'last_fatal'  => get_option( self::OPTION_FATAL, null ),
					'server_time' => time(),
				)
			);
		}

		$payload = json_decode( $body, true );
		$result  = Hop_Connector_Updater::rollback( is_array( $payload ) && isset( $payload['backup_id'] ) ? sanitize_text_field( (string) $payload['backup_id'] ) : '' );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			self::respond(
				is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500,
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}

		self::respond( 200, $result );
	}

	/**
	 * Read one request header.
	 *
	 * @param  string $name  $_SERVER key.
	 * @return string
	 */
	private static function header( $name ) {
		return isset( $_SERVER[ $name ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) : '';
	}

	/**
	 * Send a JSON response and stop.
	 *
	 * @param  int                 $status  HTTP status.
	 * @param  array<string,mixed> $data    Payload.
	 * @return void
	 */
	private static function respond( $status, array $data ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );

		echo wp_json_encode( $data );
		exit;
	}

	/**
	 * Path of the must-use loader.
	 *
	 * @return string
	 */
	public static function loader_path() {
		return WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE;
	}

	/**
	 * Source of the must-use loader, as this version of the connector writes it.
	 *
	 * @return string
	 */
	public static function loader_source() {
		$class = wp_normalize_path( __FILE__ );

		return "<?php\n/**\n * Plugin Name: Connecteur Espace client (secours)\n * Description: Charge le point d'accès de secours du connecteur avant les autres extensions. Installé et retiré par le Connecteur Espace client.\n */\n\ndefined( 'ABSPATH' ) || exit;\n\n"
			. '$hop_connector_rescue = ' . var_export( $class, true ) . ";\n\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates PHP source.
			. "if ( is_readable( \$hop_connector_rescue ) ) {\n\tdefine( 'HOP_CONNECTOR_RESCUE', true );\n\trequire_once \$hop_connector_rescue;\n\tHop_Connector_Rescue::boot();\n}\n\nunset( \$hop_connector_rescue );\n";
	}

	/**
	 * Whether the must-use loader is present and written by this version of
	 * the connector (its name and path follow the connector's updates).
	 *
	 * @return bool
	 */
	public static function is_current() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		return is_readable( self::loader_path() ) && file_get_contents( self::loader_path() ) === self::loader_source();
	}

	/**
	 * Install the must-use loader. Returns false when the host forbids it;
	 * the connector then works without the rescue endpoint.
	 *
	 * @return bool
	 */
	public static function install() {
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Single small file, no credentials prompt wanted.
		return false !== file_put_contents( self::loader_path(), self::loader_source() );
	}

	/**
	 * Remove the must-use loader.
	 *
	 * @return void
	 */
	public static function uninstall() {
		if ( file_exists( self::loader_path() ) ) {
			wp_delete_file( self::loader_path() );
		}
	}
}
