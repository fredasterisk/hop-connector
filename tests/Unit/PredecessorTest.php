<?php
/**
 * Takeover from hop-connector, the connector's former name.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class PredecessorTest extends Fred_Espace_Client_Test_Case {

	/**
	 * @var string
	 */
	private $old_folder;

	/**
	 * @var string
	 */
	private $suffix;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->old_folder = WP_PLUGIN_DIR . '/hop-connector';
		$this->suffix     = str_repeat( 'ab', 12 );
	}

	public function tear_down() {
		foreach ( array( $this->old_folder, WP_CONTENT_DIR . '/hop-connector-backups-' . $this->suffix, WP_CONTENT_DIR . '/fred-espace-client-backups-' . $this->suffix ) as $folder ) {
			if ( is_dir( $folder ) ) {
				$this->delete_tree( $folder );
			}
		}

		if ( file_exists( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' ) ) {
			unlink( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' );
		}

		wp_clear_scheduled_hook( 'hop_connector_cleanup' );
		update_option( 'active_plugins', array() );

		parent::tear_down();
	}

	public function test_the_site_stays_connected_with_the_key_of_the_former_plugin() {
		$this->install_former_plugin();

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertSame( self::KEY_ID, Fred_Espace_Client_Keys::active()['key_id'] );

		// The allowlist came along: only its address passes.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( 'hop_ip_denied', $this->dispatch( $this->signed_request( 'GET', '/fred-espace-client/v1/status' ) )->as_error()->get_error_code() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 200, $this->dispatch( $this->signed_request( 'GET', '/fred-espace-client/v1/status' ) )->get_status() );
		$this->assertSame( "198.51.100.7\n", get_option( 'fred_espace_client_allowed_ips' ) );
	}

	public function test_every_former_option_is_taken_over_then_removed() {
		$this->install_former_plugin();

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertSame( array(), $this->former_options() );
		// A nonce already used stays used.
		$this->assertSame( '1999999999', get_option( 'fred_espace_client_nonce_deadbeef' ) );
	}

	public function test_the_lock_of_the_installing_request_is_not_taken_over() {
		$this->install_former_plugin();

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertFalse( get_option( Fred_Espace_Client_Updater::OPTION_LOCK ) );
		$this->assertNotFalse( Fred_Espace_Client_Updater::acquire_lock() );
	}

	public function test_the_agency_accounts_stay_known_as_such() {
		$this->install_former_plugin();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_user_meta( $user, 'hop_connector_agency_account', '1700000000' );

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertSame( '1700000000', get_user_meta( $user, Fred_Espace_Client_Login::AGENCY_META, true ) );
		$this->assertSame( '', get_user_meta( $user, 'hop_connector_agency_account', true ) );
	}

	public function test_the_copies_move_with_their_recorded_paths() {
		$this->install_former_plugin();

		Fred_Espace_Client_Predecessor::take_over();

		$new = WP_CONTENT_DIR . '/fred-espace-client-backups-' . $this->suffix;

		$this->assertFileExists( $new . '/plugin-akismet-1/akismet.php' );
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/hop-connector-backups-' . $this->suffix );
		$this->assertSame( $new . '/plugin-akismet-1', get_option( Fred_Espace_Client_Updater::OPTION_BACKUPS )['plugin-akismet-1']['path'] );
		$this->assertSame( $new, Fred_Espace_Client_Updater::backup_root() );
	}

	public function test_the_former_plugin_is_switched_off_without_its_hooks_then_its_folder_removed() {
		$this->install_former_plugin();

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertFalse( is_plugin_active( 'hop-connector/hop-connector.php' ) );
		$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php' );
		$this->assertFalse( wp_next_scheduled( 'hop_connector_cleanup' ) );
		// Its deactivation hook would have erased this.
		$this->assertFalse( get_option( 'former_deactivation_ran' ) );
		// Its folder stays during the request that installed this plugin.
		$this->assertDirectoryExists( $this->old_folder );

		// What it wrote in its last request joins the journal, without replacing it.
		update_option( 'hop_connector_log', array( array( 'time' => time() + 5, 'event' => 'successor_installed' ) ) );
		Fred_Espace_Client_Predecessor::remove_folder();

		$this->assertDirectoryDoesNotExist( $this->old_folder );
		$this->assertSame(
			array( 'successor_installed', 'took_over', 'paired', 'earlier' ),
			array_column( get_option( 'fred_espace_client_log' ), 'event' )
		);
		$this->assertSame( array(), $this->former_options() );
		$this->assertFalse( get_option( Fred_Espace_Client_Predecessor::OPTION_REMOVE ) );
	}

	public function test_a_former_plugin_switched_on_again_is_left_alone() {
		$this->install_former_plugin();
		Fred_Espace_Client_Predecessor::take_over();
		update_option( 'active_plugins', array( 'hop-connector/hop-connector.php' ) );

		Fred_Espace_Client_Predecessor::remove_folder();

		$this->assertDirectoryExists( $this->old_folder );
		$this->assertFalse( get_option( Fred_Espace_Client_Predecessor::OPTION_REMOVE ) );
	}

	public function test_a_new_site_has_nothing_to_take_over() {
		$this->pair();

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertSame( self::KEY_ID, Fred_Espace_Client_Keys::active()['key_id'] );
		$this->assertFalse( get_option( Fred_Espace_Client_Predecessor::OPTION_REMOVE ) );
	}

	public function test_an_option_already_held_is_never_replaced() {
		$this->install_former_plugin();
		update_option( 'fred_espace_client_allowed_ips', "203.0.113.1\n" );

		Fred_Espace_Client_Predecessor::take_over();

		$this->assertSame( "203.0.113.1\n", get_option( 'fred_espace_client_allowed_ips' ) );
	}

	public function test_taking_over_twice_changes_nothing_more() {
		$this->install_former_plugin();

		Fred_Espace_Client_Predecessor::take_over();
		Fred_Espace_Client_Predecessor::take_over();

		$this->assertSame( self::KEY_ID, Fred_Espace_Client_Keys::active()['key_id'] );
		$this->assertFileExists( WP_CONTENT_DIR . '/fred-espace-client-backups-' . $this->suffix . '/plugin-akismet-1/akismet.php' );
	}

	/**
	 * A site as hop-connector 0.20.0 leaves it, while it installs this plugin.
	 *
	 * @return void
	 */
	private function install_former_plugin() {
		wp_mkdir_p( $this->old_folder );
		file_put_contents( $this->old_folder . '/hop-connector.php', "<?php\n/**\n * Plugin Name: Connecteur Espace client\n * Version: 0.20.0\n */\nregister_deactivation_hook( __FILE__, function () { update_option( 'former_deactivation_ran', 1 ); } );\n" );
		update_option( 'active_plugins', array( 'hop-connector/hop-connector.php' ) );

		update_option(
			'hop_connector_key',
			array(
				'key_id'     => self::KEY_ID,
				'public_key' => $this->platform_public_key(),
				'paired_at'  => 1700000000,
			),
			false
		);
		update_option( 'hop_connector_allowed_ips', "198.51.100.7\n" );
		update_option(
			'hop_connector_log',
			array(
				array( 'time' => time() - 10, 'event' => 'paired' ),
				array( 'time' => time() - 20, 'event' => 'earlier' ),
			),
			false
		);
		update_option( 'hop_connector_nonce_deadbeef', '1999999999', false );
		update_option( 'hop_connector_update_lock', time() + 600, false );
		update_option( 'hop_connector_backup_dir', $this->suffix, false );
		update_option( 'hop_connector_backup_parent', WP_CONTENT_DIR, false );

		$old_root = WP_CONTENT_DIR . '/hop-connector-backups-' . $this->suffix;
		wp_mkdir_p( $old_root . '/plugin-akismet-1' );
		file_put_contents( $old_root . '/plugin-akismet-1/akismet.php', "<?php\n" );
		update_option(
			'hop_connector_backups',
			array(
				'plugin-akismet-1' => array(
					'type'    => 'plugin',
					'slug'    => 'akismet',
					'version' => '5.0',
					'path'    => $old_root . '/plugin-akismet-1',
					'created' => time(),
				),
			),
			false
		);

		wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents( WPMU_PLUGIN_DIR . '/hop-connector-rescue.php', "<?php\n" );
		wp_schedule_event( time() + 3600, 'hourly', 'hop_connector_cleanup' );
	}

	/**
	 * @return list<string>
	 */
	private function former_options() {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'hop_connector_' ) . '%' ) ) );
	}

	/**
	 * @param  string $folder  Folder.
	 * @return void
	 */
	private function delete_tree( $folder ) {
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $folder );
	}
}
