<?php
/**
 * WooCommerce sync: the opt-in box and register, sign-ups of opted-in buyers, order events and
 * stats fields, and the past-customer import.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce sync.
 */
final class Woo {
	const META       = '_mailtonic_optin';
	const PLACED     = '_mailtonic_placed';
	const SIGNUP     = '_mailtonic_signup';
	const CART       = '_mailtonic_cart_token';
	const PAID       = array( 'processing', 'completed', 'on-hold' );
	const STATEMENT  = 'Everyone on this list agreed to receive email from me, and I can show when and how.';
	const SOURCES    = array( 'customers', 'website_form', 'in_person', 'other_opt_in' );
	const BLOCK_ID   = 'mailtonic/optin';
	const BATCH_ROWS = 1000;

	/**
	 * Whether the classic billing form is being printed.
	 *
	 * @var bool
	 */
	private static $in_billing = false;

	/**
	 * Hooks, once all plugins are loaded and only with WooCommerce.
	 */
	public static function init() {
		if ( ! Integrations::active( 'woocommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_init', array( self::class, 'register_block_field' ) );
		add_action(
			'woocommerce_before_checkout_billing_form',
			static function () {
				self::$in_billing = true;
			}
		);
		add_action(
			'woocommerce_after_checkout_billing_form',
			static function () {
				self::$in_billing = false;
			}
		);
		add_filter( 'woocommerce_form_field_email', array( self::class, 'after_email_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'classic_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'block_checkout' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'order_screen' ) );
		add_action( 'woocommerce_register_form', array( self::class, 'registration_box' ) );
		add_action( 'woocommerce_created_customer', array( self::class, 'registered' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'status_changed' ), 10, 4 );
		add_action( 'mailtonic_outbox_finished', array( self::class, 'import_progress' ), 10, 3 );
	}

	/**
	 * Declares compatibility with custom order tables and the cart and checkout blocks.
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MAILTONIC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MAILTONIC_FILE, true );
		}
	}

	/**
	 * Whether a part is on: optin, registration_box, signup, events, carts. All need a connection.
	 *
	 * @param string $part Part.
	 * @return bool
	 */
	public static function on( $part ) {
		$wc = Settings::wc();
		return Settings::connected() && ! empty( $wc[ $part ] );
	}

	/**
	 * The opt-in label as shown, with {store} replaced by the site name.
	 *
	 * @return string
	 */
	public static function label() {
		$label = trim( (string) Settings::wc()['label'] );
		if ( '' === $label ) {
			/* translators: {store} is replaced by the site name; keep it as is. */
			$label = __( 'Email me news and offers from {store}. I can unsubscribe at any time.', 'mailtonic' );
		}
		return str_replace( '{store}', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $label );
	}

	/**
	 * Whether the current shopper should see the box (not already opted in).
	 *
	 * @return bool
	 */
	private static function show_box() {
		return self::on( 'optin' ) && ! ( get_current_user_id() && get_user_meta( get_current_user_id(), self::META, true ) );
	}

	/**
	 * The unticked checkbox.
	 *
	 * @return string
	 */
	private static function box() {
		return woocommerce_form_field(
			'mailtonic_optin',
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide', 'mailtonic-optin' ),
				'label'    => esc_html( self::label() ),
				'required' => false,
				'return'   => true,
			),
			0
		);
	}

	/**
	 * Prints the box right after the classic checkout's billing email field.
	 *
	 * @param string $field Field HTML.
	 * @param string $key   Field key.
	 * @return string
	 */
	public static function after_email_field( $field, $key ) {
		return self::$in_billing && 'billing_email' === $key && self::show_box() ? $field . self::box() : $field;
	}

