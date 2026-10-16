<?php
/**
 * Plugin Name:       APGallery for Google Photos
 * Plugin URI:        https://apsoft.example.com/apgallery-google-photos
 * Description:       Muestra álbumes y fotos de Google Photos en la galería de tu sitio mediante el shortcode [apgallery]. Conexión segura vía OAuth 2.0.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:       7.4
 * Author:             APSoft
 * Author URI:         https://apsoft.example.com
 * License:            GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       apgallery-google-photos
 *
 * @package APGallery_Google_Photos
 */

defined( 'ABSPATH' ) || exit;

define( 'APGALLERY_VERSION', '0.5.0' );
define( 'APGALLERY_OPT_PREFIX', 'apgallery_' );
define( 'APGALLERY_CACHE_TTL', 5 * MINUTE_IN_SECONDS );

/* -------------------------------------------------------------------------
 * 1. Activación / desactivación
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'apgallery_activate' );
function apgallery_activate() {
	$defaults = array(
		'client_id'     => '',
		'client_secret' => '',
		'refresh_token' => '',
	);
	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( APGALLERY_OPT_PREFIX . $key ) ) {
			add_option( APGALLERY_OPT_PREFIX . $key, $value );
		}
	}
}

/* -------------------------------------------------------------------------
 * 2. Cifrado del refresh token y llamadas a la API de Google Photos
 * ---------------------------------------------------------------------- */

/**
 * Cifra un secreto con AES-256-CBC + HMAC (encrypt-then-MAC), derivando la
 * clave de wp_salt(). Devuelve el texto sin cifrar si OpenSSL no existe.
 *
 * @param string $plaintext Secreto en claro.
 * @return string Secreto cifrado con prefijo "apg1:" o el original.
 */
function apgallery_encrypt( $plaintext ) {
	if ( '' === $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
		return $plaintext;
	}
	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv  = random_bytes( 16 );
	$ct  = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	if ( false === $ct ) {
		return $plaintext;
	}
	$mac = hash_hmac( 'sha256', $iv . $ct, $key, true );
	return 'apg1:' . base64_encode( $iv . $mac . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Descifra un secreto generado por apgallery_encrypt(). Los valores en claro
 * (instalaciones previas a 0.5.0) se devuelven tal cual para migración suave.
 *
 * @param string $ciphertext Valor almacenado.
 * @return string Secreto en claro o cadena vacía si el MAC no coincide.
 */
function apgallery_decrypt( $ciphertext ) {
	if ( '' === $ciphertext || 0 !== strpos( $ciphertext, 'apg1:' ) ) {
		return $ciphertext;
	}
	$raw = base64_decode( substr( $ciphertext, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false === $raw || strlen( $raw ) < 48 ) {
		return '';
	}
	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv  = substr( $raw, 0, 16 );
	$mac = substr( $raw, 16, 32 );
	$ct  = substr( $raw, 48 );
	if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ct, $key, true ), $mac ) ) {
		return '';
	}
	$pt = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	return false === $pt ? '' : $pt;
}

/**
 * Devuelve el refresh token en claro (descifrándolo si es necesario).
 *
 * @return string
 */
function apgallery_get_refresh_token() {
	return apgallery_decrypt( (string) get_option( APGALLERY_OPT_PREFIX . 'refresh_token' ) );
}

/**
 * Realiza una petición HTTP a la API de Google Photos con el access token.
 *
 * @param string $endpoint Ruta relativa (ej. 'v1/albums').
 * @param array  $args     Argumentos de wp_remote_request.
 * @return array|WP_Error
 */
function apgallery_remote_request( $endpoint, $args = array() ) {
	$token = apgallery_get_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$url = 'https://photoslibrary.googleapis.com/' . ltrim( $endpoint, '/' );

	$defaults = array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'timeout' => 15,
	);

	$args = wp_parse_args( $args, $defaults );
	$args['headers'] = array_merge( $defaults['headers'], (array) ( $args['headers'] ?? array() ) );

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 401 === $code ) {
		// Token expirado: forzar refresco y reintentar una vez.
		delete_transient( APGALLERY_OPT_PREFIX . 'access_token' );
		$token = apgallery_get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$response = wp_remote_request( $url, $args );
		$body     = json_decode( wp_remote_retrieve_body( $response ), true );
	}

	if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
		$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Error desconocido de la API de Google.';
		return new WP_Error( 'apgallery_api_error', $msg );
	}

	return $body;
}

/**
 * Devuelve un access token válido, refrescándolo con el refresh token si es necesario.
 *
 * @return string|WP_Error
 */
function apgallery_get_access_token() {
	$cached = get_transient( APGALLERY_OPT_PREFIX . 'access_token' );
	if ( false !== $cached ) {
		return $cached;
	}

	$refresh_token = apgallery_get_refresh_token();
	if ( '' === $refresh_token ) {
		return new WP_Error( 'apgallery_not_connected', __( 'APGallery: no hay ninguna cuenta de Google conectada.', 'apgallery-google-photos' ) );
	}

	$response = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'client_id'     => get_option( APGALLERY_OPT_PREFIX . 'client_id' ),
				'client_secret' => get_option( APGALLERY_OPT_PREFIX . 'client_secret' ),
				'refresh_token' => $refresh_token,
				'grant_type'    => 'refresh_token',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['access_token'] ) ) {
		return new WP_Error( 'apgallery_token_error', __( 'APGallery: no se pudo renovar el token de acceso.', 'apgallery-google-photos' ) );
	}

	// Guardar con 60 s de margen antes de la expiración real.
	set_transient( APGALLERY_OPT_PREFIX . 'access_token', $body['access_token'], max( 60, (int) $body['expires_in'] - 60 ) );

	return $body['access_token'];
}

/**
 * Devuelve las fotos de un álbum desde la caché (TTL corto) o desde la API.
 *
 * Las baseUrl de Google Photos expiran en ~1 h, así que se cachea únicamente
 * la respuesta de la API durante un TTL corto para proteger la cuota.
 *
 * @param string $album_id  ID del álbum.
 * @param int    $page_size Fotos por página.
 * @param bool   $use_cache True para usar caché.
 * @return array|WP_Error
 */
function apgallery_get_cached_album_media( $album_id, $page_size, $use_cache = true ) {
	$cache_key = APGALLERY_OPT_PREFIX . 'media_' . md5( $album_id . '|' . (int) $page_size );

	if ( $use_cache ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$media = apgallery_get_album_media( $album_id, $page_size );
	if ( is_wp_error( $media ) ) {
		return $media;
	}

	if ( $use_cache ) {
		set_transient( $cache_key, $media, APGALLERY_CACHE_TTL );
	}

	return $media;
}

/**
 * Borra todos los transientes de caché de media del plugin.
 */
function apgallery_flush_media_cache() {
	global $wpdb;
	$prefix = $wpdb->esc_like( '_transient_apgallery_media_' );
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$prefix . '%',
			$wpdb->esc_like( '_transient_timeout_apgallery_media_' ) . '%'
		)
	);
}

