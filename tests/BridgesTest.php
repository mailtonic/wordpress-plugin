<?php
/**
 * B4. Bridges for Contact Form 7, WPForms and Elementor Pro Forms (tests 55-64).
 *
 * @package Mailtonic
 */

use Mailtonic\Outbox;

class BridgesTest extends Mailtonic_TestCase {

	/** Field names per plugin for: name, email, city, consent. */
	const FIELDS = array(
		'cf7'       => array( 'your-name', 'your-email', 'your-city', 'consent' ),
		'wpforms'   => array( '1', '2', '3', '4' ),
		'elementor' => array( 'name', 'email', 'city', 'consent' ),
	);

	public function plugins() {
		return array(
			'cf7'       => array( 'cf7' ),
			'wpforms'   => array( 'wpforms' ),
			'elementor' => array( 'elementor' ),
		);
	}

	private function form( $plugin, $consent_required = false, $with_consent = true ) {
		switch ( $plugin ) {
			case 'cf7':
				$consent = $with_consent ? array( 'type' => 'acceptance', 'name' => 'consent', 'options' => $consent_required ? array() : array( 'optional' ), 'content' => 'Send me news by email' ) : null;
				return (string) Mailtonic_Fixtures\cf7_form( 'Contact', $consent );
			case 'wpforms':
				return (string) Mailtonic_Fixtures\wpforms_form( 'Contact', $consent_required, $with_consent );
			default:
				return Mailtonic_Fixtures\elementor_form( 'Contact', $consent_required, $with_consent );
		}
	}

	private function save( $plugin, $form_id, array $overrides = array() ) {
		list( $name, $email, $city, $consent ) = self::FIELDS[ $plugin ];
		return $this->postAdmin(
			'mailtonic_bridge',
			array_merge(
				array(
					'plugin'        => $plugin,
					'form_id'       => $form_id,
					'enabled'       => '1',
					'list'          => '',
					'map'           => array( 'email' => $email, 'first_name' => $name, 'city' => $city ),
					'tags'          => 'wp, contact',
					'consent_field' => $consent,
				),
				$overrides
			)
		);
	}

	private function submit( $plugin, $form_id, $tick = true, $valid = true, $city = '' ) {
		list( $name, $email, $c, $consent ) = self::FIELDS[ $plugin ];
		$ticked = array( 'cf7' => '1', 'wpforms' => 'Yes please', 'elementor' => 'on' );
		$values = array(
			$name    => 'Ana',
			$email   => 'ana@example.com',
			$c       => $city,
			$consent => $tick ? $ticked[ $plugin ] : '',
		);
		switch ( $plugin ) {
			case 'cf7':
				return Mailtonic_Fixtures\cf7_submit( (int) $form_id, $values, $valid );
			case 'wpforms':
				return Mailtonic_Fixtures\wpforms_submit( (int) $form_id, $values, $valid );
			default:
				return Mailtonic_Fixtures\elementor_submit( $form_id, $values, $valid );
		}
	}

	private function signups() {
		return Outbox::find( array( 'kind' => 'signup' ) );
	}

	/**
	 * 55
	 *
	 * @dataProvider plugins
	 */
	public function test_saving_needs_email_and_consent( $plugin ) {
		$this->connect();
		$this->loginAs();
		$form = $this->form( $plugin );

		$this->save( $plugin, $form, array( 'map' => array( 'first_name' => self::FIELDS[ $plugin ][0] ) ) );
		$this->assertStringContainsString( 'Map a field to email.', $this->notices() );
		$this->assertSame( array(), get_option( 'mailtonic_bridges', array() ) );

		$this->save( $plugin, $form, array( 'consent_field' => '' ) );
		$this->assertStringContainsString( 'Choose the checkbox people tick to agree to marketing emails. Add one to your form if it has none.', $this->notices() );
		$this->assertSame( array(), get_option( 'mailtonic_bridges', array() ) );

		$this->save( $plugin, $form );
		$bridge = get_option( 'mailtonic_bridges' )[ $plugin . ':' . $form ];
		$this->assertTrue( $bridge['enabled'] );
		$this->assertSame( 'L1', $bridge['list'] );
		$this->assertSame( array( 'wp', 'contact' ), $bridge['tags'] );
	}

	/**
	 * 56
	 *
	 * @dataProvider plugins
	 */
	public function test_required_consent_field_saves_with_a_warning( $plugin ) {
		$this->connect();
		$this->loginAs();
		$form = $this->form( $plugin, true );
		$this->save( $plugin, $form );
		$this->assertArrayHasKey( $plugin . ':' . $form, get_option( 'mailtonic_bridges' ) );
		$this->assertStringContainsString( "This box must be ticked to send the form at all, so it doesn't record a free choice. Make it optional.", html_entity_decode( $this->notices(), ENT_QUOTES ) );
	}

	/**
	 * 57
	 *
	 * @dataProvider plugins
	 */
	public function test_ticked_submission_queues_a_signup( $plugin ) {
		$this->connect();
		$this->loginAs();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form );
		wp_set_current_user( 0 );

		$before = time();
		$this->submit( $plugin, $form );

