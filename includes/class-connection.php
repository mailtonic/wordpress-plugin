<?php
/**
 * The connection: app URL, API key, default list, and the connect check.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Connect, check and disconnect.
 */
final class Connection {

	/**
	 * Messages for refusals that also pause the outbox, by reason code.
	 *
	 * @return array
	 */
	public static function reasons() {
		return array(
			'unauthenticated'       => __( "mailtonic didn't accept this key. Create a new key in mailtonic under Settings › API keys.", 'mailtonic' ),
			'account_suspended'     => __( 'This mailtonic account is suspended.', 'mailtonic' ),
			'subscription_required' => __( "Your mailtonic subscription isn't active.", 'mailtonic' ),
			'unreachable'           => __( "We couldn't reach mailtonic. Try again in a minute.", 'mailtonic' ),
		);
	}

	/**
	 * Normalises and checks an app URL: https, or http for localhost and *.test hosts.
	 *
	 * @param string $url URL.
	 * @return string|null Normalised URL or null when refused.
	 */
	public static function app_url( $url ) {
		$url   = untrailingslashit( trim( (string) $url ) );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$local  = 'localhost' === $host || '127.0.0.1' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' );
		if ( 'https' === $scheme || ( 'http' === $scheme && $local ) ) {
			return esc_url_raw( $url );
		}
		return null;
	}

	/**
	 * Saves a connection after the connect check passes.
	 *
	 * @param array $input app_url, api_key (blank keeps the saved key), default_list.
	 * @return array { ok: bool, errors: string[], warnings: string[] }
	 */
	public static function connect( array $input ) {
		$app_url = isset( $input['app_url'] ) && '' !== trim( (string) $input['app_url'] ) ? self::app_url( $input['app_url'] ) : Settings::app_url();
		if ( defined( 'MAILTONIC_APP_URL' ) ) {
			$app_url = Settings::app_url();
		}
		if ( null === $app_url ) {
			return self::fail( __( 'The app URL must start with https:// (http:// only for localhost and .test hosts).', 'mailtonic' ) );
		}

		$key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		if ( '' === $key || Settings::key_from_constant() ) {
			$key = Settings::api_key();
			if ( ! $key ) {
				return self::fail( __( 'Paste your mailtonic API key.', 'mailtonic' ) );
			}
		} elseif ( ! preg_match( '/^mt_[A-Za-z0-9]{48}$/', $key ) ) {
			return self::fail( __( "This doesn't look like a mailtonic API key.", 'mailtonic' ) );
		}

		$check = self::check( $key, $app_url );
		if ( ! $check['ok'] ) {
			return $check;
		}

		Settings::update( array( 'app_url' => $app_url ) );
		Settings::set_api_key( $key );
		self::remember( $check['me'] );

		$lists = Lists::fetch();
		if ( null !== $lists ) {
			$chosen  = isset( $input['default_list'] ) ? sanitize_text_field( (string) $input['default_list'] ) : '';
			$current = (string) Settings::get( 'default_list', '' );
			$default = isset( $lists[ $chosen ] ) ? $chosen : ( isset( $lists[ $current ] ) ? $current : (string) array_key_first( $lists ) );
			Settings::update(
				array(
					'lists'        => $lists,
					'default_list' => $default,
				)
			);
		}
		return $check;
	}

	/**
	 * The connect check: GET /me. Without arguments it checks the saved key and, when it
	 * passes, refreshes the shown account and resumes a paused outbox.
	 *
	 * @param string|null $key     Key to check.
	 * @param string|null $app_url App URL to check against.
	 * @return array { ok, errors, warnings, me }
	 */
	public static function check( $key = null, $app_url = null ) {
		$stored = null === $key;
		$key    = $stored ? Settings::api_key() : $key;
		if ( ! $key ) {
			return self::fail( __( 'Not connected.', 'mailtonic' ) );
		}
		$response = Api::request( 'GET', '/me', null, null, $key, $app_url );
		$reasons  = self::reasons();
		$status   = $response['status'];

		if ( 401 === $status ) {
			return self::fail( $reasons['unauthenticated'] );
		}
		if ( 403 === $status && 'account_suspended' === $response['code'] ) {
			return self::fail( $reasons['account_suspended'] );
		}
		if ( 402 === $status ) {
			return self::fail( $reasons['subscription_required'] );
		}
		if ( ! Api::ok( $response ) || ! isset( $response['body']['api_key'] ) ) {
			return self::fail( 0 === $status || $status >= 500 || '' === $response['message'] ? $reasons['unreachable'] : $response['message'] );
		}

		$me = $response['body'];
		if ( 'write' !== ( isset( $me['api_key']['access'] ) ? $me['api_key']['access'] : '' ) ) {
			return self::fail( __( 'This key is read-only. Create a key with write access.', 'mailtonic' ) );
		}
		$warnings = array();
		if ( 'owner' !== ( isset( $me['role']['key'] ) ? $me['role']['key'] : '' ) ) {
			$warnings[] = __( "This key's member isn't an Owner, so some features may be refused.", 'mailtonic' );
		}
		if ( $stored ) {
			self::remember( $me );
		}
		return array(
			'ok'       => true,
			'errors'   => array(),
			'warnings' => $warnings,
			'me'       => $me,
		);
	}

	/**
	 * Removes the key and caches and leaves the outbox waiting (entries expire after 7 days).
	 */
	public static function disconnect() {
		Lists::forget_fields();
		delete_transient( Forms::CACHE );
		Settings::forget( array( 'key', 'key_last4', 'account_name', 'key_name', 'member_email', 'member_role', 'paused' ) );
	}

	/**
	 * Stores the account shown on the Connection screen and resumes the outbox.
	 *
	 * @param array $me GET /me body.
	 */
	private static function remember( array $me ) {
		Settings::update(
			array(
				'account_name' => isset( $me['customer']['name'] ) ? sanitize_text_field( $me['customer']['name'] ) : '',
				'key_name'     => isset( $me['api_key']['name'] ) ? sanitize_text_field( $me['api_key']['name'] ) : '',
				'member_email' => isset( $me['user']['email'] ) ? sanitize_email( $me['user']['email'] ) : '',
				'member_role'  => isset( $me['role']['name'] ) ? sanitize_text_field( $me['role']['name'] ) : '',
			)
		);
		Settings::forget( array( 'paused' ) );
	}

	/**
	 * A failed result.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private static function fail( $message ) {
		return array(
			'ok'       => false,
			'errors'   => array( $message ),
			'warnings' => array(),
			'me'       => null,
		);
	}
}