/**
 * Obtiene la lista de álbumes del usuario.
 *
 * @return array|WP_Error
 */
function apgallery_get_albums( $page_token = '' ) {
	$query = array(
		'pageSize'                 => 50,
		'excludeNonAppCreatedData' => 'false',
	);
	if ( '' !== $page_token ) {
		$query['pageToken'] = $page_token;
	}

	return apgallery_remote_request(
		add_query_arg( $query, 'v1/albums' ),
		array( 'method' => 'GET' )
	);
}

/**
 * Obtiene las fotos de un álbum.
 *
 * @param string $album_id   ID del álbum de Google Photos.
 * @param int    $page_size  Fotos por página (máx. 100).
 * @param string $page_token  Token de paginación.
 * @return array|WP_Error
 */
function apgallery_get_album_media( $album_id, $page_size = 24, $page_token = '' ) {
	$payload = array(
		'albumId'  => $album_id,
		'pageSize' => min( 100, max( 1, (int) $page_size ) ),
	);

	if ( '' !== $page_token ) {
		$payload['pageToken'] = $page_token;
	}

	return apgallery_remote_request(
		'v1/mediaItems:search',
		array(
			'method' => 'POST',
			'body'   => wp_json_encode( $payload ),
		)
	);
}

/* -------------------------------------------------------------------------
 * 3. Panel de ajustes (admin)
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'apgallery_add_settings_page' );
add_action( 'admin_menu', 'apgallery_add_media_page' );
function apgallery_add_settings_page() {
	add_options_page(
		__( 'APGallery for Google Photos', 'apgallery-google-photos' ),
		__( 'APGallery Google Photos', 'apgallery-google-photos' ),
		'manage_options',
		'apgallery-google-photos',
		'apgallery_render_settings_page'
	);
}

add_action( 'admin_enqueue_scripts', 'apgallery_admin_styles' );
function apgallery_admin_styles( $hook ) {
	if ( 'settings_page_apgallery-google-photos' !== $hook ) {
		return;
	}
	echo '<style>
		.apgallery-status { display:inline-flex; align-items:center; gap:.4rem; font-weight:600; }
		.apgallery-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
		.apgallery-dot.ok { background:#00a32a; }
		.apgallery-dot.ko { background:#d63638; }
		.apgallery-albums { margin-top:1rem; }
		.apgallery-album { display:inline-block; margin:.25rem .5rem .25rem 0; padding:.35rem .7rem; background:#f0f0f1; border-radius:4px; font-family:monospace; }
	</style>';
}

function apgallery_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'apgallery-google-photos' ) );
	}

	$connected = (bool) get_option( APGALLERY_OPT_PREFIX . 'refresh_token' );

	// Avisos de redirección (OAuth o importaciones).
	if ( isset( $_GET['apgallery_ok'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Cuenta de Google conectada correctamente.', 'apgallery-google-photos' ) . '</p></div>';
	}
	if ( isset( $_GET['apgallery_error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['apgallery_error'] ) ) ) . '</p></div>';
	}

	// Guardar credenciales.
	if ( isset( $_POST['apgallery_save'] ) && check_admin_referer( 'apgallery_save_credentials' ) ) {
		update_option( APGALLERY_OPT_PREFIX . 'client_id', sanitize_text_field( wp_unslash( $_POST['apgallery_client_id'] ?? '' ) ) );
		update_option( APGALLERY_OPT_PREFIX . 'client_secret', sanitize_text_field( wp_unslash( $_POST['apgallery_client_secret'] ?? '' ) ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Credenciales guardadas.', 'apgallery-google-photos' ) . '</p></div>';
	}

	// Desconectar cuenta.
	if ( isset( $_POST['apgallery_disconnect'] ) && check_admin_referer( 'apgallery_disconnect' ) ) {
		delete_option( APGALLERY_OPT_PREFIX . 'refresh_token' );
		delete_transient( APGALLERY_OPT_PREFIX . 'access_token' );
		apgallery_flush_media_cache();
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cuenta de Google desconectada.', 'apgallery-google-photos' ) . '</p></div>';
		$connected = false;
	}

	$client_id     = get_option( APGALLERY_OPT_PREFIX . 'client_id' );
	$client_secret = get_option( APGALLERY_OPT_PREFIX . 'client_secret' );
	$auth_url      = apgallery_build_auth_url();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'APGallery for Google Photos', 'apgallery-google-photos' ); ?></h1>

		<p>
			<span class="apgallery-status">
				<span class="apgallery-dot <?php echo $connected ? 'ok' : 'ko'; ?>"></span>
				<?php echo $connected ? esc_html__( 'Cuenta de Google conectada', 'apgallery-google-photos' ) : esc_html__( 'Sin conectar', 'apgallery-google-photos' ); ?>
			</span>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'apgallery_save_credentials' ); ?>
			<h2><?php esc_html_e( 'Credenciales de Google Cloud', 'apgallery-google-photos' ); ?></h2>
			<p><?php esc_html_e( 'Crea un proyecto en Google Cloud Console, habilita la Photos Library API y crea credenciales OAuth 2.0 (aplicación web). La URL de redirección debe ser:', 'apgallery-google-photos' ); ?></p>
			<p><code><?php echo esc_html( apgallery_get_redirect_uri() ); ?></code></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="apgallery_client_id"><?php esc_html_e( 'Client ID', 'apgallery-google-photos' ); ?></label></th>
					<td><input type="text" class="regular-text" id="apgallery_client_id" name="apgallery_client_id" value="<?php echo esc_attr( $client_id ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="apgallery_client_secret"><?php esc_html_e( 'Client Secret', 'apgallery-google-photos' ); ?></label></th>
					<td><input type="password" class="regular-text" id="apgallery_client_secret" name="apgallery_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" autocomplete="new-password" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Guardar credenciales', 'apgallery-google-photos' ), 'primary', 'apgallery_save', false ); ?>
		</form>

		<?php if ( $client_id && $client_secret ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Conexión de la cuenta', 'apgallery-google-photos' ); ?></h2>
			<?php if ( $connected ) : ?>
				<form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Desconectar la cuenta de Google?', 'apgallery-google-photos' ) ); ?>');">
					<?php wp_nonce_field( 'apgallery_disconnect' ); ?>
					<button type="submit" class="button button-secondary" name="apgallery_disconnect" value="1"><?php esc_html_e( 'Desconectar cuenta', 'apgallery-google-photos' ); ?></button>
				</form>
			<?php elseif ( $auth_url ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>"><?php esc_html_e( 'Conectar con Google Photos', 'apgallery-google-photos' ); ?></a></p>
			<?php endif; ?>

			<?php if ( $connected ) : ?>
				<?php apgallery_render_albums_preview(); ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Muestra una vista previa con los IDs de los álbumes disponibles
 * (útiles para usarlos en el shortcode).
 */
