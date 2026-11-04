<?php
/**
 * B9. Activation, deactivation and uninstall (tests 98-99).
 *
 * @package Mailtonic
 */

use Mailtonic\Plugin;
use Mailtonic\Settings;

class LifecycleTest extends Mailtonic_TestCase {

	const HOOKS = array( 'mailtonic_outbox', 'mailtonic_cart_sweep', 'mailtonic_prune' );

	/** 98 */
	public function test_activation_and_deactivation() {
		global $wpdb;
		Plugin::deactivate();
		foreach ( self::HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}
		delete_option( 'mailtonic_db_version' );

		Plugin::activate( false );

		foreach ( array( 'mailtonic_outbox', 'mailtonic_carts' ) as $table ) {
			$this->assertSame( $wpdb->prefix . $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) );
		}
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{16}$/', Settings::site_key() );
		$this->assertSame( Plugin::DB_VERSION, get_option( 'mailtonic_db_version' ) );
		$schedules = array(
			'mailtonic_outbox'     => 'mailtonic_5min',
			'mailtonic_cart_sweep' => 'mailtonic_15min',
			'mailtonic_prune'      => 'daily',
		);
		foreach ( $schedules as $hook => $recurrence ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ) );
			$this->assertSame( $recurrence, wp_get_schedule( $hook ) );
		}
		$this->assertCount( 0, $this->http->requests() );

		$this->connect();
		Plugin::deactivate();
		foreach ( self::HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}
		$this->assertTrue( Settings::connected() );
		Plugin::activate( false );
	}

	/** 98 (connect notice) */
	public function test_activation_shows_one_connect_notice_until_connected() {
		Plugin::activate( false );
		$this->loginAs();
		set_current_screen( 'dashboard' );
		ob_start();
		do_action( 'admin_notices' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Connect mailtonic', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );

		$this->connect();
		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringNotContainsString( 'Connect mailtonic', ob_get_clean() );
	}

	/** 99 */
	public function test_uninstall_removes_everything_and_calls_nothing() {
		global $wpdb;
		$this->connect();
		update_option( 'mailtonic_bridges', array( 'x' => array() ) );
		set_transient( 'mailtonic_forms', array(), 600 );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_mailtonic_optin', array( 'at' => 1 ) );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_mailtonic_optin', array( 'at' => 1 ) );
		update_post_meta( $post, '_wc_other/mailtonic/optin', '1' );

		$queries = array();
		add_filter(
			'query',
			static function ( $q ) use ( &$queries ) {
				$queries[] = $q;
				return $q;
			}
		);
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mailtonic/mailtonic.php' );
		}
		include dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'mailtonic_settings' ) );
		$this->assertFalse( get_option( 'mailtonic_bridges' ) );
		$this->assertFalse( get_option( 'mailtonic_db_version' ) );
		$this->assertFalse( get_transient( 'mailtonic_forms' ) );
		$this->assertSame( '', get_user_meta( $user, '_mailtonic_optin', true ) );
		$this->assertSame( '', get_post_meta( $post, '_mailtonic_optin', true ) );
		$this->assertSame( '', get_post_meta( $post, '_wc_other/mailtonic/optin', true ) );
		foreach ( self::HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}
		$drops = implode( "\n", preg_grep( '/DROP (TEMPORARY )?TABLE/i', $queries ) );
		$this->assertStringContainsString( $wpdb->prefix . 'mailtonic_outbox', $drops );
		$this->assertStringContainsString( $wpdb->prefix . 'mailtonic_carts', $drops );
		$this->assertCount( 0, $this->http->requests() );
		Plugin::activate( false );
	}
}
