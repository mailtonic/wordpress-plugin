<?php
/**
 * PHPUnit bootstrap: the WordPress test suite, WooCommerce (when available) and the
 * form-plugin fixture stubs, then the plugin itself.
 *
 * @package Mailtonic
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$mailtonic_wc_dir = getenv( 'MAILTONIC_WC_DIR' );
if ( false === $mailtonic_wc_dir ) {
	$mailtonic_wc_dir = dirname( __DIR__ ) . '/.cache/woocommerce-' . ( getenv( 'MAILTONIC_WC_VERSION' ) ? getenv( 'MAILTONIC_WC_VERSION' ) : '11.2.1' ) . '/woocommerce';
}
define( 'MAILTONIC_TESTS_WC', '' !== $mailtonic_wc_dir && file_exists( $mailtonic_wc_dir . '/woocommerce.php' ) );
if ( MAILTONIC_TESTS_WC ) {
	putenv( 'MAILTONIC_PLUGIN_DIR=' . dirname( $mailtonic_wc_dir ) );
}

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

// Nothing in the test suite may reach the network: unscripted requests are refused here,
// after the per-test fake (priority 10) had its chance.
tests_add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		return false === $pre ? new WP_Error( 'mailtonic_test_blocked', 'Blocked request to ' . $url ) : $pre;
	},
	PHP_INT_MAX,
	3
);
tests_add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $mailtonic_wc_dir ) {
		require __DIR__ . '/fixtures/form-plugins.php';
		if ( MAILTONIC_TESTS_WC ) {
			define( 'WC_TAX_ROUNDING_MODE', 'auto' );
			define( 'WC_USE_TRANSACTIONS', false );
			require $mailtonic_wc_dir . '/woocommerce.php';
		}
		require dirname( __DIR__ ) . '/mailtonic.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( MAILTONIC_TESTS_WC ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_roles();
		}
		Mailtonic\Plugin::activate( false );
	}
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
require __DIR__ . '/Support/class-fake-mailtonic.php';
require __DIR__ . '/Support/class-redirect-exception.php';
require __DIR__ . '/Support/class-testcase.php';