function apgallery_render_albums_preview() {
	$albums = apgallery_get_albums();
	if ( is_wp_error( $albums ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $albums->get_error_message() ) . '</p></div>';
		return;
	}
	if ( empty( $albums['albums'] ) ) {
		echo '<p><em>' . esc_html__( 'No se encontraron álbumes en la cuenta conectada.', 'apgallery-google-photos' ) . '</em></p>';
		return;
	}
	echo '<h2>' . esc_html__( 'Álbumes disponibles', 'apgallery-google-photos' ) . '</h2>';
	echo '<div class="apgallery-albums">';
	foreach ( $albums['albums'] as $album ) {
		printf(
			'<span class="apgallery-album" title="%1$s">%1$s<br/>[apgallery album="%2$s"]</span>',
			esc_html( $album['title'] ?? 'Sin título' ),
			esc_attr( $album['id'] )
		);
	}
	echo '</div>';
	echo '<p><em>' . esc_html__( 'Copia el shortcode del álbum que quieras mostrar en cualquier página o entrada.', 'apgallery-google-photos' ) . '</em></p>';
}

/* -------------------------------------------------------------------------
 * 4. Flujo OAuth 2.0
 * ---------------------------------------------------------------------- */

function apgallery_get_redirect_uri() {
	return admin_url( 'admin-post.php?action=apgallery_oauth_callback' );
}

function apgallery_build_auth_url() {
	$client_id = get_option( APGALLERY_OPT_PREFIX . 'client_id' );
	if ( empty( $client_id ) ) {
		return '';
	}

	$state = wp_create_nonce( 'apgallery_oauth_state' );
	set_transient( APGALLERY_OPT_PREFIX . 'oauth_state', $state, 10 * MINUTE_IN_SECONDS );

	return add_query_arg(
		array(
			'client_id'     => $client_id,
			'redirect_uri'  => apgallery_get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/photoslibrary.readonly',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		),
		'https://accounts.google.com/o/oauth2/v2/auth'
	);
}

// Callback de redirección desde Google.
add_action( 'admin_post_apgallery_oauth_callback', 'apgallery_oauth_callback' );
function apgallery_oauth_callback() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permisos insuficientes.', 'apgallery-google-photos' ) );
	}

	$fail = static function ( $message ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'apgallery-google-photos', 'apgallery_error' => $message ), admin_url( 'options-general.php' ) ) );
		exit;
	};

	if ( isset( $_GET['error'] ) ) {
		$fail( sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
	}

	if ( ! isset( $_GET['code'], $_GET['state'] ) ) {
		$fail( __( 'Respuesta de Google incompleta.', 'apgallery-google-photos' ) );
	}

	$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
	$saved = get_transient( APGALLERY_OPT_PREFIX . 'oauth_state' );
	if ( ! $saved || ! hash_equals( $saved, $state ) ) {
		$fail( __( 'Estado OAuth inválido. Intenta conectar de nuevo.', 'apgallery-google-photos' ) );
	}
	delete_transient( APGALLERY_OPT_PREFIX . 'oauth_state' );

	$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

	$response = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'client_id'     => get_option( APGALLERY_OPT_PREFIX . 'client_id' ),
				'client_secret' => get_option( APGALLERY_OPT_PREFIX . 'client_secret' ),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => apgallery_get_redirect_uri(),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		$fail( $response->get_error_message() );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['refresh_token'] ) ) {
		$fail( __( 'Google no devolvió el refresh token. Revoca el acceso en tu cuenta de Google y conéctate de nuevo.', 'apgallery-google-photos' ) );
	}

	update_option( APGALLERY_OPT_PREFIX . 'refresh_token', apgallery_encrypt( $body['refresh_token'] ) );
	set_transient( APGALLERY_OPT_PREFIX . 'access_token', $body['access_token'], max( 60, (int) $body['expires_in'] - 60 ) );
	apgallery_flush_media_cache();

	wp_safe_redirect( add_query_arg( array( 'page' => 'apgallery-google-photos', 'apgallery_ok' => '1' ), admin_url( 'options-general.php' ) ) );
	exit;
}

/* -------------------------------------------------------------------------
 * 5. Página "Google Photos" en Medios: visualizar e importar
 * ---------------------------------------------------------------------- */

function apgallery_add_media_page() {
	add_media_page(
		__( 'Google Photos', 'apgallery-google-photos' ),
		__( 'Google Photos', 'apgallery-google-photos' ),
		'upload_files',
		'apgallery-google-photos-media',
		'apgallery_render_media_page'
	);
}

