<?php
/**
 * Release checks with a key the tests hold.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * The real check, with a key the tests hold instead of the agency's.
 */
class Hop_Connector_Release_With_Test_Key extends Hop_Connector_Release {

	/**
	 * @var array<string, string>
	 */
	public static $test_keys = array();

	protected static function keys() {
		return self::$test_keys;
	}
}
