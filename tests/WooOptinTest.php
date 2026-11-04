<?php
/**
 * B5. WooCommerce opt-in box and sign-up (tests 65-72).
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;
use Mailtonic\Woo;

/**
 * @group woocommerce
 */
class WooOptinTest extends Mailtonic_TestCase {

	const LABEL = 'Email me news and offers from Acme Shop. I can unsubscribe at any time.';

	public function set_up() {
		parent::set_up();
		$this->requireWoo();
	}

	private function classic_box() {
		do_action( 'woocommerce_before_checkout_billing_form', WC()->checkout() );
		$html = woocommerce_form_field( 'billing_email', array( 'type' => 'email', 'label' => 'Email', 'return' => true ), '' );
		do_action( 'woocommerce_after_checkout_billing_form', WC()->checkout() );
		return $html;
	}

	private function signups() {
		return Outbox::find( array( 'kind' => 'signup' ) );
	}

	/** 65 */
	public function test_box_is_unticked_labelled_and_after_the_email_field() {
		$this->connect();
		$html = $this->classic_box();
		$this->assertMatchesRegularExpression( '#name="billing_email".*name="mailtonic_optin"#s', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*type="checkbox"[^>]*name="mailtonic_optin"#', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="mailtonic_optin"[^>]*checked#', $html );
		$this->assertStringContainsString( self::LABEL, $html );
		$this->assertStringNotContainsString( 'mailtonic_optin', woocommerce_form_field( 'billing_email', array( 'type' => 'email', 'return' => true ), '' ) );

		Woo::register_block_field(); // What woocommerce_init does on every connected request.
		$fields = Automattic\WooCommerce\Blocks\Package::container()->get( Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class )->get_additional_fields();
		$this->assertArrayHasKey( 'mailtonic/optin', $fields );
		$this->assertSame( 'checkbox', $fields['mailtonic/optin']['type'] );
		$this->assertSame( 'contact', $fields['mailtonic/optin']['location'] );
		$this->assertSame( self::LABEL, $fields['mailtonic/optin']['label'] );
		$this->assertFalse( $fields['mailtonic/optin']['required'] );
		$this->assertArrayNotHasKey( 'checked', $fields['mailtonic/optin']['attributes'] );
	}

	/** 66 */
	public function test_opted_in_customer_sees_no_box() {
		$user = $this->loginAs( 'customer' );
		update_user_meta( $user, Woo::META, array( 'at' => time(), 'text' => self::LABEL, 'ip' => '1.2.3.4', 'ua' => 'UA', 'page' => 'checkout' ) );
		$this->assertStringNotContainsString( 'mailtonic_optin', $this->classic_box() );
	}

	/** 67 */
	public function test_tick_records_the_register_on_order_and_user() {
		$this->connect();
		$user  = $this->loginAs( 'customer' );
		$order = $this->checkout( 'buyer@example.com', true );

		$entry = $order->get_meta( Woo::META );
		$this->assertSame( self::LABEL, $entry['text'] );
		$this->assertSame( '203.0.113.9', $entry['ip'] );
		$this->assertSame( 'UA test', $entry['ua'] );
		$this->assertSame( 'checkout', $entry['page'] );
		$this->assertEqualsWithDelta( time(), $entry['at'], 5 );
		$this->assertSame( $entry, get_user_meta( $user, Woo::META, true ) );

		ob_start();
		do_action( 'woocommerce_admin_order_data_after_billing_address', $order );
		$this->assertStringContainsString( 'Marketing opt-in: yes', wp_strip_all_tags( ob_get_clean() ) );
	}

	/** 67 (block) */
	public function test_block_checkout_tick_records_the_register() {
		$this->connect();
		$order = $this->checkout( 'block@example.com', true, true );
		$this->assertSame( 'checkout', $order->get_meta( Woo::META )['page'] );
		$this->assertSame( self::LABEL, $order->get_meta( Woo::META )['text'] );

		$order = $this->checkout( 'block2@example.com', false, true );
		$this->assertSame( '', $order->get_meta( Woo::META ) );
	}

	/** 68 */
	public function test_no_tick_records_nothing_and_keeps_an_earlier_entry() {
		$this->connect();
		$user    = $this->loginAs( 'customer' );
		$earlier = array( 'at' => 1700000000, 'text' => 'Old label', 'ip' => '1.2.3.4', 'ua' => 'UA', 'page' => 'checkout' );
		update_user_meta( $user, Woo::META, $earlier );
		$order = $this->checkout( 'buyer@example.com', false );
		$this->assertSame( '', $order->get_meta( Woo::META ) );
		$this->assertSame( $earlier, get_user_meta( $user, Woo::META, true ) );
		ob_start();
		do_action( 'woocommerce_admin_order_data_after_billing_address', $order );
		$this->assertStringContainsString( 'Marketing opt-in: no', wp_strip_all_tags( ob_get_clean() ) );
	}

	/** 69 */
	public function test_paid_opted_in_order_queues_a_signup() {
		$this->connect();
		$this->listFields( array( 'email', 'first_name', 'last_name', 'phone', 'city', 'country' ) );
		$order = $this->paidOrder( 'buyer@example.com', true, 'processing' );

		$rows = $this->signups();
		$this->assertCount( 1, $rows );
		$this->assertSame( '/lists/L1/signups', $rows[0]['path'] );
		$this->assertSame( 'woocommerce', $rows[0]['source'] );
		$body = json_decode( $rows[0]['body'], true );
		$this->assertSame( 'buyer@example.com', $body['email'] );
		$this->assertSame(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'Lee',
				'phone'      => '+15550100',
				'city'       => 'Pune',
				'country'    => 'IN',
			),
			$body['fields']
		);
		$this->assertSame( array( 'woocommerce-customer' ), $body['tags'] );
		$entry = $order->get_meta( Woo::META );
		$this->assertSame( $entry['text'], $body['consent']['text'] );
		$this->assertSame( gmdate( 'c', $entry['at'] ), $body['consent']['given_at'] );
		$this->assertSame( '203.0.113.9', $body['consent']['ip'] );
		$this->assertSame( 'UA test', $body['consent']['user_agent'] );
		$this->assertSame( wc_get_checkout_url(), $body['consent']['page_url'] );
		$this->assertSame(
			array(
				'integration' => 'wordpress',
				'plugin'      => 'woocommerce',
				'form_ref'    => 'checkout',
				'site_url'    => home_url(),
			),
			$body['source']
		);
	}

