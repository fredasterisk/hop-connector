<?php
/**
 * Plugin Name:       Connecteur Espace client
 * Description:       Relie ce site à l’espace client (inventaire, mises à jour, sauvegardes, connexion en un clic) par des requêtes signées.
 * Version:           0.18.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Fred Robert
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hop-connector
 * Update URI:        https://client.fredrobert.com/connector/
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'HOP_CONNECTOR_VERSION' ) ) {
	define( 'HOP_CONNECTOR_VERSION', '0.18.0' );
}

define( 'HOP_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOP_CONNECTOR_FILE', __FILE__ );

require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-signature.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-log.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-guard.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-release.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-self-update.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-keys.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-auth.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-inventory.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-login.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-updater.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-database.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-pull.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-rescue.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-rest.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-cli.php';
	WP_CLI::add_command( 'hop-connector', 'Hop_Connector_Cli' );
}

// After the updater has renamed the extracted folder (priority 10).
add_filter( 'upgrader_pre_install', array( 'Hop_Connector_Release', 'remember_state' ), 5, 2 );
add_filter( 'upgrader_source_selection', array( 'Hop_Connector_Release', 'check_update' ), 20, 4 );
// New versions of the connector, served by the platform.
Hop_Connector_Self_Update::register();
add_action( 'rest_api_init', array( 'Hop_Connector_Rest', 'register_routes' ) );
add_action( 'init', array( 'Hop_Connector_Login', 'maybe_consume' ), 1 );
add_filter( 'allow_password_reset', array( 'Hop_Connector_Login', 'refuse_password_reset' ), 10, 2 );
// After every other check: a password, a cookie or an application password.
add_filter( 'authenticate', array( 'Hop_Connector_Login', 'refuse_password_login' ), 100 );
add_action( 'admin_menu', array( 'Hop_Connector_Admin', 'register_page' ) );
add_action( 'admin_post_hop_connector_generate', array( 'Hop_Connector_Admin', 'handle_generate' ) );
add_action( 'admin_post_hop_connector_disconnect', array( 'Hop_Connector_Admin', 'handle_disconnect' ) );
add_action( 'admin_post_hop_connector_save', array( 'Hop_Connector_Admin', 'handle_save' ) );
add_action( 'hop_connector_cleanup', array( 'Hop_Connector_Auth', 'cleanup_nonces' ) );
add_action( 'hop_connector_cleanup', array( 'Hop_Connector_Login', 'purge_expired' ) );
add_action( 'hop_connector_cleanup', array( 'Hop_Connector_Database', 'purge_expired' ) );

// Reinstall the rescue loader after a connector update or a site migration.
add_action(
	'admin_init',
	static function () {
		if ( current_user_can( 'manage_options' ) && ( ! defined( 'HOP_CONNECTOR_RESCUE' ) || ! Hop_Connector_Rescue::is_current() ) ) {
			Hop_Connector_Rescue::install();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		Hop_Connector_Rescue::install();

		if ( ! wp_next_scheduled( 'hop_connector_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'hop_connector_cleanup' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		Hop_Connector_Rescue::uninstall();
		wp_clear_scheduled_hook( 'hop_connector_cleanup' );
		// The hourly cleanup stops here: copies of the database must not stay behind.
		Hop_Connector_Database::purge_all();
	}
);
