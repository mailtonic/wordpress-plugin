<?php
/**
 * B7. WooCommerce past-customer import (tests 81-85).
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;
use Mailtonic\Settings;

/**
 * @group woocommerce
 */
class WooImportTest extends Mailtonic_TestCase {

	const STATEMENT = 'Everyone on this list agreed to receive email from me, and I can show when and how.';

	public function set_up() {
		parent::set_up();
		$this->requireWoo();
	}

	/** Paid orders created while disconnected, so their hooks queue nothing. */
	private function orders( $count, $prefix = 'c' ) {
		global $wpdb;
		if ( $count < 100 || \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$order = new WC_Order();
				$order->set_billing_email( "{$prefix}{$i}@example.com" );
				$order->set_billing_first_name( 'N' . $i );
				$order->set_status( 'completed' );
				$order->save();
			}
			return;
		}
		// Thousands of orders through the ORM take minutes; posts storage is seeded directly.
		$now = current_time( 'mysql' );
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert( $wpdb->posts, array( 'post_type' => 'shop_order', 'post_status' => 'wc-completed', 'post_date' => $now, 'post_date_gmt' => $now, 'post_title' => 'Order', 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
			$id = $wpdb->insert_id;
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, '_billing_email', %s), (%d, '_billing_first_name', %s), (%d, '_order_currency', 'USD'), (%d, '_order_total', '0')", $id, "{$prefix}{$i}@example.com", $id, 'N' . $i, $id, $id ) );
		}
		wp_cache_flush();
	}

	private function start( array $fields = array() ) {
		return $this->postAdmin(
			'mailtonic_wc_import',
			array_merge(
				array(
					'source'    => 'customers',
					'statement' => '1',
				),
				$fields
			)
		);
	}

	private function batches() {
		return Outbox::find( array( 'kind' => 'import_batch' ) );
	}

	private function rows( $batch ) {
		return json_decode( $batch['body'], true )['rows'];
	}

	/** 81 */
	public function test_declaration_is_required() {
		$this->orders( 2 );
		$this->connect();
		$this->listFields( array( 'email' ) );
		$this->loginAs();

		$this->start( array( 'statement' => '' ) );
		$this->assertStringContainsString( self::STATEMENT, $this->notices() );
		$this->assertCount( 0, $this->batches() );

		$this->start( array( 'source' => 'bought_or_scraped' ) );
		$this->assertCount( 0, $this->batches() );

		$html = $this->renderPage( 'woocommerce' );
		$this->assertStringContainsString( self::STATEMENT, $html );
		$this->assertStringContainsString( 'value="customers"', $html );
		$this->assertStringNotContainsString( 'bought_or_scraped', $html );
		$this->assertCount( 0, $this->http->requests( 'POST' ) );
	}

	/** 82 */
	public function test_batches_of_a_thousand_with_summed_progress() {
		$this->orders( 2350 );
		$this->connect();
		$this->listFields( array( 'email', 'first_name' ) );
		$this->loginAs();
		$this->start();

		$batches = $this->batches();
		$this->assertCount( 3, $batches );
		$this->assertSame( array( 1000, 1000, 350 ), array_map( function ( $b ) { return count( $this->rows( $b ) ); }, $batches ) );
		$this->assertCount( 3, array_unique( array_column( $batches, 'idempotency_key' ) ) );
		foreach ( $batches as $b ) {
			$body = json_decode( $b['body'], true );
			$this->assertSame( '/lists/L1/contacts/bulk', $b['path'] );
			$this->assertSame( 'customers', $body['source'] );
			$this->assertTrue( $body['permission_confirmed'] );
			$this->assertSame( 'add_and_update', $body['mode'] );
			$this->assertArrayNotHasKey( 'overwrite_status', $body );
		}
		$emails = array_merge( ...array_map( function ( $b ) { return array_column( $this->rows( $b ), 'email' ); }, $batches ) );
		$this->assertCount( 2350, array_unique( $emails ) );
		$first = $this->rows( $batches[0] )[0];
		$this->assertSame( array( 'woocommerce-customer' ), $first['tags'] );
		$this->assertArrayHasKey( 'first_name', $first );

		$result = static function ( $inserted, $updated, $skipped ) {
			return array( 'received' => 0, 'inserted' => $inserted, 'updated' => $updated, 'unchanged' => 1, 'suppressed' => 2, 'invalid_phones' => 0, 'skipped' => $skipped, 'errors' => array() );
		};
		$path = self::API . '/lists/L1/contacts/bulk';
		$this->http->on( 'POST', $path, 200, $result( 900, 90, array( 'invalid_email' => 7 ) ) );
		$this->http->on( 'POST', $path, 200, $result( 950, 40, array( 'invalid_email' => 3, 'duplicate' => 2 ) ) );
		$this->http->on( 'POST', $path, 200, $result( 300, 40, array() ) );
		$this->runOutbox();

		$progress = Settings::import();
		$this->assertSame( 3, $progress['sent'] );
		$this->assertSame( 3, $progress['total'] );
		$this->assertSame( 2150, $progress['counts']['inserted'] );
		$this->assertSame( 170, $progress['counts']['updated'] );
		$this->assertSame( 10, $progress['counts']['skipped']['invalid_email'] );
		$html = $this->renderPage( 'woocommerce' );
		$this->assertStringContainsString( '3 of 3 batches sent', $html );
		$this->assertStringContainsString( '2150', $html );
	}

	/** 83 */
	public function test_register_rows_carry_consent_columns() {
		$this->connect();
		$this->listFields( array( 'email' ) );
		$this->paidOrder( 'opted@example.com', true, 'completed' );
		Outbox::find( array() ) && $GLOBALS['wpdb']->query( 'DELETE FROM ' . Outbox::table() );
		$this->loginAs();
		$this->start();
		$rows = $this->rows( $this->batches()[0] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'opted@example.com', $rows[0]['email'] );
		$this->assertNotEmpty( $rows[0]['consent_at'] );
		$this->assertSame( '203.0.113.9', $rows[0]['consent_ip'] );
		$this->assertSame( 'WooCommerce checkout', $rows[0]['consent_source'] );
	}

	/** 84 */
	public function test_only_opted_in_option() {
		$this->orders( 3 );
		$this->connect();
		$this->listFields( array( 'email' ) );
		$this->paidOrder( 'opted@example.com', true, 'completed' );
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Outbox::table() );
		$user = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'member@example.com' ) );
		update_user_meta( $user, \Mailtonic\Woo::META, array( 'at' => time(), 'text' => 'x', 'ip' => '', 'ua' => '', 'page' => 'registration' ) );
		$this->loginAs();
		$this->start( array( 'only_opted_in' => '1' ) );
		$rows = $this->rows( $this->batches()[0] );
		$this->assertEqualsCanonicalizing( array( 'opted@example.com', 'member@example.com' ), array_column( $rows, 'email' ) );
		$by = array_column( $rows, 'consent_source', 'email' );
		$this->assertSame( 'WooCommerce registration', $by['member@example.com'] );
	}

	/** 85 */
	public function test_one_import_at_a_time_and_import_running_is_retried() {
		$this->orders( 2 );
		$this->connect();
		$this->listFields( array( 'email' ) );
		$this->loginAs();
		$this->start();
		$this->start();
		$this->assertStringContainsString( 'An import is already running.', $this->notices() );
		$this->assertCount( 1, $this->batches() );

		$this->http->on( 'POST', self::API . '/lists/L1/contacts/bulk', 409, array( 'error' => array( 'code' => 'import_running', 'message' => 'An import is already running for this list', 'request_id' => null ) ) );
		$this->runOutbox();
		$batch = $this->batches()[0];
		$this->assertSame( 'pending', $batch['status'] );
		$this->assertSame( 1, (int) $batch['attempts'] );
	}
}
