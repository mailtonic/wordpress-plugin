<?php
/**
 * B6. WooCommerce order events and stats (tests 73-80).
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;

/**
 * @group woocommerce
 */
class WooOrderTest extends Mailtonic_TestCase {

	public function set_up() {
		parent::set_up();
		$this->requireWoo();
		$this->connect();
	}

	private function events() {
		return Outbox::find( array( 'kind' => 'event' ) );
	}

	private function body( $row ) {
		return json_decode( $row['body'], true );
	}

	/** 73 */
	public function test_event_is_sent_after_the_signup() {
		$this->listFields( array( 'email' ) );
		$order = $this->paidOrder( 'buyer@example.com', true, 'processing', null, array( array( $this->product( '12.50', 'Blue mug' ), 2 ) ) );

		$events = $this->events();
		$this->assertCount( 1, $events );
		$body = $this->body( $events[0] );
		$this->assertSame( 'order_placed', $body['name'] );
		$this->assertSame( 'buyer@example.com', $body['email'] );
		$this->assertSame( 'L1', $body['list_uid'] );
		$p = $body['properties'];
		$this->assertSame( (string) $order->get_order_number(), $p['order_number'] );
		$this->assertSame( '25.00', $p['order_total'] );
		$this->assertSame( get_woocommerce_currency(), $p['currency'] );
		$this->assertSame( 2, $p['items_count'] );
		$this->assertSame( 'Blue mug', $p['first_item_name'] );
		$this->assertSame( 'woocommerce', $p['store'] );
		$this->assertSame( home_url(), $p['site_url'] );
		$this->assertArrayNotHasKey( 'order_url', $p );

		$this->http->on( 'POST', self::API . '/lists/L1/signups', 503, array( 'error' => array( 'code' => 'x', 'message' => 'x', 'request_id' => null ) ) );
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 201, array( 'status' => 'subscribed' ) );
		$this->http->on( 'POST', self::API . '/events', 202, array( 'recorded' => 1 ) );
		$this->runOutbox();
		$this->assertCount( 0, $this->http->requests( 'POST', self::API . '/events' ) );
		$this->runOutbox( time() + 61 );
		$paths = array_column( $this->http->requests( 'POST' ), 'path' );
		$this->assertSame( array( self::API . '/lists/L1/signups', self::API . '/lists/L1/signups', self::API . '/events' ), $paths );
	}

	/** 73 (logged-in buyer gets the order link) */
	public function test_logged_in_buyer_event_carries_the_order_url() {
		$this->listFields( array( 'email' ) );
		$user  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order = $this->paidOrder( 'member@example.com', true, 'processing', null, null, $user );
		$this->assertSame( $order->get_view_order_url(), $this->body( $this->events()[0] )['properties']['order_url'] );
	}

	/** 74 */
	public function test_known_buyer_from_an_earlier_order() {
		$this->listFields( array( 'email' ) );
		$this->paidOrder( 'guest@example.com', true );
		$this->paidOrder( 'guest@example.com', false );
		$this->assertCount( 2, $this->events() );
		$this->assertCount( 1, Outbox::find( array( 'kind' => 'signup' ) ) );
	}

	/** 75 */
	public function test_once_per_order() {
		$this->listFields( array( 'email' ) );
		$order = $this->paidOrder( 'buyer@example.com', true );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );
		$order->update_status( 'completed' );
		$this->assertCount( 1, $this->events() );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Outbox::table() . ' WHERE created_at < %d', time() + 1 ) ); // Outbox pruned, 25 hours on.
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'on-hold' );
		$order->update_status( 'processing' );
		$this->assertCount( 0, Outbox::find( array() ) );
	}

	/** 76 */
	public function test_old_paid_time_sends_stats_only() {
		$this->listFields( array( 'email', 'wc_orders_count' ) );
		$this->paidOrder( 'late@example.com', true, 'processing', time() - 3 * DAY_IN_SECONDS );
		$this->assertCount( 0, $this->events() );
		$this->assertCount( 1, Outbox::find( array( 'kind' => 'contact_update' ) ) );
	}

	/** 77 */
	public function test_properties_are_clean() {
		$this->listFields( array( 'email' ) );
		$codes = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( 'spring-sale-code-' . $i );
			$coupon->set_amount( 0.1 );
			$coupon->save();
			$codes[] = $coupon->get_code();
		}
		add_filter(
			'mailtonic_order_placed_properties',
			static function ( $props ) {
				$props['order_url'] = 'javascript:alert(1)';
				for ( $i = 0; $i < 25; $i++ ) {
					$props[ 'extra_' . $i ] = 'x';
				}
				return $props;
			}
		);
		$product = $this->product( '50.00', "<b>Blue</b>\n mug   with\tlid" . str_repeat( 'x', 300 ) );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_email( 'clean@example.com' );
		foreach ( $codes as $code ) {
			$order->apply_coupon( $code );
		}
		$order->calculate_totals();
		\Mailtonic\Woo::record_optin( $order, 'checkout' );
		$order->save();
		$order->update_status( 'processing' );

		$p = $this->body( $this->events()[0] )['properties'];
		$this->assertStringStartsWith( 'Blue mug with lid', $p['first_item_name'] );
		$this->assertSame( 255, mb_strlen( $p['first_item_name'] ) );
		$this->assertLessThanOrEqual( 255, strlen( $p['coupon_codes'] ) );
		$this->assertStringStartsWith( 'spring-sale-code-0,', $p['coupon_codes'] );
		$this->assertArrayNotHasKey( 'order_url', $p );
		$this->assertLessThanOrEqual( 20, count( $p ) );
		$this->assertArrayHasKey( 'order_number', $p );
	}

	/** 78 */
	public function test_fields_action_creates_missing_fields() {
		$this->loginAs();
		$this->listFields( array( 'email', 'first_name' ) );
		$this->http->on( 'POST', self::API . '/lists/L1/fields', 201, array( 'uid' => 'F9' ) );
		$this->postAdmin( 'mailtonic_wc_fields' );
		$posts = $this->http->requests( 'POST', self::API . '/lists/L1/fields' );
		$this->assertSame(
			array(
				array( 'key' => 'wc_orders_count', 'label' => 'Orders', 'type' => 'number' ),
				array( 'key' => 'wc_total_spent', 'label' => 'Total spent', 'type' => 'number' ),
				array( 'key' => 'wc_last_order_at', 'label' => 'Last order', 'type' => 'datetime' ),
				array( 'key' => 'wc_last_order_number', 'label' => 'Last order number', 'type' => 'text' ),
				array( 'key' => 'wc_cart_url', 'label' => 'Cart link', 'type' => 'text' ),
				array( 'key' => 'wc_cart_total', 'label' => 'Cart total', 'type' => 'number' ),
			),
			array_column( $posts, 'body' )
		);

		$this->http->reset();
		$this->listFields( array( 'email', 'wc_orders_count' ) );
		$this->postAdmin( 'mailtonic_wc_fields' );
		$this->assertCount( 5, $this->http->requests( 'POST', self::API . '/lists/L1/fields' ) );
	}

	/** 79 */
	public function test_stats_update_writes_only_existing_fields() {
		$this->listFields( array( 'email', 'wc_orders_count', 'wc_total_spent' ) );
		$this->paidOrder( 'stats@example.com', true, 'processing', null, array( array( $this->product( '10.00' ), 1 ) ) );
		$this->paidOrder( 'stats@example.com', false, 'completed', null, array( array( $this->product( '15.50' ), 1 ) ) );

		$updates = Outbox::find( array( 'kind' => 'contact_update' ) );
		$this->assertCount( 2, $updates );
		$last = end( $updates );
		$this->assertSame( 'PATCH', $last['method'] );
		$this->assertSame( '/lists/L1/contacts/' . rawurlencode( 'stats@example.com' ), $last['path'] );
		$this->assertSame( array( 'fields' => array( 'wc_orders_count' => 2, 'wc_total_spent' => 25.5 ) ), $this->body( $last ) );
	}

	/** 80 */
	public function test_refunds_send_nothing() {
		$this->listFields( array( 'email' ) );
		$order = $this->paidOrder( 'refund@example.com', true );
		$count = count( Outbox::find( array() ) );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 1 ) );
		wc_get_order( $order->get_id() )->update_status( 'refunded' );
		$this->assertCount( $count, Outbox::find( array() ) );
	}
}
