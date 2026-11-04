<?php
/**
 * Contact Form 7 bridge adapter.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Lists CF7 forms and turns sent submissions into sign-ups.
 */
final class Bridge_CF7 {

	/**
	 * Hooks. CF7 fires wpcf7_mail_sent only after validation, spam checks and a sent mail.
	 */
	public static function init() {
		add_action( 'wpcf7_mail_sent', array( self::class, 'mail_sent' ) );
		add_filter( 'wpcf7_editor_panels', array( self::class, 'editor_panels' ) );
		add_action(
			'deleted_post',
			static function ( $post_id, $post = null ) {
				if ( $post && 'wpcf7_contact_form' === $post->post_type ) {
					Bridges::forget( 'cf7', (string) $post_id );
				}
			},
			10,
			2
		);
	}

	/**
	 * CF7 forms with their fields.
	 *
	 * @return array
	 */
	public static function forms() {
		$forms = array();
		foreach ( \WPCF7_ContactForm::find() as $form ) {
			$fields = array();
			foreach ( $form->scan_form_tags() as $tag ) {
				if ( '' === (string) $tag->name || 'submit' === $tag->basetype ) {
					continue;
				}
				$consent = in_array( $tag->basetype, array( 'acceptance', 'checkbox' ), true );
				if ( 'acceptance' === $tag->basetype ) {
					$label    = trim( wp_strip_all_tags( (string) $tag->content ) );
					$required = ! $tag->has_option( 'optional' );
				} else {
					$label    = 'checkbox' === $tag->basetype && ! empty( $tag->labels ) ? (string) reset( $tag->labels ) : (string) $tag->name;
					$required = $tag->is_required();
				}
				$fields[ $tag->name ] = array(
					'label'    => $label,
					'type'     => $tag->basetype,
					'required' => $required,
					'consent'  => $consent,
				);
			}
			$forms[ (string) $form->id() ] = array(
				'title'  => $form->title(),
				'fields' => $fields,
			);
		}
		return $forms;
	}

	/**
	 * After CF7 sent the form's mail.
	 *
	 * @param object $contact_form WPCF7_ContactForm.
	 */
	public static function mail_sent( $contact_form ) {
		$submission = class_exists( 'WPCF7_Submission' ) ? \WPCF7_Submission::get_instance() : null;
		if ( ! $submission ) {
			return;
		}
		Bridges::submit(
			'cf7',
			(string) $contact_form->id(),
			(array) $submission->get_posted_data(),
			array(
				'ip'         => (string) $submission->get_meta( 'remote_ip' ),
				'user_agent' => (string) $submission->get_meta( 'user_agent' ),
				'page_url'   => (string) $submission->get_meta( 'url' ),
				'time'       => $submission->get_meta( 'timestamp' ) ? (int) $submission->get_meta( 'timestamp' ) : time(),
			)
		);
	}

	/**
	 * Adds the mailtonic tab to CF7's form editor.
	 *
	 * @param array $panels Panels.
	 * @return array
	 */
	public static function editor_panels( $panels ) {
		if ( ! Integrations::active( 'cf7' ) || ! current_user_can( 'manage_options' ) ) {
			return $panels;
		}
		$panels['mailtonic-panel'] = array(
			'title'    => __( 'mailtonic', 'mailtonic' ),
			'callback' => static function ( $form ) {
				Bridges::panel( 'cf7', (string) $form->id() );
			},
		);
		return $panels;
	}
}
