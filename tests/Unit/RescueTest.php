<?php
/**
 * The cron waits while an update replaces files: it ignores the maintenance
 * mode, and would load half of them.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class RescueTest extends Hop_Connector_Test_Case {

	public function test_wp_cron_php_is_the_cron() {
		$this->assertTrue( Hop_Connector_Rescue::is_cron( true, false, array() ) );
	}

	public function test_the_cron_command_of_forge_is_the_cron() {
		$this->assertTrue( Hop_Connector_Rescue::is_cron( false, true, array( 'wp', 'cron', 'event', 'run', '--due-now' ) ) );
		$this->assertTrue( Hop_Connector_Rescue::is_cron( false, true, array( '/usr/local/bin/wp', '--path=/home/site/public', 'cron', 'event', 'run', '--due-now', '--quiet' ) ) );
	}

	public function test_other_commands_and_visits_are_not_the_cron() {
		$this->assertFalse( Hop_Connector_Rescue::is_cron( false, true, array( 'wp', 'plugin', 'list' ) ) );
		$this->assertFalse( Hop_Connector_Rescue::is_cron( false, true, array( 'wp', 'cron', 'event', 'list' ) ) );
		$this->assertFalse( Hop_Connector_Rescue::is_cron( false, false, array( 'wp', 'cron', 'event', 'run' ) ) );
	}

	public function test_no_files_are_being_replaced_outside_an_update() {
		$this->assertFalse( Hop_Connector_Rescue::files_being_replaced() );
	}

	public function test_a_mark_left_by_a_request_that_died_expires() {
		update_option( Hop_Connector_Rescue::OPTION_COPYING, time() - 1 );

		$this->assertFalse( Hop_Connector_Rescue::files_being_replaced() );

		update_option( Hop_Connector_Rescue::OPTION_COPYING, time() + 60 );

		$this->assertTrue( Hop_Connector_Rescue::files_being_replaced() );
	}
}
