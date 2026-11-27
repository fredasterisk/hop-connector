<?php
/**
 * Remove every option created by the connector, its copies, and the
 * accounts it made for the agency.
 *
 * @package Fred_Espace_Client
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb, $wp_filesystem;

// The accounts made for the agency leave with the connector: kept, they would
// stay administrators of a site the agency no longer looks after.
require_once __DIR__ . '/includes/class-fred-espace-client-log.php';
require_once __DIR__ . '/includes/class-fred-espace-client-login.php';
Fred_Espace_Client_Login::remove_agency_accounts();

// The copies of the database hold the site's personal data, and the plugin
// copies hold old code: nothing stays behind the connector, wherever the
// copies were kept (above WordPress, or in wp-content).
$fred_espace_client_backup_dir = get_option( 'fred_espace_client_backup_dir' );

if ( is_string( $fred_espace_client_backup_dir ) && preg_match( '/^[a-f0-9]{24}$/', $fred_espace_client_backup_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$fred_espace_client_parents = array_unique( array_filter( array( (string) get_option( 'fred_espace_client_backup_parent', '' ), WP_CONTENT_DIR ) ) );

	if ( 'direct' === get_filesystem_method() && WP_Filesystem() ) {
		foreach ( $fred_espace_client_parents as $fred_espace_client_parent ) {
			if ( is_dir( $fred_espace_client_parent . '/fred-espace-client-backups-' . $fred_espace_client_backup_dir ) ) {
				$wp_filesystem->delete( $fred_espace_client_parent . '/fred-espace-client-backups-' . $fred_espace_client_backup_dir, true );
			}
		}
	} else {
		// Without direct access to the files, the copies of the database go at
		// least: they hold the site's personal data. The deactivation already
		// deleted them; this catches a copy written since.
		foreach ( $fred_espace_client_parents as $fred_espace_client_parent ) {
			$fred_espace_client_root = $fred_espace_client_parent . '/fred-espace-client-backups-' . $fred_espace_client_backup_dir;

			foreach ( array( '*.sql.gz', '*.sql.gz.part', '*.sql', '*.json' ) as $fred_espace_client_pattern ) {
				foreach ( (array) glob( $fred_espace_client_root . '/' . $fred_espace_client_pattern ) as $fred_espace_client_file ) {
					wp_delete_file( $fred_espace_client_file );
				}
			}
		}
	}
}

delete_option( 'fred_espace_client_key' );
delete_option( 'fred_espace_client_pending_key' );
delete_option( 'fred_espace_client_allowed_ips' );
delete_option( 'fred_espace_client_update_lock' );
delete_option( 'fred_espace_client_backups' );
delete_option( 'fred_espace_client_backup_dir' );
delete_option( 'fred_espace_client_backup_parent' );
delete_option( 'fred_espace_client_last_fatal' );
delete_option( 'fred_espace_client_copying' );
delete_option( 'fred_espace_client_log' );
delete_option( 'fred_espace_client_refresh_token' );
delete_option( 'fred_espace_client_rate_login' );
delete_option( 'fred_espace_client_remove_predecessor' );
delete_site_transient( 'fred_espace_client_latest' );

if ( file_exists( WPMU_PLUGIN_DIR . '/fred-espace-client-rescue.php' ) ) {
	wp_delete_file( WPMU_PLUGIN_DIR . '/fred-espace-client-rescue.php' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of our own rows.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'fred_espace_client_nonce_' ) . '%',
		$wpdb->esc_like( 'fred_espace_client_login_' ) . '%'
	)
);
