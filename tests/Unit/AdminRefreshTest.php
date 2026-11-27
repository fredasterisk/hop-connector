<?php
/**
 * Checking for updates as the admin does: the loopback request to
 * admin-ajax.php, its one-time token, and the check made in the REST request
 * when the site cannot call itself, which keeps the premium updates known.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * @group updates
 */
class AdminRefreshTest extends Fred_Espace_Client_Test_Case {

	const FILE = 'hop-premium-plugin/hop-premium-plugin.php';

	/**
	 * @var array<string,mixed>|null  Answer given to the loopback request, null to let it fail.
	 */
	private $loopback;

	/**
	 * @var array<string,mixed>  Body of the last loopback request.
	 */
	private $sent = array();

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_mkdir_p( WP_PLUGIN_DIR . '/hop-premium-plugin' );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::FILE, "<?php\n/**\n * Plugin Name: Hop Premium Plugin\n * Version: 2.1.59\n */\n" );
		wp_clean_plugins_cache( false );

		$this->loopback = null;
		add_filter( 'pre_http_request', array( $this, 'answer_loopback' ), 10, 3 );
	}

	public function tear_down() {
		global $wp_filesystem;

		remove_filter( 'pre_http_request', array( $this, 'answer_loopback' ) );

		WP_Filesystem();
		$wp_filesystem->delete( WP_PLUGIN_DIR . '/hop-premium-plugin', true );
		delete_site_transient( 'update_plugins' );
		delete_option( Fred_Espace_Client_Admin_Refresh::OPTION_TOKEN );

		parent::tear_down();
	}

	public function answer_loopback( $preempt, $args, $url ) {
		if ( admin_url( 'admin-ajax.php' ) !== $url ) {
			return $preempt;
		}

		$this->sent = (array) $args['body'];

		if ( null === $this->loopback ) {
			return new WP_Error( 'http_request_failed', 'Bouclage bloqué' );
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $this->loopback['body'] ),
			'response' => array(
				'code'    => $this->loopback['code'],
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * An update announced earlier by an updater the REST request does not load.
	 */
	private function offer( $version, $package ) {
		$updates               = new stdClass();
		$updates->response     = array(
			self::FILE => (object) array(
				'slug'        => 'hop-premium-plugin',
				'plugin'      => self::FILE,
				'new_version' => $version,
				'package'     => $package,
			),
		);
		$updates->checked      = array( self::FILE => '2.1.59' );
		$updates->last_checked = time();

		set_site_transient( 'update_plugins', $updates );
	}

	private function offered() {
		$updates = get_site_transient( 'update_plugins' );

		return isset( $updates->response[ self::FILE ]->new_version ) ? $updates->response[ self::FILE ]->new_version : null;
	}

	public function test_the_inventory_seen_from_the_admin_is_returned_as_is() {
		$inventory      = array(
			'core'    => array( 'version' => '6.8.3', 'update' => null ),
			'plugins' => array( array( 'slug' => 'hop-premium-plugin', 'version' => '2.1.59', 'update' => '2.1.63' ) ),
			'themes'  => array(),
		);
		$this->loopback = array(
			'code' => 200,
			'body' => array( 'success' => true, 'data' => $inventory ),
		);

		$this->assertSame( $inventory, Fred_Espace_Client_Admin_Refresh::refresh_and_report() );
		$this->assertSame( Fred_Espace_Client_Admin_Refresh::ACTION, $this->sent['action'] );
		$this->assertFalse( get_option( Fred_Espace_Client_Admin_Refresh::OPTION_TOKEN ), 'The token is spent once the call is over.' );
	}

	public function test_a_refused_loopback_falls_back_to_a_check_that_keeps_the_premium_update() {
		$this->loopback = array(
			'code' => 403,
			'body' => array( 'success' => false ),
		);
		$this->offer( '2.1.63', 'https://vendor.example/blocksy-companion-pro.zip' );

		$inventory = Fred_Espace_Client_Admin_Refresh::refresh_and_report();
		$plugin    = wp_list_filter( $inventory['plugins'], array( 'slug' => 'hop-premium-plugin' ) );

		$this->assertSame( '2.1.63', reset( $plugin )['update'] );
		$this->assertSame( 'refresh_local', Fred_Espace_Client_Log::entries( 1 )[0]['event'] );
	}

	public function test_an_update_from_wordpress_org_is_not_kept_once_wordpress_org_stops_offering_it() {
		$this->offer( '2.1.63', 'https://downloads.wordpress.org/plugin/hop-premium-plugin.2.1.63.zip' );

		Fred_Espace_Client_Updater::refresh();

		$this->assertNull( $this->offered() );
	}

	public function test_an_offer_no_newer_than_the_installed_version_is_dropped() {
		$this->offer( '2.1.59', 'https://vendor.example/blocksy-companion-pro.zip' );

		Fred_Espace_Client_Updater::refresh();

		$this->assertNull( $this->offered() );
	}

	public function test_the_token_opens_the_check_once_and_only_in_time() {
		update_option( Fred_Espace_Client_Admin_Refresh::OPTION_TOKEN, array( 'hash' => hash( 'sha256', 'secret-token' ), 'expires' => time() + 60 ) );

		$this->assertFalse( Fred_Espace_Client_Admin_Refresh::consume( 'wrong-token' ) );
		$this->assertTrue( Fred_Espace_Client_Admin_Refresh::consume( 'secret-token' ) );
		$this->assertFalse( Fred_Espace_Client_Admin_Refresh::consume( 'secret-token' ) );

		update_option( Fred_Espace_Client_Admin_Refresh::OPTION_TOKEN, array( 'hash' => hash( 'sha256', 'secret-token' ), 'expires' => time() - 1 ) );

		$this->assertFalse( Fred_Espace_Client_Admin_Refresh::consume( 'secret-token' ) );
	}
}
