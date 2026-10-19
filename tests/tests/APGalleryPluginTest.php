<?php
/**
 * Tests de integración de APGallery para Google Photos (Picker API).
 *
 * @package apgallery-google-photos
 */

/**
 * Test de activación, shortcode, importación y sanitización.
 */
class APGalleryPluginTest extends WP_UnitTestCase {

	/**
	 * El plugin debe estar cargado y con constantes definidas.
	 */
	public function test_plugin_constants() {
		$this->assertTrue( defined( 'APGALLERY_VERSION' ) );
		$this->assertTrue( defined( 'APGALLERY_OPT_PREFIX' ) );
		$this->assertTrue( defined( 'APGALLERY_PICKER_ENDPOINT' ) );
		$this->assertSame( 'https://photospicker.googleapis.com/', APGALLERY_PICKER_ENDPOINT );
	}

	/**
	 * La activación crea las opciones por defecto.
	 */
	public function test_activation_creates_default_options() {
		apgallery_activate();

		$this->assertSame( '', get_option( 'apgallery_client_id' ) );
		$this->assertSame( '', get_option( 'apgallery_client_secret' ) );
		$this->assertSame( '', get_option( 'apgallery_refresh_token' ) );
	}

	/**
	 * El scope solicitado es el de la Picker API (la Library API fue retirada).
	 */
	public function test_scope_is_picker_scope() {
		$this->assertSame( 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly', APGALLERY_SCOPE );
	}

	/**
	 * El shortcode [apgallery] se registra.
	 */
	public function test_shortcode_registered() {
		$this->assertTrue( shortcode_exists( 'apgallery' ) );
	}

	/**
	 * El shortcode sin fotos importadas muestra el aviso de vacío, no error fatal.
	 */
	public function test_shortcode_without_photos_returns_notice() {
		$out = do_shortcode( '[apgallery]' );
		$this->assertStringContainsString( 'apgallery-wrapper', $out );
		$this->assertStringContainsString( 'Aún no hay fotos importadas', $out );
	}

	/**
	 * El atributo columns se limita al rango 1-6.
	 */
	public function test_shortcode_columns_clamped() {
		$out = do_shortcode( '[apgallery columns="99"]' );
		$this->assertStringNotContainsString( 'cols-99', $out );
		$this->assertStringNotContainsString( 'data-columns="99"', $out );
		$this->assertStringContainsString( 'apgallery-cols-6', $out );
	}

	/**
	 * Las fotos marcadas como picked aparecen en el shortcode con escapado (XSS).
	 */
	public function test_shortcode_renders_picked_attachments_escaped() {
		$attachment_id = $this->factory->attachment->create_object(
			array( 'file' => DIR_TESTDATA . '/images/canola.jpg' )
		);
		update_post_meta( $attachment_id, 'apgallery_picked', '1' );
		update_post_meta( $attachment_id, 'apgallery_google_media_id', '"><script>alert(1)</script>' );

		$out = do_shortcode( '[apgallery]' );

		$this->assertStringContainsString( 'apgallery-item', $out );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $out );
	}

	/**
	 * get_picked_attachments devuelve solo adjuntos marcados como picked.
	 */
	public function test_get_picked_attachments_filters_by_meta() {
		$marked = $this->factory->attachment->create_object(
			array( 'file' => DIR_TESTDATA . '/images/canola.jpg' )
		);
		$other  = $this->factory->attachment->create_object(
			array( 'file' => DIR_TESTDATA . '/images/test-image.jpg' )
		);
		update_post_meta( $marked, 'apgallery_picked', '1' );

		$ids = apgallery_get_picked_attachments();

		$this->assertContains( $marked, $ids );
		$this->assertNotContains( $other, $ids );
	}

	/**
	 * Encrypt/decrypt hace roundtrip del refresh token.
	 */
	public function test_encrypt_decrypt_roundtrip() {
		$secret = 'my-super-secret-refresh-token';
		$enc    = apgallery_encrypt( $secret );

		$this->assertNotSame( $secret, $enc );
		$this->assertSame( $secret, apgallery_decrypt( $enc ) );
	}

	/**
	 * Sin refresh token, las llamadas a la API devuelven WP_Error.
	 */
	public function test_api_call_without_connection_returns_error() {
		delete_option( 'apgallery_refresh_token' );
		delete_transient( 'apgallery_access_token' );

		$result = apgallery_create_picker_session();

		$this->assertWPError( $result );
		$this->assertSame( 'apgallery_not_connected', $result->get_error_code() );
	}

	/**
	 * find_imported_media_id localiza adjuntos por su ID de Google.
	 */
	public function test_find_imported_media_id() {
		$attachment_id = $this->factory->attachment->create_object(
			array( 'file' => DIR_TESTDATA . '/images/canola.jpg' )
		);
		update_post_meta( $attachment_id, 'apgallery_google_media_id', 'gp-id-123' );

		$this->assertSame( $attachment_id, apgallery_find_imported_media_id( 'gp-id-123' ) );
		$this->assertSame( 0, apgallery_find_imported_media_id( 'no-existe' ) );
		$this->assertSame( 0, apgallery_find_imported_media_id( '' ) );
	}
}
