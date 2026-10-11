<?php
/**
 * Pruebas de las pantallas y envíos de contactos.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Nonces, permisos, validación, duplicados y salida de la pantalla de contactos.
 */
class Test_Suiteka_Basic_CRM_Admin_Contacts extends Suiteka_Basic_CRM_Test_Case {

	const CONTROLLER = 'Suiteka_Basic_CRM_Admin_Contacts';

	/**
	 * Envía el formulario de contacto.
	 *
	 * @param array $fields Campos.
	 * @param int   $id     Contacto (0 = nuevo).
	 * @param bool  $nonce  Si se envía un nonce válido.
	 * @return string|null
	 */
	private function save( $fields, $id = 0, $nonce = true ) {
		$page = $id ? array(
			'page'   => Suiteka_Basic_CRM_Admin::CONTACTS,
			'action' => 'edit',
			'id'     => (string) $id,
		) : array( 'page' => Suiteka_Basic_CRM_Admin::CONTACT_NEW );

		return $this->request(
			self::CONTROLLER,
			$page,
			array_merge(
				array(
					'suiteka_crm_operation' => 'save',
					'contact_id'            => (string) $id,
				),
				$fields
			),
			$nonce ? 'suiteka_crm_save_contact_' . $id : null
		);
	}

	public function test_invalid_nonce_dies_and_saves_nothing() {
		$before = Suiteka_Basic_CRM_Contacts::count();

		try {
			$this->save( array( 'first_name' => 'Sin nonce' ), 0, false );
			$this->fail( 'Se esperaba wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, Suiteka_Basic_CRM_Contacts::count() );
		}
	}

	public function test_user_without_crm_access_is_denied() {
		wp_set_current_user( self::$editor );

		$this->expectException( WPDieException::class );
		$this->request( self::CONTROLLER, array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ) );
	}

	public function test_valid_creation_redirects_to_the_new_record() {
		$location = $this->save(
			array(
				'first_name' => 'Nueva',
				'last_name'  => 'Persona',
				'email'      => 'nueva@example.org',
			)
		);

		$this->assertSame( 'created', $this->query_arg( $location, 'message' ) );
		$this->assertSame( 'view', $this->query_arg( $location, 'action' ) );

		$contact = Suiteka_Basic_CRM_Contacts::get( (int) $this->query_arg( $location, 'id' ) );
		$this->assertSame( 'Nueva Persona', $contact['full_name'] );
		$this->assertSame( (string) self::$admin, $contact['owner_id'] );
	}

	public function test_creation_with_new_company_creates_and_links_it() {
		$location = $this->save(
			array(
				'first_name'   => 'Con',
				'company_name' => 'Empresa Creada Al Vuelo',
			)
		);

		$this->assertSame( 'created_company', $this->query_arg( $location, 'message' ) );
		$company = Suiteka_Basic_CRM_Companies::find_by_name( 'Empresa Creada Al Vuelo' );
		$this->assertNotNull( $company );
		$this->assertSame( $company['id'], Suiteka_Basic_CRM_Contacts::get( (int) $this->query_arg( $location, 'id' ) )['company_id'] );
	}

	public function test_validation_errors_keep_input_and_mark_fields() {
		$location = $this->save(
			array(
				'first_name' => 'Juan <CEO>',
				'email'      => 'no-es-un-correo',
				'phone'      => '600 ABC',
			)
		);

		$this->assertNull( $location, 'Con errores no se redirige.' );
		$this->assertSame( 0, Suiteka_Basic_CRM_Contacts::query( array( 'search' => 'Juan' ) )['total'] );

		$html = $this->render( array( self::CONTROLLER, 'render_new' ) );

		$this->assertStringContainsString( 'id="suiteka-crm-error-summary"', $html );
		$this->assertStringContainsString( 'href="#suiteka-crm-email"', $html );
		$this->assertMatchesRegularExpression( '/id="suiteka-crm-first-name"[^>]*aria-invalid="true"[^>]*aria-describedby="suiteka-crm-first-name-error"/', $html );
		$this->assertStringContainsString( 'id="suiteka-crm-phone-error"', $html );
		$this->assertStringContainsString( 'value="Juan &lt;CEO&gt;"', $html, 'Se conserva lo escrito, escapado.' );
		$this->assertStringContainsString( 'value="no-es-un-correo"', $html );
		$this->assertStringNotContainsString( '<CEO>', $html );
	}

