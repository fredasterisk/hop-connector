<?php
/**
 * Applies one update at a time, with a local backup that can be restored.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update, backup and rollback of plugins, themes and core.
 */
class Fred_Espace_Client_Updater {

	const OPTION_LOCK    = 'fred_espace_client_update_lock';
	const OPTION_BACKUPS = 'fred_espace_client_backups';
	const LOCK_TTL       = 600;
	const KEEP_BACKUPS   = 5;

	/**
	 * Folder chosen to hold the backup directory.
	 */
	const OPTION_BACKUP_PARENT = 'fred_espace_client_backup_parent';

	/**
	 * Ask WordPress to check for updates now, through its standard mechanism.
	 *
	 * Premium plugins hook these checks to announce their own updates, which is
	 * why the admin includes are loaded first.
	 *
	 * @return void
	 */
	public static function refresh() {
		self::load_admin();

		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		Fred_Espace_Client_Self_Update::forget();
		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();
	}

	/**
	 * Apply a single update.
	 *
	 * @param  string $type           plugin, theme or core.
	 * @param  string $slug           Plugin directory, theme stylesheet, or "WordPress".
	 * @param  string $expected_from  Version the platform believes is installed.
	 * @param  string $expected_to    Version the platform expects to install.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( $type, $slug, $expected_from, $expected_to ) {
		self::load_admin();

		$held = self::acquire_lock();

		if ( false === $held ) {
			return new WP_Error( 'hop_locked', __( 'Une mise à jour est déjà en cours sur ce site.', 'fred-espace-client' ), array( 'status' => 409 ) );
		}

		// A long update must not be cut off halfway by the PHP time limit.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Hosts may forbid it; the update still runs.
			@set_time_limit( 300 );
		}

		try {
			switch ( $type ) {
				case 'plugin':
					return self::apply_plugin( $slug, $expected_from, $expected_to );
				case 'theme':
					return self::apply_theme( $slug, $expected_from, $expected_to );
				default:
					return self::apply_core( $expected_from, $expected_to );
			}
		} finally {
			// Past its time, the nightly copy may hold the lock by now.
			self::release_lock( $held );
		}
	}

	/**
	 * Activate, deactivate or delete one plugin.
	 *
	 * A plugin is deleted only once inactive, and its directory is copied
	 * aside first: the deletion can be undone with rollback().
	 *
	 * @param  string $slug    Plugin directory name.
	 * @param  string $action  activate, deactivate or delete.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function change_plugin( $slug, $action ) {
		self::load_admin();

		$file = self::plugin_file( $slug );

		if ( ! $file ) {
			return new WP_Error( 'hop_not_installed', __( 'Extension introuvable.', 'fred-espace-client' ), array( 'status' => 404 ) );
		}

		// The connector never acts on itself: Hop would lose the site.
		if ( plugin_basename( FRED_ESPACE_CLIENT_DIR . 'fred-espace-client.php' ) === $file ) {
			return new WP_Error( 'hop_forbidden', __( 'Le connecteur ne peut pas agir sur lui-même.', 'fred-espace-client' ), array( 'status' => 403 ) );
		}

		$held = self::acquire_lock();

		if ( false === $held ) {
			return new WP_Error( 'hop_locked', __( 'Une mise à jour est déjà en cours sur ce site.', 'fred-espace-client' ), array( 'status' => 409 ) );
		}

		try {
			$active    = is_plugin_active( $file ) || is_plugin_active_for_network( $file );
			$network   = is_multisite() && is_plugin_active_for_network( $file );
			$backup_id = null;

			switch ( $action ) {
				case 'activate':
					if ( ! $active ) {
						// A plugin that crashes on load is not recorded as active.
						$result = activate_plugin( $file, '', $network, false );

						if ( is_wp_error( $result ) ) {
							return new WP_Error( 'hop_activation_failed', $result->get_error_message(), array( 'status' => 409 ) );
						}
					}
					break;

				case 'deactivate':
					if ( $active ) {
						deactivate_plugins( array( $file ), false, $network );
					}
					break;

				default:
					if ( $active ) {
						return new WP_Error( 'hop_plugin_active', __( 'Désactivez l\'extension avant de la supprimer.', 'fred-espace-client' ), array( 'status' => 409 ) );
					}

					$plugins   = get_plugins();
					$backup_id = self::backup( 'plugin', $slug, (string) $plugins[ $file ]['Version'] );

					if ( is_wp_error( $backup_id ) ) {
						return $backup_id;
					}

					$deleted = delete_plugins( array( $file ) );

					if ( true !== $deleted ) {
						return new WP_Error(
							'hop_delete_failed',
							is_wp_error( $deleted ) ? $deleted->get_error_message() : __( 'Suppression impossible.', 'fred-espace-client' ),
							array( 'status' => 500 )
						);
					}
			}

			wp_clean_plugins_cache( true );
			self::purge_caches();

			$installed = array_key_exists( $file, get_plugins() );

			return array(
				'status'    => 'delete' === $action ? 'deleted' : ( $action . 'd' ),
				'slug'      => $slug,
				'active'    => $installed && ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ),
				'installed' => $installed,
				'backup_id' => $backup_id,
			);
		} finally {
			self::release_lock( $held );
		}
	}

	/**
	 * Restore a backup taken before an update.
	 *
	 * @param  string $backup_id  Identifier returned by apply().
	 * @return array<string,mixed>|WP_Error
	 */
	public static function rollback( $backup_id ) {
		self::load_admin();

		$backups = self::backups();

		// "latest:plugin:slug" designates the backup just taken for a component, for
		// the case where the update request died before returning its identifier.
		if ( 0 === strpos( $backup_id, 'latest:' ) ) {
			$parts     = explode( ':', $backup_id, 3 );
			$backup_id = '';

			foreach ( $backups as $id => $candidate ) {
				if ( 3 === count( $parts ) && $candidate['type'] === $parts[1] && $candidate['slug'] === $parts[2] && (int) $candidate['created'] >= time() - 900 ) {
					$backup_id = $id;
				}
			}
		}

		if ( ! isset( $backups[ $backup_id ] ) || ! is_dir( $backups[ $backup_id ]['path'] ) ) {
			return new WP_Error( 'hop_no_backup', __( 'Sauvegarde introuvable.', 'fred-espace-client' ), array( 'status' => 404 ) );
		}

		$backup     = $backups[ $backup_id ];
		$filesystem = self::filesystem();
		$target     = self::component_dir( $backup['type'], $backup['slug'] );

		if ( ! $filesystem || ! $target ) {
			return new WP_Error( 'hop_filesystem', __( 'Accès direct aux fichiers impossible.', 'fred-espace-client' ), array( 'status' => 500 ) );
		}

		// A failed update can leave the site in maintenance mode.
		$filesystem->delete( ABSPATH . '.maintenance' );

		// From the removal of the folder to the end of the copy.
		$copied = self::run_while_copying(
			function () use ( $filesystem, $backup, $target ) {
				$filesystem->delete( $target, true );

				if ( ! wp_mkdir_p( $target ) ) {
					return new WP_Error( 'hop_rollback_failed', __( 'Impossible de recréer le dossier.', 'fred-espace-client' ) );
				}

				return copy_dir( $backup['path'], $target );
			}
		);

		if ( is_wp_error( $copied ) ) {
			return new WP_Error( 'hop_rollback_failed', $copied->get_error_message(), array( 'status' => 500 ) );
		}

		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		self::purge_caches();

		return array(
			'status'           => 'rolled_back',
			'type'             => $backup['type'],
			'slug'             => $backup['slug'],
			'restored_version' => $backup['version'],
		);
	}

