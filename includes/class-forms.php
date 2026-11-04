<?php
/**
 * Forms and popups from mailtonic: the form list, shortcode, block, Elementor widget and site-wide popups.
 * The plugin only prints the embed script element; the form frame does everything else.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Embedding forms.
 */
final class Forms {
	const CACHE      = 'mailtonic_forms';
	const UID        = '/^[0-9A-HJKMNP-TV-Z]{26}$/';
	const MAX_POPUPS = 10;

	/**
	 * Form uids already printed on this page.
	 *
	 * @var array
	 */
	private static $printed = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'mailtonic_form', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'wp_footer', array( self::class, 'print_popups' ), 20 );
		add_action( 'elementor/widgets/register', array( self::class, 'register_widget' ) );
	}

	/**
	 * Forgets which forms were printed (a new page).
	 */
	public static function reset_printed() {
		self::$printed = array();
	}

	/**
	 * The form list from GET /forms, cached 10 minutes.
	 *
	 * @return array Form objects.
	 */
	public static function all() {
		if ( ! Settings::connected() ) {
			return array();
		}
		$cached = get_transient( self::CACHE );
		if ( false !== $cached ) {
			return $cached;
		}
		$forms  = array();
		$cursor = null;
		for ( $page = 0; $page < 10; $page++ ) {
			$response = Api::request( 'GET', '/forms?limit=100' . ( $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' ) );
			if ( ! Api::ok( $response ) || ! isset( $response['body']['data'] ) ) {
				set_transient( self::CACHE, $forms, MINUTE_IN_SECONDS );
				return $forms;
			}
			$forms  = array_merge( $forms, $response['body']['data'] );
			$cursor = isset( $response['body']['next_cursor'] ) ? $response['body']['next_cursor'] : null;
			if ( ! $cursor ) {
				break;
			}
		}
		foreach ( $forms as $form ) {
			if ( ! empty( $form['embed']['script_url'] ) ) {
				Settings::update( array( 'script_url' => esc_url_raw( $form['embed']['script_url'] ) ) );
				break;
			}
		}
		set_transient( self::CACHE, $forms, 10 * MINUTE_IN_SECONDS );
		return $forms;
	}

	/**
	 * One form from the list.
	 *
	 * @param string $uid Form uid.
	 * @return array|null
	 */
	public static function find( $uid ) {
		foreach ( self::all() as $form ) {
			if ( $form['uid'] === $uid ) {
				return $form;
			}
		}
		return null;
	}

	/**
	 * The embed script URL: the last one mailtonic gave, else the app URL's.
	 *
	 * @return string
	 */
	public static function script_url() {
		$url = Settings::get( 'script_url' );
		return $url ? $url : Settings::app_url() . '/p/forms/embed.js';
	}

	/**
	 * The embed element for a form, once per page.
	 *
	 * @param string $uid Form uid.
	 * @return string
	 */
	private static function element( $uid ) {
		if ( isset( self::$printed[ $uid ] ) ) {
			return '';
		}
		self::$printed[ $uid ] = true;
		return wp_get_script_tag(
			array(
				'async'        => true,
				'src'          => esc_url_raw( self::script_url() ),
				'data-mt-form' => $uid,
			)
		);
	}

	/**
	 * The [mailtonic_form id="…"] shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$uid = is_array( $atts ) && isset( $atts['id'] ) ? (string) $atts['id'] : '';
		if ( ! preg_match( self::UID, $uid ) ) {
			return current_user_can( 'edit_posts' ) ? '<!-- mailtonic: unknown form -->' : '';
		}
		self::all();
		return self::element( $uid );
	}

	/**
	 * Registers the mailtonic/form block (server-rendered).
	 */
	public static function register_block() {
		register_block_type(
			dirname( MAILTONIC_FILE ) . '/blocks/form',
			array(
				'render_callback' => static function ( $attributes ) {
					return self::shortcode( array( 'id' => isset( $attributes['formUid'] ) ? $attributes['formUid'] : '' ) );
				},
			)
		);
	}

	/**
	 * Passes the form list to the block editor.
	 */
	public static function editor_assets() {
		wp_add_inline_script( 'mailtonic-form-editor-script', 'window.mailtonicForms = ' . wp_json_encode( self::editor_data() ) . ';', 'before' );
	}

	/**
	 * Data for the block editor's form picker: each form with its draft or popup notice and allowed-sites warning.
	 *
	 * @return array
	 */
	public static function editor_data() {
		$forms = array();
		foreach ( self::all() as $form ) {
			$notice = '';
			if ( 'published' !== $form['status'] ) {
				$notice = __( "This form is a draft in mailtonic and won't show on your site until it's published", 'mailtonic' );
			} elseif ( 'popup' === $form['kind'] ) {
				$notice = __( 'Popups appear by their own trigger; this block marks where to load it', 'mailtonic' );
			}
			$forms[] = array(
				'uid'     => $form['uid'],
				'name'    => $form['name'],
				'kind'    => $form['kind'],
				'status'  => $form['status'],
				'notice'  => $notice,
				'warning' => self::origin_warning( $form ),
			);
		}
		return array(
			'connected'   => Settings::connected(),
			'settingsUrl' => admin_url( 'admin.php?page=mailtonic' ),
			'forms'       => $forms,
		);
	}

	/**
	 * The site's origin: scheme and host (and port) of the home URL.
	 *
	 * @return string
	 */
	public static function origin() {
		$parts = wp_parse_url( home_url() );
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * The allowed-sites warning when the form's allowed origins exclude this site.
	 *
	 * @param array $form Form object.
	 * @return string
	 */
	public static function origin_warning( array $form ) {
		$allowed = isset( $form['allowed_origins'] ) ? (array) $form['allowed_origins'] : array();
		if ( ! $allowed ) {
			return '';
		}
		$site = self::origin();
		foreach ( $allowed as $origin ) {
			$origin = strtolower( untrailingslashit( (string) $origin ) );
			if ( $origin === $site ) {
				return '';
			}
			if ( preg_match( '#^(https?)://\*\.(.+)$#', $origin, $m ) && str_starts_with( $site, $m[1] . '://' ) && str_ends_with( $site, '.' . $m[2] ) ) {
				return '';
			}
		}
		/* translators: %s: this site's origin, for example https://shop.example */
		return sprintf( __( 'This form is limited to other websites in mailtonic. Add %s to its allowed websites.', 'mailtonic' ), $site );
	}

	/**
	 * Prints the site-wide popups once, before the closing body tag, on public pages only.
	 */
	public static function print_popups() {
		if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || wp_is_rest_endpoint() || wp_is_json_request() ) {
			return;
		}
		/**
		 * Filters whether site-wide popups load on the current page.
		 *
		 * @param bool $show Whether to print them.
		 */
		if ( ! apply_filters( 'mailtonic_show_popups', true ) ) {
			return;
		}
		foreach ( (array) Settings::get( 'popups', array() ) as $uid ) {
			if ( preg_match( self::UID, $uid ) ) {
				echo self::element( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_script_tag() escapes its attributes.
			}
		}
	}

	/**
	 * Saves the site-wide popups (0-10 form uids).
	 *
	 * @param array $uids Form uids.
	 * @return string|null Error message, or null when saved.
	 */
	public static function save_popups( array $uids ) {
		$uids = array_values( array_unique( array_filter( array_map( 'strval', $uids ), static fn( $uid ) => (bool) preg_match( self::UID, $uid ) ) ) );
		if ( count( $uids ) > self::MAX_POPUPS ) {
			return __( 'Choose at most 10 popups.', 'mailtonic' );
		}
		Settings::update( array( 'popups' => $uids ) );
		return null;
	}

	/**
	 * Registers the Elementor widget while Elementor is active.
	 *
	 * @param object $widgets_manager Elementor's widgets manager.
	 */
	public static function register_widget( $widgets_manager ) {
		if ( ! Integrations::active( 'elementor' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once __DIR__ . '/class-elementor-widget.php';
		$widgets_manager->register( new Elementor_Widget() );
	}
}
