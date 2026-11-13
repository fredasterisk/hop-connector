<?php
/**
 * Reading of the site's files by the platform, for the nightly backup of a
 * site whose server it cannot reach otherwise.
 *
 * The platform asks for the list of files, compares it with the copy it
 * already holds, then asks for the files that changed. Nothing is written
 * here, nothing is zipped: the site only reads and answers.
 *
 * Only the WordPress folder is read. No symbolic link is followed, in a
 * listing or in a path asked for: a link could lead anywhere on the server.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * File listing and file reading.
 */
class Hop_Connector_Pull {

	const FILES_PER_PAGE = 5000;
	const ITEMS_PER_GO   = 500;

	/**
	 * Most bytes of files sent in one answer.
	 */
	const BYTES_PER_GO = 8 * MB_IN_BYTES;

	/**
	 * One page of the files of the site, in a fixed order.
	 *
	 * @param  string $after  Last path of the previous page, or '' for the first.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function files( $after ) {
		$root = self::root();

		if ( null === $root ) {
			return new WP_Error( 'hop_pull_failed', __( 'Le dossier de WordPress est illisible.', 'hop-connector' ), array( 'status' => 500 ) );
		}

		$after = (string) $after;

		if ( '' !== $after && ! self::is_plain_path( $after ) ) {
			return new WP_Error( 'hop_bad_path', __( 'Chemin refusé.', 'hop-connector' ), array( 'status' => 400 ) );
		}

		$files = array();
		$full  = self::walk( $root, '', '' === $after ? array() : explode( '/', $after ), self::excluded(), $files );

		return array(
			'files'          => $files,
			'next'           => $full ? $files[ count( $files ) - 1 ]['path'] : null,
			// A wp-content moved out of the WordPress folder is not read: the
			// platform must say so instead of calling the backup complete.
			'content_inside' => null !== self::relative( WP_CONTENT_DIR, $root ),
		);
	}

	/**
	 * Send the files asked for, one after the other: for each, a line of JSON
	 * ({path, size, offset, total, mtime, sha256} or {path, error}) followed by
	 * exactly `size` bytes. Files left out for lack of room are asked again.
	 *
	 * @param  mixed $items  List of {path, offset?, length?}.
	 * @return WP_Error|void
	 */
	public static function fetch( $items ) {
		$root = self::root();

		if ( null === $root || ! is_array( $items ) || count( $items ) > self::ITEMS_PER_GO ) {
			return new WP_Error( 'hop_bad_request', __( 'Demande de fichiers refusée.', 'hop-connector' ), array( 'status' => 400 ) );
		}

		$excluded = self::excluded();
		$room     = self::BYTES_PER_GO;

		self::begin();

		foreach ( $items as $item ) {
			$path = is_array( $item ) && isset( $item['path'] ) && is_string( $item['path'] ) ? $item['path'] : '';

			if ( '' === $path ) {
				continue;
			}

			$file = self::readable_file( $root, $path, $excluded );

			if ( null === $file ) {
				echo wp_json_encode(
					array(
						'path'  => $path,
						'error' => 'unreadable',
					)
				) . "\n";
				continue;
			}

			$total  = (int) filesize( $file );
			$offset = isset( $item['offset'] ) ? max( 0, (int) $item['offset'] ) : 0;
			$length = min( isset( $item['length'] ) ? max( 0, (int) $item['length'] ) : $total, max( 0, $total - $offset ) );

			// The first file always goes, cut to the room there is; the next
			// ones only whole.
			if ( $length > $room ) {
				if ( $room < self::BYTES_PER_GO ) {
					break;
				}

				$length = $room;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A piece of a local file.
			$bytes = $length > 0 ? file_get_contents( $file, false, null, $offset, $length ) : '';

			if ( false === $bytes ) {
				echo wp_json_encode(
					array(
						'path'  => $path,
						'error' => 'unreadable',
					)
				) . "\n";
				continue;
			}

			echo wp_json_encode(
				array(
					'path'   => $path,
					'size'   => strlen( $bytes ),
					'offset' => $offset,
					'total'  => $total,
					'mtime'  => (int) filemtime( $file ),
					'sha256' => hash( 'sha256', $bytes ),
				)
			) . "\n";
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw bytes of a file, sent as application/octet-stream.
			echo $bytes;

			$room -= strlen( $bytes );
			unset( $bytes );
		}

		exit;
	}

	/**
	 * Send raw bytes and stop.
	 *
	 * @param  string $bytes  Bytes to send.
	 * @return void
	 */
	public static function send( $bytes ) {
		self::begin();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw bytes, sent as application/octet-stream.
		echo $bytes;
		exit;
	}

	/**
	 * Headers of a binary answer that no cache layer may store.
	 *
	 * @return void
	 */
	private static function begin() {
		// Anything buffered so far (a notice, a stray space of another plugin) would corrupt the bytes.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Hosts may forbid it.
			@set_time_limit( 300 );
		}

		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Walk a folder in byte order, files and sub-folders together, and collect
	 * the files that come after the last path of the previous page.
	 *
	 * @param  string   $dir       Absolute folder.
	 * @param  string   $relative  Its path from the root, '' for the root.
	 * @param  string[] $after     Segments of the last path already sent.
	 * @param  string[] $excluded  Patterns left out.
	 * @param  array[]  $files     Collected so far: path, size and mtime of each file.
	 * @return bool Whether the page is full.
	 */
	private static function walk( $dir, $relative, array $after, array $excluded, array &$files ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A folder the site may not read is skipped.
		$names = @scandir( $dir );

		if ( ! is_array( $names ) ) {
			return false;
		}

		// scandir() sorts by the locale on some hosts: the order must be the same on every page.
		sort( $names, SORT_STRING );

		$depth = '' === $relative ? 0 : substr_count( $relative, '/' ) + 1;
		// Inside the folder that held the last path sent, everything up to that path is already sent.
		$resume = isset( $after[ $depth ] ) && implode( '/', array_slice( $after, 0, $depth ) ) === $relative ? $after[ $depth ] : null;

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name || ! self::is_plain_name( $name ) ) {
				continue;
			}

			$order = null === $resume ? 1 : strcmp( $name, $resume );

			if ( $order < 0 ) {
				continue;
			}

			$path = $dir . '/' . $name;
			$rel  = '' === $relative ? $name : $relative . '/' . $name;

			if ( is_link( $path ) || self::is_excluded( $rel, $excluded ) ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				// The folder of the last path sent is entered again, to send what follows in it.
				if ( ( $order > 0 || count( $after ) > $depth + 1 ) && self::walk( $path, $rel, 0 === $order ? $after : array(), $excluded, $files ) ) {
					return true;
				}

				continue;
			}

			if ( 0 === $order || ! is_file( $path ) ) {
				continue;
			}

			$files[] = array(
				'path'  => $rel,
				'size'  => (int) filesize( $path ),
				'mtime' => (int) filemtime( $path ),
			);

			if ( count( $files ) >= self::FILES_PER_PAGE ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Absolute path of a file the platform may read, or null.
	 *
	 * @param  string   $root      WordPress folder.
	 * @param  string   $relative  Path asked for.
	 * @param  string[] $excluded  Patterns left out.
	 * @return string|null
	 */
	private static function readable_file( $root, $relative, array $excluded ) {
		if ( ! self::is_plain_path( $relative ) || self::is_excluded( $relative, $excluded ) ) {
			return null;
		}

		$path = $root;

		// No folder on the way may be a link, nor the file itself.
		foreach ( explode( '/', $relative ) as $segment ) {
			$path .= '/' . $segment;

			if ( is_link( $path ) ) {
				return null;
			}
		}

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts that restrict PHP to some folders (open_basedir) refuse here.
		$real = @realpath( $path );

		return $real && null !== self::relative( $real, $root ) ? $path : null;
	}

	/**
	 * A relative path with nothing to climb out with.
	 *
	 * @param  string $path  Path received.
	 * @return bool
	 */
	private static function is_plain_path( $path ) {
		if ( '' === $path || strlen( $path ) > 4096 ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || ! self::is_plain_name( $segment ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A file name that travels safely in JSON and means the same on any system.
	 *
	 * @param  string $name  File or folder name.
	 * @return bool
	 */
	private static function is_plain_name( $name ) {
		return 1 === preg_match( '/^[^\x00-\x1F\x7F\\\\\/:]+$/u', $name );
	}

	/**
	 * Whether a path, or a folder above it, is left out.
	 *
	 * @param  string   $relative  Path from the root.
	 * @param  string[] $excluded  Patterns.
	 * @return bool
	 */
	private static function is_excluded( $relative, array $excluded ) {
		foreach ( $excluded as $pattern ) {
			if ( fnmatch( $pattern, $relative ) || fnmatch( $pattern . '/*', $relative ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a backup does not need: the connector's own copies, the caches,
	 * and the archives other backup plugins leave in wp-content.
	 *
	 * @return list<string>
	 */
	private static function excluded() {
		$root    = (string) self::root();
		$content = self::relative( WP_CONTENT_DIR, $root );
		$content = null === $content || '' === $content ? 'wp-content' : $content;

		$excluded = array(
			'hop-connector-backups-*',
			$content . '/hop-connector-backups-*',
			$content . '/cache',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/debug.log',
			$content . '/updraft',
			$content . '/ai1wm-backups',
			$content . '/backups-dup-*',
			$content . '/wpvividbackups',
			$content . '/backupwordpress-*',
			$content . '/uploads/backwpup*',
		);

		/**
		 * Paths left out of the files the platform backs up, from the WordPress
		 * folder, as fnmatch() patterns.
		 *
		 * @param list<string> $excluded  Patterns.
		 */
		$filtered = apply_filters( 'hop_connector_pull_excluded', $excluded );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $excluded;
	}

	/**
	 * The WordPress folder, resolved.
	 *
	 * @return string|null
	 */
	private static function root() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts that restrict PHP to some folders (open_basedir) refuse here.
		$root = @realpath( ABSPATH );

		return $root ? untrailingslashit( wp_normalize_path( $root ) ) : null;
	}

	/**
	 * Path of a folder or file from the root, or null when it is outside.
	 *
	 * @param  string $path  Absolute path.
	 * @param  string $root  Resolved WordPress folder.
	 * @return string|null
	 */
	private static function relative( $path, $root ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts that restrict PHP to some folders (open_basedir) refuse here.
		$real = @realpath( $path );
		$real = $real ? untrailingslashit( wp_normalize_path( $real ) ) : '';

		if ( $real === $root ) {
			return '';
		}

		return '' !== $real && 0 === strpos( $real, $root . '/' ) ? substr( $real, strlen( $root ) + 1 ) : null;
	}
}
