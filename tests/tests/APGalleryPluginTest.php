<?php
/**
 * Tests de integración de APGallery para Google Photos.
 *
 * @package apgallery-google-photos
 */

/**
 * Test de activación, shortcode y sanitización.
 */
class APGalleryPluginTest extends WP_UnitTestCase {

	/**
	 * El plugin debe estar cargado y con constantes definidas.
	 */
	public function test_plugin_constants() {
		$this->assertTrue( defined( 'APGALLERY_VERSION' ) );
		$this->assertTrue( defined( 'APGALLERY_OPT_PREFIX' ) );
		$this->assertTrue( defined( 'APGALLERY_CACHE_TTL' ) );
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
	 * El shortcode [apgallery] se registra.
	 */
	public function test_shortcode_registered() {
		$this->assertTrue( shortcode_exists( 'apgallery' ) );
	}

	/**
	 * Shortcode sin atributo album devuelve aviso, no error fatal.
	 */
	public function test_shortcode_without_album_returns_notice() {
		$out = do_shortcode( '[apgallery]' );
		$this->assertStringContainsString( 'falta el atributo album', $out );
	}

	/**
	 * El render del shortcode escapa el ID del álbum (XSS).
	 */
	public function test_shortcode_escapes_album_id() {
		// Sin conexión a Google el render devolverá un error, pero el ID
		// malicioso nunca debe llegar crudo al HTML.
		$out = do_shortcode( '[apgallery album=\'"><script>alert(1)</script>\']' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $out );
	}

	/**
	 * El atributo columns se limita al rango 1-6.
	 */
	public function test_shortcode_columns_clamped() {
		// Se verifica indirectamente: el valor se pasa a (int) y clamp,
		// así que con columns=99 el markup no debe contener cols-99.
		$out = do_shortcode( '[apgallery album="abc" columns="99"]' );
		$this->assertStringNotContainsString( 'cols-99', $out );
		$this->assertStringNotContainsString( 'data-columns="99"', $out );
	}

	/**
	 * La caché de media guarda y devuelve el mismo payload.
	 */
	public function test_media_cache_roundtrip() {
		$media = array(
			'mediaItems'    => array(
				array(
					'id'       => 'm1',
					'baseUrl'   => 'https://lh3.example/b1',
					'filename' => 'foto.jpg',
					'mimeType' => 'PHOTO',
				),
			),
			'nextPageToken' => 'tok2',
		);

		$use_cache = true;
		apgallery_get_cached_album_media( 'album-x', 24, $use_cache );

		// Guardar manualmente como lo haría la función tras un fetch real.
		$cache_key = APGALLERY_OPT_PREFIX . 'media_' . md5( 'album-x|24' );
		set_transient( $cache_key, $media, APGALLERY_CACHE_TTL );

		$cached = apgallery_get_cached_album_media( 'album-x', 24, $use_cache );
		$this->assertSame( $media, $cached );
	}

	/**
	 * flush_media_cache elimina los transientes de media.
	 */
	public function test_flush_media_cache() {
		global $wpdb;

		$cache_key = APGALLERY_OPT_PREFIX . 'media_' . md5( 'album-y|24' );
		set_transient( $cache_key, array( 'mediaItems' => array() ), APGALLERY_CACHE_TTL );

		apgallery_flush_media_cache();

		// Verificar contra la BD (get_transient puede servirse de la object cache).
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'_transient_' . $cache_key
			)
		);
		$this->assertNull( $row );
	}

	/**
	 * Encrypt/decrypt hace roundtrip del refresh token.
	 */
	public function test_encrypt_decrypt_roundtrip() {
		$secret = 'my-super-secret-refresh-token';
		$enc   = apgallery_encrypt( $secret );

		$this->assertNotSame( $secret, $enc );
		$this->assertSame( $secret, apgallery_decrypt( $enc ) );
	}

	/**
	 * Sin refresh token, la petición a la API devuelve WP_Error.
	 */
	public function test_remote_request_without_token_is_error() {
		delete_option( 'apgallery_refresh_token' );
		$result = apgallery_get_access_token();
		$this->assertWPError( $result );
	}
}