	/**
	 * Update one plugin.
	 *
	 * @param  string $slug           Plugin directory name.
	 * @param  string $expected_from  Expected installed version.
	 * @param  string $expected_to    Expected new version.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function apply_plugin( $slug, $expected_from, $expected_to ) {
		$file = self::plugin_file( $slug );

		if ( ! $file ) {
			return new WP_Error( 'hop_not_installed', __( 'Extension introuvable.', 'fred-espace-client' ), array( 'status' => 404 ) );
		}

		$plugins = get_plugins();
		$from    = (string) $plugins[ $file ]['Version'];
		$updates = get_site_transient( 'update_plugins' );
		$offered = isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : '';

		$mismatch = self::check_versions( $from, $offered, $expected_from, $expected_to );

		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		$backup_id = self::backup( 'plugin', $slug, $from );

		if ( is_wp_error( $backup_id ) ) {
			return $backup_id;
		}

		// bulk_upgrade() keeps an active plugin active; upgrade() would deactivate it.
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$copy     = array( 'copy_started_at' => microtime( true ) );
		$results  = self::run_while_copying(
			function () use ( $upgrader, $file ) {
				return $upgrader->bulk_upgrade( array( $file ) );
			}
		);
		$result   = is_array( $results ) && isset( $results[ $file ] ) ? $results[ $file ] : false;

		$copy['copy_finished_at'] = microtime( true );

		wp_clean_plugins_cache( true );

		$plugins = get_plugins();
		$to      = isset( $plugins[ $file ] ) ? (string) $plugins[ $file ]['Version'] : '';

		return self::result( $result, $skin, $from, $to, $backup_id, $copy );
	}

	/**
	 * Update one theme.
	 *
	 * @param  string $slug           Theme stylesheet.
	 * @param  string $expected_from  Expected installed version.
	 * @param  string $expected_to    Expected new version.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function apply_theme( $slug, $expected_from, $expected_to ) {
		$theme = wp_get_theme( $slug );

		if ( ! $theme->exists() ) {
			return new WP_Error( 'hop_not_installed', __( 'Thème introuvable.', 'fred-espace-client' ), array( 'status' => 404 ) );
		}

		$from    = (string) $theme->get( 'Version' );
		$updates = get_site_transient( 'update_themes' );
		$offered = isset( $updates->response[ $slug ]['new_version'] ) ? (string) $updates->response[ $slug ]['new_version'] : '';

		$mismatch = self::check_versions( $from, $offered, $expected_from, $expected_to );

		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		$backup_id = self::backup( 'theme', $slug, $from );

		if ( is_wp_error( $backup_id ) ) {
			return $backup_id;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$copy     = array( 'copy_started_at' => microtime( true ) );
		$results  = self::run_while_copying(
			function () use ( $upgrader, $slug ) {
				return $upgrader->bulk_upgrade( array( $slug ) );
			}
		);
		$result   = is_array( $results ) && isset( $results[ $slug ] ) ? $results[ $slug ] : false;

		$copy['copy_finished_at'] = microtime( true );

		wp_clean_themes_cache( true );

		return self::result( $result, $skin, $from, (string) wp_get_theme( $slug )->get( 'Version' ), $backup_id, $copy );
	}

	/**
	 * Update WordPress itself. Core has no local backup: a failed core update
	 * can only be undone from a full hosting backup.
	 *
	 * @param  string $expected_from  Expected installed version.
	 * @param  string $expected_to    Expected new version.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function apply_core( $expected_from, $expected_to ) {
		$from   = get_bloginfo( 'version' );
		$update = find_core_update( $expected_to, get_locale() );

		if ( ! $update ) {
			$update = find_core_update( $expected_to, 'en_US' );
		}

		$mismatch = self::check_versions( $from, $update ? (string) $update->current : '', $expected_from, $expected_to );

		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$copy     = array( 'copy_started_at' => microtime( true ) );
		$result   = self::run_while_copying(
			function () use ( $upgrader, $update ) {
				return $upgrader->upgrade( $update );
			}
		);

		$copy['copy_finished_at'] = microtime( true );

		// The running request still has the old version loaded: read the new one from disk.
		$to = $from;

		if ( ! is_wp_error( $result ) && is_string( $result ) && '' !== $result ) {
			$to = $result;
		}

		return self::result( is_wp_error( $result ) ? $result : true, $skin, $from, $to, null, $copy );
	}

	/**
	 * Refuse to act when the site is not in the state the platform expects.
	 *
	 * @param  string $from           Installed version.
	 * @param  string $offered        Version WordPress offers.
	 * @param  string $expected_from  Version the platform expects to be installed.
	 * @param  string $expected_to    Version the platform expects to install.
	 * @return true|WP_Error
	 */
	private static function check_versions( $from, $offered, $expected_from, $expected_to ) {
		if ( '' === $offered ) {
			return new WP_Error( 'hop_no_update', __( 'Aucune mise à jour n\'est proposée pour cet élément.', 'fred-espace-client' ), array( 'status' => 409 ) );
		}

		if ( $from !== $expected_from || $offered !== $expected_to ) {
			return new WP_Error(
				'hop_version_mismatch',
				__( 'Les versions ne correspondent plus à celles attendues.', 'fred-espace-client' ),
				array(
					'status'    => 409,
					'installed' => $from,
					'offered'   => $offered,
				)
			);
		}

		return true;
	}

