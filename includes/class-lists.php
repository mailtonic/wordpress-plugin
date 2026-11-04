<?php
/**
 * Lists and their fields from mailtonic, cached.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Lists (from the connect check) and list fields (cached one hour).
 */
final class Lists {

	/**
	 * Lists saved at the last connect: uid => name.
	 *
	 * @return array
	 */
	public static function all() {
		return (array) Settings::get( 'lists', array() );
	}

	/**
	 * Fetches every list (GET /lists, following cursors).
	 *
	 * @return array|null uid => name, or null on failure.
	 */
	public static function fetch() {
		$lists  = array();
		$cursor = null;
		for ( $page = 0; $page < 20; $page++ ) {
			$response = Api::request( 'GET', '/lists?limit=100' . ( $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' ) );
			if ( ! Api::ok( $response ) || ! isset( $response['body']['data'] ) ) {
				return null;
			}
			foreach ( $response['body']['data'] as $list ) {
				$total                 = isset( $list['counts']['total'] ) ? (int) $list['counts']['total'] : 0;
				$lists[ $list['uid'] ] = sprintf(
					/* translators: 1: list name, 2: number of contacts */
					_n( '%1$s (%2$s contact)', '%1$s (%2$s contacts)', $total, 'mailtonic' ),
					$list['name'],
					number_format_i18n( $total )
				);
			}
			$cursor = isset( $response['body']['next_cursor'] ) ? $response['body']['next_cursor'] : null;
			if ( ! $cursor ) {
				break;
			}
		}
		return $lists;
	}

	/**
	 * Fields of a list (GET /lists/{list}/fields), cached one hour.
	 *
	 * @param string $list_uid List uid.
	 * @param bool   $fresh    Skip the cache.
	 * @return array Field objects.
	 */
	public static function fields( $list_uid, $fresh = false ) {
		$list = $list_uid;
		if ( ! $list || ! Settings::connected() ) {
			return array();
		}
		$cache = 'mailtonic_fields_' . $list;
		$found = $fresh ? false : get_transient( $cache );
		if ( false !== $found ) {
			return $found;
		}
		$response = Api::request( 'GET', '/lists/' . rawurlencode( $list ) . '/fields' );
		if ( ! Api::ok( $response ) || ! isset( $response['body']['data'] ) ) {
			set_transient( $cache, array(), MINUTE_IN_SECONDS );
			return array();
		}
		set_transient( $cache, $response['body']['data'], HOUR_IN_SECONDS );
		return $response['body']['data'];
	}

	/**
	 * Field keys of a list.
	 *
	 * @param string $list_uid List uid.
	 * @param bool   $fresh    Skip the cache.
	 * @return string[]
	 */
	public static function field_keys( $list_uid, $fresh = false ) {
		return array_column( self::fields( $list_uid, $fresh ), 'key' );
	}

	/**
	 * Forgets cached fields of every known list.
	 */
	public static function forget_fields() {
		foreach ( array_merge( array_keys( self::all() ), array( (string) Settings::get( 'default_list' ) ) ) as $uid ) {
			delete_transient( 'mailtonic_fields_' . $uid );
		}
	}
}
