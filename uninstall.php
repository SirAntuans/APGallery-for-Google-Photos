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
	'apgallery_picker_session_id',
	'apgallery_picker_session_uri',
);

foreach ( $apgallery_options as $apgallery_option ) {
	delete_option( $apgallery_option );
}

// Transientes del plugin (token de acceso y estado OAuth).
delete_transient( 'apgallery_access_token' );
delete_transient( 'apgallery_oauth_state' );

global $wpdb;

// Metadatos de trazabilidad en adjuntos importados.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s OR meta_key = %s",
		'apgallery_google_media_id',
		'apgallery_picked'
	)
);
