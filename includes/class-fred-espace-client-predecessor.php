<?php
/**
 * Takeover from hop-connector, the connector's former name.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * For WordPress, another folder is another plugin: the connector could not
 * be renamed by an update. Its last version installs this plugin beside it,
 * and this plugin, at its activation, takes over: the key, the settings, the
 * copies and the agency's accounts. Then it switches the former one off,
 * without its hooks, and removes its folder, without its uninstall.php: both
 * would erase what was just taken over, and disconnect the site.
 */
class Fred_Espace_Client_Predecessor {

	/**
	 * Main file of the former plugin.
	 */
	const FILE = 'hop-connector/hop-connector.php';

	/**
	 * Prefix of its options.
	 */
	const PREFIX = 'hop_connector_';

	/**
	 * Its options that are not taken over: the lock and the mark of the very
	 * request that installs this plugin, and the version read from the platform.
	 */
	const LEFT = array( 'hop_connector_update_lock', 'hop_connector_copying', 'hop_connector_latest' );

	/**
	 * Option set until the folder of the former plugin is removed.
	 */
	const OPTION_REMOVE = 'fred_espace_client_remove_predecessor';

	/**
	 * Take over from the former plugin, when it is here. Safe to run twice.
	 *
	 * @return void
	 */
	public static function take_over() {
		if ( ! self::is_present() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$options = self::take_options();
		self::take_user_meta();
		self::take_copies();

		if ( file_exists( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' ) ) {
			wp_delete_file( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' );
		}

		wp_clear_scheduled_hook( 'hop_connector_cleanup' );
		delete_site_transient( 'hop_connector_latest' );

		// Silently: its deactivation hook would erase the copies of the database.
		if ( is_multisite() && is_plugin_active_for_network( self::FILE ) ) {
			deactivate_plugins( self::FILE, true, true );
		}

		deactivate_plugins( self::FILE, true );
		update_option( self::OPTION_REMOVE, 1, false );

		Fred_Espace_Client_Log::record(
			'took_over',
			array(
				'result' => 'took_over',
				'note'   => sprintf( 'hop-connector, %d option(s)', $options ),
			)
		);
	}

	/**
	 * Remove the folder of the former plugin, on the first request after the
	 * takeover: the request that installed this plugin was still running its
	 * code. Never while the former plugin is active again.
	 *
	 * @return void
	 */
	public static function remove_folder() {
		if ( ! get_option( self::OPTION_REMOVE ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_plugin_active( self::FILE ) || ( is_multisite() && is_plugin_active_for_network( self::FILE ) ) ) {
			delete_option( self::OPTION_REMOVE );

			return;
		}

		$folder = WP_PLUGIN_DIR . '/' . dirname( self::FILE );

		if ( is_dir( $folder ) && ! is_link( $folder ) ) {
			self::delete_tree( $folder );
		}

		// What the former plugin wrote after the takeover, in its last request.
		self::take_options();
		delete_option( self::OPTION_REMOVE );
		wp_clean_plugins_cache( false );
	}

	/**
	 * Whether the former plugin is here: its folder, or its key.
	 *
	 * @return bool
	 */
	public static function is_present() {
		return is_dir( WP_PLUGIN_DIR . '/' . dirname( self::FILE ) ) || false !== get_option( self::PREFIX . 'key' );
	}

	/**
	 * Copy every option of the former plugin under this plugin's prefix, as
	 * stored (serialized), then delete it.
	 *
	 * @return int Options taken over.
	 */
	private static function take_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off takeover of rows the options API cannot list.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%'
			),
			ARRAY_A
		);

		$taken = 0;

		foreach ( (array) $rows as $row ) {
			$name = (string) $row['option_name'];

			if ( self::PREFIX . 'log' === $name ) {
				self::merge_log( maybe_unserialize( (string) $row['option_value'] ) );
				++$taken;
			} elseif ( ! in_array( $name, self::LEFT, true ) ) {
				// An option this plugin already holds is never replaced: the
				// former plugin may still write after the takeover, in the
				// request that installed this one.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The value is copied as stored, without unserializing it.
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						'fred_espace_client_' . substr( $name, strlen( self::PREFIX ) ),
						(string) $row['option_value'],
						(string) $row['autoload']
					)
				);
				wp_cache_delete( 'fred_espace_client_' . substr( $name, strlen( self::PREFIX ) ), 'options' );
				++$taken;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
			wp_cache_delete( $name, 'options' );
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $taken;
	}

	/**
	 * Add the entries of the former journal to this plugin's, by date: the
	 * history goes on, and a line the former plugin wrote after the takeover
	 * joins it instead of replacing it.
	 *
	 * @param  mixed $former  Entries of the former journal.
	 * @return void
	 */
	private static function merge_log( $former ) {
		$current = get_option( Fred_Espace_Client_Log::OPTION, array() );
		$entries = array_merge( is_array( $current ) ? $current : array(), is_array( $former ) ? $former : array() );

		usort(
			$entries,
			static function ( $a, $b ) {
				return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
			}
		);

		update_option( Fred_Espace_Client_Log::OPTION, array_slice( $entries, 0, Fred_Espace_Client_Log::KEEP ), false );
	}

	/**
	 * The accounts made for the agency stay known as such.
	 *
	 * @return void
	 */
	private static function take_user_meta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::PREFIX . 'agency_account' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once, at the takeover.
		$wpdb->update( $wpdb->usermeta, array( 'meta_key' => Fred_Espace_Client_Login::AGENCY_META ), array( 'meta_key' => self::PREFIX . 'agency_account' ) );

		foreach ( (array) $users as $user_id ) {
			wp_cache_delete( (int) $user_id, 'user_meta' );
		}
	}

	/**
	 * Rename the folder of the copies, and the paths recorded in the options
	 * just taken over.
	 *
	 * @return void
	 */
	private static function take_copies() {
		$suffix = get_option( 'fred_espace_client_backup_dir' );

		if ( ! is_string( $suffix ) || ! preg_match( '/^[a-f0-9]{24}$/', $suffix ) ) {
			return;
		}

		$parents = array_unique( array_filter( array( (string) get_option( 'fred_espace_client_backup_parent' ), WP_CONTENT_DIR ) ) );

		foreach ( $parents as $parent ) {
			$old = $parent . '/hop-connector-backups-' . $suffix;
			$new = $parent . '/fred-espace-client-backups-' . $suffix;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Within one local directory.
			if ( ! is_dir( $old ) || file_exists( $new ) || ! rename( $old, $new ) ) {
				continue;
			}

			$backups = get_option( Fred_Espace_Client_Updater::OPTION_BACKUPS );

			if ( is_array( $backups ) ) {
				foreach ( $backups as $id => $backup ) {
					if ( is_array( $backup ) && isset( $backup['path'] ) ) {
						$backups[ $id ]['path'] = str_replace( $old, $new, (string) $backup['path'] );
					}
				}

				update_option( Fred_Espace_Client_Updater::OPTION_BACKUPS, $backups, false );
			}

			return;
		}
	}

	/**
	 * Delete a folder and everything in it, links included but never followed.
	 *
	 * @param  string $folder  Folder.
	 * @return void
	 */
	private static function delete_tree( $folder ) {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Folder of the former plugin, emptied first.
				rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $folder );
	}
}
