<?php
/**
 * Copy and restoration of the database, step after step.
 *
 * These tests run real CREATE, DROP and RENAME statements: they leave the
 * transaction of the WordPress test case, and clean up after themselves.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * @group database
 */
class DatabaseTest extends Fred_Espace_Client_Test_Case {

	/**
	 * @var string
	 */
	private $table;

	public function set_up() {
		global $wpdb;

		parent::set_up();

		// The test case turns CREATE TABLE into CREATE TEMPORARY TABLE; a
		// restoration renames tables, which temporary tables cannot follow.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->table = $wpdb->prefix . 'hop_items';

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
		$wpdb->query( "CREATE TABLE `{$this->table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$this->insert_items( array( 'un', 'deux', 'trois' ) );
	}

	public function tear_down() {
		global $wpdb;

		$this->set_step_seconds( Fred_Espace_Client_Database::STEP_SECONDS );
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );

		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix . 'hop_' ) . '%' ) ) as $left ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$left}`" );
		}

		Fred_Espace_Client_Database::purge_all();
		delete_option( Fred_Espace_Client_Updater::OPTION_LOCK );
		update_option( 'blogname', 'Test Blog' );
		// The DDL above already committed this test's writes: commit the
		// cleanup too, or the rollback of the test case would undo it.
		$wpdb->query( 'COMMIT' );

		parent::tear_down();
	}

	public function test_a_restoration_brings_back_the_rows_of_the_copy() {
		$copy = Fred_Espace_Client_Database::snapshot();

		$this->assertSame( 'done', $copy['status'] );

		$this->change_everything();

		$restored = Fred_Espace_Client_Database::restore( $copy['backup_id'] );

		$this->assertSame( 'restored', $restored['status'] );
		$this->assertSame( array( 'un', 'deux', 'trois' ), $this->labels() );
		$this->assertSame( 'Avant', $this->fresh_option( 'blogname' ) );
		$this->assertSame( array(), $this->temporary_tables() );
	}

	public function test_the_connectors_own_options_never_go_into_a_copy() {
		$this->pair();
		$copy = Fred_Espace_Client_Database::snapshot();
		$sql  = $this->read_copy( $copy['backup_id'] );

		$this->assertStringContainsString( 'blogname', $sql );
		$this->assertStringNotContainsString( Fred_Espace_Client_Keys::OPTION_ACTIVE, $sql );
	}

	public function test_a_restoration_keeps_the_site_connected() {
		$this->pair();
		$copy = Fred_Espace_Client_Database::snapshot();

		Fred_Espace_Client_Database::restore( $copy['backup_id'] );

		wp_cache_flush();
		$this->assertSame( self::KEY_ID, Fred_Espace_Client_Keys::active()['key_id'] );
		$this->assertSame( 200, $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) )->get_status() );
	}

	public function test_a_key_revoked_after_the_copy_stays_revoked() {
		$this->pair();
		$copy = Fred_Espace_Client_Database::snapshot();
		Fred_Espace_Client_Keys::revoke();

		Fred_Espace_Client_Database::restore( $copy['backup_id'] );

		wp_cache_flush();
		$this->assertNull( Fred_Espace_Client_Keys::active() );
	}

	public function test_until_the_last_step_the_site_keeps_its_current_tables() {
		$this->set_step_seconds( 0 );
		$copy = Fred_Espace_Client_Database::snapshot();
		$this->change_everything();

		$step  = Fred_Espace_Client_Database::start_restore( $copy['backup_id'] );
		$steps = 1;

		$this->assertSame( 'running', $step['status'] );

		while ( 'running' === $step['status'] ) {
			// Between two steps, WordPress still runs on the tables as they are now.
			$this->assertSame( array( 'quatre' ), $this->labels() );
			$this->assertSame( 'Après', $this->fresh_option( 'blogname' ) );

			$step = Fred_Espace_Client_Database::advance_restore( $step['restore_id'] );
			++$steps;
		}

		$this->assertSame( 'restored', $step['status'] );
		$this->assertGreaterThan( 2, $steps );
		$this->assertSame( array( 'un', 'deux', 'trois' ), $this->labels() );
	}

	public function test_a_copy_in_steps_keeps_every_row_of_a_large_table() {
		$labels = array();

		for ( $i = 1; $i <= Fred_Espace_Client_Database::ROWS_PER_GO * 2 + 7; $i++ ) {
			$labels[] = 'ligne ' . $i;
		}

		$this->insert_items( $labels );
		$this->set_step_seconds( 0 );

		$step  = Fred_Espace_Client_Database::start();
		$steps = 1;

		while ( 'running' === $step['status'] ) {
			$step = Fred_Espace_Client_Database::advance( $step['backup_id'] );
			++$steps;
		}

		$this->assertSame( 'done', $step['status'] );
		$this->assertGreaterThan( 3, $steps );

		$expected = $this->labels();
		$this->change_everything();
		Fred_Espace_Client_Database::restore( $step['backup_id'] );

		$this->assertSame( $expected, $this->labels() );
		$this->assertCount( Fred_Espace_Client_Database::ROWS_PER_GO * 2 + 10, $expected );
	}

	public function test_a_statement_that_fails_leaves_the_current_tables_untouched() {
		$copy = Fred_Espace_Client_Database::snapshot();
		$path = $this->copy_path( $copy['backup_id'] );
		$sql  = $this->read_copy( $copy['backup_id'] );

		// A broken row in the middle of the copy, after the table it belongs to was recreated.
		$this->assertStringContainsString( "INSERT INTO `{$this->table}`", $sql );
		$broken = str_replace( "INSERT INTO `{$this->table}`", "INSERT INTO `{$this->table}` (nope) VALUES ('x');\nINSERT INTO `{$this->table}`", $sql );
		file_put_contents( $path, gzencode( $broken ) );

		$this->change_everything();

		$result = Fred_Espace_Client_Database::restore( $copy['backup_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'hop_restore_failed', $result->get_error_code() );
		$this->assertSame( array( 'quatre' ), $this->labels() );
		$this->assertSame( 'Après', $this->fresh_option( 'blogname' ) );
		$this->assertSame( array(), $this->temporary_tables() );
		$this->assertFalse( get_option( Fred_Espace_Client_Updater::OPTION_LOCK ) );
	}

	public function test_no_restoration_while_an_update_holds_the_lock() {
		$copy = Fred_Espace_Client_Database::snapshot();
		Fred_Espace_Client_Updater::acquire_lock();

		$result = Fred_Espace_Client_Database::start_restore( $copy['backup_id'] );

		$this->assertSame( 'hop_locked', $result->get_error_code() );
	}

	public function test_an_unknown_or_crafted_identifier_finds_no_copy() {
		foreach ( array( 'db-20260101000000-0000000000000000', '../wp-config', 'pull-x' ) as $id ) {
			$this->assertSame( 'hop_no_backup', Fred_Espace_Client_Database::start_restore( $id )->get_error_code(), $id );
		}

		$this->assertSame( 'hop_no_backup', Fred_Espace_Client_Database::advance_restore( 'restore-../../x' )->get_error_code() );
	}

	public function test_a_pulled_copy_is_read_in_pieces_then_discarded() {
		$copy = Fred_Espace_Client_Database::snapshot( 'pull' );

		$this->assertSame( 'done', $copy['status'] );

		$bytes = '';

		for ( $offset = 0; $offset < $copy['size']; $offset += 1000 ) {
			$bytes .= Fred_Espace_Client_Database::chunk( $copy['backup_id'], $offset, 1000 );
		}

		$this->assertSame( $copy['sha256'], hash( 'sha256', $bytes ) );
		$this->assertFalse( get_option( Fred_Espace_Client_Updater::OPTION_LOCK ), 'The nightly copy gives the lock back.' );

		Fred_Espace_Client_Database::discard( $copy['backup_id'] );

		$this->assertWPError( Fred_Espace_Client_Database::chunk( $copy['backup_id'], 0, 10 ) );
	}

	public function test_a_pulled_copy_cannot_be_restored_as_a_copy_before_updates() {
		$copy = Fred_Espace_Client_Database::snapshot( 'pull' );

		$this->assertSame( 'hop_no_backup', Fred_Espace_Client_Database::start_restore( $copy['backup_id'] )->get_error_code() );
	}

	/**
	 * Change rows and an option after the copy.
	 *
	 * @return void
	 */
	private function change_everything() {
		global $wpdb;

		$wpdb->query( "DELETE FROM `{$this->table}`" );
		$this->insert_items( array( 'quatre' ) );
		update_option( 'blogname', 'Après' );
	}

	/**
	 * @param  list<string> $labels  Rows to add.
	 * @return void
	 */
	private function insert_items( array $labels ) {
		global $wpdb;

		foreach ( array_chunk( $labels, 500 ) as $chunk ) {
			$values = implode( ',', array_map( function ( $label ) use ( $wpdb ) {
				return $wpdb->prepare( '(%s)', $label );
			}, $chunk ) );
			$wpdb->query( "INSERT INTO `{$this->table}` (label) VALUES {$values}" );
		}

		if ( 'Après' !== get_option( 'blogname' ) ) {
			update_option( 'blogname', 'Avant' );
		}
	}

	/**
	 * @return list<string>
	 */
	private function labels() {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( "SELECT label FROM `{$this->table}` ORDER BY id" ) );
	}

	/**
	 * An option read from the database, not from the cache.
	 *
	 * @param  string $name  Option.
	 * @return string
	 */
	private function fresh_option( $name ) {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * @return list<string>
	 */
	private function temporary_tables() {
		global $wpdb;

		return array_values( array_filter( (array) $wpdb->get_col( 'SHOW TABLES' ), function ( $table ) use ( $wpdb ) {
			return 1 === preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . 'hop_[ro]\d+$/', $table );
		} ) );
	}

	/**
	 * @param  string $backup_id  Identifier of a finished copy.
	 * @return string
	 */
	private function copy_path( $backup_id ) {
		return Fred_Espace_Client_Updater::backup_root() . '/' . $backup_id . '.sql.gz';
	}

	/**
	 * Text of a copy. Each step appends its own gzip member: gzdecode() would
	 * read only the first.
	 *
	 * @param  string $backup_id  Identifier of a finished copy.
	 * @return string
	 */
	private function read_copy( $backup_id ) {
		$file = gzopen( $this->copy_path( $backup_id ), 'rb' );
		$sql  = '';

		while ( ! gzeof( $file ) ) {
			$sql .= gzread( $file, 65536 );
		}

		gzclose( $file );

		return $sql;
	}

	/**
	 * @param  int $seconds  Seconds of a step; 0 stops each step after one batch.
	 * @return void
	 */
	private function set_step_seconds( $seconds ) {
		$property = new ReflectionProperty( 'Fred_Espace_Client_Database', 'step_seconds' );
		$property->setAccessible( true );
		$property->setValue( null, $seconds );
	}
}
