<?php
/**
 * Base test case and the helpers of WORDPRESS.TESTS.md (tests/Support).
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;
use Mailtonic\Settings;

/**
 * Base class for every plugin test.
 */
abstract class Mailtonic_TestCase extends WP_UnitTestCase {
	const KEY      = 'mt_abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';
	const APP      = 'https://app.mailtonic.test';
	const API      = '/api/v2';
	const FORM_UID = '01HZY3K5V7W9X1Y3Z5A7B9C1D3';

	/**
	 * The HTTP fake.
	 *
	 * @var Mailtonic_Fake
	 */
	protected $http;

	public function set_up() {
		parent::set_up();
		$this->http = new Mailtonic_Fake();
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Mailtonic_Redirect_Exception( $location );
			}
		);
		\Mailtonic\Forms::reset_printed();
		remove_all_actions( 'shutdown' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	public function tear_down() {
		remove_all_actions( 'shutdown' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/** Answers for GET /me. */
	protected function me( $role = 'owner', $access = 'write' ) {
		return array(
			'user'     => array(
				'id'    => 'U1',
				'name'  => 'Asha',
				'email' => 'asha@acme.test',
			),
			'customer' => array(
				'uid'  => 'C1',
				'name' => 'Acme Ltd',
			),
			'role'     => array(
				'key'  => $role,
				'name' => ucfirst( $role ),
			),
			'api_key'  => array(
				'name'   => 'WordPress shop',
				'access' => $access,
			),
		);
	}

	/** A form object as GET /forms returns it. */
	protected function formObject( $uid = self::FORM_UID, $kind = 'inline', $status = 'published', $origins = array(), $name = 'Footer signup' ) {
		return array(
			'uid'             => $uid,
			'name'            => $name,
			'kind'            => $kind,
			'status'          => $status,
			'list_uid'        => 'L1',
			'allowed_origins' => $origins,
			'published_at'    => 'published' === $status ? '2026-10-01T10:00:00Z' : null,
			'created_at'      => '2026-09-01T10:00:00Z',
			'updated_at'      => '2026-10-02T10:00:00Z',
			'embed'           => array(
				'script_url' => 'https://t.acme.test/p/forms/embed.js',
				'script'     => '<script async src="https://t.acme.test/p/forms/embed.js" data-mt-form="' . $uid . '"></script>',
			),
		);
	}

	/** Scripts GET /forms. */
	protected function scriptForms( array $forms ) {
		$this->http->only( 'GET', self::API . '/forms', 200, array( 'data' => $forms, 'next_cursor' => null ) );
	}

	/** Matches one embed script element for a uid. */
	protected function embedPattern( $uid, $src = 'https://t.acme.test/p/forms/embed.js' ) {
		return '#<script\b(?=[^>]*\basync\b)(?=[^>]*\bsrc="' . preg_quote( $src, '#' ) . '")(?=[^>]*\bdata-mt-form="' . $uid . '")[^>]*></script>#';
	}

	/** Saves a valid connection to https://app.mailtonic.test with default list L1. */
	protected function connect( $role = 'owner', $access = 'write' ) {
		$this->http->only( 'GET', self::API . '/me', 200, $this->me( $role, $access ) );
		$this->http->only(
			'GET',
			self::API . '/lists',
			200,
			array(
				'data'        => array(
					array(
						'uid'    => 'L1',
						'name'   => 'Newsletter',
						'counts' => array( 'total' => 3 ),
					),
					array(
						'uid'    => 'L2',
						'name'   => 'Customers',
						'counts' => array( 'total' => 1 ),
					),
				),
				'next_cursor' => null,
			)
		);
		$result = \Mailtonic\Connection::connect(
			array(
				'app_url'      => self::APP,
				'api_key'      => self::KEY,
				'default_list' => 'L1',
			)
		);
		$this->assertTrue( $result['ok'], implode( ' ', $result['errors'] ) );
		$this->http->reset();
		return $result;
	}

	/** Scripts GET /lists/L1/fields with these keys. */
	protected function listFields( array $keys, $list = 'L1' ) {
		$types = array(
			'wc_orders_count'      => 'number',
			'wc_total_spent'       => 'number',
			'wc_last_order_at'     => 'datetime',
			'wc_cart_total'        => 'number',
			'email'                => 'email',
		);
		$data  = array();
		foreach ( $keys as $i => $key ) {
			$data[] = array(
				'uid'      => 'F' . $i,
				'key'      => $key,
				'label'    => ucwords( str_replace( '_', ' ', $key ) ),
				'type'     => isset( $types[ $key ] ) ? $types[ $key ] : 'text',
				'required' => 'email' === $key,
				'position' => $i,
			);
		}
		delete_transient( 'mailtonic_fields_' . $list );
		$this->http->only( 'GET', self::API . "/lists/{$list}/fields", 200, array( 'data' => $data ) );
	}

	protected function runOutbox( $now = null ) {
		Outbox::run( $now );
	}

	protected function runCartSweep( $now = null ) {
		\Mailtonic\Carts::sweep( $now );
	}

	protected function runPrune( $now = null ) {
		Outbox::prune( $now );
		\Mailtonic\Carts::prune( $now );
	}

	/** Outbox rows, oldest first. */
	protected function entries( $where = array() ) {
		return Outbox::find( $where );
	}

	/** Logs in a user with a role. */
	protected function loginAs( $role = 'administrator' ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Posts an admin form to admin-post.php?action=$action with a valid nonce (unless $nonce is false).
	 * Returns the redirect location, or null.
	 */
	protected function postAdmin( $action, array $fields = array(), $nonce = true ) {
		if ( ! current_user_can( 'manage_options' ) && ! wp_get_current_user()->exists() ) {
			$this->loginAs( 'administrator' );
		}
		$_POST = $fields;
		if ( $nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( $action );
		}
		$_POST['action'] = $action;
		$_REQUEST        = $_POST;
		try {
			do_action( 'admin_post_' . $action );
		} catch ( Mailtonic_Redirect_Exception $e ) {
			return $e->location;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return null;
	}

	/** Renders a plugin admin page and returns its HTML. */
	protected function renderPage( $page ) {
		set_current_screen( 'toplevel_page_mailtonic' );
		ob_start();
		\Mailtonic\Admin::render( $page );
		$html = ob_get_clean();
		set_current_screen( 'front' );
		return $html;
	}

	/** Notices waiting for the current user, as one string. */
	protected function notices() {
		ob_start();
		\Mailtonic\Admin::print_notices();
		return ob_get_clean();
	}

	// ----- WooCommerce helpers.

	protected function requireWoo() {
		if ( ! MAILTONIC_TESTS_WC ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
	}

	protected function product( $price = '10.00', $name = 'Blue mug', $stock = null ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
		}
		$product->save();
		return $product;
	}

	/**
	 * A WooCommerce order through WooCommerce's API, moved to $status so its hooks fire.
	 * With $optIn the order carries an opt-in register entry as the checkout box records it.
	 */
	protected function paidOrder( $email = 'buyer@example.com', $optIn = true, $status = 'processing', $paidAt = null, $items = null, $user_id = 0 ) {
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		foreach ( null === $items ? array( array( $this->product(), 1 ) ) : $items as $item ) {
			$order->add_product( $item[0], $item[1] );
		}
		$order->set_billing_email( $email );
		$order->set_billing_first_name( 'Ana' );
		$order->set_billing_last_name( 'Lee' );
		$order->set_billing_phone( '+15550100' );
		$order->set_billing_city( 'Pune' );
		$order->set_billing_country( 'IN' );
		$order->set_customer_ip_address( '203.0.113.9' );
		$order->set_customer_user_agent( 'UA test' );
		$order->calculate_totals();
		if ( $optIn ) {
			\Mailtonic\Woo::record_optin( $order, 'checkout', time() - 60 );
		}
		if ( null !== $paidAt ) {
			$order->set_date_paid( $paidAt );
		}
		$order->save();
		if ( 'pending' !== $status ) {
			$order->update_status( $status );
		}
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Submits the classic checkout (through WC_Checkout::create_order and its hooks) or the
	 * checkout block's Store API order update, with or without the opt-in tick. Returns the order.
	 */
	protected function checkout( $email = 'buyer@example.com', $tick = true, $block = false ) {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = 'UA test';
		if ( $block ) {
			$order = wc_create_order( array( 'customer_id' => get_current_user_id() ) );
			$order->set_billing_email( $email );
			$order->set_customer_ip_address( '203.0.113.9' );
			$order->set_customer_user_agent( 'UA test' );
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
			$request->set_param( 'additional_fields', array( 'mailtonic/optin' => $tick ) );
			do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $request );
			$order->save();
			return wc_get_order( $order->get_id() );
		}
		if ( $tick ) {
			$_POST['mailtonic_optin'] = '1';
		}
		$id = WC()->checkout()->create_order(
			array(
				'billing_email'      => $email,
				'billing_first_name' => 'Ana',
				'billing_last_name'  => 'Lee',
				'payment_method'     => '',
			)
		);
		$_POST = array();
		$this->assertIsInt( $id );
		return wc_get_order( $id );
	}
}