	/**
	 * Registers the box as an additional field of the checkout block, in the contact section.
	 */
	public static function register_block_field() {
		if ( ! self::show_box() ) {
			return;
		}
		$field = array(
			'id'       => self::BLOCK_ID,
			'label'    => self::label(),
			'location' => 'contact',
			'type'     => 'checkbox',
			'required' => false,
		);
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			woocommerce_register_additional_checkout_field( $field );
		} elseif ( function_exists( '__experimental_woocommerce_blocks_register_checkout_field' ) ) {
			__experimental_woocommerce_blocks_register_checkout_field( $field );
		}
	}

	/**
	 * Classic checkout: records a tick and the shopper's tracked cart.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public static function classic_checkout( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		if ( ! empty( $_POST['mailtonic_optin'] ) && self::show_box() ) {
			self::record_optin( $order, 'checkout' );
		}
		self::remember_cart( $order );
	}

	/**
	 * Checkout block (Store API): records a tick and the shopper's tracked cart.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 */
	public static function block_checkout( $order, $request ) {
		$fields = (array) $request->get_param( 'additional_fields' );
		if ( ! empty( $fields[ self::BLOCK_ID ] ) && self::on( 'optin' ) ) {
			self::record_optin( $order, 'checkout' );
		}
		self::remember_cart( $order );
	}

	/**
	 * Stores the session's cart token on the order so its payment completes the cart.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function remember_cart( $order ) {
		$token = Carts::token( false );
		if ( $token ) {
			$order->update_meta_data( self::CART, $token );
		}
	}

	/**
	 * Records an opt-in in the register: on the order (saved by the caller) and on its user.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $page  checkout or registration.
	 * @param int|null  $at    Time of the tick.
	 * @return array The register entry.
	 */
	public static function record_optin( $order, $page, $at = null ) {
		$entry = array(
			'at'   => null === $at ? time() : (int) $at,
			'text' => self::label(),
			'ip'   => $order->get_customer_ip_address() ? $order->get_customer_ip_address() : \WC_Geolocation::get_ip_address(),
			'ua'   => $order->get_customer_user_agent() ? $order->get_customer_user_agent() : wc_get_user_agent(),
			'page' => $page,
		);
		$order->update_meta_data( self::META, $entry );
		if ( $order->get_customer_id() ) {
			update_user_meta( $order->get_customer_id(), self::META, $entry );
		}
		return $entry;
	}

	/**
	 * Shows the opt-in on the order's admin screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_screen( $order ) {
		$entry = $order->get_meta( self::META );
		echo '<p><strong>' . esc_html__( 'Marketing opt-in:', 'mailtonic' ) . '</strong> ';
		if ( $entry ) {
			/* translators: %s: date and time of the tick */
			echo esc_html( sprintf( __( 'yes, %s', 'mailtonic' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['at'] ) ) );
		} else {
			esc_html_e( 'no', 'mailtonic' );
		}
		echo '</p>';
	}

	/**
	 * The box on the My Account registration form, when that setting is on.
	 */
	public static function registration_box() {
		if ( self::on( 'registration_box' ) && self::on( 'optin' ) ) {
			echo self::box(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- woocommerce_form_field() escapes.
		}
	}

	/**
	 * A new account: records a tick and queues its sign-up.
	 *
	 * @param int $user_id New user.
	 */
	public static function registered( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the registration nonce.
		if ( empty( $_POST['mailtonic_optin'] ) || ! self::on( 'registration_box' ) || ! self::on( 'optin' ) ) {
			return;
		}
		$entry = array(
			'at'   => time(),
			'text' => self::label(),
			'ip'   => \WC_Geolocation::get_ip_address(),
			'ua'   => wc_get_user_agent(),
			'page' => 'registration',
		);
		update_user_meta( $user_id, self::META, $entry );
		$user = get_userdata( $user_id );
		if ( self::on( 'signup' ) && $user ) {
			self::queue_signup( $user->user_email, array(), $entry, 'registration', 'user:' . $user_id );
		}
	}

	/**
	 * A sign-up body as N2 takes it.
	 *
	 * @param string $email    Email.
	 * @param array  $fields   Field values.
	 * @param array  $entry    Register entry.
	 * @param string $form_ref checkout or registration.
	 * @param string $key       Idempotency reference.
	 * @param string $reference Reference shown in the sync log.
	 * @return int|false
	 */
	private static function queue_signup( $email, array $fields, array $entry, $form_ref, $key, $reference = '' ) {
		$list = (string) Settings::get( 'default_list', '' );
		if ( '' === $list ) {
			return false;
		}
		$body = array( 'email' => $email );
		if ( $fields ) {
			$body['fields'] = $fields;
		}
		$tags = (array) Settings::wc()['tags'];
		if ( $tags ) {
			$body['tags'] = array_values( $tags );
		}
		$page            = 'registration' === $entry['page'] ? wc_get_page_permalink( 'myaccount' ) : wc_get_checkout_url();
		$body['consent'] = Bridges::consent( $entry['text'], $entry['at'], $entry['ip'], $entry['ua'], $page );
		$body['source']  = array(
			'integration' => 'wordpress',
			'plugin'      => 'woocommerce',
			'form_ref'    => $form_ref,
			'site_url'    => home_url(),
		);
		return Outbox::queue(
			array(
				'kind'      => 'signup',
				'source'    => 'woocommerce',
				'reference' => '' !== $reference ? $reference : __( 'Registration', 'mailtonic' ),
				'method'    => 'POST',
				'path'      => '/lists/' . rawurlencode( $list ) . '/signups',
				'body'      => $body,
				'key'       => $key . ':signup',
			)
		);
	}

	/**
	 * Order status changes: the first paid status sends the order.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Old status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 */
	public static function status_changed( $order_id, $from, $to, $order = null ) {
		if ( in_array( $to, self::PAID, true ) ) {
			self::order_paid( $order ? $order : wc_get_order( $order_id ) );
		}
	}

	/**
	 * Whether a buyer counts as known: this order ticked, or the register holds an opt-in for the
	 * buyer's account or an earlier order with the same billing email.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function known( $order ) {
		if ( $order->get_meta( self::META ) ) {
			return true;
		}
		if ( $order->get_customer_id() && get_user_meta( $order->get_customer_id(), self::META, true ) ) {
			return true;
		}
		// ponytail: loads the buyer's earlier orders; fine for normal order counts per email.
		foreach ( wc_get_orders(
			array(
				'billing_email' => $order->get_billing_email(),
				'limit'         => -1,
				'exclude'       => array( $order->get_id() ),
			)
		) as $earlier ) {
			if ( $earlier->get_meta( self::META ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mapped list fields from an order (only keys the list has).
	 *
	 * @param \WC_Order $order Order.
	 * @param string[]  $keys  List field keys.
	 * @return array
	 */
	private static function mapped( $order, array $keys ) {
		$fields = array();
		foreach ( (array) Settings::wc()['map'] as $key => $prop ) {
			$getter = 'get_' . $prop;
			if ( in_array( $key, $keys, true ) && is_callable( array( $order, $getter ) ) ) {
				$value = trim( (string) $order->$getter() );
				if ( '' !== $value ) {
					$fields[ $key ] = $value;
				}
			}
		}
		return $fields;
	}

	/**
	 * The first paid status of an order: completes its tracked cart, then for known buyers queues
	 * the sign-up (when ticked), the order_placed event (unless older than 2 days) and the stats.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_paid( $order ) {
		if ( ! $order || ! Settings::connected() || $order->get_meta( self::PLACED ) || $order instanceof \WC_Order_Refund ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		Carts::complete( $order );
		$list = (string) Settings::get( 'default_list', '' );
		if ( '' === $list || ! self::known( $order ) ) {
			return;
		}
		$keys   = Lists::field_keys( $list );
		$ref    = (string) $order->get_id();
		$number = '#' . $order->get_order_number();
		$signup = 0;
		$entry  = $order->get_meta( self::META );
		if ( $entry && self::on( 'signup' ) && ! $order->get_meta( self::SIGNUP ) ) {
			$signup = (int) self::queue_signup( $email, self::mapped( $order, $keys ), $entry, 'checkout', $ref, $number );
			$order->update_meta_data( self::SIGNUP, time() );
		}

		$paid = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		if ( self::on( 'events' ) && ( ! $paid || $paid->getTimestamp() >= time() - 2 * DAY_IN_SECONDS ) ) {
			Outbox::queue(
				array(
					'kind'       => 'event',
					'source'     => 'woocommerce',
					'reference'  => $number,
					'method'     => 'POST',
					'path'       => '/events',
					'body'       => array(
						'name'       => 'order_placed',
						'email'      => $email,
						'list_uid'   => $list,
						'properties' => self::order_properties( $order ),
					),
					'key'        => $ref . ':placed',
					'depends_on' => $signup,
				)
			);
		}

		$stats = self::stats( $email, $keys );
		if ( $stats ) {
			Outbox::queue(
				array(
					'kind'       => 'contact_update',
					'source'     => 'woocommerce',
					'reference'  => $number,
					'method'     => 'PATCH',
					'path'       => '/lists/' . rawurlencode( $list ) . '/contacts/' . rawurlencode( $email ),
					'body'       => array( 'fields' => $stats ),
					'key'        => $ref . ':stats',
					'depends_on' => $signup,
				)
			);
		}
		$order->update_meta_data( self::PLACED, time() );
		$order->save();
	}

	/**
	 * Properties of an order_placed event.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private static function order_properties( $order ) {
		$items = $order->get_items();
		$first = reset( $items );
		$props = array(
			'order_number'    => (string) $order->get_order_number(),
			'order_total'     => number_format( (float) $order->get_total(), 2, '.', '' ),
			'currency'        => strtoupper( substr( $order->get_currency(), 0, 3 ) ),
			'items_count'     => (int) array_sum( array_map( static fn( $i ) => $i->get_quantity(), $items ) ),
			'first_item_name' => $first ? $first->get_name() : '',
			'coupon_codes'    => implode( ',', $order->get_coupon_codes() ),
			'order_url'       => $order->get_customer_id() ? $order->get_view_order_url() : '',
			'store'           => 'woocommerce',
			'site_url'        => home_url(),
		);
		/**
		 * Filters the order_placed event's properties before queueing. The 20-key, scalar and
		 * 255-character rules are applied afterwards.
		 *
		 * @param array     $props Properties.
		 * @param \WC_Order $order Order.
		 */
		return self::clean( apply_filters( 'mailtonic_order_placed_properties', $props, $order ) );
	}

	/**
	 * Event properties: at most 20, scalar, strings cleaned of markup, on one line and at most 255
	 * characters; *_url values only when http(s). Empty strings are left out.
	 *
	 * @param array $props Properties.
	 * @return array
	 */
	public static function clean( $props ) {
		$out = array();
		foreach ( (array) $props as $key => $value ) {
			if ( count( $out ) >= 20 ) {
				break;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			if ( is_string( $value ) ) {
				$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
				if ( '' === $value || ( str_ends_with( (string) $key, '_url' ) && ! preg_match( '#^https?://#i', $value ) ) ) {
					continue;
				}
				$value = mb_substr( $value, 0, 255 );
			}
			$out[ sanitize_key( $key ) ] = $value;
		}
		return $out;
	}

	/**
	 * The stats fields the list has, from the buyer's paid orders.
	 *
	 * @param string   $email Billing email.
	 * @param string[] $keys  List field keys.
	 * @return array
	 */
	private static function stats( $email, array $keys ) {
		$wanted = array_intersect( array( 'wc_orders_count', 'wc_total_spent', 'wc_last_order_at', 'wc_last_order_number' ), $keys );
		if ( ! $wanted ) {
			return array();
		}
		$agg = array();
		foreach ( wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => self::PAID,
				'limit'         => -1,
			)
		) as $order ) {
			self::aggregate( $agg, $order );
		}
		return array_intersect_key( self::stat_fields( reset( $agg ) ), array_flip( $wanted ) );
	}

	/**
	 * Adds an order to per-email stats.
	 *
	 * @param array     $agg   Stats by email.
	 * @param \WC_Order $order Order.
	 */
	private static function aggregate( array &$agg, $order ) {
		$email = strtolower( $order->get_billing_email() );
		$date  = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$time  = $date ? $date->getTimestamp() : 0;
		if ( ! isset( $agg[ $email ] ) ) {
			$agg[ $email ] = array(
				'count'  => 0,
				'sum'    => 0.0,
				'last'   => -1,
				'number' => '',
				'order'  => $order,
			);
		}
		++$agg[ $email ]['count'];
		$agg[ $email ]['sum'] += (float) $order->get_total();
		if ( $time > $agg[ $email ]['last'] ) {
			$agg[ $email ]['last']   = $time;
			$agg[ $email ]['number'] = (string) $order->get_order_number();
			$agg[ $email ]['order']  = $order;
		}
	}

	/**
	 * Stats as list field values.
	 *
	 * @param array|false $stats Aggregated stats.
	 * @return array
	 */
	private static function stat_fields( $stats ) {
		if ( ! $stats ) {
			return array();
		}
		return array(
			'wc_orders_count'      => $stats['count'],
			'wc_total_spent'       => round( $stats['sum'], 2 ),
			'wc_last_order_at'     => gmdate( 'c', max( 0, $stats['last'] ) ),
			'wc_last_order_number' => $stats['number'],
		);
	}

	/**
	 * The fields the "Add WooCommerce fields to my list" action creates.
	 *
	 * @return array key => [label, type]
	 */
	public static function stat_definitions() {
		return array(
			'wc_orders_count'      => array( __( 'Orders', 'mailtonic' ), 'number' ),
			'wc_total_spent'       => array( __( 'Total spent', 'mailtonic' ), 'number' ),
			'wc_last_order_at'     => array( __( 'Last order', 'mailtonic' ), 'datetime' ),
			'wc_last_order_number' => array( __( 'Last order number', 'mailtonic' ), 'text' ),
			'wc_cart_url'          => array( __( 'Cart link', 'mailtonic' ), 'text' ),
			'wc_cart_total'        => array( __( 'Cart total', 'mailtonic' ), 'number' ),
		);
	}

	/**
	 * Creates the WooCommerce fields the default list does not have yet (POST /lists/{list}/fields).
	 *
	 * @return array { ok, errors, warnings, created }
	 */
	public static function create_fields() {
		$list = (string) Settings::get( 'default_list', '' );
		$keys = Lists::field_keys( $list, true );
		if ( '' === $list || ! Settings::connected() ) {
			return array(
				'ok'       => false,
				'errors'   => array( __( 'Connect mailtonic and choose a default list first.', 'mailtonic' ) ),
				'warnings' => array(),
				'created'  => 0,
			);
		}
		$created = 0;
		$errors  = array();
		foreach ( self::stat_definitions() as $key => $def ) {
			if ( in_array( $key, $keys, true ) ) {
				continue;
			}
			$response = Api::request(
				'POST',
				'/lists/' . rawurlencode( $list ) . '/fields',
				array(
					'key'   => $key,
					'label' => $def[0],
					'type'  => $def[1],
				),
				'wp:' . Settings::site_key() . ':field:' . $list . ':' . $key
			);
			if ( Api::ok( $response ) ) {
				++$created;
			} else {
				/* translators: 1: field key, 2: mailtonic's error message */
				$errors[] = sprintf( __( 'mailtonic refused the field %1$s: %2$s', 'mailtonic' ), $key, $response['message'] ? $response['message'] : Connection::reasons()['unreachable'] );
			}
		}
		delete_transient( 'mailtonic_fields_' . $list );
		return array(
			'ok'       => ! $errors,
			'errors'   => $errors,
			'warnings' => array(),
			'created'  => $created,
		);
	}

	/**
	 * Starts the past-customer import: rows in batches of 1,000 as import_batch outbox entries.
	 *
	 * @param array $input source, statement, only_opted_in.
	 * @return array { ok, errors, warnings }
	 */
	public static function start_import( array $input ) {
		$fail     = static fn( $message ) => array(
			'ok'       => false,
			'errors'   => array( $message ),
			'warnings' => array(),
		);
		$progress = Settings::import();
		if ( ! empty( $progress['running'] ) && Outbox::find(
			array(
				'kind'   => 'import_batch',
				'status' => 'pending',
			),
			1
		) ) {
			return $fail( __( 'An import is already running.', 'mailtonic' ) );
		}
		$source = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : '';
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return $fail( __( 'Choose where these customers came from.', 'mailtonic' ) );
		}
		if ( empty( $input['statement'] ) ) {
			/* translators: %s: the declaration statement */
			return $fail( sprintf( __( 'To import, tick “%s”', 'mailtonic' ), self::statement() ) );
		}
		$list = (string) Settings::get( 'default_list', '' );
		if ( '' === $list || ! Settings::connected() ) {
			return $fail( __( 'Connect mailtonic and choose a default list first.', 'mailtonic' ) );
		}
		$rows = self::import_rows( $list, ! empty( $input['only_opted_in'] ) );
		if ( ! $rows ) {
			return $fail( __( 'There are no customers to import.', 'mailtonic' ) );
		}
		$id      = wp_generate_password( 8, false, false );
		$batches = array_chunk( $rows, self::BATCH_ROWS );
		update_option(
			Settings::IMPORT,
			array(
				'id'         => $id,
				'total'      => count( $batches ),
				'sent'       => 0,
				'failed'     => 0,
				'rows'       => count( $rows ),
				'counts'     => array(
					'inserted'   => 0,
					'updated'    => 0,
					'unchanged'  => 0,
					'suppressed' => 0,
					'skipped'    => array(),
				),
				'running'    => true,
				'started_at' => time(),
			),
			false
		);
		foreach ( $batches as $n => $batch ) {
			Outbox::queue(
				array(
					'kind'      => 'import_batch',
					'source'    => 'woocommerce',
					/* translators: 1: batch number, 2: number of batches */
					'reference' => sprintf( __( 'Import batch %1$d of %2$d', 'mailtonic' ), $n + 1, count( $batches ) ),
					'method'    => 'POST',
					'path'      => '/lists/' . rawurlencode( $list ) . '/contacts/bulk',
					'body'      => array(
						'rows'                 => $batch,
						'mode'                 => 'add_and_update',
						'source'               => $source,
						'permission_confirmed' => true,
					),
					'key'       => 'import:' . $id . ':' . ( $n + 1 ),
				)
			);
		}
		return array(
			'ok'       => true,
			'errors'   => array(),
			'warnings' => array(),
		);
	}

	/**
	 * The declaration statement, word for word.
	 *
	 * @return string
	 */
	public static function statement() {
		return __( 'Everyone on this list agreed to receive email from me, and I can show when and how.', 'mailtonic' );
	}

	/**
	 * Import rows: every distinct billing email of paid orders plus registered customers, or only the
	 * opt-in register; with mapped fields, existing stats fields, the tags and register consent columns.
	 *
	 * @param string $list_uid      List uid.
	 * @param bool   $only_opted_in Only register entries.
	 * @return array
	 */
	private static function import_rows( $list_uid, $only_opted_in ) {
		$keys     = Lists::field_keys( $list_uid );
		$agg      = array();
		$register = array();
		$users    = array();

		// ponytail: builds every row in one admin request; move to a background job if stores outgrow it.
		for ( $page = 1; ; $page++ ) {
			$orders = wc_get_orders(
				array(
					'status'  => self::PAID,
					'limit'   => 500,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'DESC',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! is_email( $order->get_billing_email() ) ) {
					continue;
				}
				self::aggregate( $agg, $order );
				$entry = $order->get_meta( self::META );
				$email = strtolower( $order->get_billing_email() );
				if ( $entry && ! isset( $register[ $email ] ) ) {
					$register[ $email ] = $entry;
				}
			}
			if ( count( $orders ) < 500 ) {
				break;
			}
		}
		foreach ( get_users(
			array(
				'role__in' => array( 'customer' ),
				'fields'   => 'all',
			)
		) as $user ) {
			$users[ strtolower( $user->user_email ) ] = $user;
		}
		foreach ( get_users(
			array(
				'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => 'all',
			)
		) as $user ) {
			$email              = strtolower( $user->user_email );
			$users[ $email ]    = $user;
			$register[ $email ] = get_user_meta( $user->ID, self::META, true );
		}

		$emails = $only_opted_in ? array_keys( $register ) : array_unique( array_merge( array_keys( $agg ), array_keys( $users ), array_keys( $register ) ) );
		$tags   = array_values( (array) Settings::wc()['tags'] );
		$rows   = array();
		foreach ( $emails as $email ) {
			if ( ! is_email( $email ) ) {
				continue;
			}
			$row = array( 'email' => $email );
			if ( isset( $agg[ $email ] ) ) {
				$row += self::mapped( $agg[ $email ]['order'], $keys );
				$row += array_intersect_key( self::stat_fields( $agg[ $email ] ), array_flip( $keys ) );
			} elseif ( isset( $users[ $email ] ) ) {
				foreach ( (array) Settings::wc()['map'] as $key => $prop ) {
					$value = (string) get_user_meta( $users[ $email ]->ID, $prop, true );
					if ( in_array( $key, $keys, true ) && '' !== $value ) {
						$row[ $key ] = $value;
					}
				}
			}
			if ( $tags ) {
				$row['tags'] = $tags;
			}
			if ( isset( $register[ $email ] ) && is_array( $register[ $email ] ) ) {
				$entry                 = $register[ $email ];
				$row['consent_at']     = gmdate( 'c', (int) $entry['at'] );
				$row['consent_ip']     = (string) $entry['ip'];
				$row['consent_source'] = 'registration' === $entry['page'] ? 'WooCommerce registration' : 'WooCommerce checkout';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Adds a finished import batch to the import's progress.
	 *
	 * @param array      $row     Outbox entry.
	 * @param string     $outcome sent, skipped or failed.
	 * @param array|null $body    API answer.
	 */
	public static function import_progress( $row, $outcome, $body ) {
		$progress = Settings::import();
		if ( 'import_batch' !== $row['kind'] || empty( $progress['id'] ) || false === strpos( $row['idempotency_key'], ':import:' . $progress['id'] . ':' ) ) {
			return;
		}
		if ( 'sent' === $outcome ) {
			++$progress['sent'];
			foreach ( array( 'inserted', 'updated', 'unchanged', 'suppressed' ) as $count ) {
				$progress['counts'][ $count ] += isset( $body[ $count ] ) ? (int) $body[ $count ] : 0;
			}
			foreach ( isset( $body['skipped'] ) ? (array) $body['skipped'] : array() as $reason => $n ) {
				$reason                                   = sanitize_key( $reason );
				$progress['counts']['skipped'][ $reason ] = ( isset( $progress['counts']['skipped'][ $reason ] ) ? $progress['counts']['skipped'][ $reason ] : 0 ) + (int) $n;
			}
		} else {
			++$progress['failed'];
		}
		$progress['running'] = $progress['sent'] + $progress['failed'] < $progress['total'];
		update_option( Settings::IMPORT, $progress, false );
	}
}
