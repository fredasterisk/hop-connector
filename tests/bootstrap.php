<?php
/**
 * PHPUnit bootstrap: WordPress's test suite, with the connector loaded as a
 * plugin.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test bootstrap, never loaded by WordPress.

$root = dirname( __DIR__ );

require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root ) {
		require $root . '/hop-connector.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-hop-connector-test-case.php';
require_once __DIR__ . '/class-hop-connector-release-with-test-key.php';
