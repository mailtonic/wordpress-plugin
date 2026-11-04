<?php
/**
 * Abandoned carts: snapshots of known, opted-in shoppers' carts, the sweep that reports them,
 * recovery links and the checkout's cart-contact route.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Tracked carts.
 */
final class Carts {
	const TABLE   = 'mailtonic_carts';
	const SESSION = 'mailtonic_cart';
	const NONCE   = 'mailtonic_cart_contact';
	const LIMIT   = 10;

	/**
	 * Hooks (with WooCommerce only).
	 */
	public static function init() {
		if ( ! Integrations::active( 'woocommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_cart_updated', array( self::class, 'snapshot' ) );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_action( 'wp_loaded', array( self::class, 'handle_recovery' ), 30 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'checkout_script' ) );
	}

	/**
	 * The table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * The table's schema for dbDelta.
	 *
	 * @param string $collate Charset and collation.
	 * @return string
	 */
	public static function schema( $collate ) {
		$table = self::table();
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token varchar(64) NOT NULL,
  email varchar(254) DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  items longtext NOT NULL,
  total decimal(19,4) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  version int(10) unsigned NOT NULL DEFAULT 1,
  updated_at bigint(20) unsigned NOT NULL,
  reminded_version int(10) unsigned DEFAULT NULL,
  completed_order_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY completed_updated (completed_order_id,updated_at)
) {$collate};";
	}

	/**
	 * Whether cart tracking is on and WooCommerce's session and cart exist.
	 *
	 * @return bool
	 */
	private static function ready() {
		return Woo::on( 'carts' ) && function_exists( 'WC' ) && WC()->session && WC()->cart;
	}

	/**
	 * The session's cart token.
	 *
	 * @param bool $create Make one when missing.
	 * @return string|null
	 */
	public static function token( $create = true ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$token = WC()->session->get( self::SESSION );
		if ( ! $token && $create ) {
			$token = wp_generate_password( 32, false, false );
			WC()->session->set( self::SESSION, $token );
		}
		return $token ? (string) $token : null;
	}

	/**
	 * A cart by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A cart by token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	private static function by_token( $token ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The current session's tracked cart.
	 *
	 * @return array|null
	 */
	public static function current() {
		$token = self::token( false );
		return $token ? self::by_token( $token ) : null;
	}

	/**
	 * The logged-in customer's email when the register holds their opt-in.
	 *
	 * @return string
	 */
	private static function user_email() {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! get_user_meta( $user->ID, Woo::META, true ) ) {
			return '';
		}
		$billing = (string) get_user_meta( $user->ID, 'billing_email', true );
		return is_email( $billing ) ? $billing : $user->user_email;
	}

	/**
	 * The session cart's items and total.
	 *
	 * @return array
	 */
	private static function contents() {
		$items = array();
		$sum   = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			$items[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'quantity'     => (int) $item['quantity'],
				'name'         => $product ? $product->get_name() : '',
			);
			$sum    += $product ? (float) $product->get_price() * (int) $item['quantity'] : 0;
		}
		$total = (float) WC()->cart->get_total( 'edit' );
		return array(
			'items'    => wp_json_encode( $items ),
			'total'    => round( $total > 0 ? $total : $sum, 2 ),
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * Writes the session cart's snapshot: always for a tracked cart, and for a new cart only when the
	 * shopper is known (logged in with an opt-in, or $email from the cart-contact route).
	 *
	 * @param string|null $email Email to set.
	 */
	public static function snapshot( $email = null ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		$email = is_string( $email ) && is_email( $email ) ? $email : null;
		$token = self::token( false );
		$row   = $token ? self::by_token( $token ) : null;
		if ( ! $row && ! $email ) {
			$email = self::user_email();
			if ( ! $email ) {
				return;
			}
		}
		$token    = $token ? $token : self::token( true );
		$contents = self::contents();
		if ( $row ) {
			// The version follows the items; a total recalculated later (coupons, shipping) is refreshed without a new version.
			$fields = array();
			if ( $row['items'] !== $contents['items'] ) {
				$fields = $contents + array(
					'version'    => (int) $row['version'] + 1,
					'updated_at' => time(),
				);
			} elseif ( abs( (float) $row['total'] - $contents['total'] ) > 0.001 ) {
				$fields = array( 'total' => $contents['total'] );
			}
			if ( $email && $email !== $row['email'] ) {
				$fields['email'] = $email;
			}
			if ( $fields ) {
				$wpdb->update( self::table(), $fields, array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			$contents + array(
				'token'      => $token,
				'email'      => $email,
				'user_id'    => get_current_user_id() ? get_current_user_id() : null,
				'version'    => 1,
				'updated_at' => time(),
			)
		);
	}

	/**
	 * The checkout reported an email and the opt-in tick: track the session cart under that email,
	 * or, unticked, clear the email from it.
	 *
	 * @param string $email     Email.
	 * @param bool   $opted_in  Whether the box is ticked.
	 */
	public static function set_contact( $email, $opted_in ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		if ( $opted_in ) {
			self::snapshot( $email );
			return;
		}
		$row = self::current();
		if ( $row ) {
			$wpdb->update( self::table(), array( 'email' => null ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Registers POST /wp-json/mailtonic/v1/cart-contact.
	 */
	public static function register_route() {
		register_rest_route(
			'mailtonic/v1',
			'/cart-contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'cart_contact' ),
				'permission_callback' => static function ( $request ) {
					$nonce = $request->get_header( 'X-Mailtonic-Nonce' );
					return $nonce && wp_verify_nonce( $nonce, self::NONCE ) ? true : new \WP_Error( 'rest_forbidden', __( 'This page has expired. Reload it and try again.', 'mailtonic' ), array( 'status' => 403 ) );
				},
				'args'                => array(
					'email'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'opted_in' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The cart-contact route: 10 calls per minute per IP, the current session only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cart_contact( $request ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'mailtonic_rl_' . md5( $ip );
		$state = get_transient( $key );
		$state = is_array( $state ) && $state['until'] > time() ? $state : array(
			'n'     => 0,
			'until' => time() + MINUTE_IN_SECONDS,
		);
		if ( $state['n'] >= self::LIMIT ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Try again in a minute.', 'mailtonic' ), array( 'status' => 429 ) );
		}
		++$state['n'];
		set_transient( $key, $state, max( 1, $state['until'] - time() ) );

		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$opted_in = (bool) $request->get_param( 'opted_in' );
		if ( $opted_in && ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'mailtonic' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
			wc_load_cart();
		}
		self::set_contact( $email, $opted_in );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Loads the checkout script that reports the email and tick to the route.
	 */
	public static function checkout_script() {
		if ( ! Woo::on( 'carts' ) || ! Woo::on( 'optin' ) || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'mailtonic-checkout', plugins_url( 'assets/checkout.js', MAILTONIC_FILE ), array(), Plugin::VERSION, true );
		wp_add_inline_script(
			'mailtonic-checkout',
			'window.mailtonicCheckout = ' . wp_json_encode(
				array(
					'url'   => rest_url( 'mailtonic/v1/cart-contact' ),
					'nonce' => wp_create_nonce( self::NONCE ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The HMAC of a token and version under a key derived from the site's salts.
	 *
	 * @param string $token   Token.
	 * @param int    $version Version.
	 * @return string
	 */
	private static function sign( $token, $version ) {
		return hash_hmac( 'sha256', $token . '|' . (int) $version, hash_hmac( 'sha256', 'mailtonic-cart-recovery', wp_salt( 'secure_auth' ) ) );
	}

	/**
	 * A cart's recovery link.
	 *
	 * @param array $cart Cart.
	 * @return string
	 */
	public static function recovery_url( array $cart ) {
		return add_query_arg(
			array(
				'mailtonic_cart' => $cart['token'],
				'v'              => (int) $cart['version'],
				'sig'            => self::sign( $cart['token'], $cart['version'] ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Opens a recovery link: rebuilds the cart's purchasable items and goes to the checkout, or, for a
	 * bad or expired link, goes to the cart page. It never logs anyone in.
	 */
	public static function handle_recovery() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signature authenticates the link.
		if ( empty( $_GET['mailtonic_cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['mailtonic_cart'] ) );
		$v     = isset( $_GET['v'] ) ? absint( $_GET['v'] ) : 0;
		$sig   = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable
		$cart = self::by_token( $token );
		$ok   = $cart && hash_equals( self::sign( $token, $v ), $sig ) && (int) $cart['version'] === $v && empty( $cart['completed_order_id'] ) && (int) $cart['updated_at'] >= time() - 7 * DAY_IN_SECONDS;
		if ( ! $ok ) {
			wc_add_notice( __( 'This cart link has expired.', 'mailtonic' ), 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		WC()->session->set( self::SESSION, $token );
		WC()->cart->empty_cart();
		$skipped = array();
		foreach ( (array) json_decode( $cart['items'], true ) as $item ) {
			$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! WC()->cart->add_to_cart( $item['product_id'], $item['quantity'], $item['variation_id'] ) ) {
				$skipped[] = $item['name'];
			}
		}
		WC()->cart->calculate_totals();
		if ( $skipped ) {
			/* translators: %s: comma-separated product names */
			wc_add_notice( sprintf( __( 'Some items are no longer available and were left out: %s', 'mailtonic' ), implode( ', ', $skipped ) ), 'notice' );
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Marks the order's cart (by token or email) completed.
	 *
	 * @param \WC_Order $order Paid order.
	 */
	public static function complete( $order ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET completed_order_id = %d WHERE completed_order_id IS NULL AND (token = %s OR email = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order->get_id(),
				(string) $order->get_meta( Woo::CART ),
				$order->get_billing_email()
			)
		);
	}

	/**
	 * The sweep: carts idle at least the idle time (60 minutes by default) and changed within 7 days,
	 * not completed and not reminded for this version, get a contact_update (cart fields) and a
	 * cart_abandoned event.
	 *
	 * @param int|null $now Current time.
	 */
	public static function sweep( $now = null ) {
		global $wpdb;
		if ( ! Woo::on( 'carts' ) ) {
			return;
		}
		$list = (string) Settings::get( 'default_list', '' );
		if ( '' === $list ) {
			return;
		}
		$now   = null === $now ? time() : (int) $now;
		$idle  = min( 1440, max( 30, (int) Settings::wc()['idle_minutes'] ) ) * MINUTE_IN_SECONDS;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE email IS NOT NULL AND completed_order_id IS NULL AND (reminded_version IS NULL OR reminded_version < version) AND updated_at <= %d AND updated_at >= %d ORDER BY id ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now - $idle,
				$now - 7 * DAY_IN_SECONDS
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return;
		}
		$keys = Lists::field_keys( $list );
		foreach ( $rows as $cart ) {
			$items = (array) json_decode( $cart['items'], true );
			$wpdb->update( $table, array( 'reminded_version' => $cart['version'] ), array( 'id' => $cart['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $items ) {
				continue;
			}
			$ref    = $cart['token'] . ':' . $cart['version'];
			$url    = self::recovery_url( $cart );
			$total  = number_format( (float) $cart['total'], 2, '.', '' );
			$fields = array_intersect_key(
				array(
					'wc_cart_url'   => $url,
					'wc_cart_total' => (float) $total,
				),
				array_flip( $keys )
			);
			if ( $fields ) {
				Outbox::queue(
					array(
						'kind'      => 'contact_update',
						'source'    => 'woocommerce',
						'reference' => 'Cart ' . $cart['token'],
						'method'    => 'PATCH',
						'path'      => '/lists/' . rawurlencode( $list ) . '/contacts/' . rawurlencode( $cart['email'] ),
						'body'      => array( 'fields' => $fields ),
						'key'       => $ref,
					)
				);
			}
			$props = array(
				'cart_total'      => $total,
				'currency'        => $cart['currency'],
				'items_count'     => (int) array_sum( array_column( $items, 'quantity' ) ),
				'first_item_name' => (string) $items[0]['name'],
				'recovery_url'    => $url,
				'store'           => 'woocommerce',
				'site_url'        => home_url(),
			);
			/**
			 * Filters the cart_abandoned event's properties before queueing. The 20-key, scalar and
			 * 255-character rules are applied afterwards.
			 *
			 * @param array $props Properties.
			 * @param array $cart  The tracked cart.
			 */
			$props = Woo::clean( apply_filters( 'mailtonic_cart_abandoned_properties', $props, $cart ) );
			Outbox::queue(
				array(
					'kind'      => 'event',
					'source'    => 'woocommerce',
					'reference' => 'Cart ' . $cart['token'],
					'method'    => 'POST',
					'path'      => '/events',
					'body'      => array(
						'name'       => 'cart_abandoned',
						'email'      => $cart['email'],
						'list_uid'   => $list,
						'properties' => $props,
					),
					'key'       => $ref,
				)
			);
		}
	}

	/**
	 * Deletes carts 30 days after their last change.
	 *
	 * @param int|null $now Current time.
	 */
	public static function prune( $now = null ) {
		global $wpdb;
		$now   = null === $now ? time() : (int) $now;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %d", $now - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Carts holding an email (privacy tools).
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function for_email( $email ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes carts holding an email.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	public static function erase( $email ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'email' => $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