add_action( 'admin_enqueue_scripts', 'apgallery_media_styles' );
function apgallery_media_styles( $hook ) {
	if ( 'media_page_apgallery-google-photos-media' !== $hook && 'album_page_apgallery-google-photos-media' !== $hook ) {
		return;
	}
	echo '<style>
		.apgallery-media-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; margin-top:1rem; }
		.apgallery-media-item { position:relative; margin:0; border:1px solid #dcdcde; border-radius:8px; overflow:hidden; background:#fff; }
		.apgallery-media-item img { width:100%; aspect-ratio:1/1; object-fit:cover; display:block; }
		.apgallery-media-item .apgallery-import-form { position:absolute; inset:auto 0 0 0; padding:8px; background:rgba(255,255,255,.92); text-align:center; }
		.apgallery-media-actions { margin:1rem 0; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
		.apgallery-album-select { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
	</style>';
}

/**
 * Renderiza la página Medios → Google Photos: selector de álbum, miniaturas
 * y botón para importar cada foto a la biblioteca de medios.
 */
function apgallery_render_media_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'apgallery-google-photos' ) );
	}

	$connected = (bool) get_option( APGALLERY_OPT_PREFIX . 'refresh_token' );
	if ( ! $connected ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Google Photos', 'apgallery-google-photos' ) . '</h1>';
		echo '<div class="notice notice-warning"><p>' . sprintf(
			/* translators: %s: URL de la página de ajustes del plugin. */
			esc_html__( 'Aún no hay ninguna cuenta de Google conectada. Configúrala en %s.', 'apgallery-google-photos' ),
			'<a href="' . esc_url( admin_url( 'options-general.php?page=apgallery-google-photos' ) ) . '">' . esc_html__( 'Ajustes → APGallery Google Photos', 'apgallery-google-photos' ) . '</a>'
		) . '</p></div></div>';
		return;
	}

	// Importar una foto a la biblioteca de medios.
	if ( isset( $_POST['apgallery_import'] ) && check_admin_referer( 'apgallery_import_photo' ) ) {
		$result = apgallery_import_photo_to_media(
			sanitize_text_field( wp_unslash( $_POST['apgallery_baseurl'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['apgallery_filename'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['apgallery_media_id'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . sprintf(
				/* translators: %s: nombre del archivo importado. */
				esc_html__( 'Foto "%s" importada a la biblioteca de medios.', 'apgallery-google-photos' ),
				esc_html( $result )
			) . '</p></div>';
		}
	}

	$album_id   = isset( $_GET['album'] ) ? sanitize_text_field( wp_unslash( $_GET['album'] ) ) : '';
	$page_token = isset( $_GET['paged_token'] ) ? sanitize_text_field( wp_unslash( $_GET['paged_token'] ) ) : '';

	echo '<div class="wrap"><h1>' . esc_html__( 'Google Photos', 'apgallery-google-photos' ) . '</h1>';

	$albums = apgallery_get_albums();
	if ( is_wp_error( $albums ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $albums->get_error_message() ) . '</p></div></div>';
		return;
	}

	if ( empty( $albums['albums'] ) ) {
		echo '<p><em>' . esc_html__( 'No se encontraron álbumes en la cuenta conectada.', 'apgallery-google-photos' ) . '</em></p></div>';
		return;
	}

	$albums_by_id = array();
	foreach ( $albums['albums'] as $album ) {
		$albums_by_id[ $album['id'] ] = $album['title'] ?? __( 'Sin título', 'apgallery-google-photos' );
	}

	echo '<form method="get" class="apgallery-album-select">';
	echo '<input type="hidden" name="page" value="apgallery-google-photos-media" />';
	echo '<label for="apgallery_album"><strong>' . esc_html__( 'Álbum:', 'apgallery-google-photos' ) . '</strong></label> ';
	echo '<select name="album" id="apgallery_album">';
	foreach ( $albums_by_id as $id => $title ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $id ),
			selected( $album_id, $id, false ),
			esc_html( $title )
		);
	}
	echo '</select> ';
	submit_button( __( 'Ver fotos', 'apgallery-google-photos' ), 'secondary', '', false );
	echo '</form>';

	if ( '' === $album_id ) {
		echo '<p><em>' . esc_html__( 'Elige un álbum para ver sus fotos e importarlas a tu biblioteca de medios.', 'apgallery-google-photos' ) . '</em></p></div>';
		return;
	}

	$media = apgallery_get_album_media( $album_id, 24, $page_token );
	if ( is_wp_error( $media ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $media->get_error_message() ) . '</p></div></div>';
		return;
	}

	$album_title = $albums_by_id[ $album_id ] ?? '';
	echo '<h2>' . sprintf(
		/* translators: %s: título del álbum. */
		esc_html__( 'Fotos del álbum "%s"', 'apgallery-google-photos' ),
			esc_html( $album_title )
	) . '</h2>';

	if ( empty( $media['mediaItems'] ) ) {
		echo '<p><em>' . esc_html__( 'Este álbum no tiene fotos.', 'apgallery-google-photos' ) . '</em></p></div>';
		return;
	}

	echo '<div class="apgallery-media-grid">';
	foreach ( $media['mediaItems'] as $item ) {
		if ( 'PHOTO' !== ( $item['mimeType'] ?? '' ) ) {
			continue;
		}
		$thumb     = $item['baseUrl'] . '=w300-h300-c';
		$download  = $item['baseUrl'] . '=d';
		$filename  = $item['filename'] ?? 'google-photo.jpg';
		$media_id  = apgallery_find_imported_media_id( $item['id'] ?? '' );
		?>
		<figure class="apgallery-media-item">
			<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $filename ); ?>" loading="lazy" />
			<form method="post" class="apgallery-import-form">
				<?php wp_nonce_field( 'apgallery_import_photo' ); ?>
				<input type="hidden" name="apgallery_baseurl" value="<?php echo esc_attr( $download ); ?>" />
				<input type="hidden" name="apgallery_filename" value="<?php echo esc_attr( $filename ); ?>" />
				<input type="hidden" name="apgallery_media_id" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>" />
				<?php if ( $media_id ) : ?>
					<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $media_id ) ); ?>"><?php esc_html_e( 'Ya en medios', 'apgallery-google-photos' ); ?></a>
				<?php else : ?>
					<button type="submit" class="button button-primary button-small" name="apgallery_import" value="1"><?php esc_html_e( 'Importar a medios', 'apgallery-google-photos' ); ?></button>
				<?php endif; ?>
			</form>
		</figure>
		<?php
	}
	echo '</div>';

	if ( ! empty( $media['nextPageToken'] ) ) {
		echo '<div class="apgallery-media-actions">';
		printf(
			'<a class="button button-secondary" href="%s">%s</a>',
			esc_url( add_query_arg( array( 'page' => 'apgallery-google-photos-media', 'album' => $album_id, 'paged_token' => $media['nextPageToken'] ), admin_url( 'upload.php' ) ) ),
			esc_html__( 'Cargar más fotos', 'apgallery-google-photos' )
		);
		echo '</div>';
	}

	echo '</div>';
}

/**
 * Busca un adjunto ya importado por su ID de media item de Google Photos.
 *
 * @param string $google_media_id ID del media item de Google.
 * @return int ID del adjunto o 0 si no existe.
 */
function apgallery_find_imported_media_id( $google_media_id ) {
	if ( '' === $google_media_id ) {
		return 0;
	}
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => APGALLERY_OPT_PREFIX . 'google_media_id',
					'value' => $google_media_id,
				),
			),
			'fields'      => 'ids',
			'numberposts' => 1,
		)
	);
	return ! empty( $existing ) ? (int) $existing[0] : 0;
}

/**
 * Descarga una foto de Google Photos y la añade a la biblioteca de medios.
 *
 * @param string $baseurl        URL de descarga (baseUrl con '=d').
 * @param string $filename       Nombre de archivo sugerido.
 * @param string $google_media_id ID del media item de Google (para evitar duplicados).
 * @return string|WP_Error Nombre del archivo importado.
 */
