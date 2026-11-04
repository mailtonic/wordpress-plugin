<?php
/**
 * B8. WooCommerce abandoned carts (tests 86-95).
 *
 * @package Mailtonic
 */

use Mailtonic\Carts;
use Mailtonic\Outbox;
use Mailtonic\Settings;

/**
 * @group woocommerce
 */
class CartsTest extends Mailtonic_TestCase {

	public function set_up() {
		parent::set_up();
		$this->requireWoo();
		$this->connect();
		$this->fresh_session();
	}

	private function fresh_session() {
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->cart = new WC_Cart();
		WC()->cart->get_cart_from_session();
		WC()->cart->empty_cart();
	}

	private function contact( $email, $opted_in, $nonce = null, $ip = '192.0.2.1' ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$request                = new WP_REST_Request( 'POST', '/mailtonic/v1/cart-contact' );
		$request->set_param( 'email', $email );
		$request->set_param( 'opted_in', $opted_in );
		$request->set_header( 'X-Mailtonic-Nonce', null === $nonce ? wp_create_nonce( 'mailtonic_cart_contact' ) : $nonce );
		return rest_get_server()->dispatch( $request );
	}

	private function tracked( $email = 'cart@example.com', $price = '20.00' ) {
		WC()->cart->add_to_cart( $this->product( $price, 'Teapot' )->get_id(), 2 );
		WC()->cart->calculate_totals();
		$this->assertSame( 200, $this->contact( $email, true )->get_status() );
		return Carts::current();
	}

	private function age( $cart, $seconds ) {
		global $wpdb;
		$wpdb->update( Carts::table(), array( 'updated_at' => time() - $seconds ), array( 'id' => $cart['id'] ) );
	}

	/** 86 */
	public function test_no_tick_no_capture_and_route_guards() {
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		$this->assertSame( 200, $this->contact( 'cart@example.com', false )->get_status() );
		$this->assertNull( Carts::current() );

		$this->assertSame( 200, $this->contact( 'cart@example.com', true )->get_status() );
		$this->assertSame( 'cart@example.com', Carts::current()['email'] );

		$this->assertSame( 403, $this->contact( 'cart@example.com', true, 'bad' )->get_status() );

		for ( $i = 0; $i < 7; $i++ ) {
			$this->contact( 'cart@example.com', true, null, '192.0.2.50' );
		}
		$this->assertSame( 200, $this->contact( 'cart@example.com', true, null, '192.0.2.50' )->get_status() );
		$this->assertSame( 200, $this->contact( 'cart@example.com', true, null, '192.0.2.50' )->get_status() );
		$this->assertSame( 200, $this->contact( 'cart@example.com', true, null, '192.0.2.50' )->get_status() );
		$this->assertSame( 429, $this->contact( 'cart@example.com', true, null, '192.0.2.50' )->get_status() );
		$this->assertSame( 400, $this->contact( 'not-an-email', true, null, '192.0.2.99' )->get_status() );
	}

