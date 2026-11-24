<?php
/**
 * Signed releases: the site installs only what the agency's key signed.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class ReleaseTest extends Fred_Espace_Client_Test_Case {

	/**
	 * @var string
	 */
	private $folder;

	/**
	 * @var string
	 */
	private $keypair;

	public function set_up() {
		parent::set_up();

		$this->keypair = sodium_crypto_sign_keypair();
		$this->folder  = get_temp_dir() . 'hop-release-' . wp_generate_password( 8, false );

		Fred_Espace_Client_Release_With_Test_Key::$test_keys = array(
			'test-key' => base64_encode( sodium_crypto_sign_publickey( $this->keypair ) ),
		);

		wp_mkdir_p( $this->folder . '/includes' );
		file_put_contents( $this->folder . '/fred-espace-client.php', "<?php\n/**\n * Plugin Name: Connecteur Espace client\n * Version:           9.0.0\n */\n" );
		file_put_contents( $this->folder . '/includes/a.php', "<?php\n// A.\n" );
	}

	public function tear_down() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$wp_filesystem->delete( $this->folder, true );

		parent::tear_down();
	}

	public function test_a_release_signed_by_an_allowed_key_passes() {
		$this->sign();

		$checked = Fred_Espace_Client_Release_With_Test_Key::verify( $this->folder, '1.0.0' );

		$this->assertSame( array( 'version' => '9.0.0', 'key' => 'test-key' ), $checked );
	}

	public function test_an_unsigned_release_is_refused() {
		$this->assertRefused( 'hop_release_unsigned', '1.0.0' );
	}

	public function test_a_release_signed_by_an_unknown_key_is_refused() {
		$this->keypair = sodium_crypto_sign_keypair();
		$this->sign();

		$this->assertRefused( 'hop_release_bad_signature', '1.0.0' );
	}

	public function test_a_file_changed_after_signing_is_refused() {
		$this->sign();
		file_put_contents( $this->folder . '/includes/a.php', "<?php\n// Changed.\n" );

		$this->assertRefused( 'hop_release_bad_files', '1.0.0' );
	}

	public function test_a_file_added_after_signing_is_refused() {
		$this->sign();
		file_put_contents( $this->folder . '/includes/b.php', "<?php\n// Added.\n" );

		$this->assertRefused( 'hop_release_bad_files', '1.0.0' );
	}

	public function test_a_signed_file_removed_is_refused() {
		$this->sign();
		unlink( $this->folder . '/includes/a.php' );

		$this->assertRefused( 'hop_release_bad_files', '1.0.0' );
	}

	public function test_an_older_or_equal_release_is_refused_even_signed() {
		$this->sign();

		$this->assertRefused( 'hop_release_downgrade', '9.0.0' );
		$this->assertRefused( 'hop_release_downgrade', '9.1.0' );
	}

	public function test_a_version_signed_apart_from_the_header_is_refused() {
		$this->sign( '9.5.0' );

		$this->assertRefused( 'hop_release_bad_manifest', '1.0.0' );
	}

	public function test_a_manifest_for_another_plugin_is_refused() {
		$this->sign( '9.0.0', 'other-plugin' );

		$this->assertRefused( 'hop_release_bad_manifest', '1.0.0' );
	}

	public function test_the_shipped_keys_are_the_agencys() {
		$this->assertSame( array( 'principale-2026', 'secours-2026' ), array_keys( Fred_Espace_Client_Release::KEYS ) );
	}

	/**
	 * Write release.json and release.sig as bin/release.php does.
	 *
	 * @param  string $version  Version written in the manifest.
	 * @param  string $plugin   Plugin written in the manifest.
	 * @return void
	 */
	private function sign( $version = '9.0.0', $plugin = 'fred-espace-client' ) {
		$files = array(
			'fred-espace-client.php' => hash_file( 'sha256', $this->folder . '/fred-espace-client.php' ),
			'includes/a.php'    => hash_file( 'sha256', $this->folder . '/includes/a.php' ),
		);

		$manifest = (string) wp_json_encode(
			array(
				'plugin'  => $plugin,
				'version' => $version,
				'files'   => $files,
			)
		);

		file_put_contents( $this->folder . '/release.json', $manifest );
		file_put_contents(
			$this->folder . '/release.sig',
			(string) wp_json_encode(
				array(
					'key'       => 'test-key',
					'signature' => base64_encode( sodium_crypto_sign_detached( $manifest, sodium_crypto_sign_secretkey( $this->keypair ) ) ),
				)
			)
		);
	}

	/**
	 * @param  string $code     Expected error code.
	 * @param  string $current  Installed version.
	 * @return void
	 */
	private function assertRefused( $code, $current ) {
		$checked = Fred_Espace_Client_Release_With_Test_Key::verify( $this->folder, $current );

		$this->assertWPError( $checked );
		$this->assertSame( $code, $checked->get_error_code() );
	}
}
