<?php
/**
 * Fixture stubs of Contact Form 7, WPForms Lite and Elementor (with Elementor Pro Forms):
 * just enough of each plugin's public surface to list forms and fire the same after-submit
 * hooks with the same arguments as the real plugins.
 *
 * @package Mailtonic
 */

// phpcs:disable

namespace {

// ----- Contact Form 7.

class WPCF7_FormTag {
	public $type       = '';
	public $basetype   = '';
	public $name       = '';
	public $options    = array();
	public $raw_values = array();
	public $values     = array();
	public $labels     = array();
	public $content    = '';

	public function __construct( array $tag ) {
		foreach ( $tag as $k => $v ) {
			$this->$k = $v;
		}
		$this->basetype = rtrim( $this->type, '*' );
		if ( ! $this->labels ) {
			$this->labels = $this->values;
		}
	}

	public function is_required() {
		return '*' === substr( $this->type, -1 );
	}

	public function has_option( $option ) {
		return in_array( $option, $this->options, true );
	}
}

class WPCF7_ContactForm {
	private static $tags = array();
	private $post;

	public static function create( $title, array $tags ) {
		$id                = wp_insert_post( array( 'post_type' => 'wpcf7_contact_form', 'post_title' => $title, 'post_status' => 'publish' ) );
		self::$tags[ $id ] = array_map(
			static function ( $t ) {
				return new WPCF7_FormTag( $t );
			},
			$tags
		);
		return self::get_instance( $id );
	}

	public static function find( $args = array() ) {
		return array_map( array( __CLASS__, 'get_instance' ), get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}

	public static function get_instance( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'wpcf7_contact_form' !== $post->post_type ) {
			return null;
		}
		$form       = new self();
		$form->post = $post;
		return $form;
	}

	public function id() {
		return $this->post->ID;
	}

	public function title() {
		return $this->post->post_title;
	}

	public function scan_form_tags( $cond = null ) {
		return isset( self::$tags[ $this->post->ID ] ) ? self::$tags[ $this->post->ID ] : array();
	}
}

class WPCF7_Submission {
	private static $instance;
	private $posted;
	private $meta;

	public static function get_instance() {
		return self::$instance;
	}

	public static function fixture( $posted, $meta ) {
		self::$instance         = new self();
		self::$instance->posted = $posted;
		self::$instance->meta   = $meta;
	}

	public function get_posted_data( $name = '' ) {
		return '' === $name ? $this->posted : ( isset( $this->posted[ $name ] ) ? $this->posted[ $name ] : null );
	}

	public function get_meta( $name ) {
		return isset( $this->meta[ $name ] ) ? $this->meta[ $name ] : null;
	}
}

add_action(
	'init',
	static function () {
		register_post_type( 'wpcf7_contact_form', array( 'public' => false ) );
		register_post_type( 'wpforms', array( 'public' => false ) );
	}
);

// ----- WPForms Lite.

function wpforms() {
	static $wpforms;
	return $wpforms ? $wpforms : ( $wpforms = new stdClass() );
}

function wpforms_decode( $data ) {
	return json_decode( $data, true );
}

// ----- Elementor and Elementor Pro Forms.
}

namespace Elementor {

abstract class Widget_Base {
	private $settings = array();
	public $controls  = array();

	public function __construct( $data = array(), $args = null ) {
		$this->settings = isset( $data['settings'] ) ? $data['settings'] : array();
	}

	abstract public function get_name();

	public function get_settings_for_display( $key = null ) {
		return null === $key ? $this->settings : ( isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null );
	}

	public function start_controls_section( $id, $args = array() ) {}

	public function end_controls_section() {}

	public function add_control( $id, $args ) {
		$this->controls[ $id ] = $args;
	}

	public function render_content() {
		$this->render();
	}
}

class Controls_Manager {
	const TAB_CONTENT = 'content';
	const SELECT      = 'select';
	const RAW_HTML    = 'raw_html';
}

}

namespace ElementorPro\Modules\Forms\Classes {

class Form_Record {
	private $data;
	private $form_settings;

	public function __construct( $data, $form_settings ) {
		$this->data          = $data;
		$this->form_settings = $form_settings;
	}

	public function get( $property ) {
		return isset( $this->data[ $property ] ) ? $this->data[ $property ] : null;
	}

	public function get_form_settings( $setting ) {
		return isset( $this->form_settings[ $setting ] ) ? $this->form_settings[ $setting ] : null;
	}
}

}

