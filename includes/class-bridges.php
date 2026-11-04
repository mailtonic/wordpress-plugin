<?php
/**
 * Bridges between forms of Contact Form 7, WPForms and Elementor Pro Forms and a mailtonic list.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge settings, validation and the sign-up queued for an accepted, consented submission.
 *
 * Each adapter class lists its plugin's forms as
 * [ form id => [ 'title' => string, 'fields' => [ name => [ 'label', 'type', 'required', 'consent' ] ] ] ]
 * and calls submit() from its plugin's after-submit hook.
 */
final class Bridges {
	const OPTION = 'mailtonic_bridges';

	/**
	 * Adapter class per plugin key, with the integration slug that must be active.
	 */
	const ADAPTERS = array(
		'cf7'       => array( Bridge_CF7::class, 'cf7' ),
		'wpforms'   => array( Bridge_WPForms::class, 'wpforms' ),
		'elementor' => array( Bridge_Elementor::class, 'elementor_pro' ),
	);

	/**
	 * Hooks of every adapter.
	 */
	public static function init() {
		foreach ( self::ADAPTERS as $adapter ) {
			$adapter[0]::init();
		}
	}

	/**
	 * The plugin names, for screens.
	 *
	 * @return array
	 */
	public static function names() {
		return array(
			'cf7'       => __( 'Contact Form 7', 'mailtonic' ),
			'wpforms'   => __( 'WPForms', 'mailtonic' ),
			'elementor' => __( 'Elementor Pro Forms', 'mailtonic' ),
		);
	}

	/**
	 * Plugin keys whose plugin is active.
	 *
	 * @return string[]
	 */
	public static function active_plugins() {
		return array_keys( array_filter( self::ADAPTERS, static fn( $a ) => Integrations::active( $a[1] ) ) );
	}

	/**
	 * Forms of an active plugin.
	 *
	 * @param string $plugin Plugin key.
	 * @return array
	 */
	public static function forms( $plugin ) {
		if ( ! isset( self::ADAPTERS[ $plugin ] ) || ! Integrations::active( self::ADAPTERS[ $plugin ][1] ) ) {
			return array();
		}
		return self::ADAPTERS[ $plugin ][0]::forms();
	}

	/**
	 * All bridges.
	 *
	 * @return array plugin:form id => bridge.
	 */
	public static function all() {
		$bridges = get_option( self::OPTION, array() );
		return is_array( $bridges ) ? $bridges : array();
	}

