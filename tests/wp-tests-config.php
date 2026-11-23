<?php
/**
 * WordPress test configuration. The database is read from the environment,
 * so the same file serves the CI and a local MySQL.
 *
 *     WP_TESTS_DB_NAME, WP_TESTS_DB_USER, WP_TESTS_DB_PASSWORD, WP_TESTS_DB_HOST
 *
 * The test suite empties this database: never point it at a real site.
 *
 * @package Hop_Connector
 */

// phpcs:disable -- Test configuration, never loaded by WordPress.

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );

// The core package ships no wp-content: a minimal one, with the default theme.
define( 'WP_CONTENT_DIR', __DIR__ . '/fixtures/wp-content' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'hop_connector_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
define( 'WPLANG', '' );

$table_prefix = 'wptests_';
