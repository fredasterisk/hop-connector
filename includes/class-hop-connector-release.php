<?php
/**
 * Signed releases: an update of the connector is installed only when the
 * agency signed it, with a key that never leaves the agency's computer.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks a new version of the connector before WordPress installs it.
 *
 * The release zip carries release.json, the SHA-256 of every file, and
 * release.sig, an Ed25519 signature of release.json. Whoever takes the
 * GitHub account can publish a release, but cannot sign it: the site refuses
 * it, and keeps the version it has.
 *
 * The check runs in the version already installed, whatever the new one
 * says. A zip uploaded by hand in Plugins > Add is not checked: an
 * administrator of the site can already change any file.
 */
class Hop_Connector_Release {

	/**
	 * Public keys allowed to sign a release, by name. The main key signs; the
	 * backup key, kept offline on paper, signs a release that drops a lost or
	 * stolen main key.
	 *
	 * @var array<string, string>
	 */
	const KEYS = array(
		'principale-2026' => 'mVIVSCuhCQmOLlfg5g/XUAZUn2R1Qi/wDDG1HtCsubg=',
		'secours-2026'    => 'ImxoodIpdOGcORYHoK0YrQGWf0cBk6/kGi7qyKveMvw=',
	);

	const MANIFEST = 'release.json';

	const SIGNATURE = 'release.sig';

	/**
	 * How the connector was active before an upgrade began: true network
	 * wide, false on this site, null when it was not.
	 *
	 * @var bool|null
	 */
	private static $was_active = null;

	/**
	 * Note how the connector is active, before WordPress deactivates it for
	 * a single upgrade (upgrader_pre_install, priority 10).
	 *
	 * @param  bool|WP_Error $response    Answer so far.
	 * @param  array         $hook_extra  What is being upgraded.
	 * @return bool|WP_Error
	 */
	public static function remember_state( $response, $hook_extra = array() ) {
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && plugin_basename( HOP_CONNECTOR_FILE ) === $hook_extra['plugin'] ) {
			$network          = is_multisite() && is_plugin_active_for_network( $hook_extra['plugin'] );
			self::$was_active = $network || is_plugin_active( $hook_extra['plugin'] ) ? $network : null;
		}

