<?php
/**
 * Pruebas de detección y combinación de duplicados.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Criterios de coincidencia y combinación sin pérdida de datos.
 */
class Test_Suiteka_Basic_CRM_Duplicates extends Suiteka_Basic_CRM_Test_Case {

	/**
	 * Datos mínimos para buscar coincidencias.
	 *
	 * @param array $data Datos.
	 * @return array
	 */
	private function data( $data ) {
		return array_merge(
			array(
				'first_name' => 'Nadie',
				'last_name'  => 'Coincide',
				'email'      => '',
				'phone'      => '',
			),
			$data
		);
	}

	public function test_matches_by_email_phone_digits_and_name() {
		$by_email = $this->make_contact( array( 'email' => 'Ana@Example.org' ) );
		$by_phone = $this->make_contact( array( 'phone' => '+34 (600) 11-22.33' ) );
		$by_name  = $this->make_contact(
			array(
				'first_name' => 'José',
				'last_name'  => 'Pérez',
			)
		);

		$this->assertSame( array( $by_email ), $this->found( array( 'email' => 'ana@example.org' ) ) );
		$this->assertSame( array( $by_phone ), $this->found( array( 'phone' => '34600112233' ) ) );
		$this->assertSame(
			array( $by_name ),
			$this->found(
				array(
					'first_name' => 'JOSÉ',
					'last_name'  => 'pérez',
				)
			)
		);
		$this->assertSame( array(), $this->found( array( 'phone' => '12345' ) ), 'Teléfonos de menos de 6 dígitos no cuentan.' );
	}

	public function test_excludes_the_record_being_edited_and_hides_others() {
		$own   = $this->make_contact(
			array(
				'email'    => 'mismo@example.org',
				'owner_id' => self::$sales,
			)
		);
		$other = $this->make_contact(
			array(
				'email'    => 'mismo@example.org',
				'owner_id' => self::$sales2,
			)
		);

		$result = Suiteka_Basic_CRM_Duplicates::find_for( $this->data( array( 'email' => 'mismo@example.org' ) ), $own, self::$sales );

		$this->assertSame( array(), $result['visible'] );
		$this->assertSame( 1, $result['hidden'], 'Solo se indica cuántos hay, sin datos.' );

		$admin_view = Suiteka_Basic_CRM_Duplicates::find_for( $this->data( array( 'email' => 'mismo@example.org' ) ), $own, null );
		$this->assertSame( array( $other ), array_map( 'intval', wp_list_pluck( $admin_view['visible'], 'id' ) ) );
	}

	public function test_groups() {
		$this->make_contact( array( 'email' => 'grupo@example.org' ) );
		$this->make_contact( array( 'email' => 'GRUPO@example.org' ) );
		$this->make_contact( array( 'email' => 'solo@example.org' ) );

		$groups = wp_list_filter( Suiteka_Basic_CRM_Duplicates::groups(), array( 'criterion' => 'email' ) );

		$this->assertCount( 1, $groups );
		$this->assertCount( 2, array_values( $groups )[0]['contacts'] );
		$this->assertSame( array(), Suiteka_Basic_CRM_Duplicates::groups( self::$sales ) );
	}

	public function test_merge_keeps_every_piece_of_data() {
		global $wpdb;

		$company = $this->make_company();
		$keep    = $this->make_contact(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'García',
				'email'      => 'ana@example.org',
				'status'     => 'customer',
				'tags'       => array( 'A' ),
				'notes'      => 'Notas de A',
			)
		);
		$discard = $this->make_contact(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'Garcia',
				'email'      => 'otro@example.org',
				'phone'      => '600 000 001',
				'job_title'  => 'Directora',
				'company_id' => $company,
				'status'     => 'lead',
				'tags'       => array( 'B' ),
				'notes'      => 'Notas de B',
			)
		);
		Suiteka_Basic_CRM_Activities::add(
			array(
				'type'       => 'call',
				'content'    => 'Llamada a B',
				'contact_id' => $discard,
			)
		);
		$wpdb->update( Suiteka_Basic_CRM::table( 'contacts' ), array( 'created_at' => '2020-01-01 00:00:00' ), array( 'id' => $discard ) );

		$this->assertTrue( Suiteka_Basic_CRM_Duplicates::merge( $keep, $discard ) );

		$merged = Suiteka_Basic_CRM_Contacts::get( $keep );
		$this->assertNull( Suiteka_Basic_CRM_Contacts::get( $discard ) );
		$this->assertSame( 'Ana García', $merged['full_name'], 'El nombre es el del conservado.' );
		$this->assertSame( 'ana@example.org', $merged['email'], 'No se sobrescriben campos con valor.' );
		$this->assertSame( 'customer', $merged['status'] );
		$this->assertSame( '600 000 001', $merged['phone'], 'Se completan los vacíos.' );
		$this->assertSame( 'Directora', $merged['job_title'] );
		$this->assertSame( (string) $company, $merged['company_id'] );
		$this->assertSame( 'Notas de A', $merged['notes'] );
		$this->assertSame( '2020-01-01 00:00:00', $merged['created_at'], 'Se conserva la fecha más antigua.' );
		$this->assertSame( array( 'A', 'B' ), array_values( Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $keep ) ) );
		$this->assertSame( array(), Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $discard ) );

		$history = Suiteka_Basic_CRM_Activities::for_contact( $keep );
		$events  = wp_list_pluck( $history, 'event' );
		$this->assertContains( 'merged', $events );
		$this->assertContains( 'merged_notes', $events );
		$this->assertContains( 'Notas de B', wp_list_pluck( $history, 'content' ) );
		$this->assertContains( 'Llamada a B', wp_list_pluck( $history, 'content' ) );
		$this->assertSame( array(), Suiteka_Basic_CRM_Activities::for_contact( $discard ) );
	}

	public function test_merge_rejects_invalid_pairs() {
		$id = $this->make_contact();

		$this->assertWPError( Suiteka_Basic_CRM_Duplicates::merge( $id, $id ) );
		$this->assertWPError( Suiteka_Basic_CRM_Duplicates::merge( $id, 999999 ) );
		$this->assertNotNull( Suiteka_Basic_CRM_Contacts::get( $id ), 'Un error no borra nada.' );
	}

	/**
	 * IDs visibles que coinciden.
	 *
	 * @param array $data Datos.
	 * @return int[]
	 */
	private function found( $data ) {
		$result = Suiteka_Basic_CRM_Duplicates::find_for( $this->data( $data ) );

		return array_map( 'intval', wp_list_pluck( $result['visible'], 'id' ) );
	}
}
