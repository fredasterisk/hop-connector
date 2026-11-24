<?php
/**
 * Read-only inventory of core, plugins and themes.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the payloads returned by /status and /inventory.
 */
class Fred_Espace_Client_Inventory {

	/**
	 * What this connector can do, as the platform asks it. A capability is
	 * added, never renamed; one taken away is a new major version of the API.
	 * See docs/api.md.
	 *
	 * @var list<string>
	 */
	const CAPABILITIES = array(
		'ed25519',
		'plugins_state',
		'database_snapshot',
		'database_steps',
		'restore_steps',
		'pull',
		'rescue',
		'rollback',
		'login_token',
		'self_update',
		'cron_waits',
	);

	/**
	 * Lightweight site status.
	 *
	 * @return array<string,mixed>
	 */
	public static function status() {
		return array(
			'connector_version' => FRED_ESPACE_CLIENT_VERSION,
			// Where the platform speaks to this connector, and what it can do: the
			// platform decides by capability, not by version.
			'api'               => Fred_Espace_Client_Rest::REST_NAMESPACE,
			'capabilities'      => self::CAPABILITIES,
			'wp_version'        => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'site_url'          => home_url(),
			'site_name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'multisite'         => is_multisite(),
			'maintenance'       => file_exists( ABSPATH . '.maintenance' ),
			'rescue'            => defined( 'FRED_ESPACE_CLIENT_RESCUE' ),
			'last_fatal'        => get_option( 'fred_espace_client_last_fatal', null ),
			'signing'           => Fred_Espace_Client_Keys::signing(),
			'server_time'       => time(),
		);
	}

	/**
	 * Full inventory.
	 *
	 * @return array<string,mixed>
	 */
	public static function inventory() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		return array_merge(
			self::status(),
			array(
				'core'    => array(
					'version' => get_bloginfo( 'version' ),
					'update'  => self::core_update(),
				),
				'plugins' => self::plugins(),
				'themes'  => self::themes(),
			)
		);
	}

	/**
	 * Version of the available core update, if any.
	 *
	 * @return string|null
	 */
	private static function core_update() {
		$updates = get_site_transient( 'update_core' );

		if ( ! is_object( $updates ) || empty( $updates->updates ) ) {
			return null;
		}

		foreach ( $updates->updates as $update ) {
			if ( isset( $update->response, $update->current ) && 'upgrade' === $update->response
				&& version_compare( $update->current, get_bloginfo( 'version' ), '>' ) ) {
				return (string) $update->current;
			}
		}

		return null;
	}

	/**
	 * Installed plugins.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function plugins() {
		$updates      = get_site_transient( 'update_plugins' );
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$plugins      = array();

		foreach ( get_plugins() as $file => $data ) {
			$slug = strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' );

			$plugins[] = array(
				'slug'        => $slug,
				'file'        => $file,
				'name'        => wp_strip_all_tags( $data['Name'] ),
				'version'     => (string) $data['Version'],
				'active'      => is_plugin_active( $file ) || is_plugin_active_for_network( $file ),
				'update'      => isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : null,
				'auto_update' => in_array( $file, $auto_updates, true ),
			);
		}

		return $plugins;
	}

	/**
	 * Installed themes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function themes() {
		$updates      = get_site_transient( 'update_themes' );
		$auto_updates = (array) get_site_option( 'auto_update_themes', array() );
		$active       = array( get_stylesheet(), get_template() );
		$themes       = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'        => (string) $slug,
				'name'        => wp_strip_all_tags( $theme->get( 'Name' ) ),
				'version'     => (string) $theme->get( 'Version' ),
				'active'      => in_array( $slug, $active, true ),
				'update'      => isset( $updates->response[ $slug ]['new_version'] ) ? (string) $updates->response[ $slug ]['new_version'] : null,
				'auto_update' => in_array( $slug, $auto_updates, true ),
			);
		}

		return $themes;
	}
}
