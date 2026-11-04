<?php
/**
 * Elementor Pro Forms bridge adapter.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Lists Elementor Pro form widgets and turns accepted submissions into sign-ups.
 */
final class Bridge_Elementor {

	/**
	 * Hooks. Elementor Pro fires elementor_pro/forms/new_record only after validation and spam checks.
	 */
	public static function init() {
		add_action( 'elementor_pro/forms/new_record', array( self::class, 'new_record' ) );
	}

	/**
	 * Form widgets in Elementor documents, with their fields.
	 *
	 * @return array Keyed by the form widget's id.
	 */
	public static function forms() {
		$forms = array();
		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => array( 'publish', 'draft', 'private', 'future' ),
				'numberposts' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- form widgets live in few documents.
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_elementor_data',
						'value'   => '"widgetType":"form"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		foreach ( $posts as $post ) {
			$data = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
			if ( is_array( $data ) ) {
				self::collect( $data, $forms );
			}
		}
		return $forms;
	}

	/**
	 * Walks Elementor elements for form widgets.
	 *
	 * @param array $elements Elements.
	 * @param array $forms    Collected forms.
	 */
	private static function collect( array $elements, array &$forms ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) && 'form' === $element['widgetType'] ) {
				$fields = array();
				foreach ( isset( $element['settings']['form_fields'] ) ? (array) $element['settings']['form_fields'] : array() as $field ) {
					$type = isset( $field['field_type'] ) ? $field['field_type'] : 'text';
					$name = isset( $field['custom_id'] ) ? (string) $field['custom_id'] : '';
					if ( '' === $name ) {
						continue;
					}
					$label = isset( $field['field_label'] ) ? trim( wp_strip_all_tags( (string) $field['field_label'] ) ) : '';
					if ( 'acceptance' === $type && ! empty( $field['acceptance_text'] ) ) {
						$label = trim( wp_strip_all_tags( (string) $field['acceptance_text'] ) );
					}
					$fields[ $name ] = array(
						'label'    => $label,
						'type'     => $type,
						'required' => ! empty( $field['required'] ) && 'false' !== $field['required'],
						'consent'  => in_array( $type, array( 'acceptance', 'checkbox' ), true ),
					);
				}
				$forms[ (string) $element['id'] ] = array(
					'title'  => ! empty( $element['settings']['form_name'] ) ? (string) $element['settings']['form_name'] : __( 'Elementor form', 'mailtonic' ),
					'fields' => $fields,
				);
			}
			if ( ! empty( $element['elements'] ) ) {
				self::collect( (array) $element['elements'], $forms );
			}
		}
	}

	/**
	 * After Elementor Pro accepted a submission.
	 *
	 * @param object $record Form record.
	 */
	public static function new_record( $record ) {
		$values = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$values[ (string) $id ] = isset( $field['raw_value'] ) ? $field['raw_value'] : ( isset( $field['value'] ) ? $field['value'] : '' );
		}
		$meta     = Bridges::request_meta();
		$recorded = (array) $record->get( 'meta' );
		foreach ( array(
			'remote_ip'  => 'ip',
			'user_agent' => 'user_agent',
			'page_url'   => 'page_url',
		) as $from => $to ) {
			if ( ! empty( $recorded[ $from ]['value'] ) ) {
				$meta[ $to ] = (string) $recorded[ $from ]['value'];
			}
		}
		Bridges::submit( 'elementor', (string) $record->get_form_settings( 'id' ), $values, $meta );
	}
}
