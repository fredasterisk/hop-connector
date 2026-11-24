<?php
/**
 * Installation of the connector under its new name, fred-espace-client.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installs and activates the successor of this plugin, on the platform's
 * signed request.
 *
 * For WordPress, another folder is another plugin: an update cannot rename
 * the folder. This version installs the new plugin beside it; the new one,
 * at its activation, takes over the key, the settings and the copies, then
 * switches this one off and removes its folder, without its uninstall.php.
 */
class Hop_Connector_Successor {

	/**
	 * Name of the successor: its folder, its main file, its signed manifest.
	 */
	const SLUG = 'fred-espace-client';

	/**
	 * Download, check and install the successor, then activate it.
	 *
	 * @param  string $download_url  Address of its zip on the platform.
	 * @param  string $sha256        SHA-256 of that zip, as the platform checked it.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function install( $download_url, $sha256 ) {
		$download_url = (string) $download_url;
		$sha256       = strtolower( (string) $sha256 );

		if ( 0 !== strpos( $download_url, Hop_Connector_Self_Update::platform_url() . '/connector/' . self::SLUG . '/' ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new WP_Error( 'hop_successor_refused', __( 'Seul un zip de la plateforme, avec son empreinte, peut être installé.', 'hop-connector' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$held = Hop_Connector_Updater::acquire_lock();

		if ( false === $held ) {
			return new WP_Error( 'hop_locked', __( 'Une mise à jour est déjà en cours sur ce site.', 'hop-connector' ), array( 'status' => 409 ) );
		}

		$zip    = null;
		$folder = null;

		try {
			$zip = download_url( $download_url, 120 );

			if ( is_wp_error( $zip ) ) {
				return new WP_Error( 'hop_successor_download', $zip->get_error_message(), array( 'status' => 502 ) );
			}

			if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $zip ) ) ) {
				return new WP_Error( 'hop_successor_refused', __( 'Le zip téléchargé n’est pas celui que la plateforme a vérifié.', 'hop-connector' ), array( 'status' => 400 ) );
			}

			$folder  = get_temp_dir() . 'hop-successor-' . wp_generate_password( 12, false );
			$checked = self::checked( $zip, $folder );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$skin      = new Automatic_Upgrader_Skin();
			$upgrader  = new Plugin_Upgrader( $skin );
			$installed = Hop_Connector_Updater::run_while_copying(
				function () use ( $upgrader, $zip ) {
					return $upgrader->install( $zip, array( 'overwrite_package' => true ) );
				}
			);

			if ( true !== $installed ) {
				$messages = array_map( 'wp_strip_all_tags', array_map( 'strval', $skin->get_upgrade_messages() ) );

				return new WP_Error( 'hop_successor_install', trim( ( is_wp_error( $installed ) ? $installed->get_error_message() . ' ' : '' ) . implode( ' ', $messages ) ), array( 'status' => 500 ) );
			}

			wp_clean_plugins_cache( true );

			$file    = self::SLUG . '/' . self::SLUG . '.php';
			$network = is_multisite() && is_plugin_active_for_network( plugin_basename( HOP_CONNECTOR_FILE ) );

			// Its activation takes over from this plugin, in this very request.
			$activated = activate_plugin( $file, '', $network );

			if ( is_wp_error( $activated ) ) {
				return new WP_Error( 'hop_successor_activate', $activated->get_error_message(), array( 'status' => 500 ) );
			}

			Hop_Connector_Log::record(
				'successor_installed',
				array(
					'key_id' => $checked['key'],
					'result' => 'installed',
					'note'   => self::SLUG . ' ' . $checked['version'],
				)
			);

			return array(
				'status'  => 'installed',
				'plugin'  => self::SLUG,
				'version' => $checked['version'],
			);
		} finally {
			if ( is_string( $zip ) && file_exists( $zip ) ) {
				wp_delete_file( $zip );
			}

			if ( null !== $folder ) {
				self::remove( $folder );
			}

			Hop_Connector_Updater::release_lock( $held );
		}
	}

	/**
	 * Whether the successor is installed on this site.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::SLUG . '/' . self::SLUG . '.php' );
	}

	/**
	 * Unpack the zip apart and check the agency's signature, as for an update
	 * of this plugin.
	 *
	 * @param  string $zip     Downloaded zip.
	 * @param  string $folder  Empty folder to unpack into.
	 * @return array{version:string,key:string}|WP_Error
	 */
	private static function checked( $zip, $folder ) {
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'hop_filesystem', __( 'Accès direct aux fichiers impossible.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		$unzipped = unzip_file( $zip, $folder );

		if ( is_wp_error( $unzipped ) ) {
			return new WP_Error( 'hop_successor_refused', $unzipped->get_error_message(), array( 'status' => 400 ) );
		}

		$checked = static::verify( $folder . '/' . self::SLUG );

		if ( is_wp_error( $checked ) ) {
			Hop_Connector_Log::record(
				'update_refused',
				array(
					'result' => $checked->get_error_code(),
					'note'   => self::SLUG,
				)
			);

			return new WP_Error( $checked->get_error_code(), $checked->get_error_message(), array( 'status' => 400 ) );
		}

		return $checked;
	}

	/**
	 * Check a folder as Hop_Connector_Release checks an update. A method, so
	 * the tests can check the same code with a key they hold.
	 *
	 * @param  string $folder  Unpacked plugin folder.
	 * @return array{version:string,key:string}|WP_Error
	 */
	protected static function verify( $folder ) {
		return Hop_Connector_Release::verify( $folder, HOP_CONNECTOR_VERSION, self::SLUG );
	}

	/**
	 * Remove a temporary folder.
	 *
	 * @param  string $folder  Folder.
	 * @return void
	 */
	private static function remove( $folder ) {
		global $wp_filesystem;

		if ( $wp_filesystem && is_dir( $folder ) ) {
			$wp_filesystem->delete( $folder, true );
		}
	}
}
