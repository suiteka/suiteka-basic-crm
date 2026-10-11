<?php
/**
 * Pruebas de las pantallas de empresas, historial y duplicados.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Envíos de empresas, actividades y combinación.
 */
class Test_Suiteka_Basic_CRM_Admin_Other extends Suiteka_Basic_CRM_Test_Case {

	public function test_company_creation_and_duplicate_name() {
		$controller = 'Suiteka_Basic_CRM_Admin_Companies';
		$get        = array(
			'page'   => Suiteka_Basic_CRM_Admin::COMPANIES,
			'action' => 'new',
		);
		$post       = array(
			'suiteka_crm_operation' => 'save_company',
			'company_id'            => '0',
			'name'                  => 'Talleres Pérez',
			'website'               => 'talleres.example',
		);

		$location = $this->request( $controller, $get, $post, 'suiteka_crm_save_company_0' );
		$this->assertSame( 'created', $this->query_arg( $location, 'message' ) );
		$this->assertSame( 'https://talleres.example', Suiteka_Basic_CRM_Companies::find_by_name( 'Talleres Pérez' )['website'] );

		$this->assertNull( $this->request( $controller, $get, $post, 'suiteka_crm_save_company_0' ), 'Nombre repetido: no se guarda.' );
	}

	public function test_sales_user_cannot_delete_company_with_others_contacts() {
		$company = $this->make_company( array( 'owner_id' => self::$sales ) );
		$this->make_contact(
			array(
				'company_id' => $company,
				'owner_id'   => self::$sales2,
			)
		);
		wp_set_current_user( self::$sales );

		try {
			$this->request(
				'Suiteka_Basic_CRM_Admin_Companies',
				array( 'page' => Suiteka_Basic_CRM_Admin::COMPANIES ),
				array(
					'suiteka_crm_operation' => 'delete_company',
					'company_id'            => (string) $company,
				),
				'suiteka_crm_delete_company_' . $company
			);
			$this->fail( 'Se esperaba un 403.' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( Suiteka_Basic_CRM_Companies::get( $company ) );
		}
	}

	public function test_admin_deletes_company_and_contacts_survive() {
		$company = $this->make_company();
		$contact = $this->make_contact( array( 'company_id' => $company ) );

		$location = $this->request(
			'Suiteka_Basic_CRM_Admin_Companies',
			array( 'page' => Suiteka_Basic_CRM_Admin::COMPANIES ),
			array(
				'suiteka_crm_operation' => 'delete_company',
				'company_id'            => (string) $company,
			),
			'suiteka_crm_delete_company_' . $company
		);

		$this->assertSame( 'deleted', $this->query_arg( $location, 'message' ) );
		$this->assertSame( '0', Suiteka_Basic_CRM_Contacts::get( $contact )['company_id'] );
	}

	public function test_add_and_delete_activity() {
		$contact = $this->make_contact();
		$get     = array(
			'page'   => Suiteka_Basic_CRM_Admin::CONTACTS,
			'action' => 'view',
			'id'     => (string) $contact,
		);

		$location = $this->request(
			'Suiteka_Basic_CRM_Admin_Contacts',
			$get,
			array(
				'suiteka_crm_operation' => 'add_activity',
				'object_id'             => (string) $contact,
				'activity_type'         => 'call',
				'activity_content'      => 'Llamada de seguimiento',
				'activity_date'         => '2026-01-15T09:30',
			),
			'suiteka_crm_add_activity_contact_' . $contact
		);
		$this->assertSame( 'activity_added', $this->query_arg( $location, 'message' ) );

		$calls = wp_list_filter( Suiteka_Basic_CRM_Activities::for_contact( $contact ), array( 'type' => 'call' ) );
		$this->assertCount( 1, $calls );
		$call = array_values( $calls )[0];
		$this->assertSame( get_gmt_from_date( '2026-01-15 09:30:00' ), $call['occurred_at'] );

		$location = $this->request(
			'Suiteka_Basic_CRM_Admin_Contacts',
			$get,
			array(
				'suiteka_crm_operation' => 'delete_activity',
				'activity_id'           => $call['id'],
				'object_id'             => (string) $contact,
			),
			'suiteka_crm_delete_activity_' . $call['id']
		);
		$this->assertSame( 'activity_deleted', $this->query_arg( $location, 'message' ) );
		$this->assertNull( Suiteka_Basic_CRM_Activities::get( (int) $call['id'] ) );
	}

	public function test_invalid_activity_keeps_input() {
		$contact = $this->make_contact();

		$location = $this->request(
			'Suiteka_Basic_CRM_Admin_Contacts',
			array(
				'page'   => Suiteka_Basic_CRM_Admin::CONTACTS,
				'action' => 'view',
				'id'     => (string) $contact,
			),
			array(
				'suiteka_crm_operation' => 'add_activity',
				'object_id'             => (string) $contact,
				'activity_type'         => 'email-masivo',
				'activity_content'      => 'Texto conservado',
				'activity_date'         => '2026-02-30T10:00',
			),
			'suiteka_crm_add_activity_contact_' . $contact
		);

		$this->assertNull( $location );
		$html = $this->render( array( 'Suiteka_Basic_CRM_Admin_Contacts', 'render_main' ) );
		$this->assertStringContainsString( 'Texto conservado', $html );
		$this->assertStringContainsString( 'id="suiteka-crm-activity-date-error"', $html );
		$this->assertStringContainsString( 'id="suiteka-crm-activity-type-error"', $html );
	}

	public function test_system_events_cannot_be_deleted() {
		$contact = $this->make_contact();
		$event   = Suiteka_Basic_CRM_Activities::for_contact( $contact )[0];

		$location = $this->request(
			'Suiteka_Basic_CRM_Admin_Contacts',
			array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ),
			array(
				'suiteka_crm_operation' => 'delete_activity',
				'activity_id'           => $event['id'],
				'object_id'             => (string) $contact,
			),
			'suiteka_crm_delete_activity_' . $event['id']
		);

		$this->assertSame( 'not_found', $this->query_arg( $location, 'message' ) );
		$this->assertNotNull( Suiteka_Basic_CRM_Activities::get( (int) $event['id'] ) );
	}

