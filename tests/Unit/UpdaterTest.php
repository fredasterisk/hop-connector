<?php
/**
 * Applying an update: the expected versions, the backup taken first, the
 * rollback, and one operation at a time.
 *
 * The update comes from a local zip: WordPress installs a package that is a
 * file on disk without downloading anything.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * @group updates
 */
class UpdaterTest extends Hop_Connector_Test_Case {

	const SLUG = 'hop-test-plugin';

	const FILE = 'hop-test-plugin/hop-test-plugin.php';

	/**
	 * @var string
	 */
	private $package;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->write_plugin( WP_PLUGIN_DIR . '/' . self::SLUG, '1.0.0' );
		$this->package = $this->build_package( '1.1.0' );
		$this->offer( '1.1.0' );
		wp_clean_plugins_cache( false );
	}

	public function tear_down() {
		global $wp_filesystem;

		WP_Filesystem();
		$wp_filesystem->delete( WP_PLUGIN_DIR . '/' . self::SLUG, true );
		$wp_filesystem->delete( dirname( $this->package ), true );
		delete_site_transient( 'update_plugins' );
		delete_option( Hop_Connector_Updater::OPTION_LOCK );

		parent::tear_down();
	}

	public function test_an_update_replaces_the_plugin_after_a_backup() {
		$result = Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' );

		$this->assertSame( 'updated', $result['status'], implode( ' / ', $result['messages'] ?? array() ) );
		$this->assertSame( '1.0.0', $result['from'] );
		$this->assertSame( '1.1.0', $result['to'] );
		$this->assertStringStartsWith( 'plugin-hop-test-plugin-', $result['backup_id'] );
		$this->assertSame( '1.1.0', $this->installed_version() );
		$this->assertFalse( get_option( Hop_Connector_Updater::OPTION_LOCK ) );
	}

	public function test_a_rollback_puts_back_the_version_before_the_update() {
		$result = Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' );

		$rolled_back = Hop_Connector_Updater::rollback( $result['backup_id'] );

		$this->assertSame( 'rolled_back', $rolled_back['status'] );
		$this->assertSame( '1.0.0', $rolled_back['restored_version'] );
		$this->assertSame( '1.0.0', $this->installed_version() );
	}

	public function test_the_cron_is_kept_out_while_the_files_are_replaced_only() {
		$seen = array();

		add_filter(
			'upgrader_pre_install',
			static function ( $response ) use ( &$seen ) {
				$seen[] = Hop_Connector_Rescue::files_being_replaced();

				return $response;
			}
		);

		$result = Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' );

		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( array( true ), $seen );
		$this->assertFalse( Hop_Connector_Rescue::files_being_replaced() );

		$seen = array();
		Hop_Connector_Updater::rollback( $result['backup_id'] );

		$this->assertFalse( Hop_Connector_Rescue::files_being_replaced() );
		$this->assertSame( '1.0.0', $this->installed_version() );
	}

	public function test_the_mark_goes_even_when_the_copy_fails() {
		add_filter(
			'upgrader_pre_install',
			static function () {
				return new WP_Error( 'disk_full', 'Disque plein' );
			}
		);

		$level  = ob_get_level();
		$result = Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' );

		// On a failed copy, the upgrader of WordPress leaves its output buffer open.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		$this->assertSame( 'failed', $result['status'] );
		$this->assertFalse( Hop_Connector_Rescue::files_being_replaced() );
	}

	public function test_nothing_is_touched_when_the_versions_are_not_the_expected_ones() {
		$result = Hop_Connector_Updater::apply( 'plugin', self::SLUG, '0.9.0', '1.1.0' );

		$this->assertSame( 'hop_version_mismatch', $result->get_error_code() );
		$this->assertSame( '1.0.0', $result->get_error_data()['installed'] );
		$this->assertSame( '1.0.0', $this->installed_version() );
		$this->assertFalse( get_option( Hop_Connector_Updater::OPTION_LOCK ) );
	}

	public function test_an_update_no_longer_offered_is_reported_as_such() {
		delete_site_transient( 'update_plugins' );

		$result = Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' );

		$this->assertSame( 'hop_no_update', $result->get_error_code() );
	}

	public function test_an_unknown_plugin_is_not_found() {
		$this->assertSame( 'hop_not_installed', Hop_Connector_Updater::apply( 'plugin', 'nope', '1.0.0', '1.1.0' )->get_error_code() );
	}

	public function test_one_operation_at_a_time() {
		Hop_Connector_Updater::acquire_lock();

		$this->assertSame( 'hop_locked', Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' )->get_error_code() );
		$this->assertSame( 'hop_locked', Hop_Connector_Updater::change_plugin( self::SLUG, 'activate' )->get_error_code() );
		$this->assertSame( '1.0.0', $this->installed_version() );
	}

	public function test_a_lock_left_behind_expires() {
		update_option( Hop_Connector_Updater::OPTION_LOCK, time() - 1 );

		$this->assertNotFalse( Hop_Connector_Updater::acquire_lock() );
	}

	public function test_an_update_gives_back_only_its_own_lock() {
		// Another value than the one apply() took.
		$other = time() + 1234;

		// Past its time, the lock of a long update may be taken by the nightly copy.
		add_filter(
			'site_transient_update_plugins',
			static function ( $value ) use ( $other ) {
				update_option( Hop_Connector_Updater::OPTION_LOCK, $other, false );

				return $value;
			}
		);

		Hop_Connector_Updater::apply( 'plugin', self::SLUG, '1.0.0', '1.1.0' );

		wp_cache_delete( Hop_Connector_Updater::OPTION_LOCK, 'options' );
		$this->assertSame( $other, (int) get_option( Hop_Connector_Updater::OPTION_LOCK ) );
	}

	public function test_the_connector_never_acts_on_itself() {
		$result = Hop_Connector_Updater::change_plugin( basename( HOP_CONNECTOR_DIR ), 'deactivate' );

		// The connector is loaded from outside the plugins folder in the tests:
		// either way, it must not answer with a deactivation.
		$this->assertWPError( $result );
	}

	/**
	 * @return string
	 */
	private function installed_version() {
		wp_clean_plugins_cache( false );
		$plugins = get_plugins();

		return isset( $plugins[ self::FILE ] ) ? (string) $plugins[ self::FILE ]['Version'] : '';
	}

	/**
	 * @param  string $folder   Plugin folder.
	 * @param  string $version  Version.
	 * @return void
	 */
	private function write_plugin( $folder, $version ) {
		wp_mkdir_p( $folder );
		file_put_contents( $folder . '/hop-test-plugin.php', "<?php\n/**\n * Plugin Name: Hop Test Plugin\n * Version: {$version}\n */\n" );
	}

	/**
	 * A zip as WordPress.org serves them: one folder named after the plugin.
	 *
	 * @param  string $version  Version inside.
	 * @return string
	 */
	private function build_package( $version ) {
		$work = get_temp_dir() . 'hop-package-' . wp_generate_password( 8, false );
		wp_mkdir_p( $work );

		$zip_path = $work . '/' . self::SLUG . '.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( self::SLUG . '/hop-test-plugin.php', "<?php\n/**\n * Plugin Name: Hop Test Plugin\n * Version: {$version}\n */\n" );
		$zip->close();

		return $zip_path;
	}

	/**
	 * Make WordPress offer an update of the test plugin.
	 *
	 * @param  string $version  Version offered.
	 * @return void
	 */
	private function offer( $version ) {
		$updates           = new stdClass();
		$updates->response = array(
			self::FILE => (object) array(
				'slug'        => self::SLUG,
				'plugin'      => self::FILE,
				'new_version' => $version,
				'package'     => $this->package,
			),
		);
		$updates->checked  = array( self::FILE => '1.0.0' );
		$updates->last_checked = time();

		set_site_transient( 'update_plugins', $updates );
	}
}
