<?php
/**
 * Plugin Name:       APGallery for Google Photos
 * Plugin URI:        https://apsoft.example.com/apgallery-google-photos
 * Description:       Muestra fotos de Google Photos en tu sitio mediante el shortcode [apgallery], usando la Google Photos Picker API (OAuth 2.0).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:       7.4
 * Author:             APSoft
 * Author URI:        https://apsoft.example.com
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       apgallery-google-photos
 *
 * @package APGallery_Google_Photos
 */

defined( 'ABSPATH' ) || exit;

define( 'APGALLERY_VERSION', '1.0.0' );
define( 'APGALLERY_OPT_PREFIX', 'apgallery_' );
define( 'APGALLERY_PICKER_ENDPOINT', 'https://photospicker.googleapis.com/' );
define( 'APGALLERY_SCOPE', 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly' );

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
 * 2. Cifrado del refresh token y llamadas a la Google Photos Picker API
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
 * Realiza una petición HTTP a la Google Photos Picker API con el access token.
 *
 * @param string $endpoint Ruta relativa (ej. 'v1/sessions').
 * @param array  $args     Argumentos de wp_remote_request.
 * @return array|WP_Error
 */
function apgallery_remote_request( $endpoint, $args = array() ) {
	$token = apgallery_get_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$url = APGALLERY_PICKER_ENDPOINT . ltrim( $endpoint, '/' );

	$defaults = array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'   => 'application/json',
		),
		'timeout' => 30,
	);

	$args         = wp_parse_args( $args, $defaults );
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
		$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Error desconocido de la API de Google Photos.', 'apgallery-google-photos' );
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

/* -------------------------------------------------------------------------
 * 3. Sesiones de la Picker API
 * ---------------------------------------------------------------------- */

/**
 * Crea una nueva sesión de selección y devuelve el PickingSession.
 *
 * @return array|WP_Error
 */
function apgallery_create_picker_session() {
	return apgallery_remote_request(
		'v1/sessions',
		array(
			'method' => 'POST',
			'body'   => '{}',
		)
	);
}

/**
 * Obtiene el estado de una sesión de selección.
 *
 * @param string $session_id ID de la sesión.
 * @return array|WP_Error
 */
function apgallery_get_picker_session( $session_id ) {
	return apgallery_remote_request(
		'v1/sessions/' . rawurlencode( $session_id ),
		array( 'method' => 'GET' )
	);
}

/**
 * Elimina una sesión de selección (recomendado por Google tras listar los items).
 *
 * @param string $session_id ID de la sesión.
 * @return array|WP_Error
 */
function apgallery_delete_picker_session( $session_id ) {
	return apgallery_remote_request(
		'v1/sessions/' . rawurlencode( $session_id ),
		array( 'method' => 'DELETE' )
	);
}

/**
 * Lista (con paginación) todos los media items elegidos en una sesión.
 *
 * @param string $session_id ID de la sesión.
 * @return array|WP_Error Lista de PickedMediaItem o WP_Error.
 */
function apgallery_list_picked_media( $session_id ) {
	$items = array();
	$token = '';

	do {
		$query = array(
			'sessionId' => $session_id,
			'pageSize'  => 50,
		);
		if ( '' !== $token ) {
			$query['pageToken'] = $token;
		}

		$response = apgallery_remote_request(
			add_query_arg( $query, 'v1/mediaItems' ),
			array( 'method' => 'GET' )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['mediaItems'] ) && is_array( $response['mediaItems'] ) ) {
			$items = array_merge( $items, $response['mediaItems'] );
		}

		$token = $response['nextPageToken'] ?? '';
	} while ( '' !== $token );

	return $items;
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
 * Descarga una foto elegida en una sesión y la añade a la biblioteca de medios.
 * Los adjuntos importados se marcan con el meta "apgallery_picked" para que el
 * shortcode [apgallery] pueda mostrarlos sin volver a llamar a la API.
 *
 * @param array $item PickedMediaItem de la Picker API.
 * @return int|WP_Error ID del adjunto creado o el existente si ya estaba importado.
 */
