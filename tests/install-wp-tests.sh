#!/usr/bin/env bash
# Instala el WordPress test suite (adaptado del script estándar de WP-CLI).
set -ex

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}
TMPDIR=${TMPDIR-/tmp}

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
    if [ -d "$WP_CORE_DIR" ]; then
        rm -rf "$WP_CORE_DIR"
    fi
    mkdir -p "$WP_CORE_DIR"
    if [ "$WP_VERSION" = "latest" ]; then
        download "https://wordpress.org/latest.zip" /tmp/wp.zip
    else
        download "https://wordpress.org/wordpress-$WP_VERSION.zip" /tmp/wp.zip
    fi
    unzip -q /tmp/wp.zip -d "$TMPDIR"
    mv "$TMPDIR/wordpress/"* "$WP_CORE_DIR"
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR" ]; then
        rm -rf "$WP_TESTS_DIR"
    fi
    mkdir -p "$WP_TESTS_DIR"
    download "https://raw.githubusercontent.com/WordPress/wordpress-develop/master/tests/phpunit/includes/functions.php" /tmp/functions.php
    mkdir -p "$WP_TESTS_DIR/includes"
    cp /tmp/functions.php "$WP_TESTS_DIR/includes/functions.php"

    download "https://raw.githubusercontent.com/WordPress/wordpress-develop/master/tests/phpunit/includes/bootstrap.php" /tmp/bootstrap.php

    # Skeleton de bootstrap mínimo si la descarga falla.
    if [ ! -s /tmp/bootstrap.php ]; then
        cat > /tmp/bootstrap.php <<'EOF'
<?php
// Bootstrap mínimo generado por tests/install-wp-tests.sh
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
require_once $_tests_dir . '/includes/functions.php';
EOF
    fi
    cp /tmp/bootstrap.php "$WP_TESTS_DIR/includes/bootstrap.php"
}

install_db() {
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e \
        "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
}

install_wp
install_test_suite
install_db
