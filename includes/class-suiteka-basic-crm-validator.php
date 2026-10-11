<?php
/**
 * Saneado y validación de formularios.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lee valores de un formulario, los sanea y acumula un error por campo.
 *
 * Conserva además lo escrito originalmente para volver a mostrarlo si hay
 * errores (siempre escapado al imprimirlo).
 */
final class Suiteka_Basic_CRM_Validator {

	/**
	 * Datos recibidos, ya sin barras invertidas añadidas.
	 *
	 * @var array
	 */
	private $input;

	/**
	 * Errores por campo.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Valores tal como se escribieron.
	 *
	 * @var string[]
	 */
	private $raw = array();

	/**
	 * Constructor.
	 *
	 * @param array $input Datos sin barras invertidas añadidas (wp_unslash()).
	 */
	public function __construct( $input ) {
		$this->input = is_array( $input ) ? $input : array();
	}

	/**
	 * Texto de una línea.
	 *
	 * @param string $key      Campo.
	 * @param string $label    Etiqueta visible.
	 * @param int    $max      Longitud máxima en caracteres.
	 * @param bool   $required Si es obligatorio.
	 * @return string Valor saneado ('' si no es válido).
	 */
	public function text( $key, $label, $max, $required = false ) {
		return $this->clean( $key, $label, $max, $required, false );
	}

	/**
	 * Texto de varias líneas.
	 *
	 * @param string $key      Campo.
	 * @param string $label    Etiqueta visible.
	 * @param int    $max      Longitud máxima en caracteres.
	 * @param bool   $required Si es obligatorio.
	 * @return string
	 */
	public function textarea( $key, $label, $max, $required = false ) {
		return $this->clean( $key, $label, $max, $required, true );
	}

	/**
	 * Correo electrónico opcional.
	 *
	 * @param string $key   Campo.
	 * @param string $label Etiqueta visible.
	 * @param int    $max   Longitud máxima.
	 * @return string
	 */
	public function email( $key, $label, $max ) {
		$value = $this->text( $key, $label, $max );

		if ( '' !== $value && ! is_email( $value ) ) {
			$this->add_error( $key, __( 'El correo electrónico no es válido.', 'suiteka-basic-crm' ) );
			return '';
		}

		return '' === $value ? '' : sanitize_email( $value );
	}

	/**
	 * Teléfono opcional.
	 *
	 * @param string $key   Campo.
	 * @param string $label Etiqueta visible.
	 * @param int    $max   Longitud máxima.
	 * @return string
	 */
	public function phone( $key, $label, $max ) {
		$value = $this->text( $key, $label, $max );

		if ( '' !== $value && ! preg_match( '/^[0-9+()\-. ]+$/', $value ) ) {
			$this->add_error( $key, __( 'El teléfono solo puede contener números, espacios y los signos + ( ) - .', 'suiteka-basic-crm' ) );
			return '';
		}

		return $value;
	}