	public function test_duplicates_require_confirmation() {
		$this->make_contact( array( 'email' => 'repetido@example.org' ) );
		$fields = array(
			'first_name' => 'Otra',
			'email'      => 'REPETIDO@example.org',
		);

		$this->assertNull( $this->save( $fields ), 'Primero se avisa.' );
		$html = $this->render( array( self::CONTROLLER, 'render_new' ) );
		$this->assertStringContainsString( 'name="confirm_duplicates"', $html );
		$this->assertStringContainsString( 'value="REPETIDO@example.org"', $html );

		$this->reset_static( self::CONTROLLER );
		$location = $this->save( array_merge( $fields, array( 'confirm_duplicates' => '1' ) ) );
		$this->assertSame( 'created', $this->query_arg( $location, 'message' ) );
	}

	public function test_editing_without_changing_identity_skips_duplicate_check() {
		$this->make_contact( array( 'email' => 'dup@example.org' ) );
		$id = $this->make_contact( array( 'email' => 'dup@example.org' ) );

		$location = $this->save(
			array(
				'first_name' => Suiteka_Basic_CRM_Contacts::get( $id )['first_name'],
				'last_name'  => Suiteka_Basic_CRM_Contacts::get( $id )['last_name'],
				'email'      => 'dup@example.org',
				'job_title'  => 'Nuevo cargo',
			),
			$id
		);

		$this->assertSame( 'updated', $this->query_arg( $location, 'message' ) );
		$this->assertSame( 'Nuevo cargo', Suiteka_Basic_CRM_Contacts::get( $id )['job_title'] );
	}

	public function test_sales_user_cannot_choose_owner() {
		wp_set_current_user( self::$sales );

		$location = $this->save(
			array(
				'first_name' => 'Mío',
				'owner_id'   => (string) self::$sales2,
			)
		);

		$this->assertSame( (string) self::$sales, Suiteka_Basic_CRM_Contacts::get( (int) $this->query_arg( $location, 'id' ) )['owner_id'] );
	}

	public function test_sales_user_cannot_view_or_edit_others_records() {
		$theirs = $this->make_contact( array( 'owner_id' => self::$sales2 ) );
		wp_set_current_user( self::$sales );

		foreach ( array( 'view', 'edit', 'delete' ) as $action ) {
			try {
				$this->request(
					self::CONTROLLER,
					array(
						'page'   => Suiteka_Basic_CRM_Admin::CONTACTS,
						'action' => $action,
						'id'     => (string) $theirs,
					)
				);
				$this->fail( "Se esperaba un 403 en $action." );
			} catch ( WPDieException $e ) {
				$this->assertTrue( true );
			}
		}

		try {
			$this->save( array( 'first_name' => 'Robado' ), $theirs );
			$this->fail( 'Se esperaba un 403 al guardar.' );
		} catch ( WPDieException $e ) {
			$this->assertNotSame( 'Robado', Suiteka_Basic_CRM_Contacts::get( $theirs )['first_name'] );
		}

		try {
			$this->request(
				self::CONTROLLER,
				array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ),
				array(
					'suiteka_crm_operation' => 'delete',
					'contact_id'            => (string) $theirs,
				),
				'suiteka_crm_delete_contact_' . $theirs
			);
			$this->fail( 'Se esperaba un 403 al borrar.' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( Suiteka_Basic_CRM_Contacts::get( $theirs ) );
		}
	}

	public function test_list_shows_only_visible_records() {
		$this->make_contact(
			array(
				'first_name' => 'Visible',
				'owner_id'   => self::$sales,
			)
		);
		$this->make_contact(
			array(
				'first_name' => 'Invisible',
				'owner_id'   => self::$sales2,
			)
		);
		wp_set_current_user( self::$sales );

		$this->request( self::CONTROLLER, array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ) );
		$html = $this->render( array( self::CONTROLLER, 'render_main' ) );

