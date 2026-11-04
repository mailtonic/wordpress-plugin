<?php
/**
 * The plugin's settings option, the encrypted API key and the site key.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Settings stored in the `mailtonic_settings` option (autoload off).
 */
final class Settings {
	const OPTION      = 'mailtonic_settings';
	const IMPORT      = 'mailtonic_import';
	const DEFAULT_APP = 'https://app.mailtonic.in';

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Merges values into the settings.
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * Removes settings.
	 *
	 * @param string[] $keys Keys.
	 */
	public static function forget( array $keys ) {
		update_option( self::OPTION, array_diff_key( self::all(), array_flip( $keys ) ), false );
	}

	/**
	 * The mailtonic app URL, without a trailing slash.
	 *
	 * @return string
	 */
	public static function app_url() {
		if ( defined( 'MAILTONIC_APP_URL' ) ) {
			return untrailingslashit( (string) MAILTONIC_APP_URL );
		}
		return (string) self::get( 'app_url', self::DEFAULT_APP );
	}

	/**
	 * Whether the API key comes from the MAILTONIC_API_KEY constant.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'MAILTONIC_API_KEY' ) && '' !== (string) MAILTONIC_API_KEY;
	}

	/**
	 * The API key in plain text, or null.
	 *
	 * @return string|null
	 */
	public static function api_key() {
		if ( self::key_from_constant() ) {
			return (string) MAILTONIC_API_KEY;
		}
		$stored = self::get( 'key' );
		return $stored ? self::decrypt( $stored ) : null;
	}

	/**
	 * Stores the API key encrypted, with its last four characters for display.
	 *
	 * @param string $key Plain key.
	 */
	public static function set_api_key( $key ) {
		if ( self::key_from_constant() ) {
			return;
		}
		self::update(
			array(
				'key'       => self::encrypt( $key ),
				'key_last4' => substr( $key, -4 ),
			)
		);
	}

	/**
	 * The key as shown after saving: `mt_…` plus its last four characters.
	 *
	 * @return string
	 */
	public static function masked_key() {
		$key = self::api_key();
		return $key ? 'mt_…' . substr( $key, -4 ) : '';
	}

	/**
	 * Whether the site has an API key.
	 *
	 * @return bool
	 */
	public static function connected() {
		return null !== self::api_key();
	}

	/**
	 * The random 16-character site key made at activation (never the URL).
	 *
	 * @return string
	 */
	public static function site_key() {
		$key = self::get( 'site_key' );
		if ( ! $key ) {
			$key = wp_generate_password( 16, false, false );
			self::update( array( 'site_key' => $key ) );
		}
		return $key;
	}

	/**
	 * WooCommerce settings with their defaults.
	 *
	 * @return array
	 */
	public static function wc() {
		$defaults = array(
			'optin'            => true,
			'registration_box' => false,
			'signup'           => true,
			'events'           => true,
			'carts'            => true,
			'label'            => '',
			'tags'             => array( 'woocommerce-customer' ),
			'map'              => array(
				'first_name' => 'billing_first_name',
				'last_name'  => 'billing_last_name',
				'phone'      => 'billing_phone',
				'city'       => 'billing_city',
				'country'    => 'billing_country',
			),
			'idle_minutes'     => 60,
		);
		$wc       = self::get( 'wc', array() );
		return array_merge( $defaults, is_array( $wc ) ? $wc : array() );
	}

	/**
	 * Merges WooCommerce settings.
	 *
	 * @param array $values Values.
	 */
	public static function update_wc( array $values ) {
		self::update( array( 'wc' => array_merge( self::get( 'wc', array() ), $values ) ) );
	}

	/**
	 * Progress of the past-customer import.
	 *
	 * @return array
	 */
	public static function import() {
		$import = get_option( self::IMPORT, array() );
		return is_array( $import ) ? $import : array();
	}

	/**
	 * The secretbox key, derived from the site's authentication salt.
	 *
	 * @return string
	 */
	private static function secret() {
		return hash_hmac( 'sha256', 'mailtonic-api-key', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypts with libsodium secretbox.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	private static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts, or null when the salts changed or the value is damaged.
	 *
	 * @param string $stored Stored value.
	 * @return string|null
	 */
	private static function decrypt( $stored ) {
		$raw = base64_decode( (string) $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::secret() );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}
