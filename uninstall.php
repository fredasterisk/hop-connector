<?php
/**
 * Remove every option created by the connector, its copies, and the
 * accounts it made for the agency.
 *
 * @package Hop_Connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb, $wp_filesystem;

// The accounts made for the agency leave with the connector: kept, they would
// stay administrators of a site the agency no longer looks after.
require_once __DIR__ . '/includes/class-hop-connector-log.php';
require_once __DIR__ . '/includes/class-hop-connector-login.php';
Hop_Connector_Login::remove_agency_accounts();

// The copies of the database hold the site's personal data, and the plugin
// copies hold old code: nothing stays behind the connector, wherever the
// copies were kept (above WordPress, or in wp-content).
$hop_connector_backup_dir = get_option( 'hop_connector_backup_dir' );

if ( is_string( $hop_connector_backup_dir ) && preg_match( '/^[a-f0-9]{24}$/', $hop_connector_backup_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$hop_connector_parents = array_unique( array_filter( array( (string) get_option( 'hop_connector_backup_parent', '' ), WP_CONTENT_DIR ) ) );

	if ( 'direct' === get_filesystem_method() && WP_Filesystem() ) {
		foreach ( $hop_connector_parents as $hop_connector_parent ) {
			if ( is_dir( $hop_connector_parent . '/hop-connector-backups-' . $hop_connector_backup_dir ) ) {
				$wp_filesystem->delete( $hop_connector_parent . '/hop-connector-backups-' . $hop_connector_backup_dir, true );
			}
		}
	} else {
		// Without direct access to the files, the copies of the database go at
		// least: they hold the site's personal data. The deactivation already
		// deleted them; this catches a copy written since.
		foreach ( $hop_connector_parents as $hop_connector_parent ) {
			$hop_connector_root = $hop_connector_parent . '/hop-connector-backups-' . $hop_connector_backup_dir;

			foreach ( array( '*.sql.gz', '*.sql.gz.part', '*.sql', '*.json' ) as $hop_connector_pattern ) {
				foreach ( (array) glob( $hop_connector_root . '/' . $hop_connector_pattern ) as $hop_connector_file ) {
					wp_delete_file( $hop_connector_file );
				}
			}
		}
	}
}

delete_option( 'hop_connector_key' );
delete_option( 'hop_connector_pending_key' );
delete_option( 'hop_connector_allowed_ips' );
delete_option( 'hop_connector_update_lock' );
delete_option( 'hop_connector_backups' );
delete_option( 'hop_connector_backup_dir' );
delete_option( 'hop_connector_backup_parent' );
delete_option( 'hop_connector_last_fatal' );
delete_option( 'hop_connector_copying' );
delete_option( 'hop_connector_log' );
delete_option( 'hop_connector_rate_login' );
delete_site_transient( 'hop_connector_latest' );

if ( file_exists( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' ) ) {
	wp_delete_file( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of our own rows.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'hop_connector_nonce_' ) . '%',
		$wpdb->esc_like( 'hop_connector_login_' ) . '%'
	)
);
