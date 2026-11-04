<?php
/**
 * The "mailtonic form" Elementor widget. Loaded only when Elementor registers widgets.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget: a form picker that prints the same element as the shortcode.
 */
class Elementor_Widget extends \Elementor\Widget_Base {

	/** Widget name. */
	public function get_name() {
		return 'mailtonic-form';
	}

	/** Widget title. */
	public function get_title() {
		return __( 'mailtonic form', 'mailtonic' );
	}

	/** Widget icon. */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/** Widget categories. */
	public function get_categories() {
		return array( 'general' );
	}

	/** The form picker. */
	protected function register_controls() {
		$options = array( '' => __( 'Choose a form', 'mailtonic' ) );
		foreach ( Forms::all() as $form ) {
			$options[ $form['uid'] ] = $form['name'] . ( 'published' === $form['status'] ? '' : ' — ' . __( 'Draft in mailtonic', 'mailtonic' ) );
		}
		$this->start_controls_section( 'mailtonic', array( 'label' => __( 'mailtonic form', 'mailtonic' ) ) );
		$this->add_control(
			'form_uid',
			array(
				'label'   => __( 'Form', 'mailtonic' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $options,
				'default' => '',
			)
		);
		$this->end_controls_section();
	}

	/** Front-end output. */
	protected function render() {
		echo Forms::shortcode( array( 'id' => (string) $this->get_settings_for_display( 'form_uid' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_script_tag() output or a fixed comment.
	}
}
