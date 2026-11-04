<?php
/**
 * B3. Outbox (tests 44-54).
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;
use Mailtonic\Settings;

class OutboxTest extends Mailtonic_TestCase {

	private function queue( $ref = 'r1', $kind = 'signup', $email = 'ana@example.com', $path = '/lists/L1/signups' ) {
		return Outbox::queue(
			array(
				'kind'      => $kind,
				'source'    => 'admin',
				'reference' => 'Test ' . $ref,
				'method'    => 'POST',
				'path'      => $path,
				'body'      => array( 'email' => $email, 'consent' => array( 'text' => 'Yes' ) ),
				'key'       => $ref,
			)
		);
	}

	private function entry( $id ) {
		return Outbox::find( array( 'id' => $id ) )[0];
	}

	private function error( $code, $message = 'Refused.' ) {
		return array( 'error' => array( 'code' => $code, 'message' => $message, 'request_id' => 'req1' ) );
	}

	/** 44 */
	public function test_entries_are_sent_after_the_response_with_the_right_headers() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 201, array( 'status' => 'subscribed' ) );
		$id = $this->queue();
		$this->assertCount( 0, $this->http->requests() );

		do_action( 'shutdown' );

		$req = $this->http->requests( 'POST', self::API . '/lists/L1/signups' );
		$this->assertCount( 1, $req );
		$h = $req[0]['headers'];
		$this->assertSame( 'Bearer ' . self::KEY, $h['Authorization'] );
		$this->assertSame( 'wp:' . Settings::site_key() . ':signup:r1', $h['Idempotency-Key'] );
		$this->assertSame( 'application/json', $h['Content-Type'] );
		$this->assertSame( 'application/json', $h['Accept'] );
		$this->assertSame( 'mailtonic-wordpress/' . \Mailtonic\Plugin::VERSION . '; ' . home_url(), $h['User-Agent'] );
		$this->assertSame( 10, $req[0]['args']['timeout'] );
		$this->assertSame( 'ana@example.com', $req[0]['body']['email'] );
		$this->assertSame( 'sent', $this->entry( $id )['status'] );
	}

	/** 45 */
	public function test_same_fact_queues_one_entry_with_a_site_key_not_the_url() {
		$this->connect();
		$this->queue( '42:placed', 'event' );
		$this->queue( '42:placed', 'event' );
		$rows = Outbox::find( array() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 16, strlen( Settings::site_key() ) );
		$this->assertStringContainsString( Settings::site_key(), $rows[0]['idempotency_key'] );
		$this->assertStringNotContainsString( 'shop.test', $rows[0]['idempotency_key'] );
	}

	/** 46 */
	public function test_retry_with_backoff_then_failed_after_six_attempts() {
		$this->connect();
		$path = self::API . '/lists/L1/signups';
		$this->http->on( 'POST', $path, 503, $this->error( 'unavailable' ) );
		$this->http->on( 'POST', $path, 500, $this->error( 'server_error' ) );
		$id  = $this->queue();
		$now = time();

		$this->runOutbox( $now );
		$e = $this->entry( $id );
		$this->assertSame( 'pending', $e['status'] );
		$this->assertSame( 1, (int) $e['attempts'] );
		$this->assertSame( $now + 60, (int) $e['next_attempt_at'] );

		foreach ( array( 5, 15, 60, 240 ) as $minutes ) {
			$now = (int) $this->entry( $id )['next_attempt_at'];
			$this->runOutbox( $now - 1 );
			$this->runOutbox( $now );
			$this->assertSame( $now + $minutes * 60, (int) $this->entry( $id )['next_attempt_at'] );
		}
		$this->runOutbox( (int) $this->entry( $id )['next_attempt_at'] );

		$e = $this->entry( $id );
		$this->assertSame( 'failed', $e['status'] );
		$this->assertSame( 6, (int) $e['attempts'] );
		$req = $this->http->requests( 'POST', $path );
		$this->assertCount( 6, $req );
		$this->assertCount( 1, array_unique( array_map( static function ( $r ) { return $r['headers']['Idempotency-Key']; }, $req ) ) );
	}

	/** 46 (timeout) */
	public function test_network_error_retries() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 'timeout' );
		$id = $this->queue();
		$this->runOutbox();
		$this->assertSame( 'pending', $this->entry( $id )['status'] );
		$this->assertSame( 1, (int) $this->entry( $id )['attempts'] );
	}

	/** 47 */
	public function test_429_uses_retry_after_with_a_30_second_floor() {
		$this->connect();
		$path = self::API . '/lists/L1/signups';
		$this->http->on( 'POST', $path, 429, $this->error( 'rate_limited' ), array( 'Retry-After' => '120' ) );
		$this->http->on( 'POST', $path, 429, $this->error( 'rate_limited' ), array( 'Retry-After' => '5' ) );
		$id  = $this->queue();
		$now = time();
		$this->runOutbox( $now );
		$this->assertSame( $now + 120, (int) $this->entry( $id )['next_attempt_at'] );
		$this->runOutbox( $now + 120 );
		$this->assertSame( $now + 150, (int) $this->entry( $id )['next_attempt_at'] );
		$this->assertSame( 'pending', $this->entry( $id )['status'] );
	}

	public function pausing() {
		return array(
			'401'       => array( 401, 'unauthenticated', "didn't accept" ),
			'402'       => array( 402, 'subscription_required', "subscription isn't active" ),
			'suspended' => array( 403, 'account_suspended', 'suspended' ),
		);
	}

	/**
	 * 48
	 *
	 * @dataProvider pausing
	 */
	public function test_auth_and_billing_refusals_pause_the_outbox( $status, $code, $reason ) {
		$this->connect();
		$path = self::API . '/lists/L1/signups';
		$this->http->on( 'POST', $path, $status, $this->error( $code ) );
		$this->http->on( 'POST', $path, 201, array( 'status' => 'subscribed' ) );
		$a = $this->queue( 'a' );
		$b = $this->queue( 'b' );

		$this->runOutbox();
		$this->runOutbox( time() + 3600 );
		$this->assertCount( 1, $this->http->requests( 'POST', $path ) );
		$this->assertSame( 'pending', $this->entry( $a )['status'] );
		$this->assertSame( 'pending', $this->entry( $b )['status'] );
		$this->loginAs();
		$this->assertStringContainsString( $reason, html_entity_decode( \Mailtonic\Admin::status_line(), ENT_QUOTES ) );

		$this->http->only( 'GET', self::API . '/me', 200, $this->me() );
		$this->assertTrue( \Mailtonic\Connection::check()['ok'] );
		$this->runOutbox();
		$this->assertSame( 'sent', $this->entry( $a )['status'] );
		$this->assertSame( 'sent', $this->entry( $b )['status'] );
	}

	/** 48 (expiry) */
	public function test_paused_entries_expire_as_failed_after_seven_days() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 401, $this->error( 'unauthenticated' ) );
		$id = $this->queue();
		$this->runOutbox();
		$this->runOutbox( time() + 7 * DAY_IN_SECONDS + 1 );
		$this->assertSame( 'failed', $this->entry( $id )['status'] );
	}

	/** 49 */
	public function test_contact_not_found_on_an_event_is_skipped() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/events', 404, $this->error( 'contact_not_found' ) );
		$id = $this->queue( 'e1', 'event', 'ana@example.com', '/events' );
		$this->runOutbox();
		$this->runOutbox( time() + 3600 );
		$this->assertSame( 'skipped', $this->entry( $id )['status'] );
		$this->assertCount( 1, $this->http->requests() );
	}

	/** 50 */
	public function test_other_4xx_fail_at_once_with_the_api_code_and_message() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 422, $this->error( 'suppressed', 'This address is suppressed.' ) );
		$this->http->on( 'POST', self::API . '/lists/L2/signups', 422, array( 'error' => array( 'code' => 'validation_failed', 'message' => 'The data is invalid.', 'fields' => array( 'email' => array( 'Invalid email.' ) ), 'request_id' => 'r' ) ) );
		$a = $this->queue( 'a' );
		$b = $this->queue( 'b', 'signup', 'x@example.com', '/lists/L2/signups' );
		$this->runOutbox();
		$e = $this->entry( $a );
		$this->assertSame( 'failed', $e['status'] );
		$this->assertSame( 'suppressed', $e['last_error_code'] );
		$this->assertSame( 'This address is suppressed.', $e['last_error'] );
		$this->assertSame( 422, (int) $e['last_status'] );
		$e = $this->entry( $b );
		$this->assertSame( 'validation_failed', $e['last_error_code'] );
		$this->assertStringContainsString( 'email: Invalid email.', $e['last_error'] );
	}

	/** 51 */
	public function test_batches_of_fifty_oldest_first_under_a_lock() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 201, array() );
		$ids = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$ids[] = $this->queue( 'n' . $i, 'signup', "n{$i}@example.com" );
		}
		set_transient( 'mailtonic_outbox_lock', 1, 240 );
		$this->runOutbox();
		$this->assertCount( 0, $this->http->requests() );
		delete_transient( 'mailtonic_outbox_lock' );

		$this->runOutbox();
		$sent = $this->http->requests();
		$this->assertCount( 50, $sent );
		$this->assertSame( 'n0@example.com', $sent[0]['body']['email'] );
		$this->assertSame( 'n49@example.com', $sent[49]['body']['email'] );
		$this->assertSame( 'pending', $this->entry( $ids[50] )['status'] );
		$this->assertFalse( get_transient( 'mailtonic_outbox_lock' ) );
	}

	/** 52 */
	public function test_sync_log_masks_emails_and_retries() {
		$this->connect();
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 422, $this->error( 'contact_limit', 'Contact limit reached for ana@example.com.' ) );
		$a = $this->queue( 'a' );
		$b = $this->queue( 'b', 'signup', 'bo@example.com' );
		$this->runOutbox();
		$this->loginAs();

		$html = $this->renderPage( 'log' );
		$this->assertStringContainsString( 'a***@example.com', $html );
		$this->assertStringContainsString( 'b***@example.com', $html );
		$this->assertStringNotContainsString( 'ana@example.com', $html );
		$this->assertStringNotContainsString( 'consent', $html );
		$this->assertStringContainsString( 'contact_limit', $html );

		$this->postAdmin( 'mailtonic_retry', array( 'id' => $a ) );
		$this->assertSame( 'pending', $this->entry( $a )['status'] );
		$this->assertSame( 'failed', $this->entry( $b )['status'] );
		$this->http->only( 'POST', self::API . '/lists/L1/signups', 201, array() );
		$this->runOutbox();
		$this->assertSame( 'sent', $this->entry( $a )['status'] );

		$this->postAdmin( 'mailtonic_retry_all' );
		$this->assertSame( 'pending', $this->entry( $b )['status'] );
		$this->runOutbox();
		$this->assertSame( 'sent', $this->entry( $b )['status'] );
	}

	/** 52 (filters) */
	public function test_sync_log_filters_by_status_and_source() {
		$this->connect();
		$this->queue( 'a' );
		$this->loginAs();
		$_GET['status'] = 'failed';
		$this->assertStringNotContainsString( 'Test a', $this->renderPage( 'log' ) );
		$_GET['status'] = 'pending';
		$this->assertStringContainsString( 'Test a', $this->renderPage( 'log' ) );
		$_GET['source'] = 'cf7';
		$this->assertStringNotContainsString( 'Test a', $this->renderPage( 'log' ) );
	}

	/** 53 */
	public function test_prune() {
		global $wpdb;
		$this->connect();
		$now   = time();
		$sent  = $this->queue( 's' );
		$old   = $this->queue( 'f31' );
		$young = $this->queue( 'f29' );
		$table = Outbox::table();
		$wpdb->update( $table, array( 'status' => 'sent', 'finished_at' => $now - 8 * DAY_IN_SECONDS ), array( 'id' => $sent ) );
		$wpdb->update( $table, array( 'status' => 'failed', 'finished_at' => $now - 31 * DAY_IN_SECONDS ), array( 'id' => $old ) );
		$wpdb->update( $table, array( 'status' => 'failed', 'finished_at' => $now - 29 * DAY_IN_SECONDS ), array( 'id' => $young ) );
		$this->runPrune( $now );
		$this->assertSame( array( (string) $young ), array_column( Outbox::find( array() ), 'id' ) );
	}

	/** 54 */
	public function test_nothing_is_queued_without_a_connection() {
		$this->assertFalse( $this->queue() );
		$this->assertCount( 0, Outbox::find( array() ) );

		$form = Mailtonic_Fixtures\cf7_form();
		update_option(
			'mailtonic_bridges',
			array(
				'cf7:' . $form => array(
					'plugin'        => 'cf7',
					'form_id'       => (string) $form,
					'enabled'       => true,
					'list'          => 'L1',
					'map'           => array( 'email' => 'your-email' ),
					'tags'          => array(),
					'consent_field' => 'consent',
				),
			)
		);
		Mailtonic_Fixtures\cf7_submit( $form, array( 'your-email' => 'ana@example.com', 'consent' => '1' ) );
		$this->assertCount( 0, Outbox::find( array() ) );
		$this->assertCount( 0, $this->http->requests() );
	}
}
