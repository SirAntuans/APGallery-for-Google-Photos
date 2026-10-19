<?php
/**
 * Bootstrap del entorno de tests de PHPUnit para APGallery.
 *
 * Carga el test suite de WordPress y el plugin bajo test.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Cargar el bootstrap de WordPress si existe (CI), si no, stubs mínimos.
if ( file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	require_once $_tests_dir . '/includes/functions.php';

	// Activar el plugin antes de instalar WP.
	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/apgallery-google-photos.php';
		}
	);

	require_once $_tests_dir . '/includes/bootstrap.php';
} else {
	echo "WordPress test suite no encontrado en {$_tests_dir}; ejecutando solo lint básico.\n"; // phpcs:ignore
}
