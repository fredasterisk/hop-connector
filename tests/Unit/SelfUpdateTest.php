<?php
/**
 * New versions of the connector, read from the platform.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class SelfUpdateTest extends Hop_Connector_Test_Case {

	/**
	 * What the platform answers to latest.json.
	 *
	 * @var array|WP_Error
	 */
	private $answer;

	/**
	 * Requests sent to the platform.
	 *
	 * @var list<string>
	 */
	private $requests = array();

	public function set_up() {
		parent::set_up();

		Hop_Connector_Self_Update::forget();
		$this->answer   = $this->json( $this->release() );
		$this->requests = array();

		add_filter( 'pre_http_request', array( $this, 'platform' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'platform' ) );
		Hop_Connector_Self_Update::forget();

		parent::tear_down();
	}

	public function platform( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://client.fredrobert.com/' ) ) {
			return $preempt;
		}

		$this->requests[] = $url;

		return $this->answer;
	}

	public function test_wordpress_learns_the_new_version_from_the_platform() {
		$update = apply_filters( 'update_plugins_client.fredrobert.com', false, array(), plugin_basename( HOP_CONNECTOR_FILE ), array() );

		$this->assertSame( array( 'https://client.fredrobert.com/connector/latest.json' ), $this->requests );
		$this->assertSame( '9.1.0', $update['version'] );
		$this->assertSame( 'https://client.fredrobert.com/connector/hop-connector-9.1.0.zip', $update['package'] );
		$this->assertSame( '6.5', $update['requires'] );
		$this->assertSame( '7.4', $update['requires_php'] );
	}

	public function test_the_header_points_at_the_platform_not_at_github() {
		$headers = get_file_data( HOP_CONNECTOR_FILE, array( 'uri' => 'Update URI' ) );

		$this->assertSame( 'client.fredrobert.com', wp_parse_url( $headers['uri'], PHP_URL_HOST ) );
		$this->assertFalse( class_exists( 'Asterisk_GitHub_Updater', false ) && has_filter( 'update_plugins_github.com' ) );
	}

	public function test_other_plugins_are_left_alone() {
		$this->assertFalse( Hop_Connector_Self_Update::check( false, array(), 'other/other.php' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_zip_outside_the_platform_is_never_offered() {
		$this->answer = $this->json( array_merge( $this->release(), array( 'download_url' => 'https://evil.example/hop-connector-9.1.0.zip' ) ) );

		$this->assertFalse( Hop_Connector_Self_Update::check( false, array(), plugin_basename( HOP_CONNECTOR_FILE ) ) );
	}

	public function test_a_version_that_is_not_a_number_is_never_offered() {
		$this->answer = $this->json( array_merge( $this->release(), array( 'version' => '9.1.0<script>' ) ) );

		$this->assertNull( Hop_Connector_Self_Update::latest() );
	}

	public function test_the_platform_is_asked_once_an_hour_even_when_it_fails() {
		$this->answer = new WP_Error( 'http_request_failed', 'Délai dépassé' );

		$this->assertNull( Hop_Connector_Self_Update::latest() );
		$this->assertNull( Hop_Connector_Self_Update::latest() );
		$this->assertCount( 1, $this->requests );

		Hop_Connector_Self_Update::forget();
		$this->answer = $this->json( $this->release() );

		$this->assertSame( '9.1.0', Hop_Connector_Self_Update::latest()['version'] );
		$this->assertSame( '9.1.0', Hop_Connector_Self_Update::latest()['version'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_an_answer_other_than_200_offers_nothing() {
		$this->answer = array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 404, 'message' => 'Not Found' ),
			'cookies'  => array(),
		);

		$this->assertNull( Hop_Connector_Self_Update::latest() );
	}

	public function test_the_details_window_shows_the_changelog() {
		$details = Hop_Connector_Self_Update::details( false, 'plugin_information', (object) array( 'slug' => dirname( plugin_basename( HOP_CONNECTOR_FILE ) ) ) );

		$this->assertSame( '9.1.0', $details->version );
		$this->assertStringContainsString( 'Menu court', $details->sections['changelog'] );
		$this->assertFalse( Hop_Connector_Self_Update::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
	}

	public function test_a_check_asked_by_the_platform_forgets_the_version_read() {
		Hop_Connector_Self_Update::latest();
		$this->assertNotFalse( get_site_transient( Hop_Connector_Self_Update::CACHE ) );

		Hop_Connector_Updater::refresh();

		$this->assertFalse( get_site_transient( Hop_Connector_Self_Update::CACHE ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function release() {
		return array(
			'version'      => '9.1.0',
			'download_url' => 'https://client.fredrobert.com/connector/hop-connector-9.1.0.zip',
			'sha256'       => str_repeat( 'a', 64 ),
			'requires'     => '6.5',
			'requires_php' => '7.4',
			'published_at' => '2026-11-23T12:00:00Z',
			'changelog'    => '- Menu court',
		);
	}

	/**
	 * @param  array $data  Body.
	 * @return array
	 */
	private function json( array $data ) {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	}
}