function apgallery_import_picked_item( $item ) {
	$google_id = $item['id'] ?? '';
	$media_id  = apgallery_find_imported_media_id( $google_id );
	if ( $media_id ) {
		update_post_meta( $media_id, APGALLERY_OPT_PREFIX . 'picked', '1' );
		return $media_id;
	}

	$file      = $item['mediaFile'] ?? array();
	$mime      = $file['mimeType'] ?? '';
	$baseurl   = $file['baseUrl'] ?? '';
	$filename  = $file['filename'] ?? '';

	if ( '' === $baseurl ) {
		return new WP_Error( 'apgallery_invalid_url', __( 'APGallery: el item no tiene URL de descarga.', 'apgallery-google-photos' ) );
	}

	if ( 0 !== strpos( $mime, 'image/' ) ) {
		// Los vídeos requieren "=dv" para descargar los bytes originales.
		$baseurl .= '=dv';
	}

	if ( '' === $filename ) {
		$filename = 'google-photo';
	}
	$filename = sanitize_file_name( $filename );
	if ( '' === $filename ) {
		$filename = 'google-photo.jpg';
	}

	$ext       = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$valid_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4' );
	if ( ! in_array( $ext, $valid_ext, true ) ) {
		$filename .= 0 === strpos( $mime, 'image/' ) ? '.jpg' : '.mp4';
	}

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	// Las baseUrl de la Picker API requieren el header Authorization (a diferencia
	// de la Library API); download_url() no lo envía y Google responde 403 Forbidden.
	$access_token = apgallery_get_access_token();
	if ( is_wp_error( $access_token ) ) {
		return $access_token;
	}

	$response = wp_safe_remote_get(
		$baseurl,
		array(
			'timeout' => 300,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error(
			'apgallery_download_error',
			sprintf(
					/* translators: %d: código HTTP de la descarga. */
					__( 'Error al descargar la foto de Google (HTTP %d).', 'apgallery-google-photos' ),
					(int) wp_remote_retrieve_response_code( $response )
				)
		);
	}

	$content = wp_remote_retrieve_body( $response );
	if ( '' === $content ) {
		return new WP_Error( 'apgallery_download_error', __( 'Google devolvió una respuesta vacía al descargar la foto.', 'apgallery-google-photos' ) );
	}

	$tmp = wp_tempnam( $filename );
	if ( ! $tmp ) {
		return new WP_Error( 'apgallery_download_error', __( 'No se pudo crear el archivo temporal para la foto.', 'apgallery-google-photos' ) );
	}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$bytes = file_put_contents( $tmp, $content );
	if ( false === $bytes || 0 === $bytes ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return new WP_Error( 'apgallery_download_error', __( 'No se pudo guardar la foto descargada en el servidor.', 'apgallery-google-photos' ) );
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

	if ( '' !== $google_id ) {
		update_post_meta( $media_id, APGALLERY_OPT_PREFIX . 'google_media_id', $google_id );
	}
	update_post_meta( $media_id, APGALLERY_OPT_PREFIX . 'picked', '1' );

	return (int) $media_id;
}

/**
 * Sincroniza la sesión activa: lista los items elegidos, los importa a la
 * biblioteca de medios y elimina la sesión. Devuelve el número de fotos nuevas.
 *
 * @param string $session_id ID de la sesión.
 * @return int|WP_Error Fotos importadas (nuevas + reutilizadas) o WP_Error.
 */
function apgallery_sync_picker_session( $session_id ) {
	$session = apgallery_get_picker_session( $session_id );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( empty( $session['mediaItemsSet'] ) ) {
		return new WP_Error( 'apgallery_session_pending', __( 'Aún no has terminado de elegir fotos en Google Photos. Ábre el enlace de selección, marca las fotos y pulsa "Hecho" antes de sincronizar.', 'apgallery-google-photos' ) );
	}

	$items = apgallery_list_picked_media( $session_id );
	if ( is_wp_error( $items ) ) {
		return $items;
	}

	$imported = 0;
	$errors   = array();
	foreach ( $items as $item ) {
		$result = apgallery_import_picked_item( $item );
		if ( is_wp_error( $result ) ) {
			$errors[] = sprintf(
				'(%s) %s',
				$item['mediaFile']['filename'] ?? ( $item['id'] ?? '?' ),
				$result->get_error_message()
			);
			continue;
		}
		$imported++;
	}

	if ( ! empty( $errors ) ) {
		update_option( APGALLERY_OPT_PREFIX . 'last_import_errors', array_slice( $errors, 0, 10 ), false );
	} else {
		delete_option( APGALLERY_OPT_PREFIX . 'last_import_errors' );
	}

	apgallery_delete_picker_session( $session_id );

	return $imported;
}

/**
 * Devuelve los adjuntos importados desde Google Photos (marcados como picked).
 *
 * @param int $limit Máximo de adjuntos (0 = sin límite).
 * @return int[] IDs de adjuntos.
 */
function apgallery_get_picked_attachments( $limit = 0 ) {
	$args = array(
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => APGALLERY_OPT_PREFIX . 'picked',
				'value' => '1',
			),
		),
		'fields'          => 'ids',
		'numberposts'     => $limit > 0 ? $limit : -1,
		'orderby'         => 'date',
		'order'           => 'DESC',
	);

	return array_map( 'intval', (array) get_posts( $args ) );
}