		return $response;
	}

	/**
	 * Refuse a new version of the connector that the agency did not sign.
	 *
	 * @param  string|WP_Error $source         Folder of the extracted update.
	 * @param  string          $remote_source  Folder it was extracted in.
	 * @param  WP_Upgrader     $upgrader       Upgrader.
	 * @param  array           $hook_extra     What is being upgraded.
	 * @return string|WP_Error
	 */
	public static function check_update( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
			return $source;
		}

		if ( plugin_basename( HOP_CONNECTOR_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$checked = self::verify( $source, HOP_CONNECTOR_VERSION );

		if ( is_wp_error( $checked ) ) {
			Hop_Connector_Log::record(
				'update_refused',
				array(
					'result' => $checked->get_error_code(),
					'note'   => $checked->get_error_message(),
				)
			);

			// A single upgrade deactivates the plugin before this check: the
			// version kept must stay connected to the platform.
			if ( null !== self::$was_active && ! is_plugin_active( $hook_extra['plugin'] ) ) {
				activate_plugin( $hook_extra['plugin'], '', self::$was_active, true );
			}

			return $checked;
		}

		Hop_Connector_Log::record(
			'update_verified',
			array(
				'key_id' => $checked['key'],
				'result' => 'accepted',
				'note'   => $checked['version'],
			)
		);

		return $source;
	}

	/**
	 * Whether a folder holds a release signed by an allowed key, newer than
	 * the installed version, with every file as signed and no other.
	 *
	 * @param  string $folder   Extracted plugin folder.
	 * @param  string $current  Installed version.
	 * @return array{version:string,key:string}|WP_Error
	 */
	public static function verify( $folder, $current ) {
		$folder   = rtrim( str_replace( '\\', '/', (string) $folder ), '/' );
		$manifest = self::read( $folder . '/' . self::MANIFEST );
		$envelope = json_decode( (string) self::read( $folder . '/' . self::SIGNATURE ), true );

		if ( null === $manifest || ! is_array( $envelope ) ) {
			return self::refuse( 'hop_release_unsigned', __( 'Cette version du connecteur n’est pas signée : elle n’est pas installée.', 'hop-connector' ) );
		}

		$key_id = isset( $envelope['key'] ) ? (string) $envelope['key'] : '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a signature, never code.
		$signature = isset( $envelope['signature'] ) ? base64_decode( (string) $envelope['signature'], true ) : false;
		$keys      = static::keys();

		if ( ! isset( $keys[ $key_id ] ) || false === $signature || ! self::signed( $manifest, $signature, $keys[ $key_id ] ) ) {
			return self::refuse( 'hop_release_bad_signature', __( 'La signature de cette version du connecteur ne vient pas de l’agence : elle n’est pas installée.', 'hop-connector' ) );
		}

		$release = json_decode( $manifest, true );
		$version = is_array( $release ) && isset( $release['version'] ) ? (string) $release['version'] : '';
		$files   = is_array( $release ) && isset( $release['files'] ) && is_array( $release['files'] ) ? $release['files'] : null;

		if ( ! is_array( $release ) || 'hop-connector' !== ( $release['plugin'] ?? '' ) || '' === $version || null === $files ) {
			return self::refuse( 'hop_release_bad_manifest', __( 'La liste signée des fichiers est illisible : la version n’est pas installée.', 'hop-connector' ) );
		}

		// An older release, even signed, may carry a flaw fixed since.
		if ( 'rescue' !== $current && ! version_compare( $version, (string) $current, '>' ) ) {
			return self::refuse( 'hop_release_downgrade', __( 'Cette version signée n’est pas plus récente que celle du site : elle n’est pas installée.', 'hop-connector' ) );
		}

		$found = self::files( $folder );

		if ( null === $found ) {
			return self::refuse( 'hop_release_bad_files', __( 'Un lien symbolique se trouve dans cette version : elle n’est pas installée.', 'hop-connector' ) );
		}

		ksort( $files );
		ksort( $found );

		if ( array_keys( $files ) !== array_keys( $found ) ) {
			return self::refuse( 'hop_release_bad_files', __( 'Des fichiers de cette version ne sont pas ceux qui ont été signés : elle n’est pas installée.', 'hop-connector' ) );
		}

		foreach ( $found as $path => $hash ) {
			if ( ! is_string( $files[ $path ] ) || ! hash_equals( strtolower( $files[ $path ] ), $hash ) ) {
				return self::refuse( 'hop_release_bad_files', __( 'Des fichiers de cette version ne sont pas ceux qui ont été signés : elle n’est pas installée.', 'hop-connector' ) );
			}
		}

		if ( self::header_version( $folder . '/hop-connector.php' ) !== $version ) {
			return self::refuse( 'hop_release_bad_manifest', __( 'La version signée ne correspond pas à celle de l’extension : elle n’est pas installée.', 'hop-connector' ) );
		}

		return array(
			'version' => $version,
			'key'     => $key_id,
		);
	}

	/**
	 * Public keys allowed to sign a release. A method, so the tests can check
	 * the same code with a key they hold; nothing in WordPress can change it.
	 *
	 * @return array<string, string>
	 */
	protected static function keys() {
		return self::KEYS;
	}

	/**
	 * SHA-256 of every file under a folder, by path relative to it, without
	 * the manifest and its signature; null if a link is found.
	 *
	 * @param  string $folder  Folder.
	 * @return array<string, string>|null
	 */
	private static function files( $folder ) {
		$hashes   = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				return null;
			}

			if ( ! $item->isFile() ) {
				continue;
			}

			$path = ltrim( substr( str_replace( '\\', '/', $item->getPathname() ), strlen( $folder ) ), '/' );

			if ( self::MANIFEST === $path || self::SIGNATURE === $path ) {
				continue;
			}

			$hashes[ $path ] = hash_file( 'sha256', $item->getPathname() );
		}

		return $hashes;
	}

	/**
	 * Whether an Ed25519 signature of a message matches a public key.
	 *
	 * @param  string $message     Exact bytes signed.
	 * @param  string $signature   Raw signature.
	 * @param  string $public_key  Base64 public key.
	 * @return bool
	 */
	private static function signed( $message, $signature, $public_key ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a public key, never code.
		$key = base64_decode( $public_key, true );

		if ( false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $key );
		} catch ( Exception $exception ) {
			return false;
		}
	}

	/**
	 * Version in the plugin header of a file.
	 *
	 * @param  string $file  Main plugin file.
	 * @return string
	 */
	private static function header_version( $file ) {
		$head = self::read( $file );

		return null !== $head && preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $head, $match ) ? $match[1] : '';
	}

	/**
	 * Contents of a file, or null.
	 *
	 * @param  string $file  File.
	 * @return string|null
	 */
	private static function read( $file ) {
		if ( ! is_file( $file ) || is_link( $file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the extracted update.
		$contents = file_get_contents( $file );

		return false === $contents ? null : $contents;
	}

	/**
	 * A refusal.
	 *
	 * @param  string $code     Error code.
	 * @param  string $message  Message.
	 * @return WP_Error
	 */
	private static function refuse( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}
