<?php
/**
 * Copy of the WordPress database taken before updates, and its restoration.
 *
 * The copy is a gzipped SQL file holding one statement per line, written
 * through $wpdb so it works on any host, without mysqldump or exec().
 * It lives next to the plugin backups, in the directory closed to the web,
 * under an unguessable name.
 *
 * The same copy serves the platform's nightly backup of a site it cannot
 * reach otherwise: written here, taken away in pieces, then deleted.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database snapshots.
 */
class Hop_Connector_Database {

	const KEEP            = 3;
	const ROWS_PER_GO     = 500;
	const ROWS_PER_INSERT = 100;

	/**
	 * A copy holds every personal record of the site: it is not kept longer
	 * than needed to undo a recent update.
	 */
	const MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * A copy made for the platform to take away is deleted once taken. One
	 * left behind by a night that went wrong does not wait for the next.
	 */
	const PULLED_MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Most bytes of a copy sent in one answer.
	 */
	const CHUNK = 8 * MB_IN_BYTES;

	/**
	 * The connector's own options: its key, nonces and login tokens never go
	 * into a copy, and a restoration never brings back an older state of them.
	 */
	const OWN_OPTIONS = 'hop_connector_';

	/**
	 * Export every table of this WordPress installation.
	 *
	 * A copy taken before updates (db) stays on the site, among the last few.
	 * A copy the platform takes away each night (pull) is apart: it never
	 * pushes out the copies that can undo an update, and there is one at most.
	 *
	 * @param  string $kind  db or pull.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function snapshot( $kind = 'db' ) {
		$pulled = 'pull' === $kind;
		$root   = Hop_Connector_Updater::backup_root();

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		if ( $pulled ) {
			if ( ! Hop_Connector_Updater::acquire_lock() ) {
				return new WP_Error( 'hop_locked', __( 'Une mise à jour est déjà en cours sur ce site.', 'hop-connector' ), array( 'status' => 409 ) );
			}

			foreach ( (array) glob( $root . '/pull-*.sql.gz' ) as $left_behind ) {
				wp_delete_file( $left_behind );
			}
		}

		try {
			return self::write_snapshot( $root, $pulled );
		} finally {
			if ( $pulled ) {
				Hop_Connector_Updater::release_lock();
			}
		}
	}

	/**
	 * Write the copy.
	 *
	 * @param  string $root    Backup directory.
	 * @param  bool   $pulled  Whether the platform takes the copy away.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function write_snapshot( $root, $pulled ) {
		global $wpdb;

		self::extend_time();

		$snapshot_id = ( $pulled ? 'pull-' : 'db-' ) . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 8 ) );
		$path        = $root . '/' . $snapshot_id . '.sql.gz';
		$file        = gzopen( $path, 'wb6' );

		if ( ! $file ) {
			return new WP_Error( 'hop_backup_failed', __( 'Impossible d\'écrire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		$tables = self::tables();
		$rows   = 0;

		try {
			gzwrite( $file, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" );

			foreach ( $tables as $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from SHOW TABLES.
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

				if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
					throw new RuntimeException( sprintf( 'SHOW CREATE TABLE %s', $table ) );
				}

				gzwrite( $file, "DROP TABLE IF EXISTS `{$table}`;\n" . str_replace( array( "\r", "\n" ), ' ', $create[1] ) . ";\n" );

				$rows += self::export_rows( $file, $table );
			}

			gzwrite( $file, "SET FOREIGN_KEY_CHECKS=1;\n" );
		} catch ( Throwable $exception ) {
			gzclose( $file );
			wp_delete_file( $path );

			return new WP_Error( 'hop_backup_failed', __( 'La copie de la base a échoué : ', 'hop-connector' ) . $exception->getMessage(), array( 'status' => 500 ) );
		}

		gzclose( $file );
		self::protect( $path );

		$result = array(
			'backup_id' => $snapshot_id,
			'tables'    => count( $tables ),
			'rows'      => $rows,
			'size'      => (int) filesize( $path ),
		);

		if ( $pulled ) {
			$result['sha256'] = (string) hash_file( 'sha256', $path );
		} else {
			self::prune();
		}

		return $result;
	}

	/**
	 * A piece of a copy the platform is taking away.
	 *
	 * @param  string $snapshot_id  Identifier returned by snapshot( 'pull' ).
	 * @param  int    $offset       First byte.
	 * @param  int    $length       Bytes wanted, CHUNK at most.
	 * @return string|WP_Error
	 */
	public static function chunk( $snapshot_id, $offset, $length ) {
		$path = self::path( $snapshot_id, 'pull' );

		if ( null === $path ) {
			return new WP_Error( 'hop_no_backup', __( 'Copie de la base introuvable.', 'hop-connector' ), array( 'status' => 404 ) );
		}

		$length = max( 1, min( (int) $length, self::CHUNK ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A piece of a local file.
		$bytes = file_get_contents( $path, false, null, max( 0, (int) $offset ), $length );

		if ( false === $bytes ) {
			return new WP_Error( 'hop_backup_failed', __( 'Impossible de lire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		return $bytes;
	}

	/**
	 * Delete a copy the platform has taken away.
	 *
	 * @param  string $snapshot_id  Identifier returned by snapshot( 'pull' ).
	 * @return array<string,mixed>
	 */
	public static function discard( $snapshot_id ) {
		$path = self::path( $snapshot_id, 'pull' );

		if ( null !== $path ) {
			wp_delete_file( $path );
		}

		return array( 'status' => 'discarded' );
	}

	/**
	 * Put the database back as it was in a snapshot.
	 *
	 * @param  string $snapshot_id  Identifier returned by snapshot().
	 * @return array<string,mixed>|WP_Error
	 */
	public static function restore( $snapshot_id ) {
		global $wpdb;

		$path = self::path( $snapshot_id );

		if ( null === $path ) {
			return new WP_Error( 'hop_no_backup', __( 'Copie de la base introuvable.', 'hop-connector' ), array( 'status' => 404 ) );
		}

		self::extend_time();

		$file = gzopen( $path, 'rb' );

		if ( ! $file ) {
			return new WP_Error( 'hop_restore_failed', __( 'Impossible de lire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		$statements = 0;
		$own        = self::own_options();

		// A statement that fails must stop the restoration, not be skipped silently.
		$previous = $wpdb->suppress_errors( true );

		while ( ! gzeof( $file ) ) {
			$line = gzgets( $file );

			if ( false === $line ) {
				break;
			}

			$line = rtrim( $line, "\r\n" );

			if ( '' === $line ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Statements written by snapshot().
			if ( false === $wpdb->query( $line ) ) {
				$error = $wpdb->last_error;
				gzclose( $file );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Leave the session as found.
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
				self::put_back_own_options( $own );
				$wpdb->suppress_errors( $previous );
				wp_cache_flush();

				return new WP_Error(
					'hop_restore_failed',
					__( 'La restauration de la base s\'est arrêtée en cours de route : ', 'hop-connector' ) . $error,
					array(
						'status'     => 500,
						'statements' => $statements,
					)
				);
			}

			++$statements;
		}

		gzclose( $file );
		self::put_back_own_options( $own );
		$wpdb->suppress_errors( $previous );
		wp_cache_flush();

		return array(
			'status'     => 'restored',
			'backup_id'  => $snapshot_id,
			'statements' => $statements,
		);
	}

	/**
	 * Snapshots on disk, newest first.
	 *
	 * @return list<array{backup_id: string, size: int, created: int}>
	 */
	public static function snapshots() {
		$root = Hop_Connector_Updater::backup_root();

		if ( is_wp_error( $root ) ) {
			return array();
		}

		$snapshots = array();

		foreach ( (array) glob( $root . '/db-*.sql.gz' ) as $path ) {
			$snapshots[] = array(
				'backup_id' => basename( $path, '.sql.gz' ),
				'size'      => (int) filesize( $path ),
				'created'   => (int) filemtime( $path ),
			);
		}

		usort(
			$snapshots,
			function ( $a, $b ) {
				return strcmp( $b['backup_id'], $a['backup_id'] );
			}
		);

		return $snapshots;
	}

	/**
	 * Write the rows of one table, a few hundred at a time to spare memory.
	 *
	 * @param  resource $file   Open gzip handle.
	 * @param  string   $table  Table name.
	 * @return int Number of rows written.
	 */
	private static function export_rows( $file, $table ) {
		global $wpdb;

		$offset = 0;
		$total  = 0;
		// The connector's key never travels in a copy.
		$where = $table === $wpdb->options ? $wpdb->prepare( ' WHERE option_name NOT LIKE %s', $wpdb->esc_like( self::OWN_OPTIONS ) . '%' ) : '';

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name from SHOW TABLES, prepared condition, integers only.
			$rows = $wpdb->get_results( sprintf( 'SELECT * FROM `%s`%s LIMIT %d OFFSET %d', $table, $where, self::ROWS_PER_GO, $offset ), ARRAY_N );

			if ( null === $rows && '' !== $wpdb->last_error ) {
				throw new RuntimeException( $wpdb->last_error );
			}

			$rows = (array) $rows;

			foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $chunk ) {
				$values = array();

				foreach ( $chunk as $row ) {
					$values[] = '(' . implode( ',', array_map( array( __CLASS__, 'literal' ), $row ) ) . ')';
				}

				gzwrite( $file, "INSERT INTO `{$table}` VALUES " . implode( ',', $values ) . ";\n" );
			}

			$total  += count( $rows );
			$offset += self::ROWS_PER_GO;
		} while ( count( $rows ) === self::ROWS_PER_GO );

		return $total;
	}

	/**
	 * SQL literal of one value. The escaping turns line breaks into \n, so a
	 * statement always fits on one line of the file.
	 *
	 * @param  mixed $value  Column value.
	 * @return string
	 */
	private static function literal( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		$value = (string) $value;

		if ( $wpdb->dbh instanceof mysqli ) {
			return "'" . mysqli_real_escape_string( $wpdb->dbh, $value ) . "'";
		}

		return "'" . str_replace( array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ), array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ), $value ) . "'";
	}

	/**
	 * Tables of this installation only: another application may share the database.
	 *
	 * @return list<string>
	 */
	private static function tables() {
		global $wpdb;

		$prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

		return array_values( array_filter( array_map( 'strval', (array) $tables ), array( __CLASS__, 'is_safe_name' ) ) );
	}

	/**
	 * Table names are written between backticks: refuse anything unusual.
	 *
	 * @param  string $table  Table name.
	 * @return bool
	 */
	private static function is_safe_name( $table ) {
		return 1 === preg_match( '/^[A-Za-z0-9_$]+$/', $table );
	}

	/**
	 * Absolute path of a snapshot, or null when the identifier is unknown.
	 *
	 * @param  string $snapshot_id  Identifier.
	 * @param  string $kind         db or pull: one is never read as the other.
	 * @return string|null
	 */
	private static function path( $snapshot_id, $kind = 'db' ) {
		if ( 1 !== preg_match( '/^' . ( 'pull' === $kind ? 'pull' : 'db' ) . '-\d{14}-[a-f0-9]{16}$/', $snapshot_id ) ) {
			return null;
		}

		$root = Hop_Connector_Updater::backup_root();

		if ( is_wp_error( $root ) || ! is_file( $root . '/' . $snapshot_id . '.sql.gz' ) ) {
			return null;
		}

		return $root . '/' . $snapshot_id . '.sql.gz';
	}

	/**
	 * Keep only the most recent snapshots.
	 *
	 * @return void
	 */
	private static function prune() {
		$root = Hop_Connector_Updater::backup_root();

		foreach ( array_slice( self::snapshots(), self::KEEP ) as $old ) {
			wp_delete_file( $root . '/' . $old['backup_id'] . '.sql.gz' );
		}
	}

	/**
	 * Delete the snapshots older than MAX_AGE (hourly cron).
	 *
	 * @return void
	 */
	public static function purge_expired() {
		// A site that never took a copy has no directory to look into, and
		// backup_root() would create one.
		if ( ! get_option( 'hop_connector_backup_dir' ) ) {
			return;
		}

		$root = Hop_Connector_Updater::backup_root();

		foreach ( self::snapshots() as $snapshot ) {
			if ( $snapshot['created'] < time() - self::MAX_AGE ) {
				wp_delete_file( $root . '/' . $snapshot['backup_id'] . '.sql.gz' );
			}
		}

		foreach ( (array) glob( $root . '/pull-*.sql.gz' ) as $path ) {
			if ( (int) filemtime( $path ) < time() - self::PULLED_MAX_AGE ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Readable by the site's own user only: not by the web server, nor by the
	 * other sites of a shared server. Hosts that refuse chmod keep the default.
	 *
	 * @param  string $path  File written.
	 * @return void
	 */
	private static function protect( $path ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best effort on any host.
		@chmod( $path, 0600 );
	}

	/**
	 * The connector's rows of the options table, as they are now.
	 *
	 * @return list<array{option_name: string, option_value: string, autoload: string}>
	 */
	private static function own_options() {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw rows, put back as they were.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OWN_OPTIONS ) . '%'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * After a restoration, the connector is back exactly as it was before it:
	 * a key revoked since the copy stays revoked, the nonces already used stay
	 * used, and the list of backups stays current.
	 *
	 * @param  list<array{option_name: string, option_value: string, autoload: string}> $rows  Rows read by own_options().
	 * @return void
	 */
	private static function put_back_own_options( array $rows ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own rows, restored below.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OWN_OPTIONS ) . '%'
			)
		);

		foreach ( $rows as $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw row written back unchanged.
			$wpdb->replace( $wpdb->options, $row, array( '%s', '%s', '%s' ) );
		}
	}

	/**
	 * A large database takes a while: do not let the PHP time limit cut it in half.
	 *
	 * @return void
	 */
	private static function extend_time() {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Hosts may forbid it.
			@set_time_limit( 900 );
		}
	}
}
