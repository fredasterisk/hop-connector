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
	 * Seconds of work in one request. Nginx, Cloudflare and most proxies give
	 * up on PHP after 60 to 100 seconds: a copy of any size is written over as
	 * many requests as it needs, never in one that is cut halfway.
	 */
	const STEP_SECONDS = 20;

	/**
	 * Seconds a step may take: past STEP_SECONDS, the last batch of rows
	 * still has to finish.
	 */
	const TIME_LIMIT = 300;

	/**
	 * Seconds a nightly copy keeps the update lock after each step. The
	 * platform asks for the next step at once; one that stopped asking
	 * frees the lock on its own.
	 */
	const LOCK_SECONDS = 10 * MINUTE_IN_SECONDS;

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
	 * Export every table of this WordPress installation in one request, step
	 * after step. For the platforms that do not ask in steps.
	 *
	 * @param  string $kind  db or pull.
	 * @return array<string,mixed>|WP_Error  See advance().
	 */
	public static function snapshot( $kind = 'db' ) {
		$result = self::start( $kind );

		while ( is_array( $result ) && 'running' === $result['status'] ) {
			$result = self::advance( $result['backup_id'], $kind );
		}

		return $result;
	}

	/**
	 * Begin a copy of every table of this WordPress installation and write its
	 * first part. The platform then calls advance() until the copy is done.
	 *
	 * A copy taken before updates (db) stays on the site, among the last few.
	 * A copy the platform takes away each night (pull) is apart: it never
	 * pushes out the copies that can undo an update, and there is one at most.
	 *
	 * Until it is done, a copy is a .part file next to its progress (.job.json),
	 * and no other method reads it as a copy.
	 *
	 * @param  string $kind  db or pull.
	 * @return array<string,mixed>|WP_Error  See advance().
	 */
	public static function start( $kind = 'db' ) {
		$pulled = 'pull' === $kind;
		$root   = Hop_Connector_Updater::backup_root();

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$held = false;

		if ( $pulled ) {
			// Renewed at each step, for as long as the copy takes.
			$held = Hop_Connector_Updater::acquire_lock( self::LOCK_SECONDS );

			if ( false === $held ) {
				return new WP_Error( 'hop_locked', __( 'Une mise à jour est déjà en cours sur ce site.', 'hop-connector' ), array( 'status' => 409 ) );
			}

			// A copy, a part or a progress file left behind by an earlier night.
			foreach ( (array) glob( $root . '/pull-*' ) as $left_behind ) {
				wp_delete_file( $left_behind );
			}
		}

		$snapshot_id = ( $pulled ? 'pull-' : 'db-' ) . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 8 ) );
		$part        = self::part_path( $root, $snapshot_id );
		$file        = gzopen( $part, 'wb6' );

		if ( ! $file ) {
			self::give_back( $held );

			return new WP_Error( 'hop_backup_failed', __( 'Impossible d\'écrire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		gzwrite( $file, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" );
		gzclose( $file );
		self::protect( $part );
		clearstatcache( true, $part );

		$job = array(
			'tables' => self::tables(),
			'table'  => 0,
			'offset' => 0,
			'rows'   => 0,
			'bytes'  => (int) filesize( $part ),
			'held'   => $held,
		);

		if ( ! self::save_job( $root, $snapshot_id, $job ) ) {
			self::abandon( $root, $snapshot_id, $held );

			return new WP_Error( 'hop_backup_failed', __( 'Impossible d\'écrire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		return self::advance( $snapshot_id, $kind );
	}

	/**
	 * Write the next part of a copy begun by start(), for STEP_SECONDS or so.
	 *
	 * @param  string $snapshot_id  Identifier returned by start().
	 * @param  string $kind         db or pull: one is never continued as the other.
	 * @return array<string,mixed>|WP_Error  status running (backup_id, tables,
	 *                                       tables_done, rows) or done (backup_id,
	 *                                       tables, rows, size, and sha256 for a pull).
	 */
	public static function advance( $snapshot_id, $kind = 'db' ) {
		$root = Hop_Connector_Updater::backup_root();
		$job  = ! is_wp_error( $root ) && self::is_id( (string) $snapshot_id, $kind ) ? self::load_job( $root, $snapshot_id ) : null;

		if ( null === $job ) {
			return new WP_Error( 'hop_no_backup', __( 'Copie de la base introuvable.', 'hop-connector' ), array( 'status' => 404 ) );
		}

		self::extend_time();

		$part     = self::part_path( $root, $snapshot_id );
		$deadline = microtime( true ) + self::STEP_SECONDS;
		$count    = count( $job['tables'] );

		try {
			// A step stopped halfway (PHP cut short, server restarted) leaves
			// a broken end: it goes, and its rows are written again.
			self::truncate( $part, $job['bytes'] );

			$file = gzopen( $part, 'ab6' );

			if ( ! $file ) {
				throw new RuntimeException( __( 'fichier illisible', 'hop-connector' ) );
			}

			try {
				// At least one batch per step, so every step moves the copy forward.
				$first = true;

				while ( $job['table'] < $count && ( $first || microtime( true ) < $deadline ) ) {
					$first = false;
					$table = $job['tables'][ $job['table'] ];

					if ( 0 === $job['offset'] ) {
						self::write_structure( $file, $table );
					}

					$exported     = self::export_batch( $file, $table, $job['offset'] );
					$job['rows'] += $exported;

					if ( $exported < self::ROWS_PER_GO ) {
						++$job['table'];
						$job['offset'] = 0;
					} else {
						$job['offset'] += self::ROWS_PER_GO;
					}
				}

				if ( $job['table'] >= $count ) {
					gzwrite( $file, "SET FOREIGN_KEY_CHECKS=1;\n" );
				}
			} finally {
				gzclose( $file );
			}
		} catch ( Throwable $exception ) {
			self::abandon( $root, $snapshot_id, $job['held'] );

			return new WP_Error( 'hop_backup_failed', __( 'La copie de la base a échoué : ', 'hop-connector' ) . $exception->getMessage(), array( 'status' => 500 ) );
		}

		if ( $job['table'] >= $count ) {
			return self::finish( $root, $snapshot_id, $job );
		}

		clearstatcache( true, $part );
		$job['bytes'] = (int) filesize( $part );
		$job['held']  = false === $job['held'] ? false : Hop_Connector_Updater::renew_lock( $job['held'], self::LOCK_SECONDS );

		if ( ! self::save_job( $root, $snapshot_id, $job ) ) {
			self::abandon( $root, $snapshot_id, $job['held'] );

			return new WP_Error( 'hop_backup_failed', __( 'Impossible d\'écrire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		return array(
			'status'      => 'running',
			'backup_id'   => $snapshot_id,
			'tables'      => $count,
			'tables_done' => $job['table'],
			'rows'        => $job['rows'],
		);
	}

	/**
	 * Turn a finished part into a copy.
	 *
	 * @param  string                                   $root         Backup directory.
	 * @param  string                                   $snapshot_id  Identifier.
	 * @param  array{tables: list<string>, rows: int, held: int|false} $job  Progress.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function finish( $root, $snapshot_id, array $job ) {
		$path = $root . '/' . $snapshot_id . '.sql.gz';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Within one local directory.
		if ( ! rename( self::part_path( $root, $snapshot_id ), $path ) ) {
			self::abandon( $root, $snapshot_id, $job['held'] );

			return new WP_Error( 'hop_backup_failed', __( 'Impossible d\'écrire la copie de la base.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		wp_delete_file( self::job_path( $root, $snapshot_id ) );
		self::give_back( $job['held'] );
		clearstatcache( true, $path );

		$result = array(
			'status'    => 'done',
			'backup_id' => $snapshot_id,
			'tables'    => count( $job['tables'] ),
			'rows'      => $job['rows'],
			'size'      => (int) filesize( $path ),
		);

		if ( 0 === strpos( $snapshot_id, 'pull-' ) ) {
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
	 * Write the DROP and CREATE statements of one table.
	 *
	 * @param  resource $file   Open gzip handle.
	 * @param  string   $table  Table name.
	 * @return void
	 * @throws RuntimeException When the table cannot be described.
	 */
	private static function write_structure( $file, $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from SHOW TABLES.
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

		if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
			throw new RuntimeException( sprintf( 'SHOW CREATE TABLE %s', $table ) );
		}

		gzwrite( $file, "DROP TABLE IF EXISTS `{$table}`;\n" . str_replace( array( "\r", "\n" ), ' ', $create[1] ) . ";\n" );
	}

	/**
	 * Write one batch of rows of a table: a few hundred, to spare memory.
	 *
	 * @param  resource $file    Open gzip handle.
	 * @param  string   $table   Table name.
	 * @param  int      $offset  First row of the batch.
	 * @return int Rows written: fewer than ROWS_PER_GO at the end of the table.
	 * @throws RuntimeException When the rows cannot be read.
	 */
	private static function export_batch( $file, $table, $offset ) {
		global $wpdb;

		// The connector's key never travels in a copy.
		$where = $table === $wpdb->options ? $wpdb->prepare( ' WHERE option_name NOT LIKE %s', $wpdb->esc_like( self::OWN_OPTIONS ) . '%' ) : '';

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

		return count( $rows );
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
		if ( ! self::is_id( $snapshot_id, $kind ) ) {
			return null;
		}

		$root = Hop_Connector_Updater::backup_root();

		if ( is_wp_error( $root ) || ! is_file( $root . '/' . $snapshot_id . '.sql.gz' ) ) {
			return null;
		}

		return $root . '/' . $snapshot_id . '.sql.gz';
	}

	/**
	 * Whether an identifier is one start() could have given, for that kind.
	 *
	 * @param  string $snapshot_id  Identifier.
	 * @param  string $kind         db or pull.
	 * @return bool
	 */
	private static function is_id( $snapshot_id, $kind ) {
		return 1 === preg_match( '/^' . ( 'pull' === $kind ? 'pull' : 'db' ) . '-\d{14}-[a-f0-9]{16}$/', $snapshot_id );
	}

	/**
	 * Path of a copy being written.
	 *
	 * @param  string $root         Backup directory.
	 * @param  string $snapshot_id  Identifier.
	 * @return string
	 */
	private static function part_path( $root, $snapshot_id ) {
		return $root . '/' . $snapshot_id . '.sql.gz.part';
	}

	/**
	 * Path of the progress of a copy being written.
	 *
	 * @param  string $root         Backup directory.
	 * @param  string $snapshot_id  Identifier.
	 * @return string
	 */
	private static function job_path( $root, $snapshot_id ) {
		return $root . '/' . $snapshot_id . '.job.json';
	}

	/**
	 * Keep the progress of a copy for its next step.
	 *
	 * @param  string               $root         Backup directory.
	 * @param  string               $snapshot_id  Identifier.
	 * @param  array<string, mixed> $job          Progress.
	 * @return bool
	 */
	private static function save_job( $root, $snapshot_id, array $job ) {
		$path = self::job_path( $root, $snapshot_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A small local file, closed to the web.
		if ( false === file_put_contents( $path, (string) wp_json_encode( $job ) ) ) {
			return false;
		}

		self::protect( $path );

		return true;
	}

	/**
	 * The progress of a copy being written, or null when there is none.
	 *
	 * @param  string $root         Backup directory.
	 * @param  string $snapshot_id  Identifier.
	 * @return array{tables: list<string>, table: int, offset: int, rows: int, bytes: int, held: int|false}|null
	 */
	private static function load_job( $root, $snapshot_id ) {
		$path = self::job_path( $root, $snapshot_id );

		if ( ! is_file( $path ) || ! is_file( self::part_path( $root, $snapshot_id ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A small local file.
		$job = json_decode( (string) file_get_contents( $path ), true );

		if ( ! is_array( $job ) || ! isset( $job['tables'], $job['table'], $job['offset'], $job['rows'], $job['bytes'] ) || ! is_array( $job['tables'] ) ) {
			return null;
		}

		// Table names go between backticks: the file is checked as if it came from outside.
		$tables = array_values( array_filter( array_map( 'strval', $job['tables'] ), array( __CLASS__, 'is_safe_name' ) ) );

		if ( count( $tables ) !== count( $job['tables'] ) || (int) $job['table'] > count( $tables ) ) {
			return null;
		}

		return array(
			'tables' => $tables,
			'table'  => max( 0, (int) $job['table'] ),
			'offset' => max( 0, (int) $job['offset'] ),
			'rows'   => max( 0, (int) $job['rows'] ),
			'bytes'  => max( 0, (int) $job['bytes'] ),
			'held'   => isset( $job['held'] ) && false !== $job['held'] ? (int) $job['held'] : false,
		);
	}

	/**
	 * Cut a part back to the size it had at the end of the last step.
	 *
	 * @param  string $path   Part.
	 * @param  int    $bytes  Size kept in the progress.
	 * @return void
	 * @throws RuntimeException When the part is shorter than that: it cannot be continued.
	 */
	private static function truncate( $path, $bytes ) {
		clearstatcache( true, $path );
		$size = (int) filesize( $path );

		if ( $size === $bytes ) {
			return;
		}

		if ( $size < $bytes ) {
			throw new RuntimeException( __( 'la copie en cours a perdu une partie', 'hop-connector' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- ftruncate needs a handle.
		$handle = fopen( $path, 'r+' );

		if ( false === $handle || ! ftruncate( $handle, $bytes ) ) {
			throw new RuntimeException( __( 'fichier illisible', 'hop-connector' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Handle opened above.
		fclose( $handle );
	}

	/**
	 * Drop a copy that failed: its part, its progress and its lock.
	 *
	 * @param  string    $root         Backup directory.
	 * @param  string    $snapshot_id  Identifier.
	 * @param  int|false $held         Lock held, or false.
	 * @return void
	 */
	private static function abandon( $root, $snapshot_id, $held ) {
		wp_delete_file( self::part_path( $root, $snapshot_id ) );
		wp_delete_file( self::job_path( $root, $snapshot_id ) );
		self::give_back( $held );
	}

	/**
	 * Give the update lock back, when the copy holds it.
	 *
	 * @param  int|false $held  What acquire_lock() or renew_lock() returned.
	 * @return void
	 */
	private static function give_back( $held ) {
		if ( false !== $held ) {
			Hop_Connector_Updater::release_lock( $held );
		}
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
	 * Delete the snapshots older than MAX_AGE, and what a copy left behind
	 * after PULLED_MAX_AGE (hourly cron).
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

		// Copies taken away, and copies the platform stopped writing halfway.
		$left_behind = array_merge(
			(array) glob( $root . '/pull-*.sql.gz' ),
			(array) glob( $root . '/*.sql.gz.part' ),
			(array) glob( $root . '/*.job.json' )
		);

		foreach ( $left_behind as $path ) {
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
			@set_time_limit( self::TIME_LIMIT );
		}
	}
}
