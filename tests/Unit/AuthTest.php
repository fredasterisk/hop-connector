<?php
/**
 * Signed requests: only the platform's key passes, once.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class AuthTest extends Fred_Espace_Client_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->pair();
	}

	public function test_a_request_signed_by_the_platform_passes() {
		$response = $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_request_without_signature_is_refused() {
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/hop/v1/status' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_signature_by_another_key_is_refused() {
		$this->platform_keypair = sodium_crypto_sign_keypair();

		$response = $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'hop_unauthorized', $response->as_error()->get_error_code() );
	}

	public function test_a_body_changed_after_signing_is_refused() {
		$request = $this->signed_request( 'POST', '/hop/v1/updates/refresh', array( 'a' => 1 ) );
		$request->set_body( (string) wp_json_encode( array( 'a' => 2 ) ) );

		$this->assertSame( 401, $this->dispatch( $request )->get_status() );
	}

	public function test_a_parameter_outside_the_signed_body_is_refused() {
		$request = $this->signed_request( 'GET', '/hop/v1/status' );
		$request->set_query_params( array( 'extra' => '1' ) );

		$this->assertSame( 401, $this->dispatch( $request )->get_status() );
	}

	public function test_a_replayed_request_is_refused() {
		$request = $this->signed_request( 'GET', '/hop/v1/status' );

		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
		$this->assertSame( 401, $this->dispatch( $request )->get_status() );
	}

	public function test_a_request_outside_the_clock_window_says_so() {
		$response = $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status', array(), array( 'timestamp' => time() - 120 ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'hop_clock_skew', $response->as_error()->get_error_code() );
	}

	public function test_an_address_outside_the_allowlist_is_refused() {
		update_option( Fred_Espace_Client_Auth::OPTION_ALLOWED_IPS, "203.0.113.7\n" );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

		$response = $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) );

		$this->assertSame( 'hop_ip_denied', $response->as_error()->get_error_code() );
	}

	public function test_an_address_in_the_allowlist_passes() {
		update_option( Fred_Espace_Client_Auth::OPTION_ALLOWED_IPS, "203.0.113.7\n" );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$this->assertSame( 200, $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) )->get_status() );
	}

	public function test_the_shared_secret_of_a_pending_key_only_opens_the_handshake() {
		Fred_Espace_Client_Keys::revoke();
		Fred_Espace_Client_Keys::store_pending( self::KEY_ID, str_repeat( 'ab', 32 ) );
		$secret = array( 'secret' => str_repeat( 'ab', 32 ) );

		$status = $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status', array(), $secret ) );

		$this->assertSame( 401, $status->get_status() );

		$handshake = $this->dispatch( $this->signed_request( 'POST', '/hop/v1/handshake', array( 'public_key' => $this->platform_public_key() ), $secret ) );

		$this->assertSame( 200, $handshake->get_status() );
		$this->assertTrue( $handshake->get_data()['rotated'] );
		$this->assertSame( $this->platform_public_key(), Fred_Espace_Client_Keys::active()['public_key'] );
		$this->assertNull( Fred_Espace_Client_Keys::pending() );
	}

	public function test_a_handshake_without_public_key_keeps_the_site_unpaired() {
		Fred_Espace_Client_Keys::revoke();
		Fred_Espace_Client_Keys::store_pending( self::KEY_ID, str_repeat( 'ab', 32 ) );

		$response = $this->dispatch( $this->signed_request( 'POST', '/hop/v1/handshake', array(), array( 'secret' => str_repeat( 'ab', 32 ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( Fred_Espace_Client_Keys::active() );
	}

	public function test_an_expired_pending_key_is_refused() {
		Fred_Espace_Client_Keys::revoke();
		Fred_Espace_Client_Keys::store_pending( self::KEY_ID, str_repeat( 'ab', 32 ) );
		$pending            = get_option( Fred_Espace_Client_Keys::OPTION_PENDING );
		$pending['expires'] = time() - 1;
		update_option( Fred_Espace_Client_Keys::OPTION_PENDING, $pending );

		$response = $this->dispatch( $this->signed_request( 'POST', '/hop/v1/handshake', array( 'public_key' => $this->platform_public_key() ), array( 'secret' => str_repeat( 'ab', 32 ) ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_a_revoked_site_refuses_the_old_key() {
		Fred_Espace_Client_Keys::revoke();

		$this->assertSame( 401, $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) )->get_status() );
	}

	public function test_the_new_address_answers_like_the_former_one() {
		$this->assertSame( 200, $this->dispatch( $this->signed_request( 'GET', '/fred-espace-client/v1/status' ) )->get_status() );
		$this->assertSame( 200, $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) )->get_status() );
	}

	public function test_a_signature_for_one_address_does_not_open_the_other() {
		$request = $this->signed_request( 'GET', '/hop/v1/status' );
		$moved   = new WP_REST_Request( 'GET', '/fred-espace-client/v1/status' );

		foreach ( $request->get_headers() as $name => $values ) {
			$moved->set_header( $name, $values );
		}

		$this->assertSame( 401, $this->dispatch( $moved )->get_status() );
	}
}
