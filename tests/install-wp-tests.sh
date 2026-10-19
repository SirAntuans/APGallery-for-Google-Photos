#!/usr/bin/env bash
# Instala el WordPress test suite (adaptado del script estándar de WP-CLI).
set -ex

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}
TMPDIR=${TMPDIR:-/tmp}

WP_TESTS_DIR="$TMPDIR/wordpress-tests-lib"
WP_CORE_DIR="$TMPDIR/wordpress"

download() {
    if [ -x "$(command -v curl)" ]; then
        curl -sL "$1" -o "$2"
    elif [ -x "$(command -v wget)" ]; then
        wget -qO "$2" "$1"
    fi
}

install_wp() {
    rm -rf "$WP_CORE_DIR"
    mkdir -p "$WP_CORE_DIR"

    if [ "$WP_VERSION" = "latest" ]; then
        download "https://wordpress.org/latest.zip" /tmp/wp.zip
    else
        download "https://wordpress.org/wordpress-$WP_VERSION.zip" /tmp/wp.zip
    fi
    # Descomprimir en un directorio intermedio y mover el contenido.
    rm -rf /tmp/wp-extract
    mkdir -p /tmp/wp-extract
    unzip -q /tmp/wp.zip -d /tmp/wp-extract
    mv /tmp/wp-extract/wordpress/* "$WP_CORE_DIR/"
}

install_test_suite() {
    rm -rf "$WP_TESTS_DIR"
    mkdir -p "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"

    # Descargar wordpress-develop (tag correspondiente) que contiene el suite completo.
    if [ "$WP_VERSION" = "latest" ]; then
        download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/master.zip" /tmp/wpdev.zip
    else
        download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.zip" /tmp/wpdev.zip
    fi
    rm -rf /tmp/wpdev-extract
    mkdir -p /tmp/wpdev-extract
    unzip -q /tmp/wpdev.zip -d /tmp/wpdev-extract
    WPDEV_DIR=$(echo /tmp/wpdev-extract/wordpress-develop-*)

    cp -r "$WPDEV_DIR/tests/phpunit/includes/." "$WP_TESTS_DIR/includes/"
    if [ -d "$WPDEV_DIR/tests/phpunit/data" ]; then
        cp -r "$WPDEV_DIR/tests/phpunit/data/." "$WP_TESTS_DIR/data/"
    fi
    if [ -d "$WPDEV_DIR/tests/phpunit/build" ]; then
        # Suite moderno (WP 6.1+): build contiene el core compilado del suite.
        cp -r "$WPDEV_DIR/tests/phpunit/build" "$WP_TESTS_DIR/build"
        cp "$WPDEV_DIR/wp-tests-config.php" "$WP_TESTS_DIR/wp-tests-config.php" 2>/dev/null || true
    else
        # Suite clásico: usa el core ya instalado en $WP_CORE_DIR.
        cp "$WPDEV_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php" 2>/dev/null || true
    fi
}

write_wp_tests_config() {
    cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
/* Test suite config generado por tests/install-wp-tests.sh */
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'test' );
define( 'SECURE_AUTH_KEY', 'test' );
define( 'LOGGED_IN_KEY', 'test' );
define( 'NONCE_KEY', 'test' );
define( 'AUTH_SALT', 'test' );
define( 'SECURE_AUTH_SALT', 'test' );
define( 'LOGGED_IN_SALT', 'test' );
define( 'NONCE_SALT', 'test' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_FORCE_KNOWN_BUGS', false );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'DIR_TESTDATA', '$WP_TESTS_DIR/data' );
define( 'WP_RUN_CORE_TESTS', false );
EOF
    # Apuntar el core al WP instalado (suite clásico) o al build (moderno).
    if [ -f "$WP_TESTS_DIR/build/wp-settings.php" ]; then
        echo "define( 'ABSPATH', '$WP_TESTS_DIR/build/' );" >> "$WP_TESTS_DIR/wp-tests-config.php"
    else
        echo "define( 'ABSPATH', '$WP_CORE_DIR/' );" >> "$WP_TESTS_DIR/wp-tests-config.php"
    fi
}

install_db() {
    mysql -h"${DB_HOST%%:*}" -P"${DB_HOST##*:}" -u"$DB_USER" -p"$DB_PASS" \
        -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;" 2>/dev/null || \
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
}

install_wp
install_test_suite
write_wp_tests_config
install_db
