<?php
/**
 * Configuración de la librería de pruebas de WordPress.
 *
 * Todos los valores se leen de variables de entorno. ATENCIÓN: la suite
 * borra todas las tablas de la base de datos indicada; usa una base de
 * datos exclusiva para pruebas.
 *
 * @package Suiteka_Basic_CRM
 */

$suiteka_basic_crm_env = function ( $name, $fallback ) {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

define( 'ABSPATH', rtrim( $suiteka_basic_crm_env( 'WP_CORE_DIR', '/tmp/wordpress' ), '/' ) . '/' );

define( 'DB_NAME', $suiteka_basic_crm_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', $suiteka_basic_crm_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $suiteka_basic_crm_env( 'WP_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', $suiteka_basic_crm_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Variable exigida por la librería de pruebas.

define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Suiteka Basic CRM Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
