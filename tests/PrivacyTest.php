<?php
/**
 * B9. Privacy tools (tests 96-97).
 *
 * @package Mailtonic
 */

use Mailtonic\Carts;
use Mailtonic\Outbox;

/**
 * @group woocommerce
 */
class PrivacyTest extends Mailtonic_TestCase {

	const EMAIL = 'ana@example.com';

	public function set_up() {
		parent::set_up();
		$this->requireWoo();
		$this->connect();
		$this->listFields( array( 'email' ) );
	}

	private function contacts() {
		$contact = static function ( $uid, $list ) {
			return array(
				'uid'      => $uid,
				'list_uid' => $list,
				'email'    => self::EMAIL,
				'status'   => 'subscribed',
				'fields'   => array( 'first_name' => 'Ana' ),
				'tags'     => array( 'wp' ),
				'consent'  => array( 'given_at' => '2026-10-01T10:00:00Z', 'source' => 'form', 'ip' => '203.0.113.9', 'opt_in_confirmed_at' => null ),
			);
		};
		$this->http->on( 'GET', self::API . '/contacts', 200, array( 'data' => array( $contact( 'K1', 'L1' ), $contact( 'K2', 'L2' ) ), 'next_cursor' => null ) );
	}

	private function seed() {
		$order = $this->paidOrder( self::EMAIL, true );
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		Carts::set_contact( self::EMAIL, true );
		return $order;
	}

	private function exporter() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		return $exporters['mailtonic']['callback'];
	}

	private function eraser() {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		return $erasers['mailtonic']['callback'];
	}

	/** 96 */
	public function test_exporter() {
		$order = $this->seed();
		$this->contacts();
		$result = call_user_func( $this->exporter(), self::EMAIL, 1 );
		$this->assertTrue( $result['done'] );
		$groups = array_count_values( array_column( $result['data'], 'group_id' ) );
		$this->assertSame( 1, $groups['mailtonic-optins'] );
		$this->assertSame( 1, $groups['mailtonic-carts'] );
		$this->assertSame( 2, $groups['mailtonic-contacts'] );
		$this->assertStringContainsString( 'email=' . rawurlencode( self::EMAIL ), $this->http->requests( 'GET', self::API . '/contacts' )[0]['query'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), wp_json_encode( $result['data'] ) );
		$this->assertStringContainsString( 'subscribed', wp_json_encode( $result['data'] ) );
	}

	/** 97 */
	public function test_eraser() {
		$order = $this->seed();
		$this->contacts();
		$this->http->on( 'DELETE', self::API . '/lists/L1/contacts/K1', 204 );
		$this->http->on( 'DELETE', self::API . '/lists/L2/contacts/K2', 204 );
		$this->assertNotEmpty( Outbox::find( array( 'status' => 'pending' ) ) );

		$result = call_user_func( $this->eraser(), self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( \Mailtonic\Woo::META ) );
		$this->assertNull( Carts::current() );
		$this->assertCount( 0, Outbox::find( array( 'status' => 'pending' ) ) );
		$this->assertCount( 2, $this->http->requests( 'DELETE' ) );
	}

	/** 97 (unreachable) */
	public function test_eraser_reports_contacts_retained_when_mailtonic_is_unreachable() {
		$this->seed();
		$this->http->on( 'GET', self::API . '/contacts', 'timeout' );
		$result = call_user_func( $this->eraser(), self::EMAIL, 1 );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( "couldn't reach mailtonic", html_entity_decode( implode( ' ', $result['messages'] ), ENT_QUOTES ) );
	}
}
