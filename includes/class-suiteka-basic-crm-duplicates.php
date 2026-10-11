<?php
/**
 * Posibles duplicados y combinación de contactos.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tablas propias.

/**
 * Detecta contactos que pueden ser la misma persona y los combina sin perder datos.
 *
 * Criterios: mismo correo (sin distinguir mayúsculas), mismo teléfono
 * normalizado (solo dígitos, al menos MIN_PHONE_DIGITS) o mismo nombre completo.
 */
final class Suiteka_Basic_CRM_Duplicates {

	const MIN_PHONE_DIGITS = 6;
	const GROUP_LIMIT      = 50;

	/**
	 * Campos que la combinación rellena si están vacíos en el contacto conservado.
	 *
	 * @var string[]
	 */
	private static $fill_fields = array( 'email', 'phone', 'job_title', 'company', 'company_id', 'source', 'status', 'owner_id' );

	/**
	 * Expresión SQL que deja solo los dígitos de la columna phone (los únicos
	 * otros caracteres que admite el validador son espacio y + ( ) - .).
	 *
	 * @param string $column Columna.
	 * @return string
	 */
	private static function phone_sql( $column ) {
		return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '+', ''), '(', ''), ')', ''), '-', ''), '.', '')";
	}

	/**
	 * Dígitos de un teléfono.
	 *
	 * @param string $phone Teléfono.
	 * @return string
	 */
	public static function phone_digits( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	/**
	 * Contactos que coinciden con los datos de un formulario.
	 *
	 * @param array    $data       first_name, last_name, email, phone.
	 * @param int      $exclude_id Contacto que se edita.
	 * @param int|null $scope      Ámbito de visibilidad.
	 * @return array { visible: array[], hidden: int }
	 */
	public static function find_for( $data, $exclude_id = 0, $scope = null ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'contacts' );
		$or    = array();

		$full_name = Suiteka_Basic_CRM_Contacts::full_name( $data['first_name'], $data['last_name'] );
		if ( '' !== $full_name ) {
			$or[] = $wpdb->prepare( 'full_name = %s', $full_name );
		}
		if ( '' !== $data['email'] ) {
			$or[] = $wpdb->prepare( 'email = %s', $data['email'] );
		}
		$digits = self::phone_digits( $data['phone'] );
		if ( strlen( $digits ) >= self::MIN_PHONE_DIGITS ) {
			$or[] = $wpdb->prepare( self::phone_sql( 'phone' ) . ' = %s', $digits ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Expresión fija.
		}

		$result = array(
			'visible' => array(),
			'hidden'  => 0,
		);

		if ( empty( $or ) ) {
			return $result;
		}

		$where = '(' . implode( ' OR ', $or ) . ')' . $wpdb->prepare( ' AND id <> %d', $exclude_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno; condiciones preparadas.
		$rows = $wpdb->get_results( "SELECT id, full_name, email, phone, owner_id, created_at FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 50", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( self::in_scope( $row, $scope ) ) {
				$result['visible'][] = $row;
			} else {
				++$result['hidden'];
			}
		}

		return $result;
	}

	/**
	 * Grupos de posibles duplicados entre los contactos visibles.
	 *
	 * @param int|null $scope Ámbito de visibilidad.
	 * @return array[] Cada grupo: criterion (email|phone|name), value, contacts.
	 */
	public static function groups( $scope = null ) {
		global $wpdb;

		$table    = Suiteka_Basic_CRM::table( 'contacts' );
		$owner    = null === $scope ? '' : $wpdb->prepare( ' AND owner_id = %d AND owner_id <> 0', $scope );
		$phone    = self::phone_sql( 'phone' );
		$criteria = array(
			'email' => array( 'email', "email <> ''" ),
			'phone' => array( $phone, $wpdb->prepare( "CHAR_LENGTH({$phone}) >= %d", self::MIN_PHONE_DIGITS ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Expresión fija.
			'name'  => array( 'full_name', "full_name <> ''" ),
		);

		$groups = array();

		foreach ( $criteria as $criterion => $sql ) {
			list( $expr, $condition ) = $sql;

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla y expresiones internos; $owner ya está preparado.
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT {$expr} AS k FROM {$table} WHERE {$condition}{$owner} GROUP BY k HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC, k ASC LIMIT %d",
					self::GROUP_LIMIT
				)
			);

			foreach ( (array) $keys as $key ) {
				$contacts = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, full_name, email, phone, company_id, owner_id, created_at FROM {$table} WHERE {$expr} = %s{$owner} ORDER BY created_at ASC, id ASC LIMIT 20", $key ),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

				if ( is_array( $contacts ) && count( $contacts ) > 1 ) {
					$groups[] = array(
						'criterion' => $criterion,
						'value'     => $key,
						'contacts'  => $contacts,
					);
				}
			}
		}

		return $groups;
	}

	/**
	 * Resultado de combinar dos contactos, sin guardar nada.
	 *
	 * @param array $keep    Contacto que se conserva (fila completa).
	 * @param array $discard Contacto que desaparece (fila completa).
	 * @return array Valores resultantes de las columnas.
	 */
	public static function preview( $keep, $discard ) {
		$result = $keep;

		foreach ( self::$fill_fields as $field ) {
			$empty = in_array( $field, array( 'company_id', 'owner_id' ), true ) ? 0 === (int) $keep[ $field ] : '' === (string) $keep[ $field ];
			if ( $empty ) {
				$result[ $field ] = $discard[ $field ];
			}
		}

		// El nombre y los apellidos son siempre los del contacto conservado.
		$result['created_at'] = min( $keep['created_at'], $discard['created_at'] );

		return $result;
	}

	/**
	 * Combina dos contactos.
	 *
	 * Rellena los campos vacíos del conservado, une las etiquetas, reasigna
	 * las actividades, guarda las notas del otro como nota del historial,
	 * registra el evento y borra el otro. Todo en una transacción si el
	 * motor la admite.
	 *
	 * @param int $keep_id    Contacto que se conserva.
	 * @param int $discard_id Contacto que desaparece.
	 * @return true|WP_Error
	 */
	public static function merge( $keep_id, $discard_id ) {
		global $wpdb;

		$keep_id    = (int) $keep_id;
		$discard_id = (int) $discard_id;

		if ( $keep_id === $discard_id ) {
			return new WP_Error( 'same_contact', __( 'No se puede combinar un contacto consigo mismo.', 'suiteka-basic-crm' ) );
		}

		$table   = Suiteka_Basic_CRM::table( 'contacts' );
		$keep    = Suiteka_Basic_CRM_Contacts::get( $keep_id );
		$discard = Suiteka_Basic_CRM_Contacts::get( $discard_id );

		if ( ! $keep || ! $discard ) {
			return new WP_Error( 'not_found', __( 'Uno de los contactos ya no existe.', 'suiteka-basic-crm' ) );
		}

		$result = self::preview( $keep, $discard );
		$row    = array();
		foreach ( array_merge( self::$fill_fields, array( 'created_at' ) ) as $field ) {
			$row[ $field ] = $result[ $field ];
		}
		$row['updated_at'] = Suiteka_Basic_CRM::now();

		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = in_array( $key, array( 'company_id', 'owner_id' ), true ) ? '%d' : '%s';
		}

		$tags = array_unique(
			array_merge(
				array_values( Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $keep_id ) ),
				array_values( Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $discard_id ) )
			)
		);

		self::transaction( 'begin' );

		$ok = false !== $wpdb->update( $table, $row, array( 'id' => $keep_id ), $formats, array( '%d' ) );
		$ok = $ok && Suiteka_Basic_CRM_Activities::reassign_contact( $discard_id, $keep_id );

		if ( $ok ) {
			Suiteka_Basic_CRM_Tags::set_for_object( 'contact', $keep_id, $tags );

			if ( '' !== trim( (string) $discard['notes'] ) ) {
				$ok = (bool) Suiteka_Basic_CRM_Activities::add(
					array(
						'type'       => 'note',
						'event'      => 'merged_notes',
						'content'    => $discard['notes'],
						'contact_id' => $keep_id,
					)
				);
			}
		}

		$ok = $ok && (bool) Suiteka_Basic_CRM_Activities::log(
			'merged',
			$keep_id,
			0,
			array(
				'id'   => $discard_id,
				'name' => $discard['full_name'],
			)
		);

		$ok = $ok && (bool) $wpdb->delete( $table, array( 'id' => $discard_id ), array( '%d' ) );

		if ( ! $ok ) {
			self::transaction( 'rollback' );
			return new WP_Error( 'merge_failed', __( 'No se pudieron combinar los contactos. No se ha borrado nada.', 'suiteka-basic-crm' ) );
		}

		Suiteka_Basic_CRM_Tags::delete_for_object( 'contact', $discard_id );
		self::transaction( 'commit' );

		return true;
	}

	/**
	 * Inicia, confirma o revierte una transacción.
	 *
	 * Si ya hay una transacción abierta (autocommit desactivado, p. ej. en las
	 * pruebas o en otro plugin) se usa un punto de guardado para no confirmarla.
	 * Con tablas MyISAM las sentencias no tienen efecto.
	 *
	 * @param string $action begin|commit|rollback.
	 */
	private static function transaction( $action ) {
		global $wpdb;
		static $nested = false;

		if ( 'begin' === $action ) {
			$nested = '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' );
		}

		if ( $nested ) {
			if ( 'begin' === $action ) {
				$wpdb->query( 'SAVEPOINT suiteka_crm_merge' );
			} elseif ( 'commit' === $action ) {
				$wpdb->query( 'RELEASE SAVEPOINT suiteka_crm_merge' );
			} else {
				$wpdb->query( 'ROLLBACK TO SAVEPOINT suiteka_crm_merge' );
			}
		} elseif ( 'begin' === $action ) {
			$wpdb->query( 'START TRANSACTION' );
		} elseif ( 'commit' === $action ) {
			$wpdb->query( 'COMMIT' );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * Indica si un contacto está dentro del ámbito de visibilidad.
	 *
	 * @param array    $row   Fila con owner_id.
	 * @param int|null $scope Ámbito.
	 * @return bool
	 */
	private static function in_scope( $row, $scope ) {
		return null === $scope || ( (int) $row['owner_id'] > 0 && (int) $row['owner_id'] === (int) $scope );
	}
}
