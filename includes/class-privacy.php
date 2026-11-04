<?php
/**
 * WordPress personal-data exporter and eraser, and the suggested privacy-policy text.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools.
 */
final class Privacy {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( $exporters ) {
				$exporters['mailtonic'] = array(
					'exporter_friendly_name' => __( 'mailtonic', 'mailtonic' ),
					'callback'               => array( self::class, 'export' ),
				);
				return $exporters;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( $erasers ) {
				$erasers['mailtonic'] = array(
					'eraser_friendly_name' => __( 'mailtonic', 'mailtonic' ),
					'callback'             => array( self::class, 'erase' ),
				);
				return $erasers;
			}
		);
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	/**
	 * Orders and users holding a register entry for the email.
	 *
	 * @param string $email Email.
	 * @return array { orders: WC_Order[], users: WP_User[] }
	 */
	private static function holders( $email ) {
		$orders = array();
		if ( Integrations::active( 'woocommerce' ) ) {
			foreach ( wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => -1,
				)
			) as $order ) {
				if ( $order->get_meta( Woo::META ) ) {
					$orders[] = $order;
				}
			}
		}
		$user = get_user_by( 'email', $email );
		return array(
			'orders' => $orders,
			'users'  => $user && get_user_meta( $user->ID, Woo::META, true ) ? array( $user ) : array(),
		);
	}

	/**
	 * Contacts in mailtonic with the email (GET /contacts?email=).
	 *
	 * @param string $email Email.
	 * @return array|null Contacts, or null when mailtonic could not be reached.
	 */
	private static function contacts( $email ) {
		$response = Api::request( 'GET', '/contacts?email=' . rawurlencode( $email ) );
		if ( ! Api::ok( $response ) || ! isset( $response['body']['data'] ) ) {
			return null;
		}
		return $response['body']['data'];
	}

	/**
	 * A register entry as export data.
	 *
	 * @param array  $entry Entry.
	 * @param string $what  What it belongs to.
	 * @return array
	 */
	private static function entry_data( array $entry, $what ) {
		return array(
			array(
				'name'  => __( 'Opt-in for', 'mailtonic' ),
				'value' => $what,
			),
			array(
				'name'  => __( 'Ticked at', 'mailtonic' ),
				'value' => gmdate( 'c', (int) $entry['at'] ),
			),
			array(
				'name'  => __( 'Wording', 'mailtonic' ),
				'value' => $entry['text'],
			),
			array(
				'name'  => __( 'IP address', 'mailtonic' ),
				'value' => $entry['ip'],
			),
			array(
				'name'  => __( 'User agent', 'mailtonic' ),
				'value' => $entry['ua'],
			),
			array(
				'name'  => __( 'Page', 'mailtonic' ),
				'value' => $entry['page'],
			),
		);
	}

	/**
	 * The exporter.
	 *
	 * @param string $email Email (WordPress also passes a page number; one page holds everything).
	 * @return array
	 */
	public static function export( $email ) {
		$data    = array();
		$holders = self::holders( $email );
		foreach ( $holders['orders'] as $order ) {
			$data[] = array(
				'group_id'    => 'mailtonic-optins',
				'group_label' => __( 'Marketing opt-ins', 'mailtonic' ),
				'item_id'     => 'mailtonic-optin-order-' . $order->get_id(),
				/* translators: %s: order number */
				'data'        => self::entry_data( $order->get_meta( Woo::META ), sprintf( __( 'Order %s', 'mailtonic' ), $order->get_order_number() ) ),
			);
		}
		foreach ( $holders['users'] as $user ) {
			$data[] = array(
				'group_id'    => 'mailtonic-optins',
				'group_label' => __( 'Marketing opt-ins', 'mailtonic' ),
				'item_id'     => 'mailtonic-optin-user-' . $user->ID,
				'data'        => self::entry_data( get_user_meta( $user->ID, Woo::META, true ), __( 'Account', 'mailtonic' ) ),
			);
		}
		foreach ( Integrations::active( 'woocommerce' ) ? Carts::for_email( $email ) : array() as $cart ) {
			$names  = array_column( (array) json_decode( $cart['items'], true ), 'name' );
			$data[] = array(
				'group_id'    => 'mailtonic-carts',
				'group_label' => __( 'Tracked carts', 'mailtonic' ),
				'item_id'     => 'mailtonic-cart-' . $cart['id'],
				'data'        => array(
					array(
						'name'  => __( 'Items', 'mailtonic' ),
						'value' => implode( ', ', $names ),
					),
					array(
						'name'  => __( 'Total', 'mailtonic' ),
						'value' => $cart['total'] . ' ' . $cart['currency'],
					),
					array(
						'name'  => __( 'Last changed', 'mailtonic' ),
						'value' => gmdate( 'c', (int) $cart['updated_at'] ),
					),
				),
			);
		}
		if ( Settings::connected() ) {
			$lists = Lists::all();
			foreach ( (array) self::contacts( $email ) as $contact ) {
				$consent = isset( $contact['consent'] ) ? (array) $contact['consent'] : array();
				$data[]  = array(
					'group_id'    => 'mailtonic-contacts',
					'group_label' => __( 'mailtonic contacts', 'mailtonic' ),
					'item_id'     => 'mailtonic-contact-' . $contact['uid'],
					'data'        => array(
						array(
							'name'  => __( 'List', 'mailtonic' ),
							'value' => isset( $lists[ $contact['list_uid'] ] ) ? $lists[ $contact['list_uid'] ] : $contact['list_uid'],
						),
						array(
							'name'  => __( 'Status', 'mailtonic' ),
							'value' => $contact['status'],
						),
						array(
							'name'  => __( 'Fields', 'mailtonic' ),
							'value' => wp_json_encode( isset( $contact['fields'] ) ? $contact['fields'] : array() ),
						),
						array(
							'name'  => __( 'Tags', 'mailtonic' ),
							'value' => implode( ', ', isset( $contact['tags'] ) ? (array) $contact['tags'] : array() ),
						),
						array(
							'name'  => __( 'Consent', 'mailtonic' ),
							'value' => trim( ( isset( $consent['source'] ) ? $consent['source'] : '' ) . ' ' . ( isset( $consent['given_at'] ) ? $consent['given_at'] : '' ) ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * The eraser.
	 *
	 * @param string $email Email (WordPress also passes a page number; one page holds everything).
	 * @return array
	 */
	public static function erase( $email ) {
		$removed  = false;
		$retained = false;
		$messages = array();
		$holders  = self::holders( $email );
		foreach ( $holders['orders'] as $order ) {
			$order->delete_meta_data( Woo::META );
			$order->save();
			$removed = true;
		}
		foreach ( $holders['users'] as $user ) {
			delete_user_meta( $user->ID, Woo::META );
			$removed = true;
		}
		if ( Integrations::active( 'woocommerce' ) && Carts::erase( $email ) ) {
			$removed = true;
		}
		if ( Outbox::erase_pending( $email ) ) {
			$removed = true;
		}
		if ( Settings::connected() ) {
			$contacts = self::contacts( $email );
			if ( null === $contacts ) {
				$retained   = true;
				$messages[] = __( "mailtonic contacts with this email were kept: we couldn't reach mailtonic. Try again in a minute.", 'mailtonic' );
			}
			foreach ( (array) $contacts as $contact ) {
				$response = Api::request( 'DELETE', '/lists/' . rawurlencode( $contact['list_uid'] ) . '/contacts/' . rawurlencode( $contact['uid'] ) );
				if ( Api::ok( $response ) || 404 === $response['status'] ) {
					$removed = true;
				} else {
					$retained = true;
					/* translators: 1: list uid, 2: reason */
					$messages[] = sprintf( __( 'The mailtonic contact on list %1$s was kept: %2$s', 'mailtonic' ), $contact['list_uid'], $response['message'] ? $response['message'] : Connection::reasons()['unreachable'] );
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'This site uses mailtonic to send email. When you sign up with a form that asks for your agreement, or tick the box to receive news and offers at checkout or registration, we send your email address, the details you entered, the wording you agreed to, the time, your IP address and browser user agent to mailtonic.', 'mailtonic' ) . '</p>'
			. '<p>' . esc_html__( 'If you opted in, we also send a summary of each order (order number, total, items count, first item and coupon codes) and, when you leave items in your cart, a summary of that cart with a link to restore it. If you did not opt in, nothing about you is sent.', 'mailtonic' ) . '</p>'
			. '<p>' . esc_html__( 'mailtonic forms on this site keep their state in your browser’s local storage and set no cookies.', 'mailtonic' ) . '</p>';
		wp_add_privacy_policy_content( __( 'mailtonic', 'mailtonic' ), wp_kses_post( $text ) );
	}
}
