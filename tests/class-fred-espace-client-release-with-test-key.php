<?php
/**
 * Release checks with a key the tests hold.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- Test code, never loaded by WordPress.

/**
 * The real check, with a key the tests hold instead of the agency's.
 */
class Fred_Espace_Client_Release_With_Test_Key extends Fred_Espace_Client_Release {

	/**
	 * @var array<string, string>
	 */
	public static $test_keys = array();

	protected static function keys() {
		return self::$test_keys;
	}
}
