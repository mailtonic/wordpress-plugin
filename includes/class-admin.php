<?php
/**
 * Admin screens (Connection, Forms, WooCommerce, Integrations, Sync log), their form handlers,
 * notices and the status line. Built with WordPress's own admin markup.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
final class Admin {
	const PAGES = array(
		'connection'   => 'mailtonic',
		'forms'        => 'mailtonic-forms',
		'woocommerce'  => 'mailtonic-woocommerce',
		'integrations' => 'mailtonic-integrations',
		'log'          => 'mailtonic-log',
	);

	const ACTIONS = array( 'connection', 'check', 'disconnect', 'refresh_forms', 'popups', 'bridge', 'woocommerce', 'wc_fields', 'wc_import', 'retry', 'retry_all', 'dismiss_notice' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_notices', array( self::class, 'connect_notice' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_mailtonic_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	/**
	 * The top-level menu and its screens.
	 */
	public static function menu() {
		add_menu_page( __( 'mailtonic', 'mailtonic' ), __( 'mailtonic', 'mailtonic' ), 'manage_options', 'mailtonic', array( self::class, 'page_connection' ), 'dashicons-email-alt' );
		add_submenu_page( 'mailtonic', __( 'Connection', 'mailtonic' ), __( 'Connection', 'mailtonic' ), 'manage_options', 'mailtonic', array( self::class, 'page_connection' ) );
		add_submenu_page( 'mailtonic', __( 'Forms', 'mailtonic' ), __( 'Forms', 'mailtonic' ), 'manage_options', 'mailtonic-forms', array( self::class, 'page_forms' ) );
		if ( Integrations::active( 'woocommerce' ) ) {
			add_submenu_page( 'mailtonic', __( 'WooCommerce', 'mailtonic' ), __( 'WooCommerce', 'mailtonic' ), 'manage_options', 'mailtonic-woocommerce', array( self::class, 'page_woocommerce' ) );
		}
		add_submenu_page( 'mailtonic', __( 'Integrations', 'mailtonic' ), __( 'Integrations', 'mailtonic' ), 'manage_options', 'mailtonic-integrations', array( self::class, 'page_integrations' ) );
		add_submenu_page( 'mailtonic', __( 'Sync log', 'mailtonic' ), __( 'Sync log', 'mailtonic' ), 'manage_options', 'mailtonic-log', array( self::class, 'page_log' ) );
	}

	/**
	 * Renders a screen by name.
	 *
	 * @param string $page connection, forms, woocommerce, integrations or log.
	 */
	public static function render( $page ) {
		call_user_func( array( self::class, 'page_' . $page ) );
	}

	// ----- Notices, guards and the status line.

	/**
	 * Queues a notice for the current user's next screen.
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Message.
	 */
	public static function notice( $type, $message ) {
		$key       = 'mailtonic_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array( $type, $message );
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Notices for a result of connect, save or similar.
	 *
	 * @param array  $result  { ok, errors, warnings }.
	 * @param string $success Message on success.
	 */
	private static function result( array $result, $success ) {
		foreach ( $result['errors'] as $error ) {
			self::notice( 'error', $error );
		}
		if ( $result['ok'] ) {
			self::notice( 'success', $success );
		}
		foreach ( $result['warnings'] as $warning ) {
			self::notice( 'warning', $warning );
		}
	}

	/**
	 * Prints and clears the current user's notices.
	 */
	public static function print_notices() {
		$key     = 'mailtonic_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		delete_transient( $key );
		foreach ( is_array( $notices ) ? $notices : array() as $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	/**
	 * Checks the capability and the nonce of an admin action.
	 *
	 * @param string $action Action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mailtonic' ), 403 );
		}
		check_admin_referer( 'mailtonic_' . $action );
	}

	/**
	 * Back to the screen the form came from.
	 *
	 * @param string $page Fallback screen slug.
	 */
	private static function back( $page = 'mailtonic' ) {
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/**
	 * A posted value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed Unslashed value; callers sanitise.
	 */
	private static function posted( $key, $fallback = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; callers sanitise.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
	}

	/**
	 * The status line shown on every plugin screen.
	 *
	 * @return string HTML.
	 */
	public static function status_line() {
		$parts = array();
		if ( Settings::connected() ) {
			/* translators: %s: mailtonic account name */
			$parts[] = esc_html( sprintf( __( 'Connected to %s.', 'mailtonic' ), Settings::get( 'account_name', __( 'mailtonic', 'mailtonic' ) ) ) );
		} else {
			$parts[] = esc_html__( 'Not connected.', 'mailtonic' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=mailtonic' ) ) . '">' . esc_html__( 'Connect mailtonic', 'mailtonic' ) . '</a>';
		}
		$paused = Settings::get( 'paused' );
		if ( $paused ) {
			$reasons = Connection::reasons();
			/* translators: %s: why sending is paused */
			$parts[] = '<strong>' . esc_html( sprintf( __( 'Sending to mailtonic is paused: %s', 'mailtonic' ), isset( $reasons[ $paused ] ) ? $reasons[ $paused ] : $paused ) ) . '</strong>';
		}
		$failed = Outbox::recent_failures();
		if ( $failed ) {
			/* translators: %d: number of failed entries */
			$parts[] = esc_html( sprintf( _n( '%d sync entry failed in the last 24 hours.', '%d sync entries failed in the last 24 hours.', $failed, 'mailtonic' ), $failed ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=mailtonic-log&status=failed' ) ) . '">' . esc_html__( 'Open the sync log', 'mailtonic' ) . '</a>';
		}
		return '<p class="mailtonic-status">' . implode( ' ', $parts ) . '</p>';
	}

	/**
	 * Screen header: title, status line, notices.
	 *
	 * @param string $title Title.
	 */
	private static function header( $title ) {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		echo wp_kses_post( self::status_line() );
		self::print_notices();
	}

	/**
	 * Opens an admin-post form.
	 *
	 * @param string $action Action without prefix.
	 */
	private static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mailtonic_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'mailtonic_' . $action );
	}

	/**
	 * A single-button admin-post form.
	 *
	 * @param string $action Action.
	 * @param string $label  Button label.
	 * @param array  $hidden Hidden fields.
	 * @param string $button Button class.
	 */
	private static function button_form( $action, $label, array $hidden = array(), $button = 'button' ) {
		self::form_open( $action );
		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		submit_button( $label, $button, 'submit', false );
		echo '</form>';
	}

	/**
	 * The one "Connect mailtonic" notice shown after activation outside the plugin's screens.
	 */
	public static function connect_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! get_option( 'mailtonic_connect_notice' ) || Settings::connected() || ! current_user_can( 'manage_options' ) || ( $screen && false !== strpos( (string) $screen->id, 'mailtonic' ) ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=mailtonic_dismiss_notice' ), 'mailtonic_dismiss_notice' );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%1$s <a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'mailtonic is installed. Connect it to your mailtonic account to embed forms and sync sign-ups.', 'mailtonic' ),
			esc_url( admin_url( 'admin.php?page=mailtonic' ) ),
			esc_html__( 'Connect mailtonic', 'mailtonic' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'mailtonic' )
		);
	}

	/**
	 * Masks emails in a string: a***@example.com.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( $text ) {
		return (string) preg_replace_callback(
			'/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
			static fn( $m ) => $m[1] . '***@' . $m[2],
			(string) $text
		);
	}

	// ----- Connection.

	/** The Connection screen. */
	public static function page_connection() {
		self::header( __( 'mailtonic connection', 'mailtonic' ) );
		$connected = Settings::connected();
		self::form_open( 'connection' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="mailtonic-api-key">' . esc_html__( 'API key', 'mailtonic' ) . '</label></th><td>';
		if ( Settings::key_from_constant() ) {
			echo '<input type="text" id="mailtonic-api-key" name="api_key" class="regular-text" value="' . esc_attr( Settings::masked_key() ) . '" readonly>';
			echo '<p class="description">' . esc_html__( 'This key is set by the MAILTONIC_API_KEY constant in wp-config.php, so it can only be changed there.', 'mailtonic' ) . '</p>';
		} elseif ( $connected ) {
			echo '<code>' . esc_html( Settings::masked_key() ) . '</code>';
			echo '<details><summary>' . esc_html__( 'Replace key', 'mailtonic' ) . '</summary>';
			echo '<input type="password" id="mailtonic-api-key" name="api_key" class="regular-text" autocomplete="off" value="">';
			echo '</details>';
		} else {
			echo '<input type="password" id="mailtonic-api-key" name="api_key" class="regular-text" autocomplete="off" value="" required>';
			echo '<p class="description">' . esc_html__( 'Create a key with write access in mailtonic under Settings › API keys, then paste it here.', 'mailtonic' ) . '</p>';
		}
		echo '</td></tr>';

		$lists = Lists::all();
		if ( $connected && $lists ) {
			echo '<tr><th scope="row"><label for="mailtonic-default-list">' . esc_html__( 'Default list', 'mailtonic' ) . '</label></th><td><select id="mailtonic-default-list" name="default_list">';
			foreach ( $lists as $uid => $name ) {
				echo '<option value="' . esc_attr( $uid ) . '"' . selected( Settings::get( 'default_list' ), $uid, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select><p class="description">' . esc_html__( 'Used by WooCommerce sync and as the default for new form bridges.', 'mailtonic' ) . '</p></td></tr>';
		}

		echo '<tr><th scope="row"><label for="mailtonic-app-url">' . esc_html__( 'App URL', 'mailtonic' ) . '</label></th><td><details' . ( Settings::DEFAULT_APP !== Settings::app_url() ? ' open' : '' ) . '><summary>' . esc_html__( 'Advanced', 'mailtonic' ) . '</summary>';
		echo '<input type="url" id="mailtonic-app-url" name="app_url" class="regular-text" value="' . esc_attr( Settings::app_url() ) . '"' . ( defined( 'MAILTONIC_APP_URL' ) ? ' readonly' : '' ) . '>';
		echo '<p class="description">' . esc_html__( 'Change only for a staging account. Must start with https://.', 'mailtonic' ) . '</p></details></td></tr>';
		echo '</tbody></table>';
		submit_button( $connected ? __( 'Save', 'mailtonic' ) : __( 'Connect', 'mailtonic' ) );
		echo '</form>';

		if ( $connected ) {
			echo '<h2>' . esc_html__( 'Account', 'mailtonic' ) . '</h2><table class="widefat striped" style="max-width:40em"><tbody>';
			foreach ( array(
				'account_name' => __( 'Account', 'mailtonic' ),
				'key_name'     => __( 'Key name', 'mailtonic' ),
				'member_email' => __( 'Member', 'mailtonic' ),
				'member_role'  => __( 'Role', 'mailtonic' ),
			) as $key => $label ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) Settings::get( $key, '' ) ) . '</td></tr>';
			}
			echo '</tbody></table><p>';
			self::button_form( 'check', __( 'Check connection', 'mailtonic' ) );
			echo '</p><p>';
			self::button_form( 'disconnect', __( 'Disconnect', 'mailtonic' ), array(), 'delete' );
			echo '</p>';
		}
		echo '</div>';
	}

	/** Saves the connection. */
	public static function handle_connection() {
		self::guard( 'connection' );
		$result = Connection::connect(
			array(
				'app_url'      => sanitize_text_field( (string) self::posted( 'app_url' ) ),
				'api_key'      => sanitize_text_field( (string) self::posted( 'api_key' ) ),
				'default_list' => sanitize_text_field( (string) self::posted( 'default_list' ) ),
			)
		);
		if ( $result['ok'] ) {
			delete_option( 'mailtonic_connect_notice' );
		}
		self::result( $result, __( 'Connected to mailtonic.', 'mailtonic' ) );
		self::back();
	}

	/** Runs the connect check. */
	public static function handle_check() {
		self::guard( 'check' );
		self::result( Connection::check(), __( 'mailtonic accepted this key.', 'mailtonic' ) );
		self::back();
	}

	/** Disconnects. */
	public static function handle_disconnect() {
		self::guard( 'disconnect' );
		Connection::disconnect();
		self::notice( 'success', __( 'Disconnected. Embedded forms keep working; sign-ups wait for 7 days for a reconnect.', 'mailtonic' ) );
		self::back();
	}

	/** Hides the activation notice. */
	public static function handle_dismiss_notice() {
		self::guard( 'dismiss_notice' );
		delete_option( 'mailtonic_connect_notice' );
		self::back( 'index.php' );
	}

	// ----- Forms.

	/** The Forms screen. */
	public static function page_forms() {
		self::header( __( 'mailtonic forms', 'mailtonic' ) );
		if ( ! Settings::connected() ) {
			echo '<p>' . esc_html__( 'Connect mailtonic to list your forms.', 'mailtonic' ) . '</p></div>';
			return;
		}
		$forms = Forms::all();
		$lists = Lists::all();
		echo '<p>' . esc_html__( 'Add a form with the "mailtonic form" block, the Elementor widget, or its shortcode. A site with its own Content-Security-Policy must allow the embed host in script-src and frame-src.', 'mailtonic' ) . '</p>';
		self::button_form( 'refresh_forms', __( 'Refresh', 'mailtonic' ) );
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( __( 'Name', 'mailtonic' ), __( 'Kind', 'mailtonic' ), __( 'Status', 'mailtonic' ), __( 'List', 'mailtonic' ), __( 'Last change', 'mailtonic' ), __( 'Shortcode', 'mailtonic' ) ) as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $forms ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No forms yet. Create one in mailtonic, then refresh.', 'mailtonic' ) . '</td></tr>';
		}
		foreach ( $forms as $form ) {
			$warning = Forms::origin_warning( $form );
			echo '<tr><td><strong>' . esc_html( $form['name'] ) . '</strong>';
			if ( $warning ) {
				echo '<p class="notice notice-warning inline">' . esc_html( $warning ) . '</p>';
			}
			echo '</td><td>' . esc_html( 'popup' === $form['kind'] ? __( 'Popup', 'mailtonic' ) : __( 'Inline', 'mailtonic' ) ) . '</td>';
			echo '<td>' . esc_html( 'published' === $form['status'] ? __( 'Published', 'mailtonic' ) : __( 'Draft in mailtonic', 'mailtonic' ) ) . '</td>';
			echo '<td>' . esc_html( isset( $lists[ $form['list_uid'] ] ) ? $lists[ $form['list_uid'] ] : (string) $form['list_uid'] ) . '</td>';
			echo '<td>' . esc_html( $form['updated_at'] ? wp_date( get_option( 'date_format' ), strtotime( $form['updated_at'] ) ) : '' ) . '</td>';
			echo '<td><code>[mailtonic_form id="' . esc_html( $form['uid'] ) . '"]</code></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Site-wide popups', 'mailtonic' ) . '</h2><p>' . esc_html__( 'Load up to 10 published popups on every page. Each popup keeps its own trigger, device and page rules from mailtonic.', 'mailtonic' ) . '</p>';
		self::form_open( 'popups' );
		$chosen = (array) Settings::get( 'popups', array() );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Site-wide popups', 'mailtonic' ) . '</legend>';
		foreach ( $forms as $form ) {
			if ( 'popup' === $form['kind'] && ( 'published' === $form['status'] || in_array( $form['uid'], $chosen, true ) ) ) {
				echo '<label><input type="checkbox" name="popups[]" value="' . esc_attr( $form['uid'] ) . '"' . checked( in_array( $form['uid'], $chosen, true ), true, false ) . '> ' . esc_html( $form['name'] ) . '</label><br>';
			}
		}
		echo '</fieldset>';
		submit_button( __( 'Save popups', 'mailtonic' ) );
		echo '</form></div>';
	}

	/** Clears the form list cache. */
	public static function handle_refresh_forms() {
		self::guard( 'refresh_forms' );
		delete_transient( Forms::CACHE );
		self::back( 'mailtonic-forms' );
	}

	/** Saves site-wide popups. */
	public static function handle_popups() {
		self::guard( 'popups' );
		$error = Forms::save_popups( array_map( 'sanitize_text_field', (array) self::posted( 'popups', array() ) ) );
		self::notice( $error ? 'error' : 'success', $error ? $error : __( 'Popups saved.', 'mailtonic' ) );
		self::back( 'mailtonic-forms' );
	}

	// ----- Integrations.

	/** The Integrations screen: the bridge editor, or the overview. */
	public static function page_integrations() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen parameters.
		$plugin = isset( $_GET['plugin'] ) ? sanitize_key( $_GET['plugin'] ) : '';
		$form   = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		// phpcs:enable
		self::header( __( 'mailtonic integrations', 'mailtonic' ) );
		$forms = $plugin ? Bridges::forms( $plugin ) : array();
		if ( isset( $forms[ $form ] ) ) {
			self::bridge_editor( $plugin, $form, $forms[ $form ] );
			echo '</div>';
			return;
		}
		$names  = Bridges::names();
		$active = Bridges::active_plugins();
		if ( ! $active ) {
			echo '<p>' . esc_html__( 'Install Contact Form 7, WPForms or Elementor Pro to send their sign-ups to mailtonic.', 'mailtonic' ) . '</p></div>';
			return;
		}
		/* translators: %s: comma-separated form plugin names */
		echo '<p>' . esc_html( sprintf( __( 'Send sign-ups from %s forms to a mailtonic list. Only visitors who tick the form\'s consent box are sent.', 'mailtonic' ), implode( ', ', array_intersect_key( $names, array_flip( $active ) ) ) ) ) . '</p>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( __( 'Form', 'mailtonic' ), __( 'Plugin', 'mailtonic' ), __( 'List', 'mailtonic' ), __( 'Status', 'mailtonic' ) ) as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$lists = Lists::all();
		$rows  = Bridges::overview();
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No forms found.', 'mailtonic' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$bridge = $row['bridge'];
			$url    = admin_url( 'admin.php?page=mailtonic-integrations&plugin=' . rawurlencode( $row['plugin'] ) . '&form=' . rawurlencode( $row['form_id'] ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( Bridges::form_ref( $row['title'], $row['form_id'] ) ) . '</a></td>';
			echo '<td>' . esc_html( $names[ $row['plugin'] ] ) . '</td>';
			echo '<td>' . esc_html( $bridge ? ( isset( $lists[ $bridge['list'] ] ) ? $lists[ $bridge['list'] ] : $bridge['list'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $bridge ? ( $bridge['enabled'] ? __( 'On', 'mailtonic' ) : __( 'Off', 'mailtonic' ) ) : __( 'Not set up', 'mailtonic' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The bridge editor for one form.
	 *
	 * @param string $plugin  Plugin key.
	 * @param string $form_id Form id.
	 * @param array  $form    Form.
	 */
	private static function bridge_editor( $plugin, $form_id, array $form ) {
		$bridge = Bridges::get( $plugin, $form_id );
		$list   = $bridge ? $bridge['list'] : (string) Settings::get( 'default_list', '' );
		$map    = $bridge ? $bridge['map'] : array();
		echo '<h2>' . esc_html( Bridges::form_ref( $form['title'], $form_id ) ) . '</h2>';
		self::form_open( 'bridge' );
		echo '<input type="hidden" name="plugin" value="' . esc_attr( $plugin ) . '"><input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Send sign-ups', 'mailtonic' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $bridge && $bridge['enabled'], true, false ) . '> ' . esc_html__( 'Send this form\'s sign-ups to mailtonic', 'mailtonic' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="mailtonic-bridge-list">' . esc_html__( 'List', 'mailtonic' ) . '</label></th><td><select id="mailtonic-bridge-list" name="list">';
		foreach ( Lists::all() as $uid => $name ) {
			echo '<option value="' . esc_attr( $uid ) . '"' . selected( $list, $uid, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></td></tr>';
		$fields = Lists::fields( $list );
		if ( ! $fields ) {
			$fields = array(
				array(
					'key'   => 'email',
					'label' => __( 'Email', 'mailtonic' ),
				),
			);
		}
		foreach ( $fields as $field ) {
			$id = 'mailtonic-map-' . sanitize_key( $field['key'] );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( 'email' === $field['key'] ? ' *' : '' ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="map[' . esc_attr( $field['key'] ) . ']"><option value="">' . esc_html__( '— Not mapped —', 'mailtonic' ) . '</option>';
			foreach ( $form['fields'] as $name => $form_field ) {
				echo '<option value="' . esc_attr( $name ) . '"' . selected( isset( $map[ $field['key'] ] ) ? $map[ $field['key'] ] : '', $name, false ) . '>' . esc_html( ( '' !== $form_field['label'] ? $form_field['label'] : $name ) . ' (' . $name . ')' ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '<tr><th scope="row"><label for="mailtonic-bridge-consent">' . esc_html__( 'Consent checkbox', 'mailtonic' ) . ' *</label></th><td><select id="mailtonic-bridge-consent" name="consent_field"><option value="">' . esc_html__( '— Choose —', 'mailtonic' ) . '</option>';
		foreach ( $form['fields'] as $name => $form_field ) {
			if ( $form_field['consent'] ) {
				echo '<option value="' . esc_attr( $name ) . '"' . selected( $bridge ? $bridge['consent_field'] : '', $name, false ) . '>' . esc_html( ( '' !== $form_field['label'] ? $form_field['label'] : $name ) ) . '</option>';
			}
		}
		echo '</select><p class="description">' . esc_html__( 'The box visitors tick to agree to marketing emails. Its label is recorded as the wording they agreed to.', 'mailtonic' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mailtonic-bridge-tags">' . esc_html__( 'Tags', 'mailtonic' ) . '</label></th><td><input type="text" id="mailtonic-bridge-tags" name="tags" class="regular-text" value="' . esc_attr( implode( ', ', $bridge ? $bridge['tags'] : array() ) ) . '"><p class="description">' . esc_html__( 'Up to 10, separated by commas.', 'mailtonic' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save bridge', 'mailtonic' ) );
		echo '</form>';
	}

	/** Saves a bridge. */
	public static function handle_bridge() {
		self::guard( 'bridge' );
		self::result(
			Bridges::save(
				array(
					'plugin'        => sanitize_key( (string) self::posted( 'plugin' ) ),
					'form_id'       => sanitize_text_field( (string) self::posted( 'form_id' ) ),
					'enabled'       => (bool) self::posted( 'enabled', false ),
					'list'          => sanitize_text_field( (string) self::posted( 'list' ) ),
					'map'           => array_map( 'sanitize_text_field', (array) self::posted( 'map', array() ) ),
					'tags'          => sanitize_text_field( (string) self::posted( 'tags' ) ),
					'consent_field' => sanitize_text_field( (string) self::posted( 'consent_field' ) ),
				)
			),
			__( 'Bridge saved.', 'mailtonic' )
		);
		self::back( 'mailtonic-integrations' );
	}

	// ----- WooCommerce.

	/** The WooCommerce screen. */
	public static function page_woocommerce() {
		self::header( __( 'mailtonic for WooCommerce', 'mailtonic' ) );
		if ( ! Integrations::active( 'woocommerce' ) ) {
			echo '<p>' . esc_html__( 'Activate WooCommerce to sync customers, orders and carts.', 'mailtonic' ) . '</p></div>';
			return;
		}
		$wc = Settings::wc();
		self::form_open( 'woocommerce' );
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Parts', 'mailtonic' ) . '</th><td><fieldset>';
		foreach ( array(
			'optin'            => __( 'Show the unticked opt-in box at checkout', 'mailtonic' ),
			'registration_box' => __( 'Also show it on the My Account registration form', 'mailtonic' ),
			'signup'           => __( 'Sign up buyers who tick the box', 'mailtonic' ),
			'events'           => __( 'Send order_placed events and order fields for opted-in buyers', 'mailtonic' ),
			'carts'            => __( 'Send cart_abandoned events for opted-in shoppers', 'mailtonic' ),
		) as $key => $label ) {
			echo '<label><input type="checkbox" name="wc[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $wc[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		if ( ! Woo::block_field_supported() ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'This WooCommerce version cannot add the opt-in box to the checkout block, so shoppers using it cannot opt in. Update WooCommerce to 8.9 or later, or use the classic checkout.', 'mailtonic' ) . '</p>';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="mailtonic-wc-label">' . esc_html__( 'Opt-in label', 'mailtonic' ) . '</label></th><td><input type="text" id="mailtonic-wc-label" name="wc[label]" class="large-text" maxlength="300" value="' . esc_attr( '' !== $wc['label'] ? $wc['label'] : __( 'Email me news and offers from {store}. I can unsubscribe at any time.', 'mailtonic' ) ) . '"><p class="description">' . esc_html__( '{store} is replaced by your site name.', 'mailtonic' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mailtonic-wc-tags">' . esc_html__( 'Tags', 'mailtonic' ) . '</label></th><td><input type="text" id="mailtonic-wc-tags" name="wc[tags]" class="regular-text" value="' . esc_attr( implode( ', ', (array) $wc['tags'] ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="mailtonic-wc-idle">' . esc_html__( 'Cart idle time (minutes)', 'mailtonic' ) . '</label></th><td><input type="number" id="mailtonic-wc-idle" name="wc[idle_minutes]" min="30" max="1440" value="' . esc_attr( (string) $wc['idle_minutes'] ) . '"></td></tr>';
		$props = array( 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_city', 'billing_country', 'billing_company', 'billing_postcode', 'billing_state' );
		foreach ( (array) $wc['map'] + array_fill_keys( array( 'first_name', 'last_name', 'phone', 'city', 'country' ), '' ) as $key => $prop ) {
			$id = 'mailtonic-wc-map-' . sanitize_key( $key );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( sprintf( /* translators: %s: list field key */ __( 'List field %s', 'mailtonic' ), $key ) ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="wc[map][' . esc_attr( $key ) . ']"><option value="">' . esc_html__( '— Not sent —', 'mailtonic' ) . '</option>';
			foreach ( $props as $p ) {
				echo '<option value="' . esc_attr( $p ) . '"' . selected( $prop, $p, false ) . '>' . esc_html( $p ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save WooCommerce settings', 'mailtonic' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Order and cart fields', 'mailtonic' ) . '</h2><p>' . esc_html__( 'Adds Orders, Total spent, Last order, Last order number, Cart link and Cart total to your default list, so flow emails can show them.', 'mailtonic' ) . '</p>';
		self::button_form( 'wc_fields', __( 'Add WooCommerce fields to my list', 'mailtonic' ) );

		echo '<h2>' . esc_html__( 'Import past customers', 'mailtonic' ) . '</h2>';
		$progress = Settings::import();
		if ( $progress ) {
			/* translators: 1: batches sent, 2: batches in all */
			echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d batches sent', 'mailtonic' ), $progress['sent'], $progress['total'] ) );
			if ( $progress['failed'] ) {
				/* translators: %d: failed batches */
				echo ' · ' . esc_html( sprintf( __( '%d failed (see the sync log)', 'mailtonic' ), $progress['failed'] ) );
			}
			$c = $progress['counts'];
			/* translators: 1: inserted, 2: updated, 3: unchanged, 4: suppressed */
			echo '<br>' . esc_html( sprintf( __( 'Inserted %1$d, updated %2$d, unchanged %3$d, suppressed %4$d', 'mailtonic' ), $c['inserted'], $c['updated'], $c['unchanged'], $c['suppressed'] ) );
			foreach ( $c['skipped'] as $reason => $n ) {
				/* translators: 1: reason code, 2: count */
				echo '<br>' . esc_html( sprintf( __( 'Skipped (%1$s): %2$d', 'mailtonic' ), $reason, $n ) );
			}
			echo '</p>';
		}
		self::form_open( 'wc_import' );
		echo '<fieldset><legend><strong>' . esc_html__( 'Where did these customers come from?', 'mailtonic' ) . '</strong></legend>';
		foreach ( array(
			'customers'    => __( 'They are my customers', 'mailtonic' ),
			'website_form' => __( 'They signed up on my website', 'mailtonic' ),
			'in_person'    => __( 'They signed up in person', 'mailtonic' ),
			'other_opt_in' => __( 'They opted in another way', 'mailtonic' ),
		) as $value => $label ) {
			echo '<label><input type="radio" name="source" value="' . esc_attr( $value ) . '"' . checked( 'customers', $value, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset><p><label><input type="checkbox" name="only_opted_in" value="1"> ' . esc_html__( 'Only customers who opted in', 'mailtonic' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="statement" value="1" required> ' . esc_html( Woo::statement() ) . '</label></p>';
		submit_button( __( 'Import past customers', 'mailtonic' ), 'secondary' );
		echo '</form></div>';
	}

	/** Saves WooCommerce settings. */
	public static function handle_woocommerce() {
		self::guard( 'woocommerce' );
		$in     = (array) self::posted( 'wc', array() );
		$label  = sanitize_text_field( isset( $in['label'] ) ? (string) $in['label'] : '' );
		$tags   = Bridges::tags( isset( $in['tags'] ) ? (string) $in['tags'] : '' );
		$errors = array();
		if ( mb_strlen( $label ) < 1 || mb_strlen( $label ) > 300 ) {
			$errors[] = __( 'The opt-in label must be 1 to 300 characters.', 'mailtonic' );
		}
		if ( null === $tags ) {
			$errors[] = __( 'Use at most 10 tags of up to 60 characters each.', 'mailtonic' );
		}
		if ( ! $errors ) {
			$map = array();
			foreach ( isset( $in['map'] ) ? (array) $in['map'] : array() as $key => $prop ) {
				$prop = sanitize_key( $prop );
				if ( '' !== $prop && str_starts_with( $prop, 'billing_' ) ) {
					$map[ sanitize_key( $key ) ] = $prop;
				}
			}
			$values = array(
				'label'        => $label,
				'tags'         => $tags,
				'map'          => $map,
				'idle_minutes' => min( 1440, max( 30, absint( isset( $in['idle_minutes'] ) ? $in['idle_minutes'] : 60 ) ) ),
			);
			foreach ( array( 'optin', 'registration_box', 'signup', 'events', 'carts' ) as $part ) {
				$values[ $part ] = ! empty( $in[ $part ] );
			}
			Settings::update_wc( $values );
		}
		self::result(
			array(
				'ok'       => ! $errors,
				'errors'   => $errors,
				'warnings' => array(),
			),
			__( 'WooCommerce settings saved.', 'mailtonic' )
		);
		self::back( 'mailtonic-woocommerce' );
	}

	/** Creates the WooCommerce fields. */
	public static function handle_wc_fields() {
		self::guard( 'wc_fields' );
		$result = Woo::create_fields();
		/* translators: %d: number of fields created */
		self::result( $result, sprintf( _n( '%d field added to your list.', '%d fields added to your list.', $result['created'], 'mailtonic' ), $result['created'] ) );
		self::back( 'mailtonic-woocommerce' );
	}

	/** Starts the past-customer import. */
	public static function handle_wc_import() {
		self::guard( 'wc_import' );
		self::result(
			Woo::start_import(
				array(
					'source'        => sanitize_key( (string) self::posted( 'source' ) ),
					'statement'     => (bool) self::posted( 'statement', false ),
					'only_opted_in' => (bool) self::posted( 'only_opted_in', false ),
				)
			),
			__( 'Import started. Batches are sent in the background.', 'mailtonic' )
		);
		self::back( 'mailtonic-woocommerce' );
	}

	// ----- Sync log.

	/** The Sync log screen. */
	public static function page_log() {
		self::header( __( 'mailtonic sync log', 'mailtonic' ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$source = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
		// phpcs:enable
		$statuses = array(
			'pending' => __( 'Pending', 'mailtonic' ),
			'sent'    => __( 'Sent', 'mailtonic' ),
			'failed'  => __( 'Failed', 'mailtonic' ),
			'skipped' => __( 'Skipped', 'mailtonic' ),
		);
		$sources  = array(
			'woocommerce' => 'WooCommerce',
			'cf7'         => 'Contact Form 7',
			'wpforms'     => 'WPForms',
			'elementor'   => 'Elementor',
			'admin'       => __( 'Admin', 'mailtonic' ),
		);
		echo '<form method="get"><input type="hidden" name="page" value="mailtonic-log">';
		echo '<label for="mailtonic-log-status" class="screen-reader-text">' . esc_html__( 'Status', 'mailtonic' ) . '</label><select id="mailtonic-log-status" name="status"><option value="">' . esc_html__( 'All statuses', 'mailtonic' ) . '</option>';
		foreach ( $statuses as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <label for="mailtonic-log-source" class="screen-reader-text">' . esc_html__( 'Source', 'mailtonic' ) . '</label><select id="mailtonic-log-source" name="source"><option value="">' . esc_html__( 'All sources', 'mailtonic' ) . '</option>';
		foreach ( $sources as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $source, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'mailtonic' ), 'secondary', '', false );
		echo '</form>';
		self::button_form( 'retry_all', __( 'Retry all failed', 'mailtonic' ) );

		$where = array_filter(
			array(
				'status' => isset( $statuses[ $status ] ) ? $status : '',
				'source' => isset( $sources[ $source ] ) ? $source : '',
			)
		);
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( __( 'Time', 'mailtonic' ), __( 'Source', 'mailtonic' ), __( 'Reference', 'mailtonic' ), __( 'Kind', 'mailtonic' ), __( 'Contact', 'mailtonic' ), __( 'Status', 'mailtonic' ), __( 'Attempts', 'mailtonic' ), __( 'Last answer', 'mailtonic' ), '' ) as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = Outbox::find( $where, 200 );
		if ( ! $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'Nothing here yet.', 'mailtonic' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$body  = json_decode( $row['body'], true );
			$email = isset( $body['email'] ) ? (string) $body['email'] : ( 'contact_update' === $row['kind'] ? rawurldecode( (string) substr( strrchr( $row['path'], '/' ), 1 ) ) : '' );
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( isset( $sources[ $row['source'] ] ) ? $sources[ $row['source'] ] : $row['source'] ) . '</td>';
			echo '<td>' . esc_html( self::mask( $row['reference'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['kind'] ) . '</td>';
			echo '<td>' . esc_html( self::mask( $email ) ) . '</td>';
			echo '<td>' . esc_html( isset( $statuses[ $row['status'] ] ) ? $statuses[ $row['status'] ] : $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['attempts'] ) . '</td>';
			echo '<td>' . esc_html( trim( $row['last_status'] . ' ' . $row['last_error_code'] ) );
			if ( $row['last_error'] ) {
				echo '<br><small>' . esc_html( self::mask( $row['last_error'] ) ) . '</small>';
			}
			echo '</td><td>';
			if ( 'failed' === $row['status'] ) {
				self::button_form( 'retry', __( 'Retry', 'mailtonic' ), array( 'id' => $row['id'] ), 'button-small' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Retries one failed entry. */
	public static function handle_retry() {
		self::guard( 'retry' );
		Outbox::retry( absint( self::posted( 'id', 0 ) ) );
		self::notice( 'success', __( 'The entry will be sent again in the next run.', 'mailtonic' ) );
		self::back( 'mailtonic-log' );
	}

	/** Retries every failed entry. */
	public static function handle_retry_all() {
		self::guard( 'retry_all' );
		$count = Outbox::retry();
		/* translators: %d: number of entries */
		self::notice( 'success', sprintf( _n( '%d entry will be sent again.', '%d entries will be sent again.', $count, 'mailtonic' ), $count ) );
		self::back( 'mailtonic-log' );
	}
}