		$rows = $this->signups();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'POST', $rows[0]['method'] );
		$this->assertSame( '/lists/L1/signups', $rows[0]['path'] );
		$this->assertSame( $plugin, $rows[0]['source'] );
		$body = json_decode( $rows[0]['body'], true );
		$this->assertSame( 'ana@example.com', $body['email'] );
		$this->assertSame( array( 'first_name' => 'Ana' ), $body['fields'] );
		$this->assertSame( array( 'wp', 'contact' ), $body['tags'] );
		$this->assertSame( 'Send me news by email', $body['consent']['text'] );
		$this->assertSame( '198.51.100.7', $body['consent']['ip'] );
		$this->assertSame( 'Fixture UA', $body['consent']['user_agent'] );
		$this->assertSame( 'https://shop.test/contact/', $body['consent']['page_url'] );
		$this->assertGreaterThanOrEqual( $before, strtotime( $body['consent']['given_at'] ) );
		$this->assertSame(
			array(
				'integration' => 'wordpress',
				'plugin'      => $plugin,
				'form_ref'    => 'Contact (' . $form . ')',
				'site_url'    => home_url(),
			),
			$body['source']
		);
		$this->assertCount( 0, $this->http->requests() );
	}

	/**
	 * 58
	 *
	 * @dataProvider plugins
	 */
	public function test_unticked_submission_sends_nothing( $plugin ) {
		$this->connect();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form );
		$this->submit( $plugin, $form, false );
		do_action( 'shutdown' );
		$this->assertCount( 0, Outbox::find( array() ) );
		$this->assertCount( 0, $this->http->requests() );
	}

	/**
	 * 59
	 *
	 * @dataProvider plugins
	 */
	public function test_rejected_submission_sends_nothing( $plugin ) {
		$this->connect();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form );
		$this->submit( $plugin, $form, true, false );
		$this->assertCount( 0, Outbox::find( array() ) );
	}

	/**
	 * 60
	 *
	 * @dataProvider plugins
	 */
	public function test_visitor_sees_the_form_plugins_own_message( $plugin ) {
		$this->connect();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form );
		$this->http->on( 'POST', self::API . '/lists/L1/signups', 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'Down', 'request_id' => null ) ) );
		$own = array(
			'cf7'       => 'Thank you for your message. It has been sent.',
			'wpforms'   => 'Thanks for contacting us!',
			'elementor' => 'Your submission was successful.',
		);
		$this->assertSame( $own[ $plugin ], $this->submit( $plugin, $form ) );
		do_action( 'shutdown' );
		$this->http->only( 'POST', self::API . '/lists/L1/signups', 422, array( 'error' => array( 'code' => 'suppressed', 'message' => 'Suppressed', 'request_id' => null ) ) );
		$this->assertSame( $own[ $plugin ], $this->submit( $plugin, $form, true, true, 'Pune' ) );
		do_action( 'shutdown' );
		$this->assertCount( 2, $this->http->requests() );
	}

	/**
	 * 61
	 *
	 * @dataProvider plugins
	 */
	public function test_bridge_list_wins_over_the_default_list( $plugin ) {
		$this->connect();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form, array( 'list' => 'L2' ) );
		$this->submit( $plugin, $form, true, true, 'Pune' );
		$row = $this->signups()[0];
		$this->assertSame( '/lists/L2/signups', $row['path'] );
		$this->assertSame( array( 'first_name' => 'Ana', 'city' => 'Pune' ), json_decode( $row['body'], true )['fields'] );
	}

	/**
	 * 62
	 *
	 * @dataProvider plugins
	 */
	public function test_disabled_bridge_sends_nothing( $plugin ) {
		$this->connect();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form, array( 'enabled' => '' ) );
		$this->assertFalse( get_option( 'mailtonic_bridges' )[ $plugin . ':' . $form ]['enabled'] );
		$this->submit( $plugin, $form );
		$this->assertCount( 0, Outbox::find( array() ) );
	}

	/**
	 * 63
	 *
	 * @dataProvider plugins
	 */
	public function test_deleted_form_drops_its_bridge( $plugin ) {
		$this->connect();
		$form = $this->form( $plugin );
		$this->save( $plugin, $form );
		$this->assertStringContainsString( 'Contact (' . $form . ')', $this->renderPage( 'integrations' ) );

		if ( 'elementor' === $plugin ) {
			$page = get_posts( array( 'post_type' => 'page', 'title' => 'Contact page', 'fields' => 'ids' ) )[0];
			wp_delete_post( $page, true );
		} else {
			wp_delete_post( (int) $form, true );
		}

		$this->assertStringNotContainsString( 'Contact (' . $form . ')', $this->renderPage( 'integrations' ) );
		$this->assertArrayNotHasKey( $plugin . ':' . $form, get_option( 'mailtonic_bridges', array() ) );
	}

	/** 64 */
	public function test_bridge_panels_only_with_their_plugin() {
		$this->connect();
		$this->loginAs();
		$wpforms = $this->form( 'wpforms' );
		$this->save( 'wpforms', $wpforms );
		$this->assertStringContainsString( 'WPForms', $this->renderPage( 'integrations' ) );
		$this->assertArrayHasKey( 'mailtonic', apply_filters( 'wpforms_builder_settings_sections', array(), array() ) );
		$this->assertArrayHasKey( 'mailtonic-panel', apply_filters( 'wpcf7_editor_panels', array() ) );

		add_filter(
			'mailtonic_integration_active',
			static function ( $active, $slug ) {
				return 'wpforms' === $slug ? false : $active;
			},
			10,
			2
		);
		$html = $this->renderPage( 'integrations' );
		$this->assertStringNotContainsString( 'WPForms', $html );
		$this->assertStringNotContainsString( 'Contact (' . $wpforms . ')', $html );
		$this->assertArrayNotHasKey( 'mailtonic', apply_filters( 'wpforms_builder_settings_sections', array(), array() ) );
		Mailtonic_Fixtures\wpforms_submit( (int) $wpforms, array( 2 => 'ana@example.com', 4 => 'Yes please' ) );
		$this->assertCount( 0, Outbox::find( array() ) );
	}
}
