<?php
/**
 * Plugin Name:       Hop Connector
 * Description:       Connecte ce site à la plateforme de gestion Hop (inventaire, mises à jour, connexion en un clic) par des requêtes signées.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Fred Robert
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hop-connector
 * Update URI:        https://github.com/fredasterisk/hop-connector
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'HOP_CONNECTOR_VERSION' ) ) {
	define( 'HOP_CONNECTOR_VERSION', '0.3.0' );
}

define( 'HOP_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

// Mises à jour en un clic depuis les releases GitHub.
require_once __DIR__ . '/includes/github-updater.php';
Asterisk_GitHub_Updater::register( __FILE__ );

require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-signature.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-keys.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-auth.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-inventory.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-login.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-updater.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-rescue.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-rest.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-admin.php';

add_action( 'rest_api_init', array( 'Hop_Connector_Rest', 'register_routes' ) );
add_action( 'init', array( 'Hop_Connector_Login', 'maybe_consume' ), 1 );
add_action( 'admin_menu', array( 'Hop_Connector_Admin', 'register_page' ) );
add_action( 'admin_post_hop_connector_generate', array( 'Hop_Connector_Admin', 'handle_generate' ) );
add_action( 'admin_post_hop_connector_disconnect', array( 'Hop_Connector_Admin', 'handle_disconnect' ) );
add_action( 'admin_post_hop_connector_save', array( 'Hop_Connector_Admin', 'handle_save' ) );
add_action( 'hop_connector_cleanup', array( 'Hop_Connector_Auth', 'cleanup_nonces' ) );

// Reinstall the rescue loader after a connector update or a site migration.
add_action(
	'admin_init',
	static function () {
		if ( ! defined( 'HOP_CONNECTOR_RESCUE' ) && current_user_can( 'manage_options' ) ) {
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
	}
);
