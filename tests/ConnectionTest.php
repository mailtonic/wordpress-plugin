<?php
/**
 * B1. Connection (tests 23-32).
 *
 * @package Mailtonic
 */

use Mailtonic\Settings;

class ConnectionTest extends Mailtonic_TestCase {

	private function post_key( $key = self::KEY, $app_url = self::APP ) {
		return $this->postAdmin(
			'mailtonic_connection',
			array(
				'app_url' => $app_url,
				'api_key' => $key,
			)
		);
	}

	private function script_lists() {
		$this->http->on( 'GET', self::API . '/lists', 200, array( 'data' => array( array( 'uid' => 'L1', 'name' => 'Newsletter', 'counts' => array( 'total' => 3 ) ) ), 'next_cursor' => null ) );
	}

	/** 23 */
	public function test_valid_key_saves_and_shows_the_account() {
		$this->loginAs();
		$this->http->on( 'GET', self::API . '/me', 200, $this->me() );
		$this->script_lists();

		$this->post_key();

		$this->assertSame( self::KEY, Settings::api_key() );
		$this->assertSame( 'L1', Settings::get( 'default_list' ) );
		$me = $this->http->requests( 'GET', self::API . '/me' )[0];
		$this->assertSame( 'Bearer ' . self::KEY, $me['headers']['Authorization'] );
		$html = $this->renderPage( 'connection' );
		$this->assertStringContainsString( 'Acme Ltd', $html );
		$this->assertStringContainsString( 'WordPress shop', $html );
		$this->assertStringContainsString( 'asha@acme.test', $html );
		$this->assertStringNotContainsString( self::KEY, maybe_serialize( get_option( 'mailtonic_settings' ) ) );
		$this->assertStringNotContainsString( self::KEY, $html );
	}

	/** 24 */
	public function test_key_format_is_checked_before_any_request() {
		$this->loginAs();
		$this->post_key( 'abc' );
		$this->assertStringContainsString( "This doesn't look like a mailtonic API key.", html_entity_decode( $this->notices(), ENT_QUOTES ) );
		$this->assertNull( Settings::api_key() );
		$this->assertCount( 0, $this->http->requests() );
	}

	public function refusals() {
		return array(
			'401'       => array( 401, 'unauthenticated', "mailtonic didn't accept this key. Create a new key in mailtonic under Settings › API keys." ),
			'suspended' => array( 403, 'account_suspended', 'This mailtonic account is suspended.' ),
			'402'       => array( 402, 'subscription_required', "Your mailtonic subscription isn't active." ),
			'timeout'   => array( 'timeout', null, "We couldn't reach mailtonic. Try again in a minute." ),
			'500'       => array( 500, 'server_error', "We couldn't reach mailtonic. Try again in a minute." ),
		);
	}

	/**
	 * 25
	 *
	 * @dataProvider refusals
	 */
	public function test_refusals_map_to_messages_and_keep_the_previous_key( $status, $code, $message ) {
		$this->connect();
		$this->loginAs();
		$this->http->only( 'GET', self::API . '/me', $status, $code ? array( 'error' => array( 'code' => $code, 'message' => 'x', 'request_id' => 'r' ) ) : null );

		$this->post_key( 'mt_' . str_repeat( 'z', 48 ) );

		$this->assertStringContainsString( $message, html_entity_decode( $this->notices(), ENT_QUOTES ) );
		$this->assertSame( self::KEY, Settings::api_key() );
	}

	/** 26 */
	public function test_read_key_is_refused() {
		$this->loginAs();
		$this->http->on( 'GET', self::API . '/me', 200, $this->me( 'owner', 'read' ) );
		$this->post_key();
		$this->assertStringContainsString( 'This key is read-only. Create a key with write access.', $this->notices() );
		$this->assertNull( Settings::api_key() );
	}

