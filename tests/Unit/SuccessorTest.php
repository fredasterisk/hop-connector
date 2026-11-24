<?php
/**
 * Installation of the connector under its new name, on the platform's
 * signed request.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * The real installation, with a key the tests hold.
 */
class Hop_Connector_Successor_With_Test_Key extends Hop_Connector_Successor {

	protected static function verify( $folder ) {
		return Hop_Connector_Release_With_Test_Key::verify( $folder, HOP_CONNECTOR_VERSION, self::SLUG );
	}
}

/**
 * @group updates
 */
class SuccessorTest extends Hop_Connector_Test_Case {

	const URL = 'https://client.fredrobert.com/connector/fred-espace-client/fred-espace-client-9.0.0.zip';

	const FILE = 'fred-espace-client/fred-espace-client.php';

	/**
	 * @var string
	 */
	private $keypair;

	/**
	 * Bytes the platform serves.
	 *
	 * @var string
	 */
	private $zip;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->keypair = sodium_crypto_sign_keypair();
		Hop_Connector_Release_With_Test_Key::$test_keys = array( 'test-key' => base64_encode( sodium_crypto_sign_publickey( $this->keypair ) ) );
		$this->zip = $this->build_zip( $this->keypair );

		add_filter( 'pre_http_request', array( $this, 'platform' ), 10, 3 );
	}

	public function tear_down() {
		global $wp_filesystem;

		remove_filter( 'pre_http_request', array( $this, 'platform' ) );
		deactivate_plugins( self::FILE, true );
		delete_option( 'fake_successor_activated' );
		WP_Filesystem();
		$wp_filesystem->delete( WP_PLUGIN_DIR . '/fred-espace-client', true );
		wp_clean_plugins_cache( false );

		parent::tear_down();
	}

	public function platform( $preempt, $args, $url ) {
		if ( self::URL !== $url ) {
			return $preempt;
		}

		file_put_contents( $args['filename'], $this->zip );

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	}

	public function test_the_successor_is_installed_and_activated() {
		$result = Hop_Connector_Successor_With_Test_Key::install( self::URL, hash( 'sha256', $this->zip ) );

		$this->assertSame( array( 'status' => 'installed', 'plugin' => 'fred-espace-client', 'version' => '9.0.0' ), $result );
		$this->assertTrue( Hop_Connector_Successor::is_installed() );
		$this->assertTrue( is_plugin_active( self::FILE ) );
		// Its activation ran: that is where it takes over from this plugin.
		$this->assertSame( '1', get_option( 'fake_successor_activated' ) );
		$this->assertFalse( get_option( Hop_Connector_Updater::OPTION_LOCK ) );
	}

	public function test_a_zip_other_than_the_one_the_platform_checked_is_refused() {
		$result = Hop_Connector_Successor_With_Test_Key::install( self::URL, str_repeat( 'a', 64 ) );

		$this->assertSame( 'hop_successor_refused', $result->get_error_code() );
		$this->assertFalse( Hop_Connector_Successor::is_installed() );
	}

	public function test_a_zip_outside_the_platform_is_refused_without_download() {
		$result = Hop_Connector_Successor_With_Test_Key::install( 'https://evil.example/fred-espace-client.zip', hash( 'sha256', $this->zip ) );

		$this->assertSame( 'hop_successor_refused', $result->get_error_code() );
		$this->assertFalse( Hop_Connector_Successor::is_installed() );
	}

	public function test_a_successor_the_agency_did_not_sign_is_not_installed() {
		$this->zip = $this->build_zip( sodium_crypto_sign_keypair() );

		$result = Hop_Connector_Successor_With_Test_Key::install( self::URL, hash( 'sha256', $this->zip ) );

		$this->assertSame( 'hop_release_bad_signature', $result->get_error_code() );
		$this->assertFalse( Hop_Connector_Successor::is_installed() );
		$this->assertFalse( get_option( Hop_Connector_Updater::OPTION_LOCK ) );
	}

	public function test_a_signed_zip_of_this_plugin_does_not_pass_for_the_successor() {
		$this->zip = $this->build_zip( $this->keypair, 'hop-connector' );

		$result = Hop_Connector_Successor_With_Test_Key::install( self::URL, hash( 'sha256', $this->zip ) );

		$this->assertWPError( $result );
		$this->assertFalse( Hop_Connector_Successor::is_installed() );
	}

	public function test_the_shipped_check_uses_the_agencys_keys() {
		$result = Hop_Connector_Successor::install( self::URL, hash( 'sha256', $this->zip ) );

		$this->assertSame( 'hop_release_bad_signature', $result->get_error_code() );
		$this->assertFalse( Hop_Connector_Successor::is_installed() );
	}

	public function test_the_route_is_signed_journaled_and_can_be_cut() {
		$this->assertSame( 'updates', Hop_Connector_Guard::action_of( '/hop/v1/successor/install' ) );
		$this->assertTrue( Hop_Connector_Guard::journaled( '/hop/v1/successor/install', '{}' ) );
		$unsigned = new WP_REST_Request( 'POST', '/hop/v1/successor/install' );
		$unsigned->set_header( 'Content-Type', 'application/json' );
		$unsigned->set_body( (string) wp_json_encode( array( 'download_url' => self::URL, 'sha256' => hash( 'sha256', $this->zip ) ) ) );

		$this->assertSame( 401, $this->dispatch( $unsigned )->get_status() );
		$this->assertFalse( Hop_Connector_Successor::is_installed() );
	}

	/**
	 * A release of the successor, signed as bin/release.php signs.
	 *
	 * @param  string $keypair  Key that signs.
	 * @param  string $plugin   Plugin named in the manifest.
	 * @return string
	 */
	private function build_zip( $keypair, $plugin = 'fred-espace-client' ) {
		$files = array(
			'fred-espace-client.php' => "<?php\n/**\n * Plugin Name: Espace client\n * Version: 9.0.0\n */\n\nregister_activation_hook( __FILE__, function () {\n\tupdate_option( 'fake_successor_activated', '1' );\n} );\n",
		);

		$manifest = (string) wp_json_encode(
			array(
				'plugin'  => $plugin,
				'version' => '9.0.0',
				'files'   => array_map(
					function ( $contents ) {
						return hash( 'sha256', $contents );
					},
					$files
				),
			)
		);

		$files['release.json'] = $manifest;
		$files['release.sig']  = (string) wp_json_encode(
			array(
				'key'       => 'test-key',
				'signature' => base64_encode( sodium_crypto_sign_detached( $manifest, sodium_crypto_sign_secretkey( $keypair ) ) ),
			)
		);

		$path = wp_tempnam( 'successor' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::OVERWRITE );

		foreach ( $files as $name => $contents ) {
			$zip->addFromString( 'fred-espace-client/' . $name, $contents );
		}

		$zip->close();
		$bytes = (string) file_get_contents( $path );
		unlink( $path );

		return $bytes;
	}
}
