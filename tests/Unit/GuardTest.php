<?php
/**
 * Switches of wp-config.php. Constants cannot be undefined, so each test
 * defining one runs in its own process.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class GuardTest extends Fred_Espace_Client_Test_Case {

	public function test_without_switch_every_action_is_allowed() {
		$this->assertNull( Fred_Espace_Client_Guard::refusal( '/hop/v1/login-token' ) );
		$this->assertSame( array(), Fred_Espace_Client_Guard::denied() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deny_cuts_only_the_listed_actions() {
		define( 'FRED_ESPACE_CLIENT_DENY', ' Login, restore , unknown' );

		$this->assertSame( array( 'login', 'restore' ), Fred_Espace_Client_Guard::denied() );
		$this->assertSame( 'hop_action_denied', Fred_Espace_Client_Guard::refusal( '/hop/v1/login-token' )->get_error_code() );
		$this->assertSame( 'hop_action_denied', Fred_Espace_Client_Guard::refusal( '/hop/v1/backups/database/restore/continue' )->get_error_code() );
		$this->assertNull( Fred_Espace_Client_Guard::refusal( '/hop/v1/updates/apply' ) );
		$this->assertNull( Fred_Espace_Client_Guard::refusal( '/hop/v1/status' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_cuts_every_signed_request() {
		define( 'FRED_ESPACE_CLIENT_DISABLED', true );
		$this->pair();

		$response = $this->dispatch( $this->signed_request( 'GET', '/fred-espace-client/v1/status' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'hop_action_denied', $response->as_error()->get_error_code() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_denied_action_is_refused_after_a_valid_signature() {
		define( 'FRED_ESPACE_CLIENT_DENY', 'login' );
		$this->pair();

		$response = $this->dispatch( $this->signed_request( 'POST', '/fred-espace-client/v1/login-token', array( 'email' => 'agence@example.org' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'login', $response->as_error()->get_error_data()['action'] );
	}

	public function test_every_route_that_changes_the_site_has_an_action() {
		$reading = array( '/fred-espace-client/v1/handshake', '/fred-espace-client/v1/status', '/fred-espace-client/v1/inventory', '/fred-espace-client/v1/updates/refresh' );

		$this->assertNotEmpty( rest_get_server()->get_routes( 'fred-espace-client/v1' ) );

		foreach ( array_keys( rest_get_server()->get_routes( 'fred-espace-client/v1' ) ) as $route ) {
			if ( '/fred-espace-client/v1' === $route || in_array( $route, $reading, true ) ) {
				continue;
			}

			$this->assertNotNull( Fred_Espace_Client_Guard::action_of( $route ), "La route {$route} n'est rattachée à aucune action de FRED_ESPACE_CLIENT_DENY." );
		}
	}

	public function test_every_route_of_the_new_address_has_the_same_action() {
		foreach ( array_keys( rest_get_server()->get_routes( 'fred-espace-client/v1' ) ) as $route ) {
			$former = '/hop/v1/' . substr( $route, strlen( '/fred-espace-client/v1/' ) );

			$this->assertSame( Fred_Espace_Client_Guard::action_of( $former ), Fred_Espace_Client_Guard::action_of( $route ), $route );
		}

		$this->assertSame( 'login', Fred_Espace_Client_Guard::action_of( '/fred-espace-client/v1/login-token' ) );
		$this->assertTrue( Fred_Espace_Client_Guard::journaled( '/fred-espace-client/v1/updates/apply', '{}' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constants_of_the_former_name_still_count() {
		define( 'HOP_CONNECTOR_DENY', 'restore' );
		define( 'HOP_CONNECTOR_DISABLED', false );

		$this->assertSame( array( 'restore' ), Fred_Espace_Client_Guard::denied() );
		$this->assertSame( 'hop_action_denied', Fred_Espace_Client_Guard::refusal( '/fred-espace-client/v1/rollback' )->get_error_code() );
		$this->assertFalse( Fred_Espace_Client_Guard::disabled() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_former_switch_still_cuts_everything() {
		define( 'HOP_CONNECTOR_DISABLED', true );

		$this->assertTrue( Fred_Espace_Client_Guard::disabled() );
	}
}