	/** 87 */
	public function test_logged_in_opted_in_customer_is_tracked_without_the_route() {
		$user = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'member@example.com' ) );
		update_user_meta( $user, \Mailtonic\Woo::META, array( 'at' => time(), 'text' => 'x', 'ip' => '', 'ua' => '', 'page' => 'checkout' ) );
		wp_set_current_user( $user );
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$cart = Carts::current();
		$this->assertSame( 'member@example.com', $cart['email'] );
		$this->assertSame( $user, (int) $cart['user_id'] );
	}

	/** 88 */
	public function test_sweep_timing() {
		$this->listFields( array( 'email', 'wc_cart_url', 'wc_cart_total' ) );
		$cart = $this->tracked();
		$t    = (int) $cart['updated_at'];

		$this->runCartSweep( $t + 59 * 60 );
		$this->assertCount( 0, Outbox::find( array() ) );

		Settings::update_wc( array( 'idle_minutes' => 120 ) );
		$this->runCartSweep( $t + 61 * 60 );
		$this->assertCount( 0, Outbox::find( array() ) );

		Settings::update_wc( array( 'idle_minutes' => 60 ) );
		$this->runCartSweep( $t + 61 * 60 );
		$rows = Outbox::find( array() );
		$this->assertSame( array( 'contact_update', 'event' ), array_column( $rows, 'kind' ) );
		$update = json_decode( $rows[0]['body'], true );
		$this->assertSame( '/lists/L1/contacts/' . rawurlencode( 'cart@example.com' ), $rows[0]['path'] );
		$this->assertSame( array( 'wc_cart_url', 'wc_cart_total' ), array_keys( $update['fields'] ) );
		$this->assertSame( 40.0, (float) $update['fields']['wc_cart_total'] );
		$event = json_decode( $rows[1]['body'], true );
		$this->assertSame( 'cart_abandoned', $event['name'] );
		$this->assertSame( 'cart@example.com', $event['email'] );
		$p = $event['properties'];
		$this->assertSame( '40.00', $p['cart_total'] );
		$this->assertSame( get_woocommerce_currency(), $p['currency'] );
		$this->assertSame( 2, $p['items_count'] );
		$this->assertSame( 'Teapot', $p['first_item_name'] );
		$this->assertSame( 'woocommerce', $p['store'] );
		$this->assertSame( home_url(), $p['site_url'] );
		$this->assertStringStartsWith( home_url( '/?mailtonic_cart=' . $cart['token'] . '&v=' . $cart['version'] . '&sig=' ), $p['recovery_url'] );
		$this->assertSame( $p['recovery_url'], $update['fields']['wc_cart_url'] );
	}

	/** 88 (no cart fields on the list) */
	public function test_sweep_without_cart_fields_sends_only_the_event() {
		$this->listFields( array( 'email' ) );
		$cart = $this->tracked();
		$this->runCartSweep( (int) $cart['updated_at'] + 61 * 60 );
		$this->assertSame( array( 'event' ), array_column( Outbox::find( array() ), 'kind' ) );
	}

	/** 89 */
	public function test_older_than_seven_days_is_ignored() {
		$this->listFields( array( 'email' ) );
		$cart = $this->tracked();
		$this->age( $cart, 8 * DAY_IN_SECONDS );
		$this->runCartSweep();
		$this->assertCount( 0, Outbox::find( array() ) );
	}

	/** 90 */
	public function test_once_per_version() {
		$this->listFields( array( 'email' ) );
		$cart = $this->tracked();
		$now  = (int) $cart['updated_at'] + 61 * 60;
		$this->runCartSweep( $now );
		$this->runCartSweep( $now + 3600 );
		$this->assertCount( 1, Outbox::find( array( 'kind' => 'event' ) ) );

		WC()->cart->add_to_cart( $this->product( '5.00', 'Cup' )->get_id(), 1 );
		WC()->cart->calculate_totals();
		$changed = Carts::current();
		$this->assertSame( (int) $cart['version'] + 1, (int) $changed['version'] );
		$this->runCartSweep( (int) $changed['updated_at'] + 61 * 60 );
		$events = Outbox::find( array( 'kind' => 'event' ) );
		$this->assertCount( 2, $events );
		$this->assertNotSame( $events[0]['idempotency_key'], $events[1]['idempotency_key'] );
		$this->assertStringEndsWith( $changed['token'] . ':' . $changed['version'], $events[1]['idempotency_key'] );
	}

	/** 91 */
	public function test_recovery_link_restores_the_cart() {
		$this->listFields( array( 'email' ) );
		$gone = $this->product( '3.00', 'Saucer', 5 );
		WC()->cart->add_to_cart( $gone->get_id(), 1 );
		$cart = $this->tracked();
		$url  = Carts::recovery_url( $cart );
		$gone->set_stock_quantity( 0 );
		$gone->set_stock_status( 'outofstock' );
		$gone->save();

		$this->fresh_session();
		$location = $this->open( $url );

		$this->assertSame( wc_get_checkout_url(), $location );
		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Teapot', $items[0]['data']->get_name() );
		$this->assertSame( 2, $items[0]['quantity'] );
		$this->assertStringContainsString( 'Saucer', wp_json_encode( wc_get_notices() ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	private function open( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
		try {
			Carts::handle_recovery();
		} catch ( Mailtonic_Redirect_Exception $e ) {
			return $e->location;
		} finally {
			$_GET = array();
		}
		return null;
	}

	/** 92 */
	public function test_bad_links_redirect_to_the_cart_with_the_expired_message() {
		$this->listFields( array( 'email' ) );
		$cart = $this->tracked();
		$good = Carts::recovery_url( $cart );
		$bad  = array(
			preg_replace( '/sig=[a-f0-9]+/', 'sig=' . str_repeat( 'a', 64 ), $good ),
			str_replace( '&v=' . $cart['version'], '&v=' . ( $cart['version'] + 5 ), $good ),
		);
		$this->fresh_session();
		$keep = $this->product( '1.00', 'Kept' );
		WC()->cart->add_to_cart( $keep->get_id(), 1 );
		foreach ( $bad as $url ) {
			wc_clear_notices();
			$this->assertSame( wc_get_cart_url(), $this->open( $url ) );
			$this->assertStringContainsString( 'This cart link has expired.', wp_json_encode( wc_get_notices() ) );
			$this->assertSame( 'Kept', array_values( WC()->cart->get_cart() )[0]['data']->get_name() );
		}

		$GLOBALS['wpdb']->update( Carts::table(), array( 'completed_order_id' => 99 ), array( 'id' => $cart['id'] ) );
		$this->assertSame( wc_get_cart_url(), $this->open( $good ) );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	/** 93 */
	public function test_order_completes_the_cart() {
		$this->listFields( array( 'email' ) );
		$cart  = $this->tracked( 'buyer@example.com' );
		$order = $this->paidOrder( 'buyer@example.com', true );
		$this->assertSame( $order->get_id(), (int) Carts::find( $cart['id'] )['completed_order_id'] );
		$this->runCartSweep( time() + 2 * HOUR_IN_SECONDS );
		$this->assertCount( 0, Outbox::find( array( 'kind' => 'event', 'reference' => 'Cart ' . $cart['token'] ) ) );
		foreach ( Outbox::find( array( 'kind' => 'event' ) ) as $e ) {
			$this->assertSame( 'order_placed', json_decode( $e['body'], true )['name'] );
		}
	}

	/** 93 (same session, other email) */
	public function test_order_from_the_same_session_completes_the_cart() {
		$this->listFields( array( 'email' ) );
		$cart  = $this->tracked( 'first@example.com' );
		$order = $this->checkout( 'second@example.com', true );
		$order->update_status( 'processing' );
		$this->assertSame( $order->get_id(), (int) Carts::find( $cart['id'] )['completed_order_id'] );
	}

	/** 94 */
	public function test_unticking_clears_the_email() {
		$cart = $this->tracked();
		$this->contact( 'cart@example.com', false );
		$this->assertNull( Carts::find( $cart['id'] )['email'] );
	}

	/** 95 */
	public function test_prune() {
		$cart = $this->tracked();
		$this->age( $cart, 31 * DAY_IN_SECONDS );
		$this->runPrune();
		$this->assertNull( Carts::find( $cart['id'] ) );
	}
}