	/**
	 * Dirección web opcional (http o https). Si falta el protocolo se usa https.
	 *
	 * @param string $key   Campo.
	 * @param string $label Etiqueta visible.
	 * @param int    $max   Longitud máxima.
	 * @return string
	 */
	public function url( $key, $label, $max ) {
		$value = $this->text( $key, $label, $max );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $value ) ) {
			$value = 'https://' . $value;
		}

		$clean = esc_url_raw( $value, array( 'http', 'https' ) );
		$host  = $clean ? wp_parse_url( $clean, PHP_URL_HOST ) : '';

		if ( '' === $clean || ! is_string( $host ) || false === strpos( $host, '.' ) || mb_strlen( $clean ) > $max ) {
			$this->add_error( $key, __( 'La dirección web no es válida.', 'suiteka-basic-crm' ) );
			return '';
		}

		return $clean;
	}

	/**
	 * Valor de una lista cerrada.
	 *
	 * @param string   $key     Campo.
	 * @param string   $label   Etiqueta visible.
	 * @param string[] $allowed Claves permitidas.
	 * @return string
	 */
	public function choice( $key, $label, $allowed ) {
		$value = $this->raw_string( $key, $label );

		if ( null === $value ) {
			return '';
		}

		if ( ! in_array( $value, $allowed, true ) ) {
			/* translators: %s: nombre del campo. */
			$this->add_error( $key, sprintf( __( 'Elige un valor válido para «%s».', 'suiteka-basic-crm' ), $label ) );
			return '';
		}

		return $value;
	}

	/**
	 * Entero no negativo (identificadores).
	 *
	 * @param string $key   Campo.
	 * @param string $label Etiqueta visible.
	 * @return int
	 */
	public function id( $key, $label ) {
		$value = $this->raw_string( $key, $label );

		if ( null === $value || '' === $value ) {
			return 0;
		}

		if ( ! ctype_digit( $value ) ) {
			/* translators: %s: nombre del campo. */
			$this->add_error( $key, sprintf( __( 'Elige un valor válido para «%s».', 'suiteka-basic-crm' ), $label ) );
			return 0;
		}

		return absint( $value );
	}

	/**
	 * Añade un error a un campo (solo se conserva el primero).
	 *
	 * @param string $key     Campo.
	 * @param string $message Mensaje.
	 */
	public function add_error( $key, $message ) {
		if ( ! isset( $this->errors[ $key ] ) ) {
			$this->errors[ $key ] = $message;
		}
	}

	/**
	 * Errores por campo.
	 *
	 * @return string[]
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * Valores tal como se escribieron, para volver a mostrarlos.
	 *
	 * @return string[]
	 */
	public function raw_values() {
		return $this->raw;
	}

	/**
	 * Lee un valor como texto. Registra un error si no es texto.
	 *
	 * @param string $key   Campo.
	 * @param string $label Etiqueta visible.
	 * @return string|null Null si el valor no es texto.
	 */
	private function raw_string( $key, $label ) {
		$value = isset( $this->input[ $key ] ) ? $this->input[ $key ] : '';

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			$this->raw[ $key ] = '';
			/* translators: %s: nombre del campo. */
			$this->add_error( $key, sprintf( __( 'El valor de «%s» no es válido.', 'suiteka-basic-crm' ), $label ) );
			return null;
		}

		$this->raw[ $key ] = $value;

		return $value;
	}

	/**
	 * Sanea un texto y rechaza los cambios que no sean de espacios.
	 *
	 * La función sanitize_text_field() quita etiquetas, el signo «<» y secuencias como
	 * «%41». En lugar de guardar un valor distinto sin avisar, se pide a la
	 * persona que lo corrija.
	 *
	 * @param string $key       Campo.
	 * @param string $label     Etiqueta visible.
	 * @param int    $max       Longitud máxima.
	 * @param bool   $required  Si es obligatorio.
	 * @param bool   $multiline Si admite saltos de línea.
	 * @return string
	 */
	private function clean( $key, $label, $max, $required, $multiline ) {
		$value = $this->raw_string( $key, $label );

		if ( null === $value ) {
			return '';
		}

		if ( $multiline ) {
			$value    = str_replace( "\r\n", "\n", $value );
			$clean    = sanitize_textarea_field( $value );
			$expected = trim( $value );
		} else {
			$clean    = sanitize_text_field( $value );
			$expected = trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
		}

		if ( $clean !== $expected ) {
			/* translators: %s: nombre del campo. */
			$this->add_error( $key, sprintf( __( '«%s» contiene caracteres no permitidos: quita las etiquetas HTML, el signo «<» y las secuencias como «%%41».', 'suiteka-basic-crm' ), $label ) );
			return '';
		}

		if ( $required && '' === $clean ) {
			/* translators: %s: nombre del campo. */
			$this->add_error( $key, sprintf( __( '«%s» es obligatorio.', 'suiteka-basic-crm' ), $label ) );
			return '';
		}

		if ( mb_strlen( $clean ) > $max ) {
			/* translators: 1: nombre del campo, 2: número máximo de caracteres. */
			$this->add_error( $key, sprintf( __( '«%1$s» no puede superar %2$d caracteres.', 'suiteka-basic-crm' ), $label, $max ) );
			return '';
		}

		return $clean;
	}
}