function apgallery_import_photo_to_media( $baseurl, $filename, $google_media_id = '' ) {
	if ( '' === $baseurl || ! preg_match( '#^https://photoslibrary\.googleapis\.com/#', $baseurl ) ) {
		return new WP_Error( 'apgallery_invalid_url', __( 'APGallery: URL de descarga no válida.', 'apgallery-google-photos' ) );
	}

	if ( '' === $filename ) {
		$filename = 'google-photo.jpg';
	}
	$filename = sanitize_file_name( $filename );
	if ( '' === $filename ) {
		$filename = 'google-photo.jpg';
	}

	$ext       = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$valid_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
	if ( ! in_array( $ext, $valid_ext, true ) ) {
		$filename .= '.jpg';
	}

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$tmp = download_url( $baseurl );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
	);

	$media_id = media_handle_sideload( $file_array, 0 );
	if ( is_wp_error( $media_id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $media_id;
	}

	// Guardar el ID de Google para evitar duplicados y permitir trazabilidad.
	if ( '' !== $google_media_id ) {
		update_post_meta( $media_id, APGALLERY_OPT_PREFIX . 'google_media_id', $google_media_id );
	}

	return $filename;
}

/* -------------------------------------------------------------------------
 * 6. Pestaña "Google Photos" en el modal de la biblioteca de medios
 * ---------------------------------------------------------------------- */

/**
 * AJAX: lista de álbumes para el modal de medios.
 */
add_action( 'wp_ajax_apgallery_modal_albums', 'apgallery_ajax_modal_albums' );
function apgallery_ajax_modal_albums() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'apgallery-google-photos' ) ), 403 );
	}
	$albums = apgallery_get_albums();
	if ( is_wp_error( $albums ) ) {
		wp_send_json_error( array( 'message' => $albums->get_error_message() ), 500 );
	}
	$out = array();
	foreach ( ( $albums['albums'] ?? array() ) as $album ) {
		$out[] = array(
			'id'    => $album['id'],
			'title' => $album['title'] ?? __( 'Sin título', 'apgallery-google-photos' ),
		);
	}
	wp_send_json_success( array( 'albums' => $out ) );
}

/**
 * AJAX: fotos de un álbum para el modal de medios.
 */
add_action( 'wp_ajax_apgallery_modal_media', 'apgallery_ajax_modal_media' );
function apgallery_ajax_modal_media() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'apgallery-google-photos' ) ), 403 );
	}
	$album_id   = isset( $_POST['album'] ) ? sanitize_text_field( wp_unslash( $_POST['album'] ) ) : '';
	$page_token = isset( $_POST['paged_token'] ) ? sanitize_text_field( wp_unslash( $_POST['paged_token'] ) ) : '';
	if ( '' === $album_id ) {
		wp_send_json_error( array( 'message' => __( 'Falta el ID del álbum.', 'apgallery-google-photos' ) ), 400 );
	}

	$media = apgallery_get_album_media( $album_id, 24, $page_token );
	if ( is_wp_error( $media ) ) {
		wp_send_json_error( array( 'message' => $media->get_error_message() ), 500 );
	}

	$out = array();
	foreach ( ( $media['mediaItems'] ?? array() ) as $item ) {
		if ( 'PHOTO' !== ( $item['mimeType'] ?? '' ) ) {
			continue;
		}
		$out[] = array(
			'id'          => $item['id'],
			'filename'    => $item['filename'] ?? '',
			'thumb'       => $item['baseUrl'] . '=w300-h300-c',
			'download'    => $item['baseUrl'] . '=d',
			'imported_id' => apgallery_find_imported_media_id( $item['id'] ?? '' ),
		);
	}
	wp_send_json_success(
		array(
			'items'      => $out,
			'next_token' => $media['nextPageToken'] ?? '',
		)
	);
}

/**
 * AJAX: importa (o reutiliza) una foto y devuelve el ID del adjunto.
 */