		$this->assertStringContainsString( 'Visible', $html );
		$this->assertStringNotContainsString( 'Invisible', $html );
	}

	public function test_get_on_delete_only_shows_confirmation() {
		$id = $this->make_contact();

		$this->request(
			self::CONTROLLER,
			array(
				'page'   => Suiteka_Basic_CRM_Admin::CONTACTS,
				'action' => 'delete',
				'id'     => (string) $id,
			)
		);
		$html = $this->render( array( self::CONTROLLER, 'render_main' ) );

		$this->assertStringContainsString( 'suiteka_crm_operation" value="delete"', $html );
		$this->assertNotNull( Suiteka_Basic_CRM_Contacts::get( $id ) );
	}

	public function test_post_delete_removes_the_contact() {
		$id = $this->make_contact();

		$location = $this->request(
			self::CONTROLLER,
			array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ),
			array(
				'suiteka_crm_operation' => 'delete',
				'contact_id'            => (string) $id,
			),
			'suiteka_crm_delete_contact_' . $id
		);

		$this->assertSame( 'deleted', $this->query_arg( $location, 'message' ) );
		$this->assertNull( Suiteka_Basic_CRM_Contacts::get( $id ) );
	}

	public function test_nonce_is_bound_to_the_record() {
		$a = $this->make_contact();
		$b = $this->make_contact();

		$this->expectException( WPDieException::class );
		$this->request(
			self::CONTROLLER,
			array( 'page' => Suiteka_Basic_CRM_Admin::CONTACTS ),
			array(
				'suiteka_crm_operation' => 'delete',
				'contact_id'            => (string) $b,
			),
			'suiteka_crm_delete_contact_' . $a
		);
	}

	public function test_pagination_links_do_not_repeat_the_previous_notice() {
		for ( $i = 0; $i < Suiteka_Basic_CRM_Contacts::PER_PAGE + 1; $i++ ) {
			$this->make_contact( array( 'status' => 'lead' ) );
		}

		$this->request(
			self::CONTROLLER,
			array(
				'page'    => Suiteka_Basic_CRM_Admin::CONTACTS,
				'message' => 'created',
				'status'  => 'lead',
			)
		);
		$html = $this->render( array( self::CONTROLLER, 'render_main' ) );

		$this->assertMatchesRegularExpression( '/class="next-page button" href="[^"]*paged=2/', $html );
		$this->assertMatchesRegularExpression( '/class="next-page button" href="[^"]*status=lead/', $html, 'Conserva los filtros.' );
		$this->assertDoesNotMatchRegularExpression( '/href="[^"]*message=/', $html );
	}

	public function test_sortable_headers_expose_aria_sort() {
		$this->make_contact();

		$this->request(
			self::CONTROLLER,
			array(
				'page'    => Suiteka_Basic_CRM_Admin::CONTACTS,
				'orderby' => 'name',
				'order'   => 'asc',
			)
		);
		$html = $this->render( array( self::CONTROLLER, 'render_main' ) );

		$this->assertStringContainsString( 'aria-sort="ascending"', $html );
	}

	public function test_output_is_escaped() {
		$id = $this->make_contact(
			array(
				'first_name' => 'Eve',
				'notes'      => 'x',
				'job_title'  => '"><img src=x onerror=alert(1)>',
			)
		);

		$this->request(
			self::CONTROLLER,
			array(
				'page'   => Suiteka_Basic_CRM_Admin::CONTACTS,
				'action' => 'view',
				'id'     => (string) $id,
			)
		);
		$html = $this->render( array( self::CONTROLLER, 'render_main' ) );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html );
	}

	public function test_unknown_url_message_is_ignored() {
		$this->make_contact();

		$this->request(
			self::CONTROLLER,
			array(
				'page'    => Suiteka_Basic_CRM_Admin::CONTACTS,
				'message' => '<script>x</script>',
			)
		);
		$html = $this->render( array( self::CONTROLLER, 'render_main' ) );

		$this->assertStringNotContainsString( 'notice-success', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
	}
}
