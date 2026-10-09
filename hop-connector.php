<?php
/**
 * Plugin Name:       Hop Connector
 * Description:       Connecte ce site à la plateforme de gestion Hop (inventaire, mises à jour, connexion en un clic) par des requêtes signées.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Fred Robert
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hop-connector
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'HOP_CONNECTOR_VERSION', '0.1.0' );
define( 'HOP_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-signature.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-keys.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-auth.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-inventory.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-login.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-rest.php';
require_once HOP_CONNECTOR_DIR . 'includes/class-hop-connector-admin.php';

add_action( 'rest_api_init', array( 'Hop_Connector_Rest', 'register_routes' ) );
add_action( 'init', array( 'Hop_Connector_Login', 'maybe_consume' ), 1 );
add_action( 'admin_menu', array( 'Hop_Connector_Admin', 'register_page' ) );
add_action( 'admin_post_hop_connector_generate', array( 'Hop_Connector_Admin', 'handle_generate' ) );
add_action( 'admin_post_hop_connector_disconnect', array( 'Hop_Connector_Admin', 'handle_disconnect' ) );
add_action( 'admin_post_hop_connector_save', array( 'Hop_Connector_Admin', 'handle_save' ) );
add_action( 'hop_connector_cleanup', array( 'Hop_Connector_Auth', 'cleanup_nonces' ) );

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'hop_connector_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'hop_connector_cleanup' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'hop_connector_cleanup' );
	}
);