namespace Mailtonic_Fixtures {

add_action(
	'plugins_loaded',
	static function () {
		do_action( 'elementor/loaded' );
		do_action( 'elementor_pro/init' );
	},
	1
);

/** Creates a CF7 form: tags are arrays of type, name, options, values, labels, content. */
function cf7_form( $title = 'Contact', $consent = array( 'type' => 'acceptance', 'name' => 'consent', 'options' => array( 'optional' ), 'content' => 'Send me news by email' ) ) {
	$tags = array(
		array( 'type' => 'text*', 'name' => 'your-name' ),
		array( 'type' => 'email*', 'name' => 'your-email' ),
		array( 'type' => 'text', 'name' => 'your-city' ),
	);
	if ( $consent ) {
		$tags[] = $consent;
	}
	return \WPCF7_ContactForm::create( $title, $tags )->id();
}

/** Submits a CF7 form; only a valid, non-spam submission fires wpcf7_mail_sent. */
function cf7_submit( $form_id, array $posted, $valid = true ) {
	\WPCF7_Submission::fixture(
		$posted,
		array(
			'remote_ip'  => '198.51.100.7',
			'user_agent' => 'Fixture UA',
			'url'        => 'https://shop.test/contact/?utm=x#top',
			'timestamp'  => time(),
		)
	);
	$form = \WPCF7_ContactForm::get_instance( $form_id );
	if ( $valid ) {
		do_action( 'wpcf7_mail_sent', $form );
		return 'Thank you for your message. It has been sent.';
	}
	do_action( 'wpcf7_validation_error', $form );
	return 'One or more fields have an error.';
}

/** Creates a WPForms form post with name, email, city and a consent checkbox (field ids 1-4). */
function wpforms_form( $title = 'Contact', $consent_required = false, $with_consent = true ) {
	$fields = array(
		1 => array( 'id' => '1', 'type' => 'name', 'label' => 'Name', 'required' => '1' ),
		2 => array( 'id' => '2', 'type' => 'email', 'label' => 'Email', 'required' => '1' ),
		3 => array( 'id' => '3', 'type' => 'text', 'label' => 'City' ),
	);
	if ( $with_consent ) {
		$fields[4] = array(
			'id'       => '4',
			'type'     => 'checkbox',
			'label'    => 'Send me news by email',
			'required' => $consent_required ? '1' : '',
			'choices'  => array( 1 => array( 'label' => 'Yes please' ) ),
		);
	}
	$id = wp_insert_post( array( 'post_type' => 'wpforms', 'post_title' => $title, 'post_status' => 'publish' ) );
	wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => wp_slash( wp_json_encode( array( 'id' => $id, 'fields' => $fields, 'settings' => array( 'form_title' => $title ) ) ) ),
		)
	);
	return $id;
}

/** Submits a WPForms form; only a valid, non-spam submission fires wpforms_process_complete. */
function wpforms_submit( $form_id, array $values, $valid = true ) {
	$form_data = wpforms_decode( get_post( $form_id )->post_content );
	if ( ! $valid ) {
		return 'Please fill out all required fields.';
	}
	$fields = array();
	foreach ( $form_data['fields'] as $id => $field ) {
		$fields[ $id ] = array(
			'name'  => $field['label'],
			'value' => isset( $values[ $id ] ) ? $values[ $id ] : '',
			'id'    => (int) $id,
			'type'  => $field['type'],
		);
	}
	$_SERVER['REMOTE_ADDR']     = '198.51.100.7';
	$_SERVER['HTTP_USER_AGENT'] = 'Fixture UA';
	$_SERVER['HTTP_REFERER']    = 'https://shop.test/contact/?utm=x#top';
	do_action( 'wpforms_process_complete', $fields, array( 'fields' => $values ), $form_data, 0 );
	return 'Thanks for contacting us!';
}

/** Creates a page holding an Elementor Pro form widget; returns the widget (form) id. */
function elementor_form( $title = 'Contact', $consent_required = false, $with_consent = true ) {
	$fields = array(
		array( 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Name', 'required' => 'true' ),
		array( 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email', 'required' => 'true' ),
		array( 'custom_id' => 'city', 'field_type' => 'text', 'field_label' => 'City', 'required' => '' ),
	);
	if ( $with_consent ) {
		$fields[] = array( 'custom_id' => 'consent', 'field_type' => 'acceptance', 'field_label' => '', 'acceptance_text' => 'Send me news by email', 'required' => $consent_required ? 'true' : '' );
	}
	$form_id = substr( md5( $title . wp_rand() ), 0, 7 );
	$data    = array(
		array(
			'id'       => 'sec1',
			'elType'   => 'section',
			'elements' => array(
				array(
					'id'         => $form_id,
					'elType'     => 'widget',
					'widgetType' => 'form',
					'settings'   => array( 'form_name' => $title, 'form_fields' => $fields ),
					'elements'   => array(),
				),
			),
		),
	);
	$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => $title . ' page', 'post_status' => 'publish' ) );
	update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	return $form_id;
}

/** Submits an Elementor Pro form; only a valid submission fires elementor_pro/forms/new_record. */
function elementor_submit( $form_id, array $values, $valid = true, $title = 'Contact' ) {
	if ( ! $valid ) {
		return 'Your submission failed because of an error.';
	}
	$fields = array();
	foreach ( array( 'name' => 'Name', 'email' => 'Email', 'city' => 'City', 'consent' => '' ) as $id => $label ) {
		$fields[ $id ] = array(
			'id'        => $id,
			'type'      => 'consent' === $id ? 'acceptance' : ( 'email' === $id ? 'email' : 'text' ),
			'title'     => $label,
			'value'     => isset( $values[ $id ] ) ? $values[ $id ] : '',
			'raw_value' => isset( $values[ $id ] ) ? $values[ $id ] : '',
			'required'  => false,
		);
	}
	$record = new \ElementorPro\Modules\Forms\Classes\Form_Record(
		array(
			'fields' => $fields,
			'meta'   => array(
				'page_url'   => array( 'title' => 'Page URL', 'value' => 'https://shop.test/contact/?utm=x#top' ),
				'remote_ip'  => array( 'title' => 'Remote IP', 'value' => '198.51.100.7' ),
				'user_agent' => array( 'title' => 'User Agent', 'value' => 'Fixture UA' ),
			),
		),
		array( 'id' => $form_id, 'form_name' => $title )
	);
	do_action( 'elementor_pro/forms/new_record', $record, new \stdClass() );
	return 'Your submission was successful.';
}
}
