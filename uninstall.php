<?php
/**
 * Desinstalación de APGallery for Google Photos.
 *
 * Se ejecuta al eliminar el plugin desde WordPress y borra todas las opciones,
 * transientes y metadatos creados por el plugin. No borra los adjuntos ya
 * importados a la biblioteca de medios (pertenecen al usuario).
 *
 * @package APGallery_Google_Photos
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$apgallery_options = array(
	'apgallery_client_id',
	'apgallery_client_secret',
	'apgallery_refresh_token',
);

foreach ( $apgallery_options as $apgallery_option ) {
	delete_option( $apgallery_option );
}

// Transientes del plugin (token de acceso, estado OAuth y caché de media).
delete_transient( 'apgallery_access_token' );
delete_transient( 'apgallery_oauth_state' );

global $wpdb;

// Caché de media del shortcode (claves con hash md5).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_apgallery_media_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_apgallery_media_' ) . '%'
	)
);

// Metadatos de trazabilidad en adjuntos importados.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		'apgallery_google_media_id'
	)
);
