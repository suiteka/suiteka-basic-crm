<?php
/**
 * Pruebas del validador.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Saneado, validación y conservación de lo escrito.
 */
class Test_Suiteka_Basic_CRM_Validator extends Suiteka_Basic_CRM_Test_Case {

	public function test_required_field() {
		$v = new Suiteka_Basic_CRM_Validator( array( 'name' => '   ' ) );

		$this->assertSame( '', $v->text( 'name', 'Nombre', 50, true ) );
		$this->assertArrayHasKey( 'name', $v->errors() );
	}

	public function test_whitespace_is_normalised_without_error() {
		$v = new Suiteka_Basic_CRM_Validator( array( 'name' => "  Ana \t  María  " ) );

		$this->assertSame( 'Ana María', $v->text( 'name', 'Nombre', 50, true ) );
		$this->assertSame( array(), $v->errors() );
	}

	public function test_html_is_rejected_and_raw_value_is_kept() {
		$raw = 'Eve "<script>alert(1)</script>';
		$v   = new Suiteka_Basic_CRM_Validator( array( 'name' => $raw ) );

		$this->assertSame( '', $v->text( 'name', 'Nombre', 191, true ) );
		$this->assertArrayHasKey( 'name', $v->errors() );
		$this->assertSame( $raw, $v->raw_values()['name'] );
	}

	public function test_percent_octets_are_rejected() {
		$v = new Suiteka_Basic_CRM_Validator( array( 'name' => 'Suite 4%A1' ) );
		$v->text( 'name', 'Nombre', 50 );

		$this->assertArrayHasKey( 'name', $v->errors() );
	}

	public function test_lone_less_than_sign_is_rejected() {
		$v = new Suiteka_Basic_CRM_Validator( array( 'notes' => 'precio < 10' ) );
		$v->textarea( 'notes', 'Notas', 100 );

		$this->assertArrayHasKey( 'notes', $v->errors() );
	}

	public function test_textarea_keeps_line_breaks() {
		$v = new Suiteka_Basic_CRM_Validator( array( 'notes' => "Línea 1\r\nLínea 2" ) );

		$this->assertSame( "Línea 1\nLínea 2", $v->textarea( 'notes', 'Notas', 100 ) );
		$this->assertSame( array(), $v->errors() );
	}

	public function test_non_string_values_are_rejected() {
		$v = new Suiteka_Basic_CRM_Validator(
			array(
				'name'  => array( 'x' ),
				'email' => array( 'a@example.org' ),
			)
		);

		$this->assertSame( '', $v->text( 'name', 'Nombre', 50, true ) );
		$this->assertSame( '', $v->email( 'email', 'Correo', 100 ) );
		$this->assertArrayHasKey( 'name', $v->errors() );
		$this->assertArrayHasKey( 'email', $v->errors() );
	}

	public function test_multibyte_length_limit() {
		$ok  = new Suiteka_Basic_CRM_Validator( array( 'name' => str_repeat( 'ñ', 191 ) ) );
		$bad = new Suiteka_Basic_CRM_Validator( array( 'name' => str_repeat( 'ñ', 192 ) ) );

		$this->assertSame( str_repeat( 'ñ', 191 ), $ok->text( 'name', 'Nombre', 191 ) );
		$bad->text( 'name', 'Nombre', 191 );
		$this->assertArrayHasKey( 'name', $bad->errors() );
	}

	public function test_email() {
		$v = new Suiteka_Basic_CRM_Validator(
			array(
				'good' => 'ana@example.org',
				'bad'  => 'ana@',
			)
		);

		$this->assertSame( 'ana@example.org', $v->email( 'good', 'Correo', 100 ) );
		$this->assertSame( '', $v->email( 'bad', 'Correo', 100 ) );
		$this->assertSame( array( 'bad' ), array_keys( $v->errors() ) );
	}

	public function test_phone() {
		$v = new Suiteka_Basic_CRM_Validator(
			array(
				'good' => '+34 (600) 111-22.33',
				'bad'  => '600 ABC',
			)
		);

		$this->assertSame( '+34 (600) 111-22.33', $v->phone( 'good', 'Teléfono', 50 ) );
		$this->assertSame( '', $v->phone( 'bad', 'Teléfono', 50 ) );
		$this->assertSame( array( 'bad' ), array_keys( $v->errors() ) );
	}

	public function test_url_adds_https_and_rejects_other_protocols() {
		$v = new Suiteka_Basic_CRM_Validator(
			array(
				'plain' => 'acme.example',
				'http'  => 'http://acme.example/ruta',
				'js'    => 'javascript:alert(1)',
				'host'  => 'localhost',
			)
		);

		$this->assertSame( 'https://acme.example', $v->url( 'plain', 'Web', 191 ) );
		$this->assertSame( 'http://acme.example/ruta', $v->url( 'http', 'Web', 191 ) );
		$this->assertSame( '', $v->url( 'js', 'Web', 191 ) );
		$this->assertSame( '', $v->url( 'host', 'Web', 191 ) );
		$this->assertSame( array( 'js', 'host' ), array_keys( $v->errors() ) );
	}

	public function test_choice_and_id() {
		$v = new Suiteka_Basic_CRM_Validator(
			array(
				'ok'     => 'lead',
				'bad'    => 'hacked',
				'id'     => '12',
				'bad_id' => '12abc',
			)
		);

		$this->assertSame( 'lead', $v->choice( 'ok', 'Estado', array( '', 'lead' ) ) );
		$this->assertSame( '', $v->choice( 'bad', 'Estado', array( '', 'lead' ) ) );
		$this->assertSame( 12, $v->id( 'id', 'ID' ) );
		$this->assertSame( 0, $v->id( 'bad_id', 'ID' ) );
		$this->assertSame( array( 'bad', 'bad_id' ), array_keys( $v->errors() ) );
	}
}