	/**
	 * One bridge.
	 *
	 * @param string $plugin  Plugin key.
	 * @param string $form_id Form id.
	 * @return array|null
	 */
	public static function get( $plugin, $form_id ) {
		$all = self::all();
		$key = $plugin . ':' . $form_id;
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * The form's reference: its title and id, at most 120 characters.
	 *
	 * @param string $title   Title.
	 * @param string $form_id Form id.
	 * @return string
	 */
	public static function form_ref( $title, $form_id ) {
		return mb_substr( $title . ' (' . $form_id . ')', 0, 120 );
	}

	/**
	 * Validates and saves a bridge.
	 *
	 * @param array $input plugin, form_id, enabled, list, map (list field key => form field), tags (comma separated), consent_field.
	 * @return array { ok, errors, warnings }
	 */
	public static function save( array $input ) {
		$plugin  = isset( $input['plugin'] ) ? sanitize_key( $input['plugin'] ) : '';
		$form_id = isset( $input['form_id'] ) ? sanitize_text_field( (string) $input['form_id'] ) : '';
		$forms   = self::forms( $plugin );
		if ( ! isset( $forms[ $form_id ] ) ) {
			return array(
				'ok'       => false,
				'errors'   => array( __( 'This form no longer exists.', 'mailtonic' ) ),
				'warnings' => array(),
			);
		}
		$fields   = $forms[ $form_id ]['fields'];
		$errors   = array();
		$warnings = array();

		$map = array();
		foreach ( isset( $input['map'] ) ? (array) $input['map'] : array() as $key => $field ) {
			$key   = sanitize_key( $key );
			$field = sanitize_text_field( (string) $field );
			if ( '' !== $key && isset( $fields[ $field ] ) ) {
				$map[ $key ] = $field;
			}
		}
		if ( empty( $map['email'] ) ) {
			$errors[] = __( 'Map a field to email.', 'mailtonic' );
		}

		$consent = isset( $input['consent_field'] ) ? sanitize_text_field( (string) $input['consent_field'] ) : '';
		if ( '' === $consent || empty( $fields[ $consent ]['consent'] ) ) {
			$errors[] = __( 'Choose the checkbox people tick to agree to marketing emails. Add one to your form if it has none.', 'mailtonic' );
		} elseif ( ! empty( $fields[ $consent ]['required'] ) ) {
			$warnings[] = __( "This box must be ticked to send the form at all, so it doesn't record a free choice. Make it optional.", 'mailtonic' );
		}

		$tags = self::tags( isset( $input['tags'] ) ? $input['tags'] : '' );
		if ( null === $tags ) {
			$errors[] = __( 'Use at most 10 tags of up to 60 characters each.', 'mailtonic' );
		}

		if ( $errors ) {
			return array(
				'ok'       => false,
				'errors'   => $errors,
				'warnings' => array(),
			);
		}

		$list                            = isset( $input['list'] ) ? sanitize_text_field( (string) $input['list'] ) : '';
		$all                             = self::all();
		$all[ $plugin . ':' . $form_id ] = array(
			'plugin'        => $plugin,
			'form_id'       => $form_id,
			'enabled'       => ! empty( $input['enabled'] ),
			'list'          => '' !== $list ? $list : (string) Settings::get( 'default_list', '' ),
			'map'           => $map,
			'tags'          => $tags,
			'consent_field' => $consent,
		);
		update_option( self::OPTION, $all, false );
		return array(
			'ok'       => true,
			'errors'   => array(),
			'warnings' => $warnings,
		);
	}

	/**
	 * Parses tags: 0-10, each at most 60 characters.
	 *
	 * @param string|array $raw Comma-separated tags or a list.
	 * @return string[]|null Null when over the limits.
	 */
	public static function tags( $raw ) {
		$tags = array_values( array_unique( array_filter( array_map( static fn( $t ) => sanitize_text_field( trim( (string) $t ) ), is_array( $raw ) ? $raw : explode( ',', (string) $raw ) ) ) ) );
		foreach ( $tags as $tag ) {
			if ( mb_strlen( $tag ) > 60 ) {
				return null;
			}
		}
		return count( $tags ) > 10 ? null : $tags;
	}

	/**
	 * Bridged and bridgeable forms of active plugins. Bridges whose form was deleted are dropped.
	 *
	 * @return array Rows: plugin, form_id, title, bridge (or null).
	 */
	public static function overview() {
		$all     = self::all();
		$rows    = array();
		$changed = false;
		foreach ( self::active_plugins() as $plugin ) {
			$forms = self::forms( $plugin );
			foreach ( $forms as $form_id => $form ) {
				$key    = $plugin . ':' . $form_id;
				$rows[] = array(
					'plugin'  => $plugin,
					'form_id' => (string) $form_id,
					'title'   => $form['title'],
					'bridge'  => isset( $all[ $key ] ) ? $all[ $key ] : null,
				);
			}
			foreach ( $all as $key => $bridge ) {
				if ( $bridge['plugin'] === $plugin && ! isset( $forms[ $bridge['form_id'] ] ) ) {
					unset( $all[ $key ] );
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $all, false );
		}
		return $rows;
	}

	/**
	 * Drops a deleted form's bridge.
	 *
	 * @param string $plugin  Plugin key.
	 * @param string $form_id Form id.
	 */
	public static function forget( $plugin, $form_id ) {
		$all = self::all();
		if ( isset( $all[ $plugin . ':' . $form_id ] ) ) {
			unset( $all[ $plugin . ':' . $form_id ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Whether a checkbox or acceptance value counts as ticked.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	private static function ticked( $value ) {
		if ( is_array( $value ) ) {
			return (bool) array_filter( $value, static fn( $v ) => '' !== (string) $v && '0' !== (string) $v );
		}
		$value = strtolower( trim( (string) $value ) );
		return '' !== $value && '0' !== $value && 'false' !== $value && 'off' !== $value;
	}

	/**
	 * A page URL without query string or fragment, or '' when not http(s).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function page_url( $url ) {
		$url = strtok( (string) $url, '?#' );
		return $url && preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * The consent block for a sign-up.
	 *
	 * @param string $text     Wording the person agreed to.
	 * @param int    $given_at Time.
	 * @param string $ip       IP.
	 * @param string $ua       User agent.
	 * @param string $page_url Page.
	 * @return array
	 */
	public static function consent( $text, $given_at, $ip, $ua, $page_url ) {
		$consent = array(
			'text'     => mb_substr( (string) $text, 0, 1000 ),
			'given_at' => gmdate( 'c', (int) $given_at ),
		);
		if ( $ip && rest_is_ip_address( $ip ) ) {
			$consent['ip'] = $ip;
		}
		if ( $ua ) {
			$consent['user_agent'] = mb_substr( (string) $ua, 0, 500 );
		}
		$page_url = self::page_url( $page_url );
		if ( $page_url ) {
			$consent['page_url'] = $page_url;
		}
		return $consent;
	}

	/**
	 * Queues the sign-up for an accepted submission when its consent box was ticked.
	 *
	 * @param string $plugin  Plugin key.
	 * @param string $form_id Form id.
	 * @param array  $values  Submitted values by form field name.
	 * @param array  $meta    ip, user_agent, page_url, time.
	 * @return int|false Outbox entry id.
	 */
	public static function submit( $plugin, $form_id, array $values, array $meta ) {
		$bridge = self::get( $plugin, (string) $form_id );
		if ( ! $bridge || ! $bridge['enabled'] || ! Settings::connected() || ! Integrations::active( self::ADAPTERS[ $plugin ][1] ) ) {
			return false;
		}
		$consent_field = $bridge['consent_field'];
		if ( ! isset( $values[ $consent_field ] ) || ! self::ticked( $values[ $consent_field ] ) ) {
			return false;
		}
		$email = isset( $values[ $bridge['map']['email'] ] ) ? sanitize_email( (string) $values[ $bridge['map']['email'] ] ) : '';
		if ( ! is_email( $email ) ) {
			return false;
		}
		$forms = self::forms( $plugin );
		$form  = isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : array(
			'title'  => (string) $form_id,
			'fields' => array(),
		);

		$fields = array();
		foreach ( $bridge['map'] as $key => $field ) {
			if ( 'email' === $key || ! isset( $values[ $field ] ) ) {
				continue;
			}
			$value = trim( is_array( $values[ $field ] ) ? implode( ', ', $values[ $field ] ) : (string) $values[ $field ] );
			if ( '' !== $value ) {
				$fields[ $key ] = sanitize_text_field( $value );
			}
		}
		$form_ref = self::form_ref( $form['title'], $form_id );
		$label    = isset( $form['fields'][ $consent_field ]['label'] ) ? $form['fields'][ $consent_field ]['label'] : '';
		$body     = array( 'email' => $email );
		if ( $fields ) {
			$body['fields'] = $fields;
		}
		if ( $bridge['tags'] ) {
			$body['tags'] = $bridge['tags'];
		}
		$body['consent'] = self::consent( '' !== $label ? $label : __( 'Yes', 'mailtonic' ), isset( $meta['time'] ) ? $meta['time'] : time(), isset( $meta['ip'] ) ? $meta['ip'] : '', isset( $meta['user_agent'] ) ? $meta['user_agent'] : '', isset( $meta['page_url'] ) ? $meta['page_url'] : '' );
		$body['source']  = array(
			'integration' => 'wordpress',
			'plugin'      => $plugin,
			'form_ref'    => $form_ref,
			'site_url'    => home_url(),
		);
		return Outbox::queue(
			array(
				'kind'      => 'signup',
				'source'    => $plugin,
				'reference' => $form_ref,
				'method'    => 'POST',
				'path'      => '/lists/' . rawurlencode( $bridge['list'] ) . '/signups',
				'body'      => $body,
				'key'       => wp_generate_uuid4(),
			)
		);
	}

	/**
	 * The visitor's IP and user agent from the request.
	 *
	 * @return array
	 */
	public static function request_meta() {
		return array(
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'page_url'   => (string) wp_get_raw_referer(),
			'time'       => time(),
		);
	}

	/**
	 * The short panel shown inside a form plugin's own editor: status and a link to the bridge settings.
	 *
	 * @param string $plugin  Plugin key.
	 * @param string $form_id Form id.
	 */
	public static function panel( $plugin, $form_id ) {
		$bridge = self::get( $plugin, (string) $form_id );
		echo '<h2>' . esc_html__( 'mailtonic', 'mailtonic' ) . '</h2><p>';
		if ( ! Settings::connected() ) {
			esc_html_e( 'Connect mailtonic to send sign-ups from this form to a list.', 'mailtonic' );
		} elseif ( $bridge && $bridge['enabled'] ) {
			esc_html_e( 'Sign-ups from this form go to mailtonic when the visitor ticks the consent box.', 'mailtonic' );
		} else {
			esc_html_e( 'This form does not send sign-ups to mailtonic.', 'mailtonic' );
		}
		printf(
			'</p><p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=mailtonic-integrations&plugin=' . rawurlencode( $plugin ) . '&form=' . rawurlencode( (string) $form_id ) ) ),
			esc_html__( 'Set up in mailtonic', 'mailtonic' )
		);
	}
}
