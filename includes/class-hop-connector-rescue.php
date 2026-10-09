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
	 * Called by the must-use loader on every request.
	 *
	 * @return void
	 */
	public static function boot() {
		register_shutdown_function( array( __CLASS__, 'record_fatal' ) );

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
					'message' => substr( wp_strip_all_tags( (string) $error['message'] ), 0, 300 ),
					'file'    => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $error['file'] ) ),
					'line'    => (int) $error['line'],
					'time'    => time(),
				),
				false
			);
		} catch ( Throwable $exception ) {
			// Nothing more can be done from a shutdown handler.
			unset( $exception );
		}
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
			self::respond( 401, array( 'code' => $allowed->get_error_code() ) );
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
	 * Install the must-use loader. Returns false when the host forbids it;
	 * the connector then works without the rescue endpoint.
	 *
	 * @return bool
	 */
	public static function install() {
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}

		$class  = wp_normalize_path( __FILE__ );
		$loader = "<?php\n/**\n * Plugin Name: Hop Connector (secours)\n * Description: Charge le point d'accès de secours de Hop Connector avant les autres extensions. Installé et retiré par Hop Connector.\n */\n\ndefined( 'ABSPATH' ) || exit;\n\n"
			. '$hop_connector_rescue = ' . var_export( $class, true ) . ";\n\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates PHP source.
			. "if ( is_readable( \$hop_connector_rescue ) ) {\n\tdefine( 'HOP_CONNECTOR_RESCUE', true );\n\trequire_once \$hop_connector_rescue;\n\tHop_Connector_Rescue::boot();\n}\n\nunset( \$hop_connector_rescue );\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Single small file, no credentials prompt wanted.
		return false !== file_put_contents( self::loader_path(), $loader );
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
