<?php
/**
 * Pruebas del repositorio de empresas.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Unicidad, borrado y recuentos.
 */
class Test_Suiteka_Basic_CRM_Companies extends Suiteka_Basic_CRM_Test_Case {

	public function test_name_is_unique_case_insensitively() {
		$id = $this->make_company( array( 'name' => 'Única SA' ) );

		$duplicate = Suiteka_Basic_CRM_Companies::prepare( array( 'name' => 'ÚNICA SA' ) );
		$this->assertArrayHasKey( 'name', $duplicate['errors'] );

		$same = Suiteka_Basic_CRM_Companies::prepare( array( 'name' => 'Única SA' ), $id );
		$this->assertArrayNotHasKey( 'name', $same['errors'], 'Editar la propia empresa no es un duplicado.' );
	}

	public function test_prepare_validates_website_email_and_owner() {
		$result = Suiteka_Basic_CRM_Companies::prepare(
			array(
				'name'     => 'Web SL',
				'website'  => 'javascript:alert(1)',
				'email'    => 'no-es-correo',
				'owner_id' => (string) self::$editor,
			)
		);

		$this->assertSame( array( 'website', 'email', 'owner_id' ), array_keys( $result['errors'] ) );
	}

	public function test_update_logs_changed_fields() {
		$id   = $this->make_company();
		$data = Suiteka_Basic_CRM_Companies::get( $id );

		$data['city'] = 'Sevilla';
		$this->assertTrue( Suiteka_Basic_CRM_Companies::update( $id, $data ) );

		$history = Suiteka_Basic_CRM_Activities::for_company( $id );
		$this->assertSame( 'updated', $history[0]['event'] );
		$this->assertSame( array( 'city' ), json_decode( $history[0]['meta'], true )['fields'] );
	}

	public function test_delete_keeps_contacts_without_company() {
		$company = $this->make_company();
		$contact = $this->make_contact( array( 'company_id' => $company ) );
		$note    = Suiteka_Basic_CRM_Activities::add(
			array(
				'content'    => 'Solo de la empresa',
				'company_id' => $company,
			)
		);

		$this->assertTrue( Suiteka_Basic_CRM_Companies::delete( $company ) );

		$this->assertNull( Suiteka_Basic_CRM_Companies::get( $company ) );
		$this->assertSame( '0', Suiteka_Basic_CRM_Contacts::get( $contact )['company_id'] );
		$this->assertNull( Suiteka_Basic_CRM_Activities::get( $note ) );
	}

	public function test_contact_counts_respect_scope() {
		$company = $this->make_company( array( 'owner_id' => self::$sales ) );
		$this->make_contact(
			array(
				'company_id' => $company,
				'owner_id'   => self::$sales,
			)
		);
		$this->make_contact(
			array(
				'company_id' => $company,
				'owner_id'   => self::$sales2,
			)
		);

		$this->assertSame( 2, Suiteka_Basic_CRM_Companies::count_contacts( $company ) );
		$this->assertSame( 1, Suiteka_Basic_CRM_Companies::count_contacts( $company, self::$sales ) );
		$this->assertSame( 1, Suiteka_Basic_CRM_Companies::count_contacts_of_others( $company, self::$sales ) );

		$list = Suiteka_Basic_CRM_Companies::query( array( 'scope' => self::$sales ) );
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( '1', $list['items'][0]['contacts_count'] );
	}

	public function test_company_history_includes_visible_contacts_only() {
		$company = $this->make_company();
		$mine    = $this->make_contact(
			array(
				'company_id' => $company,
				'owner_id'   => self::$sales,
			)
		);
		$theirs  = $this->make_contact(
			array(
				'company_id' => $company,
				'owner_id'   => self::$sales2,
			)
		);

		$ids = array_map( 'intval', wp_list_pluck( Suiteka_Basic_CRM_Activities::for_company( $company, self::$sales ), 'contact_id' ) );

		$this->assertContains( $mine, $ids );
		$this->assertNotContains( $theirs, $ids );
	}

	public function test_search_and_names() {
		$this->make_company(
			array(
				'name' => 'Buscable',
				'city' => 'Bilbao',
			)
		);

		$this->assertSame( 1, Suiteka_Basic_CRM_Companies::query( array( 'search' => 'bilbao' ) )['total'] );
		$this->assertContains( 'Buscable', Suiteka_Basic_CRM_Companies::names() );
		$this->assertNotContains( 'Buscable', Suiteka_Basic_CRM_Companies::names( self::$sales ) );
	}
}
