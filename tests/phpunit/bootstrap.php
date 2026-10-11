<?php
/**
 * Arranque de las pruebas de integración.
 *
 * @package Suiteka_Basic_CRM
 */

$suiteka_basic_crm_root = dirname( __DIR__, 2 );

require_once $suiteka_basic_crm_root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$suiteka_basic_crm_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

require_once $suiteka_basic_crm_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $suiteka_basic_crm_root ) {
		require $suiteka_basic_crm_root . '/suiteka-basic-crm.php';
	}
);

require $suiteka_basic_crm_tests_dir . '/includes/bootstrap.php';

// Tablas y capacidades reales, fuera de las transacciones de cada prueba.
Suiteka_Basic_CRM::install();

// Las clases de administración solo se cargan en el escritorio; las pruebas las necesitan siempre.
foreach ( array( 'admin', 'admin-activities', 'admin-contacts', 'admin-companies', 'admin-duplicates' ) as $suiteka_basic_crm_file ) {
	require_once SUITEKA_BASIC_CRM_DIR . 'admin/class-suiteka-basic-crm-' . $suiteka_basic_crm_file . '.php';
}

require_once __DIR__ . '/class-suiteka-basic-crm-test-case.php';
