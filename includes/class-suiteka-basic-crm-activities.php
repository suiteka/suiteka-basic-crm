<?php
/**
 * Historial de actividades.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tablas propias.

/**
 * Eventos del sistema, notas, llamadas y reuniones ligados a contactos y empresas.
 */
final class Suiteka_Basic_CRM_Activities {

	const MAX_CONTENT = 5000;
	const LIMIT       = 50;

	/**
	 * Tipos que puede registrar una persona.
	 *
	 * @return string[] Clave => etiqueta.
	 */
	public static function manual_types() {
		return array(
			'note'    => __( 'Nota', 'suiteka-basic-crm' ),
			'call'    => __( 'Llamada', 'suiteka-basic-crm' ),
			'meeting' => __( 'Reunión', 'suiteka-basic-crm' ),
		);
	}

	/**
	 * Etiqueta de un tipo.
	 *
	 * @param string $type Tipo.
	 * @return string
	 */
	public static function type_label( $type ) {
		$types = self::manual_types();

		return isset( $types[ $type ] ) ? $types[ $type ] : __( 'Sistema', 'suiteka-basic-crm' );
	}

	/**
	 * Valida el formulario de una actividad manual.
	 *
	 * @param array $input Datos sin barras invertidas.
	 * @return array { data: array, errors: string[], values: string[] }
	 */
	public static function prepare( $input ) {
		$v = new Suiteka_Basic_CRM_Validator( $input );

		$data = array(
			'type'    => $v->choice( 'activity_type', __( 'Tipo', 'suiteka-basic-crm' ), array_keys( self::manual_types() ) ),
			'content' => $v->textarea( 'activity_content', __( 'Descripción', 'suiteka-basic-crm' ), self::MAX_CONTENT, true ),
		);

		// Fecha opcional en la zona horaria del sitio (formato de <input type="datetime-local">).
		$when = $v->text( 'activity_date', __( 'Fecha', 'suiteka-basic-crm' ), 19 );

		if ( '' === $when ) {
			$data['occurred_at'] = Suiteka_Basic_CRM::now();
		} elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2})?$/', $when, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60 ) {
			$data['occurred_at'] = get_gmt_from_date( "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:00" );
		} else {
			$v->add_error( 'activity_date', __( 'La fecha no es válida.', 'suiteka-basic-crm' ) );
			$data['occurred_at'] = '';
		}

		return array(
			'data'   => $data,
			'errors' => $v->errors(),
			'values' => $v->raw_values(),
		);
	}

	/**
	 * Registra una actividad.
	 *
	 * @param array $args type, event, content, meta, contact_id, company_id, user_id, occurred_at.
	 * @return int ID o 0 si falla.
	 */
	public static function add( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'type'        => 'note',
				'event'       => '',
				'content'     => '',
				'meta'        => array(),
				'contact_id'  => 0,
				'company_id'  => 0,
				'user_id'     => get_current_user_id(),
				'occurred_at' => '',
			)
		);

		$now = Suiteka_Basic_CRM::now();

		$inserted = $wpdb->insert(
			Suiteka_Basic_CRM::table( 'activities' ),
			array(
				'type'        => $args['type'],
				'event'       => $args['event'],
				'content'     => $args['content'],
				'meta'        => empty( $args['meta'] ) ? '' : wp_json_encode( $args['meta'] ),
				'contact_id'  => (int) $args['contact_id'],
				'company_id'  => (int) $args['company_id'],
				'user_id'     => (int) $args['user_id'],
				'occurred_at' => $args['occurred_at'] ? $args['occurred_at'] : $now,
				'created_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Registra un evento del sistema.
	 *
	 * @param string $event      Clave del evento.
	 * @param int    $contact_id Contacto relacionado.
	 * @param int    $company_id Empresa relacionada.
	 * @param array  $meta       Datos del evento (sin datos personales antiguos).
	 * @return int
	 */
	public static function log( $event, $contact_id, $company_id, $meta = array() ) {
		return self::add(
			array(
				'type'       => 'system',
				'event'      => $event,
				'meta'       => $meta,
				'contact_id' => $contact_id,
				'company_id' => $company_id,
			)
		);
	}

	/**
	 * Obtiene una actividad.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'activities' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Borra una actividad.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( Suiteka_Basic_CRM::table( 'activities' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Historial de un contacto, del más reciente al más antiguo.
	 *
	 * @param int $contact_id Contacto.
	 * @param int $limit      Máximo de filas.
	 * @return array[]
	 */
	public static function for_contact( $contact_id, $limit = self::LIMIT ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'activities' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d", $contact_id, $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Historial de una empresa: sus actividades y las de sus contactos visibles.
	 *
	 * @param int      $company_id Empresa.
	 * @param int|null $scope      Ámbito de visibilidad de contactos.
	 * @param int      $limit      Máximo de filas.
	 * @return array[]
	 */
	public static function for_company( $company_id, $scope = null, $limit = self::LIMIT ) {
		global $wpdb;

		$table    = Suiteka_Basic_CRM::table( 'activities' );
		$contacts = Suiteka_Basic_CRM::table( 'contacts' );
		$owner    = null === $scope ? '' : $wpdb->prepare( ' AND owner_id = %d AND owner_id <> 0', $scope );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Nombres de tabla internos; $owner ya está preparado.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE company_id = %d OR contact_id IN (SELECT id FROM {$contacts} WHERE company_id = %d{$owner}) ORDER BY occurred_at DESC, id DESC LIMIT %d",
				$company_id,
				$company_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Al borrar un contacto: borra lo que solo le pertenece a él y desvincula el resto.
	 *
	 * @param int $contact_id Contacto.
	 */
	public static function detach_contact( $contact_id ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'activities' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE contact_id = %d AND company_id = 0 AND opportunity_id = 0", $contact_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET contact_id = 0 WHERE contact_id = %d", $contact_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Al borrar una empresa: borra lo que solo le pertenece a ella y desvincula el resto.
	 *
	 * @param int $company_id Empresa.
	 */
	public static function detach_company( $company_id ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'activities' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE company_id = %d AND contact_id = 0 AND opportunity_id = 0", $company_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET company_id = 0 WHERE company_id = %d", $company_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Reasigna las actividades de un contacto a otro (combinación).
	 *
	 * @param int $from Contacto que desaparece.
	 * @param int $to   Contacto que se conserva.
	 * @return bool
	 */
	public static function reassign_contact( $from, $to ) {
		global $wpdb;

		$result = $wpdb->update(
			Suiteka_Basic_CRM::table( 'activities' ),
			array( 'contact_id' => $to ),
			array( 'contact_id' => $from ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Texto legible de un evento del sistema.
	 *
	 * @param array $activity Fila de la tabla.
	 * @return string
	 */
	public static function describe( $activity ) {
		$meta = json_decode( (string) $activity['meta'], true );
		$meta = is_array( $meta ) ? $meta : array();

		switch ( $activity['event'] ) {
			case 'created':
				return __( 'Registro creado.', 'suiteka-basic-crm' );
			case 'updated':
				$labels = array();
				$all    = array_merge( Suiteka_Basic_CRM_Contacts::labels(), Suiteka_Basic_CRM_Companies::labels() );
				foreach ( isset( $meta['fields'] ) ? (array) $meta['fields'] : array() as $field ) {
					$labels[] = isset( $all[ $field ] ) ? $all[ $field ] : $field;
				}
				/* translators: %s: lista de campos modificados. */
				return $labels ? sprintf( __( 'Datos actualizados: %s.', 'suiteka-basic-crm' ), implode( ', ', array_unique( $labels ) ) ) : __( 'Datos actualizados.', 'suiteka-basic-crm' );
			case 'merged':
				/* translators: 1: nombre del contacto combinado, 2: su ID. */
				return sprintf( __( 'Se combinó con el contacto duplicado «%1$s» (n.º %2$d).', 'suiteka-basic-crm' ), isset( $meta['name'] ) ? $meta['name'] : '', isset( $meta['id'] ) ? (int) $meta['id'] : 0 );
			case 'merged_notes':
				return __( 'Notas del contacto combinado:', 'suiteka-basic-crm' );
			default:
				return __( 'Evento del sistema.', 'suiteka-basic-crm' );
		}
	}
}
