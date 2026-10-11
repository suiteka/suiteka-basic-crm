<?php
/**
 * Desinstalación de Suiteka Basic CRM.
 *
 * Por defecto se conserva todo. Las tablas, las opciones y las capacidades
 * del plugin solo se eliminan si la opción
 * `suiteka_basic_crm_delete_data_on_uninstall` existe y vale «1»
 * (por ejemplo: `wp option update suiteka_basic_crm_delete_data_on_uninstall 1`).
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'suiteka_basic_crm_uninstall_site' ) ) {
	/**
	 * Elimina los datos del sitio actual solo si se autorizó explícitamente.
	 */
	function suiteka_basic_crm_uninstall_site() {
		global $wpdb;

		if ( '1' !== (string) get_option( 'suiteka_basic_crm_delete_data_on_uninstall', '' ) ) {
			return;
		}

		foreach ( array( 'contacts', 'companies', 'tags', 'tag_relationships', 'activities' ) as $name ) {
			$table = $wpdb->prefix . 'suiteka_crm_' . $name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas propias del plugin, nombres fijos.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		// Capacidades del CRM en todos los roles del sitio.
		$caps = array(
			'suiteka_crm_view',
			'suiteka_crm_view_others',
			'suiteka_crm_edit',
			'suiteka_crm_edit_others',
			'suiteka_crm_delete',
			'suiteka_crm_delete_others',
			'suiteka_crm_import',
			'suiteka_crm_export',
			'suiteka_crm_manage',
		);
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		delete_option( 'suiteka_basic_crm_db_version' );
		delete_option( 'suiteka_basic_crm_upgrade_lock' );
		delete_option( 'suiteka_basic_crm_delete_data_on_uninstall' );
	}
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
