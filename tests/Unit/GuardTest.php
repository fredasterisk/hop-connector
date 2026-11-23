<?php
/**
 * Switches of wp-config.php. Constants cannot be undefined, so each test
 * defining one runs in its own process.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class GuardTest extends Hop_Connector_Test_Case {

	public function test_without_switch_every_action_is_allowed() {
		$this->assertNull( Hop_Connector_Guard::refusal( '/hop/v1/login-token' ) );
		$this->assertSame( array(), Hop_Connector_Guard::denied() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deny_cuts_only_the_listed_actions() {
		define( 'HOP_CONNECTOR_DENY', ' Login, restore , unknown' );

		$this->assertSame( array( 'login', 'restore' ), Hop_Connector_Guard::denied() );
		$this->assertSame( 'hop_action_denied', Hop_Connector_Guard::refusal( '/hop/v1/login-token' )->get_error_code() );
		$this->assertSame( 'hop_action_denied', Hop_Connector_Guard::refusal( '/hop/v1/backups/database/restore/continue' )->get_error_code() );
		$this->assertNull( Hop_Connector_Guard::refusal( '/hop/v1/updates/apply' ) );
		$this->assertNull( Hop_Connector_Guard::refusal( '/hop/v1/status' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_cuts_every_signed_request() {
		define( 'HOP_CONNECTOR_DISABLED', true );
		$this->pair();

		$response = $this->dispatch( $this->signed_request( 'GET', '/hop/v1/status' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'hop_action_denied', $response->as_error()->get_error_code() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_denied_action_is_refused_after_a_valid_signature() {
		define( 'HOP_CONNECTOR_DENY', 'login' );
		$this->pair();

		$response = $this->dispatch( $this->signed_request( 'POST', '/hop/v1/login-token', array( 'email' => 'agence@example.org' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'login', $response->as_error()->get_error_data()['action'] );
	}

	public function test_every_route_that_changes_the_site_has_an_action() {
		$reading = array( '/hop/v1/handshake', '/hop/v1/status', '/hop/v1/inventory', '/hop/v1/updates/refresh' );

		foreach ( array_keys( rest_get_server()->get_routes( 'hop/v1' ) ) as $route ) {
			if ( '/hop/v1' === $route || in_array( $route, $reading, true ) ) {
				continue;
			}

			$this->assertNotNull( Hop_Connector_Guard::action_of( $route ), "La route {$route} n'est rattachée à aucune action de HOP_CONNECTOR_DENY." );
		}
	}
}
