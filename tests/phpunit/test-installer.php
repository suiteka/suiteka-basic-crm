<?php
/**
 * Pruebas de instalación y migración.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Esquema, migración 1 → 2, idempotencia y bloqueo.
 */
class Test_Suiteka_Basic_CRM_Installer extends Suiteka_Basic_CRM_Test_Case {

	public function test_all_tables_exist() {
		global $wpdb;

		foreach ( Suiteka_Basic_CRM::tables() as $name ) {
			$table = Suiteka_Basic_CRM::table( $name );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), $name );
		}
	}

	public function test_contacts_table_keeps_legacy_columns_and_adds_new_ones() {
		global $wpdb;

		$columns = $wpdb->get_col( 'DESCRIBE ' . Suiteka_Basic_CRM::table( 'contacts' ) );

		foreach ( array( 'id', 'full_name', 'email', 'phone', 'company', 'notes', 'created_at' ) as $legacy ) {
			$this->assertContains( $legacy, $columns );
		}
		foreach ( array( 'first_name', 'last_name', 'job_title', 'company_id', 'source', 'status', 'owner_id', 'updated_at' ) as $new ) {
			$this->assertContains( $new, $columns );
		}
	}

	public function test_schema_version_is_current() {
		$this->assertSame( SUITEKA_BASIC_CRM_DB_VERSION, get_option( Suiteka_Basic_CRM::DB_VERSION_OPTION ) );
	}

	public function test_unknown_table_is_rejected() {
		$this->expectException( InvalidArgumentException::class );
		Suiteka_Basic_CRM::table( 'users' );
	}

	public function test_admin_role_gets_every_capability_and_editor_none() {
		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );

		foreach ( Suiteka_Basic_CRM_Capabilities::all() as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), $cap );
			$this->assertFalse( $editor->has_cap( $cap ), $cap );
		}
	}

	/**
	 * Inserta una fila con la forma que tenía en 1.0.0 (sin las columnas nuevas rellenas).
	 *
	 * @param string $full_name Nombre.
	 * @param string $company   Empresa en texto.
	 * @return int
	 */
	private function insert_legacy( $full_name, $company ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Suiteka_Basic_CRM::table( 'contacts' ) . " (full_name, email, phone, company, notes, created_at) VALUES (%s, '', '', %s, '', '2025-01-01 00:00:00')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$full_name,
				$company
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_migration_preserves_names_and_links_companies_case_insensitively() {
		global $wpdb;

		$a = $this->insert_legacy( 'Ana López Pérez', 'Foo Legacy' );
		$b = $this->insert_legacy( 'Luis', 'FOO LEGACY' );
		$c = $this->insert_legacy( 'Sin Empresa', '' );

		$this->assertTrue( Suiteka_Basic_CRM_Installer::migrate_to_2() );

		$rows = $wpdb->get_results( 'SELECT * FROM ' . Suiteka_Basic_CRM::table( 'contacts' ) . " WHERE id IN ($a, $b, $c) ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 'Ana López Pérez', $rows[0]['first_name'], 'No se separan apellidos automáticamente.' );
		$this->assertSame( '', $rows[0]['last_name'] );
		$this->assertSame( 'Ana López Pérez', $rows[0]['full_name'] );
		$this->assertSame( '2025-01-01 00:00:00', $rows[0]['updated_at'] );
		$this->assertNotSame( '0', $rows[0]['company_id'] );
		$this->assertSame( $rows[0]['company_id'], $rows[1]['company_id'], 'Variantes de mayúsculas: misma empresa.' );
		$this->assertSame( '0', $rows[2]['company_id'] );
		$this->assertSame( 'FOO LEGACY', $rows[1]['company'], 'La columna heredada no se modifica.' );

		$company = Suiteka_Basic_CRM_Companies::get( (int) $rows[0]['company_id'] );
		$this->assertSame( '2025-01-01 00:00:00', $company['created_at'] );
		$this->assertSame( '0', $company['owner_id'] );
	}

	public function test_migration_is_idempotent() {
		global $wpdb;

		$this->insert_legacy( 'Uno', 'Idempotente' );
		$this->assertTrue( Suiteka_Basic_CRM_Installer::migrate_to_2() );
		$companies = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Suiteka_Basic_CRM::table( 'companies' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->insert_legacy( 'Dos', 'idempotente' );
		$this->assertTrue( Suiteka_Basic_CRM_Installer::migrate_to_2() );
		$this->assertTrue( Suiteka_Basic_CRM_Installer::migrate_to_2() );

		$this->assertSame( $companies, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Suiteka_Basic_CRM::table( 'companies' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Suiteka_Basic_CRM::table( 'contacts' ) . " WHERE company <> '' AND company_id = 0" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_migration_does_not_touch_contacts_already_split() {
		$id = $this->make_contact(
			array(
				'first_name' => 'María',
				'last_name'  => 'Ruiz',
			)
		);

		Suiteka_Basic_CRM_Installer::migrate_to_2();

		$contact = Suiteka_Basic_CRM_Contacts::get( $id );
		$this->assertSame( 'María', $contact['first_name'] );
		$this->assertSame( 'Ruiz', $contact['last_name'] );
	}

	public function test_active_lock_blocks_a_concurrent_install() {
		update_option( Suiteka_Basic_CRM::DB_VERSION_OPTION, '1' );
		add_option( Suiteka_Basic_CRM_Installer::LOCK_OPTION, time(), '', 'no' );

		$this->assertFalse( Suiteka_Basic_CRM_Installer::install() );
		$this->assertSame( '1', get_option( Suiteka_Basic_CRM::DB_VERSION_OPTION ) );
	}
}
