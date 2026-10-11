<?php
/**
 * Pruebas de capacidades y reglas de acceso.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Meta-capacidades por registro y ámbito de visibilidad.
 */
class Test_Suiteka_Basic_CRM_Capabilities extends Suiteka_Basic_CRM_Test_Case {

	public function test_sales_user_can_manage_only_own_records() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';

		foreach ( array( $caps::VIEW_RECORD, $caps::EDIT_RECORD, $caps::DELETE_RECORD ) as $cap ) {
			$this->assertTrue( user_can( self::$sales, $cap, self::$sales ), "$cap propio" );
			$this->assertFalse( user_can( self::$sales, $cap, self::$sales2 ), "$cap ajeno" );
			$this->assertFalse( user_can( self::$sales, $cap, 0 ), "$cap sin responsable" );
		}
	}

	public function test_admin_can_manage_every_record() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';

		foreach ( array( $caps::VIEW_RECORD, $caps::EDIT_RECORD, $caps::DELETE_RECORD ) as $cap ) {
			$this->assertTrue( user_can( self::$admin, $cap, self::$sales ) );
			$this->assertTrue( user_can( self::$admin, $cap, 0 ) );
		}
	}

	public function test_view_others_does_not_grant_edit_others() {
		$user = self::factory()->user->create( array( 'role' => 'suiteka_test_sales' ) );
		get_userdata( $user )->add_cap( Suiteka_Basic_CRM_Capabilities::VIEW_OTHERS );

		$this->assertTrue( user_can( $user, Suiteka_Basic_CRM_Capabilities::VIEW_RECORD, self::$sales ) );
		$this->assertFalse( user_can( $user, Suiteka_Basic_CRM_Capabilities::EDIT_RECORD, self::$sales ) );
		$this->assertFalse( user_can( $user, Suiteka_Basic_CRM_Capabilities::DELETE_RECORD, self::$sales ) );
	}

	public function test_editor_has_no_access() {
		$this->assertFalse( user_can( self::$editor, Suiteka_Basic_CRM_Capabilities::VIEW ) );
		$this->assertFalse( user_can( self::$editor, Suiteka_Basic_CRM_Capabilities::VIEW_RECORD, self::$editor ) );
	}

	public function test_scope() {
		$this->assertNull( Suiteka_Basic_CRM_Capabilities::scope() );

		wp_set_current_user( self::$sales );
		$this->assertSame( self::$sales, Suiteka_Basic_CRM_Capabilities::scope() );

		wp_set_current_user( 0 );
		$this->assertSame( 0, Suiteka_Basic_CRM_Capabilities::scope(), 'Sin sesión, el ámbito no coincide con ningún registro.' );
	}

	public function test_logged_out_scope_sees_no_unassigned_records() {
		$this->make_contact( array( 'owner_id' => 0 ) );

		$result = Suiteka_Basic_CRM_Contacts::query( array( 'scope' => 0 ) );

		$this->assertSame( 0, $result['total'] );
	}

	public function test_assignable_owners() {
		$this->assertTrue( Suiteka_Basic_CRM_Capabilities::is_assignable_owner( 0 ) );
		$this->assertTrue( Suiteka_Basic_CRM_Capabilities::is_assignable_owner( self::$admin ) );
		$this->assertTrue( Suiteka_Basic_CRM_Capabilities::is_assignable_owner( self::$sales ) );
		$this->assertFalse( Suiteka_Basic_CRM_Capabilities::is_assignable_owner( self::$editor ) );
		$this->assertFalse( Suiteka_Basic_CRM_Capabilities::is_assignable_owner( 999999 ) );
		$this->assertFalse( Suiteka_Basic_CRM_Capabilities::is_assignable_owner( -1 ) );

		$ids = wp_list_pluck( Suiteka_Basic_CRM_Capabilities::assignable_users(), 'ID' );
		$this->assertContains( self::$sales, array_map( 'intval', $ids ) );
		$this->assertNotContains( self::$editor, array_map( 'intval', $ids ) );
	}

	public function test_other_meta_caps_are_untouched() {
		$this->assertSame( array( 'foo' ), Suiteka_Basic_CRM_Capabilities::map_meta_cap( array( 'foo' ), 'edit_post', self::$admin, array() ) );
	}
}
