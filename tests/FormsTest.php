<?php
/**
 * B2. Forms and popups (tests 33-43).
 *
 * @package Mailtonic
 */

use Mailtonic\Forms;
use Mailtonic\Settings;

class FormsTest extends Mailtonic_TestCase {
	const POPUP_A = '01HZY3K5V7W9X1Y3Z5A7B9C1DA';
	const POPUP_B = '01HZY3K5V7W9X1Y3Z5A7B9C1DB';

	public function set_up() {
		parent::set_up();
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
	}

	private function footer() {
		ob_start();
		do_action( 'wp_footer' );
		return ob_get_clean();
	}

	/** 33 */
	public function test_shortcode_prints_one_embed_element() {
		$this->connect();
		$this->scriptForms( array( $this->formObject() ) );
		$html = do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		$this->assertSame( 1, preg_match_all( $this->embedPattern( self::FORM_UID ), $html ) );
		$this->assertSame( 1, substr_count( $html, '<script' ) );
	}

	/** 34 */
	public function test_bad_uid_prints_nothing_and_editors_see_a_comment() {
		$this->assertSame( '', Forms::shortcode( array( 'id' => '<script>' ) ) );
		$this->assertSame( '', do_shortcode( '[mailtonic_form id="01HZY3K5V7W9X1Y3Z5A7B9C1D"]' ) );
		$this->loginAs( 'editor' );
		$html = Forms::shortcode( array( 'id' => '<script>' ) );
		$this->assertSame( '<!-- mailtonic: unknown form -->', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/** 35 */
	public function test_fallback_host() {
		$this->connect();
		$this->http->only( 'GET', self::API . '/forms', 500, array( 'error' => array( 'code' => 'server_error', 'message' => 'x', 'request_id' => null ) ) );
		$html = Forms::shortcode( array( 'id' => self::FORM_UID ) );
		$this->assertMatchesRegularExpression( $this->embedPattern( self::FORM_UID, 'https://app.mailtonic.test/p/forms/embed.js' ), $html );

		Forms::reset_printed();
		Settings::update( array( 'script_url' => 'https://t.acme.test/p/forms/embed.js' ) );
		$html = Forms::shortcode( array( 'id' => self::FORM_UID ) );
		$this->assertMatchesRegularExpression( $this->embedPattern( self::FORM_UID ), $html );
	}

	/** 36 */
	public function test_form_list_is_cached_and_refresh_clears_it() {
		$this->connect();
		$this->scriptForms( array( $this->formObject() ) );
		do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		Forms::reset_printed();
		do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		$this->assertCount( 1, $this->http->requests( 'GET', self::API . '/forms' ) );

		$this->loginAs();
		$this->postAdmin( 'mailtonic_refresh_forms' );
		$this->assertFalse( get_transient( 'mailtonic_forms' ) );
		Forms::reset_printed();
		do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		$this->assertCount( 2, $this->http->requests( 'GET', self::API . '/forms' ) );
	}

	/** 37 */
	public function test_block_renders_like_the_shortcode_and_stores_only_the_uid() {
		$this->connect();
		$this->scriptForms( array( $this->formObject() ) );
		$shortcode = do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		Forms::reset_printed();

		$content = '<!-- wp:mailtonic/form {"formUid":"' . self::FORM_UID . '"} /-->';
		$this->assertSame( $shortcode, do_blocks( $content ) );

		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'mailtonic/form' );
		$this->assertNotNull( $type );
		$this->assertTrue( $type->is_dynamic() );
		$this->assertArrayHasKey( 'formUid', $type->attributes );
		$own = array_diff( array_keys( $type->attributes ), array( 'lock', 'metadata', 'className', 'style' ) );
		$this->assertSame( array( 'formUid' ), array_values( $own ) );
	}

	/** 38 */
	public function test_draft_notice_in_editor_data_and_front_end_still_prints() {
		$this->connect();
		$this->scriptForms( array( $this->formObject( self::FORM_UID, 'inline', 'draft' ), $this->formObject( self::POPUP_A, 'popup' ) ) );
		$data = Forms::editor_data();
		$this->assertSame( "This form is a draft in mailtonic and won't show on your site until it's published", $data['forms'][0]['notice'] );
		$this->assertSame( 'Popups appear by their own trigger; this block marks where to load it', $data['forms'][1]['notice'] );
		$this->assertMatchesRegularExpression( $this->embedPattern( self::FORM_UID ), do_blocks( '<!-- wp:mailtonic/form {"formUid":"' . self::FORM_UID . '"} /-->' ) );
	}

	/** 39 */
	public function test_site_wide_popups_print_once_on_public_pages_only() {
		Settings::update(
			array(
				'popups'     => array( self::POPUP_A, self::POPUP_B ),
				'script_url' => 'https://t.acme.test/p/forms/embed.js',
			)
		);
		$this->go_to( home_url( '/' ) );
		Forms::shortcode( array( 'id' => self::POPUP_A ) );
		$footer = $this->footer();
		$this->assertSame( 0, preg_match_all( $this->embedPattern( self::POPUP_A ), $footer ) );
		$this->assertSame( 1, preg_match_all( $this->embedPattern( self::POPUP_B ), $footer ) );

		Forms::reset_printed();
		$footer = $this->footer() . $this->footer();
		$this->assertSame( 1, preg_match_all( $this->embedPattern( self::POPUP_A ), $footer ) );
		$this->assertSame( 1, preg_match_all( $this->embedPattern( self::POPUP_B ), $footer ) );

		Forms::reset_printed();
		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertStringNotContainsString( 'data-mt-form', $this->footer() );

		Forms::reset_printed();
		$this->go_to( home_url( '/' ) );
		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		$this->assertStringNotContainsString( 'data-mt-form', $this->footer() );
		remove_filter( 'wp_is_rest_endpoint', '__return_true' );

		Forms::reset_printed();
		add_filter( 'mailtonic_show_popups', '__return_false' );
		$this->assertStringNotContainsString( 'data-mt-form', $this->footer() );
		remove_filter( 'mailtonic_show_popups', '__return_false' );

		Forms::reset_printed();
		set_current_screen( 'dashboard' );
		$this->assertStringNotContainsString( 'data-mt-form', $this->footer() );
		$this->assertFalse( has_action( 'login_footer', array( Forms::class, 'print_popups' ) ) );
	}

	/** 40 */
	public function test_at_most_ten_popups() {
		$this->loginAs();
		$uids = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$uids[] = sprintf( '01HZY3K5V7W9X1Y3Z5A7B9C%03d', $i );
		}
		$this->postAdmin( 'mailtonic_popups', array( 'popups' => $uids ) );
		$this->assertStringContainsString( 'Choose at most 10 popups.', $this->notices() );
		$this->assertSame( array(), Settings::get( 'popups', array() ) );

		$this->postAdmin( 'mailtonic_popups', array( 'popups' => array_slice( $uids, 0, 10 ) ) );
		$this->assertCount( 10, Settings::get( 'popups' ) );
	}