add_action( 'wp_ajax_apgallery_modal_import', 'apgallery_ajax_modal_import' );
function apgallery_ajax_modal_import() {
	if ( ! current_user_can( 'upload_files' ) || ! check_ajax_referer( 'apgallery_modal', false, false ) ) {
		wp_send_json_error( array( 'message' => __( 'Sesión no válida. Recarga la página e inténtalo de nuevo.', 'apgallery-google-photos' ) ), 403 );
	}
	$baseurl       = isset( $_POST['baseurl'] ) ? sanitize_text_field( wp_unslash( $_POST['baseurl'] ) ) : '';
	$filename      = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
	$media_item_id  = isset( $_POST['media_id'] ) ? sanitize_text_field( wp_unslash( $_POST['media_id'] ) ) : '';
	if ( '' === $baseurl ) {
		wp_send_json_error( array( 'message' => __( 'URL de descarga no válida.', 'apgallery-google-photos' ) ), 400 );
	}

	// Si ya fue importada antes, devolver el adjunto existente.
	$existing = apgallery_find_imported_media_id( $media_item_id );
	if ( $existing ) {
		wp_send_json_success(
			array(
				'id'  => $existing,
				'url' => wp_get_attachment_url( $existing ),
			)
		);
	}

	$result = apgallery_import_photo_to_media( $baseurl, $filename, $media_item_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$attachment_id = apgallery_find_imported_media_id( $media_item_id );
	if ( ! $attachment_id ) {
		wp_send_json_error( array( 'message' => __( 'No se pudo localizar el adjunto importado.', 'apgallery-google-photos' ) ), 500 );
	}
	wp_send_json_success(
		array(
			'id'  => $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		)
	);
}

/**
 * Encola el script que añade la pestaña "Google Photos" al frame de wp.media.
 */
add_action( 'admin_enqueue_scripts', 'apgallery_enqueue_media_modal_script' );
function apgallery_enqueue_media_modal_script( $hook ) {
	if ( ! get_option( APGALLERY_OPT_PREFIX . 'refresh_token' ) ) {
		return;
	}
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && false === strpos( $hook, 'apgallery' ) ) {
		return;
	}

	wp_register_script( 'apgallery-media-modal', false, array( 'media-editor', 'media-views', 'jquery' ), APGALLERY_VERSION, true );
	wp_enqueue_script( 'apgallery-media-modal' );
	wp_add_inline_script(
		'apgallery-media-modal',
		'var APGALLERY_MODAL = ' . wp_json_encode(
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'apgallery_modal' ),
				'i18n'    => array(
					'title'         => __( 'Google Photos', 'apgallery-google-photos' ),
					'selectAlbum'   => __( 'Elige un álbum…', 'apgallery-google-photos' ),
					'loadMore'      => __( 'Cargar más fotos', 'apgallery-google-photos' ),
					'importing'     => __( 'Importando…', 'apgallery-google-photos' ),
					'import'        => __( 'Importar y usar', 'apgallery-google-photos' ),
					'imported'      => __( 'Ya en medios', 'apgallery-google-photos' ),
					'error'         => __( 'Error al comunicarse con Google Photos.', 'apgallery-google-photos' ),
					'noPhotos'      => __( 'Este álbum no tiene fotos.', 'apgallery-google-photos' ),
					'noAlbums'      => __( 'No se encontraron álbumes en la cuenta conectada.', 'apgallery-google-photos' ),
					'noConnection'  => __( 'Aún no hay ninguna cuenta de Google conectada.', 'apgallery-google-photos' ),
			)
			)
		)
	);
	wp_add_inline_script( 'apgallery-media-modal', apgallery_media_modal_js() );

	echo '<style>
		.apgallery-modal-content { padding:0 16px 16px; }
		.apgallery-modal-toolbar { display:flex; gap:8px; align-items:center; margin:16px 0; flex-wrap:wrap; }
		.apgallery-modal-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; }
		.apgallery-modal-item { position:relative; margin:0; border:2px solid transparent; border-radius:4px; overflow:hidden; cursor:pointer; }
		.apgallery-modal-item img { width:100%; aspect-ratio:1/1; object-fit:cover; display:block; }
		.apgallery-modal-item.selected { border-color:#2271b1; }
		.apgallery-modal-item .apgallery-badge { position:absolute; top:4px; left:4px; background:#2271b1; color:#fff; font-size:10px; padding:2px 6px; border-radius:3px; }
		.apgallery-modal-empty { grid-column:1/-1; }
	</style>';
}

/**
 * JS del modal de medios: pestaña Google Photos en el router del frame.
 *
 * Al confirmar, importa la foto seleccionada (o reutiliza la ya importada)
 * y la devuelve como selección estándar del frame.
 *
 * @return string
 */
function apgallery_media_modal_js() {
	return "(function ($) {
	var media = wp.media;
	if (!media || !media.view || !media.view.MediaFrame || !media.view.MediaFrame.Post) return;

	function api(action, data) {
		return $.post(APGALLERY_MODAL.ajaxUrl, $.extend({ action: action, nonce: APGALLERY_MODAL.nonce }, data), null, 'json');
	}

	var GooglePhotosView = media.View.extend({
		tagName:   'div',
		className: 'apgallery-modal-content',
		events: {
			'change .apgallery-modal-album': 'onAlbumChange',
			'click .apgallery-load-more':    'onLoadMore',
			'click .apgallery-modal-item':   'onItemClick'
		},
		initialize: function () {
			this.albums = [];
			this.album = '';
			this.nextToken = '';
			this.selected = null;
			this.frame = this.options.frame;
			this.loadAlbums();
		},
		render: function () {
			var options = this.albums.map(function (a) {
				return '<option value="' + a.id + '">' + a.title + '</option>';
			}).join('');
			this.$el.html(
				'<div class="apgallery-modal-toolbar">' +
					'<label><strong>' + APGALLERY_MODAL.i18n.title + '</strong> ' +
					'<select class="apgallery-modal-album"><option value="">' + APGALLERY_MODAL.i18n.selectAlbum + '</option>' + options + '</select></label>' +
					'<button type="button" class="button apgallery-load-more" hidden>' + APGALLERY_MODAL.i18n.loadMore + '</button>' +
				'</div>' +
				'<div class="apgallery-modal-grid"></div>'
			);
			if (this.album) this.$('.apgallery-modal-album').val(this.album);
			this.delegateEvents();
			return this;
		},
		loadAlbums: function () {
			var self = this;
			api('apgallery_modal_albums', {}).done(function (res) {
				if (res && res.success) self.albums = res.data.albums;
				self.render();
			});
		},
		onAlbumChange: function (e) {
			this.album = $(e.target).val();
			this.nextToken = '';
			this.selected = null;
			this.updateToolbar();
			this.$('.apgallery-modal-grid').empty();
			if (this.album) this.loadPhotos();
		},
		onLoadMore: function (e) {
			e.preventDefault();
			this.loadPhotos();
		},
		onItemClick: function (e) {
			var $item = $(e.currentTarget);
			this.$('.apgallery-modal-item').removeClass('selected');
			$item.addClass('selected');
			this.selected = $item.data();
			this.updateToolbar();
		},
		loadPhotos: function () {
			var self = this;
			var $btn = this.$('.apgallery-load-more');
			$btn.text(APGALLERY_MODAL.i18n.importing).prop('disabled', true);
			api('apgallery_modal_media', { album: this.album, paged_token: this.nextToken }).done(function (res) {
				if (!res || !res.success) { $btn.text(APGALLERY_MODAL.i18n.error).prop('disabled', false); return; }
				self.nextToken = res.data.next_token;
				var $grid = self.$('.apgallery-modal-grid');
				$.each(res.data.items, function (i, item) {
					var $el = $('<div class="apgallery-modal-item"></div>')
						.append($('<img />').attr({ src: item.thumb, alt: item.filename, loading: 'lazy' }));
					if (item.imported_id) $el.append($('<span class="apgallery-badge"></span>').text(APGALLERY_MODAL.i18n.imported));
					$el.data(item);
					$grid.append($el);
				});
				$btn.toggle(!!self.nextToken).text(APGALLERY_MODAL.i18n.loadMore).prop('disabled', false);
				if (!res.data.items.length && !$grid.children().length) {
					$grid.append($('<p class="apgallery-modal-empty"></p>').text(APGALLERY_MODAL.i18n.noPhotos));
				}
			});
		},
		updateToolbar: function () {
			var toolbar = this.frame.toolbar.get();
			if (toolbar && toolbar.get) {
				var btn = toolbar.get('apgallery-insert');
				if (btn) btn.model.set('disabled', !this.selected);
			}
		},
		importSelected: function () {
			var self = this;
			if (!this.selected) return $.Deferred().reject();
			if (this.selected.imported_id) {
				return $.Deferred().resolve({ id: this.selected.imported_id });
			}
			return api('apgallery_modal_import', {
				baseurl:  this.selected.download,
				filename: this.selected.filename,
				media_id: this.selected.id
			});
		}
	});

	var InsertToolbar = media.view.Toolbar.extend({
		initialize: function () {
			media.view.Toolbar.prototype.initialize.apply(this, arguments);
			this.set('apgallery-insert', new media.view.Button({
				text:     APGALLERY_MODAL.i18n.import,
				style:    'primary',
				disabled: true,
				click:    function () {
					var frame = this.controller, content = frame.content.get();
					this.model.set('disabled', true).set('text', APGALLERY_MODAL.i18n.importing);
					content.importSelected().done(function (res) {
						if (!res || !res.success) return;
						var attachment = media.model.Attachment.get(res.data.id);
						attachment.fetch().done(function () {
							var selection = frame.state().get('selection');
							selection.add(attachment);
							frame.close();
							frame.trigger('select');
						});
					});
				}
			}));
		}
	});

	var GooglePhotosState = media.controller.Library.extend({
		defaults: _.defaults({
			id:       'apgallery-google-photos',
			title:    APGALLERY_MODAL.i18n.title,
			multiple: false,
			content:  'apgallery-browse',
			toolbar:  'apgallery-toolbar',
			menu:     'default'
		}, media.controller.Library.prototype.defaults),
		activate: function () {},
		deactivate: function () {}
	});

	var OriginalPost = media.view.MediaFrame.Post;
	media.view.MediaFrame.Post = OriginalPost.extend({
		initialize: function () {
			OriginalPost.prototype.initialize.apply(this, arguments);
			this.states.add([new GooglePhotosState()]);
			this.on('content:render:apgallery-browse', this.apgalleryRenderContent, this);
			this.on('toolbar:create:apgallery-toolbar', this.apgalleryCreateToolbar, this);
		},
		apgalleryRenderContent: function () {
			this.content.set(new GooglePhotosView({ frame: this }));
		},
		apgalleryCreateToolbar: function (toolbar) {
			toolbar.view = new InsertToolbar({ controller: this });
		},
		browseRouter: function (view) {
			OriginalPost.prototype.browseRouter.apply(this, arguments);
			view.set({
				'apgallery-google-photos': {
					text:     APGALLERY_MODAL.i18n.title,
					priority: 60
				}
			});
		}
	});
})(jQuery);";
}

