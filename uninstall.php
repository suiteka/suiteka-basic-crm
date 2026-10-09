<?php
/**
 * Desinstalación de Suiteka Basic CRM.
 *
 * Por defecto los contactos se conservan. La tabla solo se elimina si la
 * opción `suiteka_basic_crm_delete_data_on_uninstall` existe y vale «1»
 * (por ejemplo: `wp option update suiteka_basic_crm_delete_data_on_uninstall 1`).
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Elimina los datos del sitio actual solo si se autorizó explícitamente.
 */
function suiteka_basic_crm_uninstall_site() {
	global $wpdb;

	if ( '1' !== (string) get_option( 'suiteka_basic_crm_delete_data_on_uninstall', '' ) ) {
		return;
	}

	$table = $wpdb->prefix . 'suiteka_crm_contacts';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia del plugin, nombre fijo.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	delete_option( 'suiteka_basic_crm_db_version' );
	delete_option( 'suiteka_basic_crm_delete_data_on_uninstall' );
}

if ( is_multisite() ) {
	$suiteka_basic_crm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $suiteka_basic_crm_site_ids as $suiteka_basic_crm_site_id ) {
		switch_to_blog( $suiteka_basic_crm_site_id );
		suiteka_basic_crm_uninstall_site();
		restore_current_blog();
	}
} else {
	suiteka_basic_crm_uninstall_site();
}