	/** 41 */
	public function test_allowed_sites_warning() {
		update_option( 'home', 'https://shop.test' );
		$this->connect();
		$this->loginAs();
		$this->scriptForms( array( $this->formObject( self::FORM_UID, 'inline', 'published', array( 'https://other.test' ) ) ) );
		$this->assertStringContainsString(
			'This form is limited to other websites in mailtonic. Add https://shop.test to its allowed websites.',
			$this->renderPage( 'forms' )
		);
		$this->assertStringContainsString( 'Add https://shop.test', Forms::editor_data()['forms'][0]['warning'] );

		delete_transient( 'mailtonic_forms' );
		$this->scriptForms( array( $this->formObject( self::FORM_UID, 'inline', 'published', array( 'https://*.shop.test', 'https://shop.test' ) ) ) );
		$this->assertStringNotContainsString( 'This form is limited', $this->renderPage( 'forms' ) );
		$this->assertSame( '', Forms::editor_data()['forms'][0]['warning'] );
	}

	/** 42 */
	public function test_no_cookies() {
		$this->connect();
		$this->scriptForms( array( $this->formObject() ) );
		Settings::update( array( 'popups' => array( self::POPUP_A ) ) );
		$before = $_COOKIE;
		$this->go_to( home_url( '/' ) );
		do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		$this->footer();
		$this->assertSame( $before, $_COOKIE );
		foreach ( headers_list() as $header ) {
			$this->assertStringStartsNotWith( 'Set-Cookie', $header );
		}
	}

	/** 43 */
	public function test_elementor_widget() {
		$this->connect();
		$this->scriptForms( array( $this->formObject() ) );
		$manager = new class() {
			public $widgets = array();
			public function register( $widget ) {
				$this->widgets[ $widget->get_name() ] = $widget;
			}
		};
		do_action( 'elementor/widgets/register', $manager );
		$this->assertArrayHasKey( 'mailtonic-form', $manager->widgets );

		$class  = get_class( $manager->widgets['mailtonic-form'] );
		$widget = new $class( array( 'settings' => array( 'form_uid' => self::FORM_UID ) ) );
		ob_start();
		$widget->render_content();
		$html = ob_get_clean();
		Forms::reset_printed();
		$this->assertSame( do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' ), $html );

		add_filter(
			'mailtonic_integration_active',
			static function ( $active, $slug ) {
				return 'elementor' === $slug ? false : $active;
			},
			10,
			2
		);
		$manager->widgets = array();
		do_action( 'elementor/widgets/register', $manager );
		$this->assertSame( array(), $manager->widgets );
	}
}
