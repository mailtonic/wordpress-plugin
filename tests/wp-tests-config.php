<?php
/**
 * WordPress test suite configuration. Database settings come from the environment,
 * defaulting to the docker-compose test database.
 *
 * @package Mailtonic
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'WP_PLUGIN_DIR', getenv( 'MAILTONIC_PLUGIN_DIR' ) ? getenv( 'MAILTONIC_PLUGIN_DIR' ) : __DIR__ . '/wp-content/plugins' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'wp' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ? getenv( 'WP_DB_PASSWORD' ) : 'wp' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : '127.0.0.1:33071' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'test-auth-key' );
define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'test-logged-in-key' );
define( 'NONCE_KEY', 'test-nonce-key' );
define( 'AUTH_SALT', 'test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'test-logged-in-salt' );
define( 'NONCE_SALT', 'test-nonce-salt' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'shop.test' );
define( 'WP_TESTS_EMAIL', 'admin@shop.test' );
define( 'WP_TESTS_TITLE', 'Acme Shop' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