/* -------------------------------------------------------------------------
 * 7. Shortcode [apgallery]
 * ---------------------------------------------------------------------- */

add_action( 'init', 'apgallery_register_shortcode' );
function apgallery_register_shortcode() {
	add_shortcode( 'apgallery', 'apgallery_render_shortcode' );
}

function apgallery_render_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'album'   => '', // ID del álbum de Google Photos.
			'columns' => 3,  // Columnas del grid (1-6).
			'pagesize' => 24, // Fotos por carga (máx. 100).
			'cache'   => 1,  // 1 = usar caché (5 min), 0 = desactivada.
		),
		$atts,
		'apgallery'
	);

	$album_id = sanitize_text_field( $atts['album'] );
	if ( '' === $album_id ) {
		return '<p>' . esc_html__( 'APGallery: falta el atributo album en el shortcode.', 'apgallery-google-photos' ) . '</p>';
	}

	$use_cache = '0' !== (string) $atts['cache'];
	$media     = apgallery_get_cached_album_media( $album_id, (int) $atts['pagesize'], $use_cache );

	if ( is_wp_error( $media ) ) {
		return '<p>' . esc_html( $media->get_error_message() ) . '</p>';
	}

	wp_register_style( 'apgallery', false, array(), APGALLERY_VERSION, 'all' );
	wp_enqueue_style( 'apgallery' );
	wp_add_inline_style( 'apgallery', apgallery_public_css() );
	wp_register_script( 'apgallery', false, array(), APGALLERY_VERSION, true );
	wp_enqueue_script( 'apgallery' );
	wp_add_inline_script(
		'apgallery',
		'var APGALLERY_FRONT = ' . wp_json_encode(
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'apgallery_front' ),
				'i18n'    => array(
					'loadMore'   => __( 'Cargar más fotos', 'apgallery-google-photos' ),
					'loading'    => __( 'Cargando…', 'apgallery-google-photos' ),
					'noMore'     => __( 'No hay más fotos en este álbum.', 'apgallery-google-photos' ),
					'error'      => __( 'Error al cargar más fotos.', 'apgallery-google-photos' ),
				),
			)
		)
	);
	wp_add_inline_script( 'apgallery', apgallery_public_js() );

	$columns    = min( 6, max( 1, (int) $atts['columns'] ) );
	$next_token = $media['nextPageToken'] ?? '';
	$has_more   = ! empty( $next_token );

	ob_start();
	?>
	<div class="apgallery-wrapper"
		data-album="<?php echo esc_attr( $album_id ); ?>"
		data-pagesize="<?php echo esc_attr( (int) $atts['pagesize'] ); ?>"
		data-columns="<?php echo esc_attr( $columns ); ?>"
		data-token="<?php echo esc_attr( $next_token ); ?>">
		<div class="apgallery-grid apgallery-cols-<?php echo esc_attr( $columns ); ?>">
			<?php
			if ( ! empty( $media['mediaItems'] ) ) {
				foreach ( $media['mediaItems'] as $item ) {
					if ( 'PHOTO' !== ( $item['mimeType'] ?? '' ) ) {
						continue; // Ignorar vídeos por ahora.
					}
					$thumb = $item['baseUrl'] . '=w400-h400-c';
					$full  = $item['baseUrl'] . '=w1600';
					$alt   = $item['filename'] ?? '';
					?>
					<figure class="apgallery-item">
						<a href="<?php echo esc_url( $full ); ?>" class="apgallery-lightbox" data-alt="<?php echo esc_attr( $alt ); ?>">
							<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
						</a>
					</figure>
					<?php
				}
			} else {
				echo '<p><em>' . esc_html__( 'Este álbum no tiene fotos.', 'apgallery-google-photos' ) . '</em></p>';
			}
			?>
		</div>
		<?php if ( $has_more ) : ?>
			<div class="apgallery-load-more-wrap">
				<button type="button" class="apgallery-load-more"><?php esc_html_e( 'Cargar más fotos', 'apgallery-google-photos' ); ?></button>
			</div>
		<?php endif; ?>
		<div class="apgallery-lightbox-overlay" hidden aria-hidden="true">
			<img src="" alt="" />
			<button type="button" class="apgallery-lightbox-close" aria-label="<?php esc_attr_e( 'Cerrar', 'apgallery-google-photos' ); ?>">&times;</button>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * AJAX público (con y sin sesión): siguientes fotos de un álbum para el botón
 * "Cargar más" de la galería frontal. La caché protege la cuota de la API.
 */
