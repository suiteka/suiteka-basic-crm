<?php
/**
 * Pruebas del repositorio de contactos.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Altas, cambios, bajas, consultas y empresa por nombre.
 */
class Test_Suiteka_Basic_CRM_Contacts extends Suiteka_Basic_CRM_Test_Case {

	public function test_create_derives_full_name_dates_tags_and_history() {
		$id = $this->make_contact(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'García López',
				'tags'       => array( 'VIP', 'Madrid' ),
			)
		);

		$contact = Suiteka_Basic_CRM_Contacts::get( $id );
		$this->assertSame( 'Ana García López', $contact['full_name'] );
		$this->assertNotEmpty( $contact['created_at'] );
		$this->assertSame( $contact['created_at'], $contact['updated_at'] );
		$this->assertSame( array( 'Madrid', 'VIP' ), array_values( Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $id ) ) );

		$history = Suiteka_Basic_CRM_Activities::for_contact( $id );
		$this->assertCount( 1, $history );
		$this->assertSame( 'created', $history[0]['event'] );
	}

	public function test_update_logs_changed_field_names_only() {
		$id = $this->make_contact( array( 'email' => 'antes@example.org' ) );

		$data          = Suiteka_Basic_CRM_Contacts::get( $id );
		$data['email'] = 'despues@example.org';
		$data['tags']  = array( 'Nueva' );
		$this->assertTrue( Suiteka_Basic_CRM_Contacts::update( $id, $data ) );

		$history = Suiteka_Basic_CRM_Activities::for_contact( $id );
		$this->assertSame( 'updated', $history[0]['event'] );
		$meta = json_decode( $history[0]['meta'], true );
		$this->assertSame( array( 'email', 'tags' ), $meta['fields'] );
		$this->assertStringNotContainsString( 'antes@example.org', $history[0]['meta'], 'No se guardan valores antiguos.' );
	}

	public function test_update_without_changes_does_not_log() {
		$id   = $this->make_contact();
		$data = Suiteka_Basic_CRM_Contacts::get( $id );

		$this->assertTrue( Suiteka_Basic_CRM_Contacts::update( $id, $data ) );
		$this->assertCount( 1, Suiteka_Basic_CRM_Activities::for_contact( $id ) );
	}

	public function test_prepare_validates_combined_name_owner_status_and_tags() {
		$result = Suiteka_Basic_CRM_Contacts::prepare(
			array(
				'first_name' => str_repeat( 'a', 150 ),
				'last_name'  => str_repeat( 'b', 50 ),
				'status'     => 'inventado',
				'owner_id'   => (string) self::$editor,
				'tags'       => str_repeat( 'x', 51 ),
			)
		);

		$this->assertArrayHasKey( 'last_name', $result['errors'] );
		$this->assertArrayHasKey( 'status', $result['errors'] );
		$this->assertArrayHasKey( 'owner_id', $result['errors'] );
		$this->assertArrayHasKey( 'tags', $result['errors'] );
	}

	public function test_prepare_accepts_valid_data() {
		$result = Suiteka_Basic_CRM_Contacts::prepare(
			array(
				'first_name'   => 'Luis',
				'last_name'    => 'Martín',
				'email'        => 'luis@example.org',
				'phone'        => '600 000 000',
				'job_title'    => 'Gerente',
				'company_name' => 'Acme',
				'status'       => 'customer',
				'source'       => 'referral',
				'tags'         => 'vip, VIP , Madrid,,',
				'owner_id'     => (string) self::$sales,
				'notes'        => 'Hola',
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'vip', 'Madrid' ), $result['data']['tags'], 'Etiquetas sin duplicados ni vacías.' );
		$this->assertSame( self::$sales, $result['data']['owner_id'] );
	}

	public function test_delete_removes_tags_and_own_history_but_keeps_shared_history() {
		global $wpdb;

		$company = $this->make_company();
		$id      = $this->make_contact( array( 'tags' => array( 'Borrar' ) ) );
		$shared  = Suiteka_Basic_CRM_Activities::add(
			array(
				'content'    => 'Reunión con la empresa',
				'contact_id' => $id,
				'company_id' => $company,
			)
		);

		$this->assertTrue( Suiteka_Basic_CRM_Contacts::delete( $id ) );

		$this->assertNull( Suiteka_Basic_CRM_Contacts::get( $id ) );
		$this->assertSame( array(), Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $id ) );
		$this->assertSame( array(), Suiteka_Basic_CRM_Activities::for_contact( $id ) );
		$kept = Suiteka_Basic_CRM_Activities::get( $shared );
		$this->assertSame( '0', $kept['contact_id'] );
		$this->assertSame( (string) $company, $kept['company_id'] );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Suiteka_Basic_CRM::table( 'tag_relationships' ) . ' WHERE object_id = %d', $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_search_treats_wildcards_and_quotes_literally() {
		$this->make_contact( array( 'first_name' => 'Ana' ) );
		$this->make_contact( array( 'first_name' => "O'Brien \"100%\"" ) );

		$this->assertSame( 0, Suiteka_Basic_CRM_Contacts::query( array( 'search' => '_' ) )['total'] );
		$this->assertSame( 1, Suiteka_Basic_CRM_Contacts::query( array( 'search' => '100%' ) )['total'] );
		$this->assertSame( 1, Suiteka_Basic_CRM_Contacts::query( array( 'search' => "O'Brien" ) )['total'] );
	}

	public function test_search_by_company_name_and_phone() {
		$company = $this->make_company( array( 'name' => 'Zapatos Ruiz' ) );
		$this->make_contact(
			array(
				'company_id' => $company,
				'phone'      => '912 345 678',
			)
		);

		$this->assertSame( 1, Suiteka_Basic_CRM_Contacts::query( array( 'search' => 'zapatos' ) )['total'] );
		$this->assertSame( 1, Suiteka_Basic_CRM_Contacts::query( array( 'search' => '345' ) )['total'] );
	}

	public function test_filters() {
		$company = $this->make_company();
		$a       = $this->make_contact(
			array(
				'status'     => 'customer',
				'source'     => 'web',
				'company_id' => $company,
				'tags'       => array( 'Filtro' ),
				'owner_id'   => self::$sales,
			)
		);
		$this->make_contact(
			array(
				'status'   => '',
				'owner_id' => 0,
			)
		);

		$tag_id = array_keys( Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $a ) )[0];

		$this->assertSame( array( $a ), $this->ids( array( 'status' => 'customer' ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'source' => 'web' ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'company_id' => $company ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'tag_id' => $tag_id ) ) );
		$this->assertSame( array( $a ), $this->ids( array( 'owner' => self::$sales ) ) );
		$this->assertCount( 1, $this->ids( array( 'owner' => 0 ) ) );
		$this->assertCount( 1, $this->ids( array( 'status' => '-' ) ), '«-» filtra los que no tienen estado.' );
	}

	public function test_scope_limits_results_to_own_records() {
		$own = $this->make_contact( array( 'owner_id' => self::$sales ) );
		$this->make_contact( array( 'owner_id' => self::$sales2 ) );
		$this->make_contact( array( 'owner_id' => 0 ) );

		$this->assertSame( array( $own ), $this->ids( array( 'scope' => self::$sales ) ) );
	}

	public function test_ordering_whitelist_and_pagination_clamp() {
		$this->make_contact( array( 'first_name' => 'Bea' ) );
		$this->make_contact( array( 'first_name' => 'Abel' ) );
		$this->make_contact( array( 'first_name' => 'Ciro' ) );

		$asc = Suiteka_Basic_CRM_Contacts::query(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$this->assertSame( 'Abel', strtok( $asc['items'][0]['full_name'], ' ' ) );

		// Un orden no permitido no llega al SQL.
		$evil = Suiteka_Basic_CRM_Contacts::query(
			array(
				'orderby' => 'id; DROP TABLE x',
				'order'   => 'DESC; --',
			)
		);
		$this->assertSame( 3, $evil['total'] );

		$page = Suiteka_Basic_CRM_Contacts::query(
			array(
				'per_page' => 2,
				'paged'    => 99,
			)
		);
		$this->assertSame( 2, $page['paged'] );
		$this->assertCount( 1, $page['items'] );
	}

	public function test_resolve_company() {
		$visible = $this->make_company(
			array(
				'name'     => 'Visible SL',
				'owner_id' => self::$sales,
			)
		);
		$this->make_company(
			array(
				'name'     => 'Oculta SL',
				'owner_id' => self::$sales2,
			)
		);

		$this->assertSame( 0, Suiteka_Basic_CRM_Contacts::resolve_company( '', self::$sales )['id'] );
		$this->assertSame( $visible, Suiteka_Basic_CRM_Contacts::resolve_company( 'visible sl', self::$sales )['id'] );
		$this->assertTrue( Suiteka_Basic_CRM_Contacts::resolve_company( 'Nueva SL', self::$sales )['create'] );
		$this->assertNotSame( '', Suiteka_Basic_CRM_Contacts::resolve_company( 'Oculta SL', self::$sales )['error'] );
		$this->assertSame( '', Suiteka_Basic_CRM_Contacts::resolve_company( 'Oculta SL', null )['error'] );
	}

	public function test_custom_statuses_are_sanitised() {
		$filter = function ( $statuses ) {
			$statuses['Partner VIP'] = 'Socio';
			$statuses['bad']         = array( 'no es texto' );
			return $statuses;
		};
		add_filter( 'suiteka_basic_crm_contact_statuses', $filter );

		$statuses = Suiteka_Basic_CRM_Contacts::statuses();

		remove_filter( 'suiteka_basic_crm_contact_statuses', $filter );
		$this->assertArrayHasKey( 'partnervip', $statuses );
		$this->assertArrayNotHasKey( 'bad', $statuses );
	}

	/**
	 * IDs devueltos por una consulta.
	 *
	 * @param array $args Argumentos.
	 * @return int[]
	 */
	private function ids( $args ) {
		return array_map( 'intval', wp_list_pluck( Suiteka_Basic_CRM_Contacts::query( $args )['items'], 'id' ) );
	}
}
