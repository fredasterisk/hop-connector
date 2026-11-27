<?php
/**
 * Plugin Name:       Connecteur Espace client
 * Description:       Relie ce site à l’espace client (inventaire, mises à jour, sauvegardes, connexion en un clic) par des requêtes signées.
 * Version:           0.23.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Fred Robert
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fred-espace-client
 * Update URI:        https://client.fredrobert.com/connector/fred-espace-client/
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FRED_ESPACE_CLIENT_VERSION' ) ) {
	define( 'FRED_ESPACE_CLIENT_VERSION', '0.23.0' );
}

define( 'FRED_ESPACE_CLIENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRED_ESPACE_CLIENT_FILE', __FILE__ );

require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-signature.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-log.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-guard.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-release.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-self-update.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-keys.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-auth.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-inventory.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-login.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-updater.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-admin-refresh.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-predecessor.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-database.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-pull.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-rescue.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-rest.php';
require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FRED_ESPACE_CLIENT_DIR . 'includes/class-fred-espace-client-cli.php';
	WP_CLI::add_command( 'espace-client', 'Fred_Espace_Client_Cli' );
	// The command of the connector's former name, for scripts written before.
	WP_CLI::add_command( 'hop-connector', 'Fred_Espace_Client_Cli' );
}

// After the updater has renamed the extracted folder (priority 10).
add_filter( 'upgrader_pre_install', array( 'Fred_Espace_Client_Release', 'remember_state' ), 5, 2 );
add_filter( 'upgrader_source_selection', array( 'Fred_Espace_Client_Release', 'check_update' ), 20, 4 );
// New versions of the connector, served by the platform.
Fred_Espace_Client_Self_Update::register();
add_action( 'rest_api_init', array( 'Fred_Espace_Client_Rest', 'register_routes' ) );
// The update check made as the admin, called by the connector itself: no session, a one-time token.
add_action( 'wp_ajax_nopriv_' . Fred_Espace_Client_Admin_Refresh::ACTION, array( 'Fred_Espace_Client_Admin_Refresh', 'handle' ) );
add_action( 'wp_ajax_' . Fred_Espace_Client_Admin_Refresh::ACTION, array( 'Fred_Espace_Client_Admin_Refresh', 'handle' ) );
add_action( 'init', array( 'Fred_Espace_Client_Login', 'maybe_consume' ), 1 );
add_filter( 'allow_password_reset', array( 'Fred_Espace_Client_Login', 'refuse_password_reset' ), 10, 2 );
// After every other check: a password, a cookie or an application password.
add_filter( 'authenticate', array( 'Fred_Espace_Client_Login', 'refuse_password_login' ), 100 );
add_action( 'admin_menu', array( 'Fred_Espace_Client_Admin', 'register_page' ) );
add_action( 'admin_post_fred_espace_client_generate', array( 'Fred_Espace_Client_Admin', 'handle_generate' ) );
add_action( 'admin_post_fred_espace_client_disconnect', array( 'Fred_Espace_Client_Admin', 'handle_disconnect' ) );
add_action( 'admin_post_fred_espace_client_save', array( 'Fred_Espace_Client_Admin', 'handle_save' ) );
add_action( 'fred_espace_client_cleanup', array( 'Fred_Espace_Client_Auth', 'cleanup_nonces' ) );
add_action( 'fred_espace_client_cleanup', array( 'Fred_Espace_Client_Login', 'purge_expired' ) );
add_action( 'fred_espace_client_cleanup', array( 'Fred_Espace_Client_Database', 'purge_expired' ) );
// The folder of the former plugin goes once this one has taken over.
add_action( 'init', array( 'Fred_Espace_Client_Predecessor', 'remove_folder' ) );

// Reinstall the rescue loader after a connector update or a site migration.
add_action(
	'admin_init',
	static function () {
		if ( current_user_can( 'manage_options' ) && ( ! defined( 'FRED_ESPACE_CLIENT_RESCUE' ) || ! Fred_Espace_Client_Rescue::is_current() ) ) {
			Fred_Espace_Client_Rescue::install();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		// Installed beside hop-connector, the connector's former name: take
		// over its key, settings and copies before anything else.
		Fred_Espace_Client_Predecessor::take_over();
		Fred_Espace_Client_Rescue::install();

		if ( ! wp_next_scheduled( 'fred_espace_client_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'fred_espace_client_cleanup' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		Fred_Espace_Client_Rescue::uninstall();
		wp_clear_scheduled_hook( 'fred_espace_client_cleanup' );
		// The hourly cleanup stops here: copies of the database must not stay behind.
		Fred_Espace_Client_Database::purge_all();
	}
);