add_action( 'wp_ajax_apgallery_front_media', 'apgallery_ajax_front_media' );
add_action( 'wp_ajax_nopriv_apgallery_front_media', 'apgallery_ajax_front_media' );
function apgallery_ajax_front_media() {
	if ( ! check_ajax_referer( 'apgallery_front', false, false ) ) {
		wp_send_json_error( array( 'message' => __( 'Sesión no válida. Recarga la página.', 'apgallery-google-photos' ) ), 403 );
	}

	$album_id   = isset( $_POST['album'] ) ? sanitize_text_field( wp_unslash( $_POST['album'] ) ) : '';
	$pagesize   = isset( $_POST['pagesize'] ) ? min( 100, max( 1, (int) $_POST['pagesize'] ) ) : 24;
	$columns    = isset( $_POST['columns'] ) ? min( 6, max( 1, (int) $_POST['columns'] ) ) : 3;
	$page_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

	if ( '' === $album_id || '' === $page_token ) {
		wp_send_json_error( array( 'message' => __( 'Parámetros no válidos.', 'apgallery-google-photos' ) ), 400 );
	}

	$cache_key = APGALLERY_OPT_PREFIX . 'media_' . md5( $album_id . '|' . $pagesize . '|' . $page_token );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		$media = $cached;
	} else {
		$media = apgallery_get_album_media( $album_id, $pagesize, $page_token );
		if ( is_wp_error( $media ) ) {
			wp_send_json_error( array( 'message' => $media->get_error_message() ), 500 );
		}
		set_transient( $cache_key, $media, APGALLERY_CACHE_TTL );
	}

	$out = '';
	foreach ( ( $media['mediaItems'] ?? array() ) as $item ) {
		if ( 'PHOTO' !== ( $item['mimeType'] ?? '' ) ) {
			continue;
		}
		$thumb = $item['baseUrl'] . '=w400-h400-c';
		$full  = $item['baseUrl'] . '=w1600';
		$alt   = $item['filename'] ?? '';
		ob_start();
		?>
		<figure class="apgallery-item">
			<a href="<?php echo esc_url( $full ); ?>" class="apgallery-lightbox" data-alt="<?php echo esc_attr( $alt ); ?>">
				<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
			</a>
		</figure>
		<?php
		$out .= ob_get_clean();
	}

	wp_send_json_success(
		array(
			'html'      => $out,
			'nextToken' => $media['nextPageToken'] ?? '',
			'columns'   => $columns,
		)
	);
}

function apgallery_public_css() {
	return '
.apgallery-grid { display:grid; gap:12px; margin:1.5rem 0; }
.apgallery-cols-1 { grid-template-columns:1fr; }
.apgallery-cols-2 { grid-template-columns:repeat(2,1fr); }
.apgallery-cols-3 { grid-template-columns:repeat(3,1fr); }
.apgallery-cols-4 { grid-template-columns:repeat(4,1fr); }
.apgallery-cols-5 { grid-template-columns:repeat(5,1fr); }
.apgallery-cols-6 { grid-template-columns:repeat(6,1fr); }
@media (max-width:768px) { .apgallery-grid { grid-template-columns:repeat(2,1fr) !important; } }
.apgallery-item { margin:0; }
.apgallery-item a { display:block; line-height:0; border-radius:8px; overflow:hidden; }
.apgallery-item img { width:100%; height:100%; object-fit:cover; aspect-ratio:1/1; transition:transform .25s ease; }
.apgallery-item a:hover img { transform:scale(1.04); }
.apgallery-lightbox-overlay { position:fixed; inset:0; background:rgba(0,0,0,.9); display:flex; align-items:center; justify-content:center; z-index:99999; }
.apgallery-load-more-wrap { text-align:center; margin:1rem 0 1.5rem; }
.apgallery-load-more { padding:.55rem 1.3rem; border:1px solid #dcdcde; border-radius:8px; background:#fff; cursor:pointer; font-size:.95rem; }
.apgallery-load-more:hover { background:#f6f7f7; }
.apgallery-load-more[disabled] { opacity:.6; cursor:default; }
.apgallery-lightbox-overlay img { max-width:92vw; max-height:88vh; border-radius:4px; }
.apgallery-lightbox-close { position:absolute; top:16px; right:20px; background:none; border:0; color:#fff; font-size:42px; line-height:1; cursor:pointer; }
	';
}

function apgallery_public_js() {
	return '
(function () {
	document.addEventListener("click", function (e) {
		var link = e.target.closest(".apgallery-lightbox");
		if (link) {
			e.preventDefault();
			var overlay = link.closest(".apgallery-wrapper").querySelector(".apgallery-lightbox-overlay");
			if (overlay) {
				var img = overlay.querySelector("img");
				img.src = link.href;
				img.alt = link.dataset.alt || "";
				overlay.hidden = false;
				overlay.setAttribute("aria-hidden", "false");
			}
			return;
		}
		if (e.target.closest(".apgallery-lightbox-close") || (e.target.classList && e.target.classList.contains("apgallery-lightbox-overlay"))) {
			var ov = e.target.closest(".apgallery-lightbox-overlay");
			if (ov) {
				ov.hidden = true;
				ov.setAttribute("aria-hidden", "true");
			}
		}
	});
	document.addEventListener("keydown", function (e) {
		if (e.key !== "Escape") return;
		var ov = document.querySelector(".apgallery-lightbox-overlay:not([hidden])");
		if (ov) { ov.hidden = true; ov.setAttribute("aria-hidden", "true"); }
	});

	// Botón "Cargar más fotos": pide la siguiente página vía AJAX.
	document.addEventListener("click", function (e) {
		var btn = e.target.closest(".apgallery-load-more");
		if (!btn) return;
		var wrap = btn.closest(".apgallery-wrapper");
		if (!wrap) return;
		var i18n = (window.APGALLERY_FRONT && window.APGALLERY_FRONT.i18n) ? window.APGALLERY_FRONT.i18n : {};
		btn.disabled = true;
		btn.textContent = i18n.loading || "Cargando…";
		var data = {
			action: "apgallery_front_media",
			nonce: (window.APGALLERY_FRONT && window.APGALLERY_FRONT.nonce) ? window.APGALLERY_FRONT.nonce : "",
			album: wrap.dataset.album || "",
			pagesize: wrap.dataset.pagesize || 24,
			columns: wrap.dataset.columns || 3,
			token: wrap.dataset.token || ""
		};
		var xhr = new XMLHttpRequest();
		xhr.open("POST", (window.APGALLERY_FRONT && window.APGALLERY_FRONT.ajaxUrl) ? window.APGALLERY_FRONT.ajaxUrl : "/wp-admin/admin-ajax.php");
		xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		xhr.onload = function () {
			var res = {};
			try { res = JSON.parse(xhr.responseText); } catch (err) {}
			if (xhr.status === 200 && res.success) {
				var grid = wrap.querySelector(".apgallery-grid");
				if (grid && res.data.html) grid.insertAdjacentHTML("beforeend", res.data.html);
				if (res.data.nextToken) {
					wrap.dataset.token = res.data.nextToken;
					btn.disabled = false;
					btn.textContent = i18n.loadMore || "Cargar más fotos";
				} else {
					wrap.dataset.token = "";
					btn.textContent = i18n.noMore || "No hay más fotos en este álbum.";
				}
			} else {
				btn.disabled = false;
				btn.textContent = (res.data && res.data.message) ? res.data.message : (i18n.error || "Error al cargar más fotos.");
			}
		};
		xhr.onerror = function () {
			btn.disabled = false;
			btn.textContent = i18n.error || "Error al cargar más fotos.";
		};
		xhr.send(new URLSearchParams(data).toString());
	});
})();
	';
}