	public function test_sales_user_cannot_delete_someone_elses_note() {
		$contact = $this->make_contact( array( 'owner_id' => self::$sales ) );
		$note    = Suiteka_Basic_CRM_Activities::add(
			array(
				'content'    => 'Nota del administrador',
				'contact_id' => $contact,
				'user_id'    => self::$admin,
			)
		);
		wp_set_current_user( self::$sales );

		$this->expectException( WPDieException::class );
		$this->request(
			'Suiteka_Basic_CRM_Admin_Contacts',
			array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ),
			array(
				'suiteka_crm_operation' => 'delete_activity',
				'activity_id'           => (string) $note,
				'object_id'             => (string) $contact,
			),
			'suiteka_crm_delete_activity_' . $note
		);
	}

	public function test_merge_through_the_screen() {
		$a = $this->make_contact( array( 'email' => 'merge@example.org' ) );
		$b = $this->make_contact( array( 'email' => 'merge@example.org' ) );

		$location = $this->request(
			'Suiteka_Basic_CRM_Admin_Duplicates',
			array(
				'page'   => Suiteka_Basic_CRM_Admin::DUPLICATES,
				'action' => 'merge',
				'a'      => (string) $a,
				'b'      => (string) $b,
			),
			array(
				'suiteka_crm_operation' => 'merge',
				'contact_a'             => (string) $a,
				'contact_b'             => (string) $b,
				'keep'                  => (string) $b,
			),
			'suiteka_crm_merge_contacts_' . min( $a, $b ) . '_' . max( $a, $b )
		);

		$this->assertSame( 'merged', $this->query_arg( $location, 'message' ) );
		$this->assertSame( (string) $b, $this->query_arg( $location, 'id' ) );
		$this->assertNull( Suiteka_Basic_CRM_Contacts::get( $a ) );
	}

	public function test_sales_user_cannot_merge_into_someone_elses_contact() {
		$mine   = $this->make_contact(
			array(
				'email'    => 'x@example.org',
				'owner_id' => self::$sales,
			)
		);
		$theirs = $this->make_contact(
			array(
				'email'    => 'x@example.org',
				'owner_id' => self::$sales2,
			)
		);
		wp_set_current_user( self::$sales );

		try {
			$this->request(
				'Suiteka_Basic_CRM_Admin_Duplicates',
				array( 'page' => Suiteka_Basic_CRM_Admin::DUPLICATES ),
				array(
					'suiteka_crm_operation' => 'merge',
					'contact_a'             => (string) $mine,
					'contact_b'             => (string) $theirs,
					'keep'                  => (string) $mine,
				),
				'suiteka_crm_merge_contacts_' . min( $mine, $theirs ) . '_' . max( $mine, $theirs )
			);
			$this->fail( 'Se esperaba un 403.' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( Suiteka_Basic_CRM_Contacts::get( $theirs ) );
		}
	}

	public function test_merge_preview_screen_renders() {
		$a = $this->make_contact( array( 'email' => 'p@example.org' ) );
		$b = $this->make_contact( array( 'email' => 'p@example.org' ) );

		$this->request(
			'Suiteka_Basic_CRM_Admin_Duplicates',
			array(
				'page'   => Suiteka_Basic_CRM_Admin::DUPLICATES,
				'action' => 'merge',
				'a'      => (string) $a,
				'b'      => (string) $b,
			)
		);
		$html = $this->render( array( 'Suiteka_Basic_CRM_Admin_Duplicates', 'render' ) );

		$this->assertStringContainsString( 'Resultado si conservas A', $html );
		$this->assertStringContainsString( 'name="keep"', $html );
		$this->assertNotNull( Suiteka_Basic_CRM_Contacts::get( $a ), 'Ver la vista previa no combina nada.' );
	}
}