	/** 70 */
	public function test_paid_statuses_only_and_once() {
		$this->connect();
		$this->listFields( array( 'email' ) );
		$this->paidOrder( 'p@example.com', true, 'pending' );
		$this->paidOrder( 'f@example.com', true, 'failed' );
		$this->assertCount( 0, $this->signups() );

		$this->paidOrder( 'h@example.com', true, 'on-hold' );
		$this->paidOrder( 'c@example.com', true, 'completed' );
		$this->assertCount( 2, $this->signups() );

		$order = $this->paidOrder( 'pc@example.com', true, 'processing' );
		$order->update_status( 'completed' );
		$this->assertCount( 3, $this->signups() );
	}

	/** 71 */
	public function test_non_opted_in_buyer_is_never_sent() {
		$this->connect();
		$this->listFields( array( 'email' ) );
		$this->paidOrder( 'quiet@example.com', false, 'processing' );
		$this->assertCount( 0, Outbox::find( array() ) );
		do_action( 'shutdown' );
		$this->runOutbox();
		foreach ( $this->http->requests() as $r ) {
			$this->assertStringNotContainsString( 'quiet@example.com', $r['url'] . wp_json_encode( $r['body'] ) );
		}
	}

	/** 72 */
	public function test_registration_with_the_tick_signs_up() {
		$this->connect();
		$this->listFields( array( 'email', 'first_name' ) );
		\Mailtonic\Settings::update_wc( array( 'registration_box' => true ) );

		ob_start();
		do_action( 'woocommerce_register_form' );
		$this->assertStringContainsString( 'name="mailtonic_optin"', ob_get_clean() );

		$_POST['mailtonic_optin']   = '1';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
		$_SERVER['HTTP_USER_AGENT'] = 'UA reg';
		$user                       = wc_create_new_customer( 'reg@example.com', 'reg', wp_generate_password() );
		$_POST                      = array();
		$this->assertIsInt( $user );
		$this->assertSame( 'registration', get_user_meta( $user, Woo::META, true )['page'] );

		$rows = $this->signups();
		$this->assertCount( 1, $rows );
		$body = json_decode( $rows[0]['body'], true );
		$this->assertSame( 'reg@example.com', $body['email'] );
		$this->assertSame( 'registration', $body['source']['form_ref'] );
		$this->assertSame( '203.0.113.10', $body['consent']['ip'] );
		$this->assertArrayNotHasKey( 'fields', $body );
	}
}