	/**
	 * Build the response of an update.
	 *
	 * @param  mixed                   $result     Upgrader result.
	 * @param  Automatic_Upgrader_Skin $skin       Skin holding the messages.
	 * @param  string                  $from       Version before.
	 * @param  string                  $to         Version after.
	 * @param  string|null             $backup_id  Backup taken before the update.
	 * @param  array<string,float>     $copy       When the upgrader started and stopped replacing files.
	 * @return array<string,mixed>
	 */
	private static function result( $result, $skin, $from, $to, $backup_id, array $copy = array() ) {
		$messages = array_map( 'wp_strip_all_tags', array_map( 'strval', $skin->get_upgrade_messages() ) );

		if ( is_wp_error( $result ) ) {
			$messages[] = $result->get_error_message();
		}

		$updated = ! is_wp_error( $result ) && false !== $result && null !== $result && '' !== $to && $to !== $from;

		self::purge_caches();

		return array(
			'status'    => $updated ? 'updated' : 'failed',
			'from'      => $from,
			'to'        => $to,
			'backup_id' => $backup_id,
			'messages'  => array_values( array_unique( array_filter( $messages ) ) ),
		) + $copy;
	}

	/**
	 * Copy a plugin or theme directory aside before touching it.
	 *
	 * @param  string $type     plugin or theme.
	 * @param  string $slug     Directory name.
	 * @param  string $version  Installed version.
	 * @return string|WP_Error Backup identifier.
	 */
	private static function backup( $type, $slug, $version ) {
		$source = self::component_dir( $type, $slug );

		if ( ! self::filesystem() || ! $source || ! is_dir( $source ) ) {
			return new WP_Error( 'hop_filesystem', __( 'Accès direct aux fichiers impossible : mise à jour annulée faute de sauvegarde.', 'fred-espace-client' ), array( 'status' => 500 ) );
		}

		$root = self::backup_root();

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$backup_id = $type . '-' . sanitize_key( $slug ) . '-' . bin2hex( random_bytes( 8 ) );
		$path      = $root . '/' . $backup_id;

		if ( ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'hop_backup_failed', __( 'Impossible de créer le dossier de sauvegarde.', 'fred-espace-client' ), array( 'status' => 500 ) );
		}

