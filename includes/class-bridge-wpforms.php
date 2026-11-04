<?php
/**
 * WPForms (Lite or Pro) bridge adapter.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Lists WPForms forms and turns completed submissions into sign-ups.
 */
final class Bridge_WPForms {

	/**
	 * Hooks. WPForms fires wpforms_process_complete only after validation and spam checks.
	 */
	public static function init() {
		add_action( 'wpforms_process_complete', array( self::class, 'complete' ), 10, 3 );
		add_filter( 'wpforms_builder_settings_sections', array( self::class, 'sections' ), 20 );
		add_action( 'wpforms_form_settings_panel_content', array( self::class, 'panel' ), 20 );
		add_action(
			'deleted_post',
			static function ( $post_id, $post = null ) {
				if ( $post && 'wpforms' === $post->post_type ) {
					Bridges::forget( 'wpforms', (string) $post_id );
				}
			},
			10,
			2
		);
	}

	/**
	 * Decodes a WPForms form post.
	 *
	 * @param \WP_Post $post Form post.
	 * @return array
	 */
	private static function data( $post ) {
		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * WPForms forms with their fields.
	 *
	 * @return array
	 */
	public static function forms() {
		$forms = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wpforms',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		) as $post ) {
			$data   = self::data( $post );
			$fields = array();
			foreach ( isset( $data['fields'] ) ? (array) $data['fields'] : array() as $id => $field ) {
				$type  = isset( $field['type'] ) ? $field['type'] : '';
				$label = isset( $field['label'] ) ? trim( wp_strip_all_tags( (string) $field['label'] ) ) : '';
				if ( '' === $label && ! empty( $field['choices'] ) ) {
					$choice = reset( $field['choices'] );
					$label  = isset( $choice['label'] ) ? trim( wp_strip_all_tags( (string) $choice['label'] ) ) : '';
				}
				$fields[ (string) $id ] = array(
					'label'    => $label,
					'type'     => $type,
					'required' => ! empty( $field['required'] ),
					'consent'  => in_array( $type, array( 'checkbox', 'gdpr-checkbox' ), true ),
				);
			}
			$forms[ (string) $post->ID ] = array(
				'title'  => isset( $data['settings']['form_title'] ) ? (string) $data['settings']['form_title'] : $post->post_title,
				'fields' => $fields,
			);
		}
		return $forms;
	}

	/**
	 * After WPForms accepted and processed an entry.
	 *
	 * @param array $fields    Processed fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form data.
	 */
	public static function complete( $fields, $entry, $form_data ) {
		$values = array();
		foreach ( (array) $fields as $id => $field ) {
			$values[ (string) $id ] = isset( $field['value'] ) ? $field['value'] : '';
		}
		$meta = Bridges::request_meta();
		if ( function_exists( 'wpforms_get_ip' ) ) {
			$meta['ip'] = (string) wpforms_get_ip();
		}
		Bridges::submit( 'wpforms', (string) $form_data['id'], $values, $meta );
	}

	/**
	 * Adds the mailtonic section to WPForms' form settings.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public static function sections( $sections ) {
		if ( Integrations::active( 'wpforms' ) && current_user_can( 'manage_options' ) ) {
			$sections['mailtonic'] = __( 'mailtonic', 'mailtonic' );
		}
		return $sections;
	}

	/**
	 * The mailtonic section's content.
	 *
	 * @param object $settings WPForms builder settings panel.
	 */
	public static function panel( $settings ) {
		if ( ! Integrations::active( 'wpforms' ) || ! current_user_can( 'manage_options' ) || empty( $settings->form_data['id'] ) ) {
			return;
		}
		echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-mailtonic">';
		Bridges::panel( 'wpforms', (string) $settings->form_data['id'] );
		echo '</div>';
	}
}
