<?php
/**
 * The cron waits while an update replaces files: it ignores the maintenance
 * mode, and would load half of them.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

class RescueTest extends Fred_Espace_Client_Test_Case {

	public function test_wp_cron_php_is_the_cron() {
		$this->assertTrue( Fred_Espace_Client_Rescue::is_cron( true, false, array() ) );
	}

	public function test_the_cron_command_of_forge_is_the_cron() {
		$this->assertTrue( Fred_Espace_Client_Rescue::is_cron( false, true, array( 'wp', 'cron', 'event', 'run', '--due-now' ) ) );
		$this->assertTrue( Fred_Espace_Client_Rescue::is_cron( false, true, array( '/usr/local/bin/wp', '--path=/home/site/public', 'cron', 'event', 'run', '--due-now', '--quiet' ) ) );
	}

	public function test_other_commands_and_visits_are_not_the_cron() {
		$this->assertFalse( Fred_Espace_Client_Rescue::is_cron( false, true, array( 'wp', 'plugin', 'list' ) ) );
		$this->assertFalse( Fred_Espace_Client_Rescue::is_cron( false, true, array( 'wp', 'cron', 'event', 'list' ) ) );
		$this->assertFalse( Fred_Espace_Client_Rescue::is_cron( false, false, array( 'wp', 'cron', 'event', 'run' ) ) );
	}

	public function test_no_files_are_being_replaced_outside_an_update() {
		$this->assertFalse( Fred_Espace_Client_Rescue::files_being_replaced() );
	}

	public function test_a_mark_left_by_a_request_that_died_expires() {
		update_option( Fred_Espace_Client_Rescue::OPTION_COPYING, time() - 1 );

		$this->assertFalse( Fred_Espace_Client_Rescue::files_being_replaced() );

		update_option( Fred_Espace_Client_Rescue::OPTION_COPYING, time() + 60 );

		$this->assertTrue( Fred_Espace_Client_Rescue::files_being_replaced() );
	}
}
