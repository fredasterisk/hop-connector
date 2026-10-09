<?php
/**
 * Remove every option created by the connector.
 *
 * @package Hop_Connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'hop_connector_key' );
delete_option( 'hop_connector_pending_key' );
delete_option( 'hop_connector_allowed_ips' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of our own rows.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'hop_connector_nonce_' ) . '%',
		$wpdb->esc_like( 'hop_connector_login_' ) . '%'
	)
);