/* -------------------------------------------------------------------------
 * 4. Panel de ajustes (admin)
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'apgallery_add_settings_page' );
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
		.apgallery-shortcode { display:inline-block; margin:.25rem .5rem .25rem 0; padding:.35rem .7rem; background:#f0f0f1; border-radius:4px; font-family:monospace; }
	</style>';
}

function apgallery_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'apgallery-google-photos' ) );
	}

	$connected = (bool) get_option( APGALLERY_OPT_PREFIX . 'refresh_token' );

	// Avisos de redirección (OAuth).
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
		delete_option( APGALLERY_OPT_PREFIX . 'picker_session_id' );
		delete_transient( APGALLERY_OPT_PREFIX . 'access_token' );
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cuenta de Google desconectada.', 'apgallery-google-photos' ) . '</p></div>';
		$connected = false;
	}

	// Crear sesión de selección.
	if ( isset( $_POST['apgallery_create_session'] ) && check_admin_referer( 'apgallery_picker_session' ) ) {
		$session = apgallery_create_picker_session();
		if ( is_wp_error( $session ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $session->get_error_message() ) . '</p></div>';
		} else {
			update_option( APGALLERY_OPT_PREFIX . 'picker_session_id', sanitize_text_field( $session['id'] ?? '' ) );
			update_option( APGALLERY_OPT_PREFIX . 'picker_session_uri', sanitize_text_field( $session['pickerUri'] ?? '' ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Sesión de selección creada. Abre el enlace de abajo para elegir tus fotos.', 'apgallery-google-photos' ) . '</p></div>';
		}
	}

	// Sincronizar fotos elegidas.
	if ( isset( $_POST['apgallery_sync_session'] ) && check_admin_referer( 'apgallery_picker_session' ) ) {
		$session_id = get_option( APGALLERY_OPT_PREFIX . 'picker_session_id' );
		$result     = apgallery_sync_picker_session( $session_id );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			delete_option( APGALLERY_OPT_PREFIX . 'picker_session_id' );
			delete_option( APGALLERY_OPT_PREFIX . 'picker_session_uri' );
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: número de fotos importadas. */
						_n( '%d foto importada a la biblioteca de medios.', '%d fotos importadas a la biblioteca de medios.', $result, 'apgallery-google-photos' ),
						$result
					)
				)
			);
			$last_errors = get_option( APGALLERY_OPT_PREFIX . 'last_import_errors' );
			if ( ! empty( $last_errors ) ) {
					echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Errores de importación:', 'apgallery-google-photos' ) . '</strong></p><ul style="list-style:disc;margin-left:2em;">';
					foreach ( (array) $last_errors as $import_error ) {
						echo '<li>' . esc_html( $import_error ) . '</li>';
					}
					echo '</ul></div>';
				}
			}
		}

	$client_id     = get_option( APGALLERY_OPT_PREFIX . 'client_id' );
	$client_secret = get_option( APGALLERY_OPT_PREFIX . 'client_secret' );
	$auth_url      = apgallery_build_auth_url();

	$session_uri    = get_option( APGALLERY_OPT_PREFIX . 'picker_session_uri' );
	$session_id    = get_option( APGALLERY_OPT_PREFIX . 'picker_session_id' );
	$picked_photos = apgallery_get_picked_attachments();
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
			<p><?php esc_html_e( 'Crea un proyecto en Google Cloud Console, habilita la Google Photos Picker API y crea credenciales OAuth 2.0 (aplicación web). La URL de redirección debe ser:', 'apgallery-google-photos' ); ?></p>
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
			<h2><?php esc_html_e( 'Conexión y selección de fotos', 'apgallery-google-photos' ); ?></h2>
			<?php if ( $connected ) : ?>
				<?php if ( $session_uri ) : ?>
					<div class="notice notice-info">
						<p>
							<strong><?php esc_html_e( 'Paso 1:', 'apgallery-google-photos' ); ?></strong>
							<a href="<?php echo esc_url( $session_uri ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir el selector de Google Photos', 'apgallery-google-photos' ); ?></a>
							<?php esc_html_e( '— elige las fotos que quieras mostrar y pulsa "Hecho".', 'apgallery-google-photos' ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Paso 2:', 'apgallery-google-photos' ); ?></strong>
							<?php esc_html_e( 'Cuando termines, vuelve aquí y sincroniza:', 'apgallery-google-photos' ); ?>
						</p>
					</div>
					<form method="post" style="margin-top:1rem;">
						<?php wp_nonce_field( 'apgallery_picker_session' ); ?>
						<button type="submit" class="button button-primary" name="apgallery_sync_session" value="1"><?php esc_html_e( 'Sincronizar fotos seleccionadas', 'apgallery-google-photos' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post">
						<?php wp_nonce_field( 'apgallery_picker_session' ); ?>
						<button type="submit" class="button button-primary" name="apgallery_create_session" value="1"><?php esc_html_e( 'Elegir fotos de Google Photos', 'apgallery-google-photos' ); ?></button>
					</form>
				<?php endif; ?>

				<form method="post" style="margin-top:1rem;" onsubmit="return confirm('<?php echo esc_js( __( '¿Desconectar la cuenta de Google?', 'apgallery-google-photos' ) ); ?>');">
					<?php wp_nonce_field( 'apgallery_disconnect' ); ?>
					<button type="submit" class="button button-secondary" name="apgallery_disconnect" value="1"><?php esc_html_e( 'Desconectar cuenta', 'apgallery-google-photos' ); ?></button>
				</form>
			<?php elseif ( $auth_url ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>"><?php esc_html_e( 'Conectar con Google Photos', 'apgallery-google-photos' ); ?></a></p>
			<?php endif; ?>

			<?php if ( $connected && ! empty( $picked_photos ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Galería', 'apgallery-google-photos' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d: número de fotos importadas. */
						esc_html__( 'Hay %d fotos importadas de Google Photos. Muéstralas en cualquier página con el shortcode:', 'apgallery-google-photos' ),
						count( $picked_photos )
					);
					?>
				</p>
				<p><span class="apgallery-shortcode">[apgallery]</span><span class="apgallery-shortcode">[apgallery columns="4"]</span></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * 5. Flujo OAuth 2.0
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
			'scope'         => APGALLERY_SCOPE,
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
	if ( empty( $body['access_token'] ) ) {
		$fail( __( 'Google no devolvió el token de acceso.', 'apgallery-google-photos' ) );
	}
	if ( false === strpos( (string) ( $body['scope'] ?? '' ), 'photospicker.mediaitems.readonly' ) ) {
		$fail( __( 'Google no concedió el permiso de la Picker API. Habilita la Google Photos Picker API en tu proyecto de Google Cloud, revoca el acceso de la app en tu cuenta de Google y vuelve a conectar.', 'apgallery-google-photos' ) );
	}
	if ( empty( $body['refresh_token'] ) ) {
		$fail( __( 'Google no devolvió el refresh token. Revoca el acceso en tu cuenta de Google y conéctate de nuevo.', 'apgallery-google-photos' ) );
	}

	update_option( APGALLERY_OPT_PREFIX . 'refresh_token', apgallery_encrypt( $body['refresh_token'] ) );
	set_transient( APGALLERY_OPT_PREFIX . 'access_token', $body['access_token'], max( 60, (int) $body['expires_in'] - 60 ) );

	wp_safe_redirect( add_query_arg( array( 'page' => 'apgallery-google-photos', 'apgallery_ok' => '1' ), admin_url( 'options-general.php' ) ) );
	exit;
}

/* -------------------------------------------------------------------------
 * 6. Shortcode [apgallery]
 * ---------------------------------------------------------------------- */

add_action( 'init', 'apgallery_register_shortcode' );
function apgallery_register_shortcode() {
	add_shortcode( 'apgallery', 'apgallery_render_shortcode' );
}

function apgallery_render_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'columns' => 3, // Columnas del grid (1-6).
		),
		$atts,
		'apgallery'
	);

	$columns    = min( 6, max( 1, (int) $atts['columns'] ) );
	$attachment_ids = apgallery_get_picked_attachments();

	wp_register_style( 'apgallery', false, array(), APGALLERY_VERSION, 'all' );
	wp_enqueue_style( 'apgallery' );
	wp_add_inline_style( 'apgallery', apgallery_public_css() );
	wp_register_script( 'apgallery', false, array(), APGALLERY_VERSION, true );
	wp_enqueue_script( 'apgallery' );
	wp_add_inline_script( 'apgallery', apgallery_public_js() );

	ob_start();
	?>
	<div class="apgallery-wrapper">
		<div class="apgallery-grid apgallery-cols-<?php echo esc_attr( $columns ); ?>">
			<?php
			if ( ! empty( $attachment_ids ) ) {
				foreach ( $attachment_ids as $attachment_id ) {
					$full = wp_get_attachment_url( $attachment_id );
					if ( ! $full ) {
						continue;
					}
					$thumb = wp_get_attachment_image_url( $attachment_id, 'apgallery-thumb' );
					if ( ! $thumb ) {
						$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
					}
					if ( ! $thumb ) {
						$thumb = $full;
					}
					$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
					if ( '' === $alt ) {
						$alt = get_the_title( $attachment_id );
					}
					?>
					<figure class="apgallery-item">
						<a href="<?php echo esc_url( $full ); ?>" class="apgallery-lightbox" data-alt="<?php echo esc_attr( $alt ); ?>">
							<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
						</a>
					</figure>
					<?php
				}
			} else {
				echo '<p><em>' . esc_html__( 'Aún no hay fotos importadas de Google Photos.', 'apgallery-google-photos' ) . '</em></p>';
			}
			?>
		</div>
		<div class="apgallery-lightbox-overlay" hidden aria-hidden="true">
			<img src="" alt="" />
			<button type="button" class="apgallery-lightbox-close" aria-label="<?php esc_attr_e( 'Cerrar', 'apgallery-google-photos' ); ?>">&times;</button>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Registra el tamaño de miniatura cuadrada de la galería.
 */
add_action( 'after_setup_theme', 'apgallery_add_image_size' );
function apgallery_add_image_size() {
	add_image_size( 'apgallery-thumb', 600, 600, true );
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
})();
	';
}
