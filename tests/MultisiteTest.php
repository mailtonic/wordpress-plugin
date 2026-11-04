<?php
/**
 * B9. Multisite (test 100). Run with WP_MULTISITE=1.
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;
use Mailtonic\Plugin;
use Mailtonic\Settings;

/**
 * @group multisite
 */
class MultisiteTest extends Mailtonic_TestCase {

	/** 100 */
	public function test_sites_keep_separate_connections_outboxes_and_carts() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$other = self::factory()->blog->create();
		Plugin::activate( true );

		$this->connect();
		Outbox::queue( array( 'kind' => 'signup', 'source' => 'admin', 'reference' => 'main', 'method' => 'POST', 'path' => '/lists/L1/signups', 'body' => array( 'email' => 'a@example.com' ), 'key' => 'main' ) );
		$main_site_key = Settings::site_key();

		switch_to_blog( $other );
		$this->assertNotFalse( wp_next_scheduled( 'mailtonic_outbox' ) );
		$this->assertFalse( Settings::connected() );
		$this->assertCount( 0, Outbox::find( array() ) );
		$this->assertNotSame( $main_site_key, Settings::site_key() );
		$this->assertStringContainsString( $GLOBALS['wpdb']->prefix, Outbox::table() );
		$this->assertNotSame( $GLOBALS['wpdb']->base_prefix . 'mailtonic_outbox', Outbox::table() );
		if ( MAILTONIC_TESTS_WC ) {
			$this->assertNull( \Mailtonic\Carts::find( 1 ) );
		}
		restore_current_blog();

		$this->assertTrue( Settings::connected() );
		$this->assertCount( 1, Outbox::find( array() ) );
	}
}
