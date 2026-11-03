<?php
/**
 * Copy of the WordPress database taken before updates, and its restoration.
 *
 * The copy is a gzipped SQL file holding one statement per line, written
 * through $wpdb so it works on any host, without mysqldump or exec().
 * It lives next to the plugin backups, in the directory closed to the web,
 * under an unguessable name.
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
	 * Export every table of this WordPress installation.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function snapshot() {
		global $wpdb;

		$root = Hop_Connector_Updater::backup_root();

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		self::extend_time();

		$snapshot_id = 'db-' . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 8 ) );
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
		self::prune();

		return array(
			'backup_id' => $snapshot_id,
			'tables'    => count( $tables ),
			'rows'      => $rows,
			'size'      => (int) filesize( $path ),
		);
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
				$wpdb->suppress_errors( $previous );

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

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from SHOW TABLES, integers only.
			$rows = $wpdb->get_results( sprintf( 'SELECT * FROM `%s` LIMIT %d OFFSET %d', $table, self::ROWS_PER_GO, $offset ), ARRAY_N );

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
	 * @return string|null
	 */
	private static function path( $snapshot_id ) {
		if ( 1 !== preg_match( '/^db-\d{14}-[a-f0-9]{16}$/', $snapshot_id ) ) {
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