	/** 27 */
	public function test_non_owner_saves_with_a_warning() {
		$this->loginAs();
		$this->http->on( 'GET', self::API . '/me', 200, $this->me( 'viewer' ) );
		$this->script_lists();
		$this->post_key();
		$this->assertSame( self::KEY, Settings::api_key() );
		$this->assertStringContainsString( "This key's member isn't an Owner, so some features may be refused.", html_entity_decode( $this->notices(), ENT_QUOTES ) );
	}

	/** 28 */
	public function test_key_is_never_shown_again() {
		$this->connect();
		$this->loginAs();
		$html = $this->renderPage( 'connection' );
		$this->assertStringContainsString( 'mt_…STUV', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringContainsString( 'Replace key', $html );
	}

	/**
	 * 28 (constant)
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_key_makes_the_field_read_only() {
		define( 'MAILTONIC_API_KEY', self::KEY );
		$this->loginAs();
		$this->assertSame( self::KEY, Settings::api_key() );
		$html = $this->renderPage( 'connection' );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="api_key"[^>]*readonly/', $html );
		$this->assertStringContainsString( 'MAILTONIC_API_KEY', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
	}

	/** 29 */
	public function test_app_url_rules() {
		$this->http->on( 'GET', self::API . '/me', 200, $this->me() );
		$this->script_lists();

		$bad = \Mailtonic\Connection::connect( array( 'app_url' => 'http://example.com', 'api_key' => self::KEY ) );
		$this->assertFalse( $bad['ok'] );
		$this->assertCount( 0, $this->http->requests() );

		$local = \Mailtonic\Connection::connect( array( 'app_url' => 'http://localhost:8080/', 'api_key' => self::KEY ) );
		$this->assertTrue( $local['ok'] );
		$this->assertSame( 'http://localhost:8080', Settings::app_url() );
		$this->assertSame( 'http://localhost:8080/api/v2/me', $this->http->requests( 'GET', self::API . '/me' )[0]['url'] );

		$test = \Mailtonic\Connection::connect( array( 'app_url' => 'https://app.mailtonic.test/', 'api_key' => self::KEY ) );
		$this->assertTrue( $test['ok'] );
		$this->assertSame( 'https://app.mailtonic.test', Settings::app_url() );
	}

	/** 30 */
	public function test_editor_is_refused() {
		$this->loginAs( 'editor' );
		$this->expectException( WPDieException::class );
		$this->post_key();
	}

	/** 30 */
	public function test_missing_nonce_is_refused() {
		$this->loginAs();
		$this->expectException( WPDieException::class );
		$this->postAdmin( 'mailtonic_connection', array( 'api_key' => self::KEY ), false );
	}

	/** 31 */
	public function test_disconnect_removes_key_and_caches_and_embeds_keep_working() {
		$this->connect();
		set_transient( 'mailtonic_forms', array(), 600 );
		set_transient( 'mailtonic_fields_L1', array(), 600 );
		Settings::update( array( 'script_url' => 'https://t.acme.test/p/forms/embed.js' ) );
		$this->loginAs();

		$this->postAdmin( 'mailtonic_disconnect' );

		$this->assertNull( Settings::api_key() );
		$this->assertFalse( Settings::connected() );
		$this->assertFalse( get_transient( 'mailtonic_forms' ) );
		$this->assertFalse( get_transient( 'mailtonic_fields_L1' ) );
		$html = do_shortcode( '[mailtonic_form id="' . self::FORM_UID . '"]' );
		$this->assertStringContainsString( 'src="https://t.acme.test/p/forms/embed.js"', $html );
		$this->assertCount( 0, $this->http->requests() );
	}

	/** 32 */
	public function test_activation_and_front_end_pages_make_no_request() {
		$this->connect();
		Settings::update( array( 'popups' => array( self::FORM_UID ) ) );
		\Mailtonic\Plugin::activate( false );
		$this->go_to( home_url( '/' ) );
		ob_start();
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		ob_end_clean();
		$this->go_to( get_permalink( self::factory()->post->create() ) );
		$this->assertCount( 0, $this->http->requests() );
	}
}