		$copied = copy_dir( $source, $path );

		if ( is_wp_error( $copied ) ) {
			return new WP_Error( 'hop_backup_failed', $copied->get_error_message(), array( 'status' => 500 ) );
		}

		$backups               = self::backups();
		$backups[ $backup_id ] = array(
			'type'    => $type,
			'slug'    => $slug,
			'version' => $version,
			'path'    => $path,
			'created' => time(),
		);

		// Oldest first: drop everything beyond the last few.
		foreach ( array_slice( array_keys( $backups ), 0, max( 0, count( $backups ) - self::KEEP_BACKUPS ) ) as $oldest ) {
			self::filesystem()->delete( $backups[ $oldest ]['path'], true );
			unset( $backups[ $oldest ] );
		}

		update_option( self::OPTION_BACKUPS, $backups, false );

		return $backup_id;
	}

	/**
	 * Backup directory, closed to web access. Its name is unguessable.
	 *
	 * @return string|WP_Error
	 */
	public static function backup_root() {
		$suffix = get_option( 'fred_espace_client_backup_dir' );

		if ( ! is_string( $suffix ) || ! preg_match( '/^[a-f0-9]{24}$/', $suffix ) ) {
			$suffix = bin2hex( random_bytes( 12 ) );
			update_option( 'fred_espace_client_backup_dir', $suffix, false );
		}

		$root = self::backup_parent( $suffix ) . '/fred-espace-client-backups-' . $suffix;

		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'hop_backup_failed', __( 'Impossible de créer le dossier de sauvegarde.', 'fred-espace-client' ), array( 'status' => 500 ) );
		}

		if ( ! file_exists( $root . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Two tiny guard files.
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Two tiny guard files.
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		// nginx ignores .htaccess: closing the directory to every other user
		// keeps the web server (and the other sites of a shared server) from
		// reading the copies or running the PHP of an old plugin version.
		// Hosts that refuse chmod keep the default.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best effort on any host.
		@chmod( $root, 0700 );

		return $root;
	}

	/**
	 * Folder holding the backup directory.
	 *
	 * The folder above WordPress when the site may write there and the web
	 * server does not serve it: no address then leads to the copies. Otherwise
	 * wp-content, where only the unguessable name and the permissions protect
	 * them. Only a web request knows the public folder, so the choice is made
	 * during one, then kept.
	 *
	 * @param  string $suffix  Unguessable part of the directory name.
	 * @return string
	 */
	private static function backup_parent( $suffix ) {
		$parent = get_option( self::OPTION_BACKUP_PARENT );

		if ( is_string( $parent ) && '' !== $parent && is_dir( $parent ) ) {
			return $parent;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only resolved by realpath() and compared, never output.
		$public = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';

		if ( '' === $public ) {
			return WP_CONTENT_DIR;
		}

		$parent = WP_CONTENT_DIR;
		$above  = self::above_public_folder( $public );

		if ( null !== $above && self::move_backups( WP_CONTENT_DIR, $above, $suffix ) ) {
			$parent = $above;
		}

		update_option( self::OPTION_BACKUP_PARENT, $parent, false );

		return $parent;
	}

	/**
	 * The folder above WordPress, if the web server does not serve it and the
	 * site may write there.
	 *
	 * @param  string $public_folder  Public folder of the web server.
	 * @return string|null
	 */
	private static function above_public_folder( $public_folder ) {
		// Hosts that restrict PHP to the site's folder (open_basedir) refuse to look higher.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
		$public_folder = @realpath( $public_folder );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
		$above = @realpath( dirname( ABSPATH ) );

		if ( ! $public_folder || ! $above ) {
			return null;
		}

		$public_folder = trailingslashit( wp_normalize_path( $public_folder ) );
		$above         = trailingslashit( wp_normalize_path( $above ) );

		// Neither the public folder itself, nor anything inside it, nor the
		// root of the disk.
		if ( 0 === strpos( $above, $public_folder ) || 1 === preg_match( '#^([A-Za-z]:)?/$#', $above ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
		return @wp_is_writable( $above ) ? untrailingslashit( $above ) : null;
	}

	/**
	 * Move the backup directory, and the paths recorded for its copies.
	 *
	 * @param  string $from    Current parent folder.
	 * @param  string $to      New parent folder.
	 * @param  string $suffix  Unguessable part of the directory name.
	 * @return bool Whether the copies are now under $to, or there were none.
	 */
	private static function move_backups( $from, $to, $suffix ) {
		$source = wp_normalize_path( $from . '/fred-espace-client-backups-' . $suffix );
		$target = wp_normalize_path( $to . '/fred-espace-client-backups-' . $suffix );

		if ( ! is_dir( $source ) ) {
			return true;
		}

		// On the same disk, one atomic rename. On another one, rename fails and
		// the copies stay where they are, still listed.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- See above.
		if ( file_exists( $target ) || ! @rename( $source, $target ) ) {
			return false;
		}

		$backups = self::backups();

		foreach ( $backups as $id => $backup ) {
			$path = wp_normalize_path( (string) $backup['path'] );

			if ( 0 === strpos( $path, $source . '/' ) ) {
				$backups[ $id ]['path'] = $target . substr( $path, strlen( $source ) );
			}
		}

		update_option( self::OPTION_BACKUPS, $backups, false );

		return true;
	}

	/**
	 * Stored backups, oldest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function backups() {
		$backups = get_option( self::OPTION_BACKUPS, array() );

		return is_array( $backups ) ? $backups : array();
	}

	/**
	 * Absolute directory of a plugin or theme.
	 *
	 * @param  string $type  plugin or theme.
	 * @param  string $slug  Directory name.
	 * @return string|null
	 */
	private static function component_dir( $type, $slug ) {
		if ( '' === $slug || sanitize_file_name( $slug ) !== $slug || false !== strpos( $slug, '..' ) ) {
			return null;
		}

		return 'theme' === $type ? get_theme_root( $slug ) . '/' . $slug : WP_PLUGIN_DIR . '/' . $slug;
	}

	/**
	 * Main file of the plugin living in a directory.
	 *
	 * @param  string $slug  Plugin directory name.
	 * @return string|null
	 */
	private static function plugin_file( $slug ) {
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( dirname( $file ) === $slug ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Run what replaces files, with the cron kept out meanwhile (see
	 * Fred_Espace_Client_Rescue::boot()). The nightly copy, which only reads, does
	 * not set this mark.
	 *
	 * @param  callable $copy  What replaces the files.
	 * @return mixed What it returns.
	 */
	public static function run_while_copying( callable $copy ) {
		update_option( Fred_Espace_Client_Rescue::OPTION_COPYING, time() + self::LOCK_TTL, false );

		try {
			return $copy();
		} finally {
			delete_option( Fred_Espace_Client_Rescue::OPTION_COPYING );
		}
	}

	/**
	 * One update at a time per site. The nightly copy of the database takes
	 * the same lock: it must not read tables an update is rewriting.
	 *
	 * @param  int $ttl  Seconds after which a lock left behind expires.
	 * @return int|false When the lock expires, to give it back; false when it is taken.
	 */
	public static function acquire_lock( $ttl = self::LOCK_TTL ) {
		$expires = time() + (int) $ttl;

		if ( add_option( self::OPTION_LOCK, $expires, '', false ) ) {
			return $expires;
		}

		// A lock left behind by a crashed request expires on its own.
		if ( (int) get_option( self::OPTION_LOCK ) < time() ) {
			update_option( self::OPTION_LOCK, $expires, false );

			return $expires;
		}

		return false;
	}

	/**
	 * Give the lock back, if it is still the one taken: past its time, another
	 * request may hold it by now, and its lock is not ours to delete.
	 *
	 * @param  int $held  What acquire_lock() returned.
	 * @return void
	 */
	public static function release_lock( $held ) {
		// The value read at the start of a long request is not the one in the database any more.
		wp_cache_delete( self::OPTION_LOCK, 'options' );

		if ( (int) get_option( self::OPTION_LOCK ) === (int) $held ) {
			delete_option( self::OPTION_LOCK );
		}
	}

	/**
	 * Keep the lock for another while, if it is still the one taken.
	 *
	 * @param  int $held  What acquire_lock() or an earlier renew_lock() returned.
	 * @param  int $ttl   Seconds from now.
	 * @return int|false The new value to give back, or false when the lock is not ours any more.
	 */
	public static function renew_lock( $held, $ttl = self::LOCK_TTL ) {
		wp_cache_delete( self::OPTION_LOCK, 'options' );

		if ( (int) get_option( self::OPTION_LOCK ) !== (int) $held ) {
			return false;
		}

		$expires = time() + (int) $ttl;
		update_option( self::OPTION_LOCK, $expires, false );

		return $expires;
	}

	/**
	 * Direct filesystem access, without asking for FTP credentials.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
				return null;
			}
		}

		return $wp_filesystem;
	}

	/**
	 * Empty the object cache and the page caches that expose a purge hook,
	 * so the checks made after an update see the real site.
	 *
	 * @return void
	 */
	private static function purge_caches() {
		wp_cache_flush();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party purge hooks.
		do_action( 'litespeed_purge_all' );
		do_action( 'breeze_clear_all_cache' );
		// phpcs:enable

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
	}

	/**
	 * Load the admin code the upgraders rely on.
	 *
	 * @return void
	 */
	private static function load_admin() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
}
