<?php
/**
 * Empresas.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tablas propias.

/**
 * Repositorio y reglas de negocio de empresas.
 */
final class Suiteka_Basic_CRM_Companies {

	const PER_PAGE = 20;

	/**
	 * Longitudes máximas en caracteres.
	 *
	 * @var int[]
	 */
	public static $max_lengths = array(
		'name'    => 191,
		'website' => 191,
		'email'   => 100,
		'phone'   => 50,
		'city'    => 100,
		'notes'   => 5000,
	);

	/**
	 * Columnas editables.
	 *
	 * @var string[]
	 */
	private static $columns = array( 'name', 'website', 'email', 'phone', 'city', 'notes', 'owner_id' );

	/**
	 * Etiquetas de los campos.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			'name'     => __( 'Nombre de la empresa', 'suiteka-basic-crm' ),
			'website'  => __( 'Web', 'suiteka-basic-crm' ),
			'email'    => __( 'Correo electrónico', 'suiteka-basic-crm' ),
			'phone'    => __( 'Teléfono', 'suiteka-basic-crm' ),
			'city'     => __( 'Ciudad', 'suiteka-basic-crm' ),
			'notes'    => __( 'Notas', 'suiteka-basic-crm' ),
			'owner_id' => __( 'Responsable', 'suiteka-basic-crm' ),
		);
	}

	/**
	 * Sanea y valida el formulario de empresa.
	 *
	 * @param array $input      Datos sin barras invertidas.
	 * @param int   $exclude_id Empresa que se edita (para la unicidad del nombre).
	 * @return array { data: array, errors: string[], values: string[] }
	 */
	public static function prepare( $input, $exclude_id = 0 ) {
		$v = new Suiteka_Basic_CRM_Validator( $input );
		$l = self::labels();
		$m = self::$max_lengths;

		$data = array(
			'name'     => $v->text( 'name', $l['name'], $m['name'], true ),
			'website'  => $v->url( 'website', $l['website'], $m['website'] ),
			'email'    => $v->email( 'email', $l['email'], $m['email'] ),
			'phone'    => $v->phone( 'phone', $l['phone'], $m['phone'] ),
			'city'     => $v->text( 'city', $l['city'], $m['city'] ),
			'notes'    => $v->textarea( 'notes', $l['notes'], $m['notes'] ),
			'owner_id' => $v->id( 'owner_id', $l['owner_id'] ),
		);

		if ( '' !== $data['name'] ) {
			$existing = self::find_by_name( $data['name'] );
			if ( $existing && (int) $existing['id'] !== (int) $exclude_id ) {
				$v->add_error( 'name', __( 'Ya existe una empresa con ese nombre.', 'suiteka-basic-crm' ) );
			}
		}

		if ( ! Suiteka_Basic_CRM_Capabilities::is_assignable_owner( $data['owner_id'] ) ) {
			$v->add_error( 'owner_id', __( 'El responsable elegido no puede acceder al CRM.', 'suiteka-basic-crm' ) );
		}

		return array(
			'data'   => $data,
			'errors' => $v->errors(),
			'values' => $v->raw_values(),
		);
	}

	/**
	 * Obtiene una empresa.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'companies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Busca una empresa por nombre (según la intercalación: sin distinguir mayúsculas).
	 *
	 * @param string $name Nombre.
	 * @return array|null
	 */
	public static function find_by_name( $name ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'companies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s LIMIT 1", $name ), ARRAY_A );
	}

	/**
	 * Crea una empresa con datos ya validados.
	 *
	 * @param array $data Datos (ver prepare()).
	 * @return int ID o 0 si falla.
	 */
	public static function create( $data ) {
		global $wpdb;

		$row               = self::row( $data );
		$row['created_at'] = Suiteka_Basic_CRM::now();
		$row['updated_at'] = $row['created_at'];

		if ( ! $wpdb->insert( Suiteka_Basic_CRM::table( 'companies' ), $row, self::formats( $row ) ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		Suiteka_Basic_CRM_Activities::log( 'created', 0, $id );

		return $id;
	}

	/**
	 * Actualiza una empresa con datos ya validados.
	 *
	 * @param int   $id   ID.
	 * @param array $data Datos.
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$current = self::get( $id );
		if ( ! $current ) {
			return false;
		}

		$row     = self::row( $data );
		$changed = array();
		foreach ( $row as $key => $value ) {
			if ( (string) $current[ $key ] !== (string) $value ) {
				$changed[] = $key;
			}
		}

		if ( empty( $changed ) ) {
			return true;
		}

		$row['updated_at'] = Suiteka_Basic_CRM::now();

		$result = $wpdb->update( Suiteka_Basic_CRM::table( 'companies' ), $row, array( 'id' => $id ), self::formats( $row ), array( '%d' ) );

		if ( false === $result ) {
			return false;
		}

		Suiteka_Basic_CRM_Activities::log( 'updated', 0, $id, array( 'fields' => $changed ) );

		return true;
	}

	/**
	 * Borra una empresa. Sus contactos se conservan sin empresa.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( Suiteka_Basic_CRM::table( 'companies' ), array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			return false;
		}

		$wpdb->update( Suiteka_Basic_CRM::table( 'contacts' ), array( 'company_id' => 0 ), array( 'company_id' => $id ), array( '%d' ), array( '%d' ) );
		Suiteka_Basic_CRM_Activities::detach_company( $id );
		Suiteka_Basic_CRM_Tags::delete_for_object( 'company', $id );

		return true;
	}

	/**
	 * Número de contactos de la empresa.
	 *
	 * @param int      $id    Empresa.
	 * @param int|null $scope Ámbito de visibilidad.
	 * @return int
	 */
	public static function count_contacts( $id, $scope = null ) {
		global $wpdb;

		$contacts = Suiteka_Basic_CRM::table( 'contacts' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		if ( null === $scope ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$contacts} WHERE company_id = %d", $id ) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$contacts} WHERE company_id = %d AND owner_id = %d AND owner_id <> 0", $id, $scope ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Número de contactos de la empresa cuyo responsable no es el usuario.
	 *
	 * @param int $id      Empresa.
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function count_contacts_of_others( $id, $user_id ) {
		global $wpdb;

		$contacts = Suiteka_Basic_CRM::table( 'contacts' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$contacts} WHERE company_id = %d AND (owner_id <> %d OR owner_id = 0)", $id, $user_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Nombres de empresas visibles, para sugerencias.
	 *
	 * @param int|null $scope Ámbito de visibilidad.
	 * @param int      $limit Máximo.
	 * @return string[]
	 */
	public static function names( $scope = null, $limit = 1000 ) {
		global $wpdb;

		$table = Suiteka_Basic_CRM::table( 'companies' );
		$where = null === $scope ? '' : $wpdb->prepare( 'WHERE owner_id = %d AND owner_id <> 0', $scope );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla interno; $where ya está preparado.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT name FROM {$table} {$where} ORDER BY name ASC LIMIT %d", $limit ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $names ) ? $names : array();
	}

	/**
	 * Lista paginada de empresas.
	 *
	 * @param array $args search, scope, orderby (name|city|contacts|created|updated), order, paged, per_page.
	 * @return array { items: array[], total: int, paged: int }
	 */
	public static function query( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'scope'    => null,
				'orderby'  => 'name',
				'order'    => 'ASC',
				'paged'    => 1,
				'per_page' => self::PER_PAGE,
			)
		);

		$table    = Suiteka_Basic_CRM::table( 'companies' );
		$contacts = Suiteka_Basic_CRM::table( 'contacts' );
		$where    = array( '1=1' );

		if ( null !== $args['scope'] ) {
			$where[] = $wpdb->prepare( 'co.owner_id = %d AND co.owner_id <> 0', $args['scope'] );
		}

		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(co.name LIKE %s OR co.email LIKE %s OR co.city LIKE %s OR co.website LIKE %s)', $like, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// Recuento de contactos visibles por empresa.
		$count_scope = null === $args['scope'] ? '' : $wpdb->prepare( 'WHERE owner_id = %d AND owner_id <> 0', $args['scope'] );

		$orderby_map = array(
			'name'     => 'co.name',
			'city'     => 'co.city',
			'contacts' => 'contacts_count',
			'created'  => 'co.created_at',
			'updated'  => 'co.updated_at',
		);
		$orderby     = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'co.name';
		$order       = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$per_page    = max( 1, (int) $args['per_page'] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Nombres de tabla internos; las condiciones ya están preparadas y el orden sale de una lista blanca.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} co WHERE {$where_sql}" );

		$max_page = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( max( 1, (int) $args['paged'] ), $max_page );
		$items    = array();

		if ( $total > 0 ) {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT co.*, COALESCE(cc.n, 0) AS contacts_count FROM {$table} co LEFT JOIN (SELECT company_id, COUNT(*) AS n FROM {$contacts} {$count_scope} GROUP BY company_id) cc ON cc.company_id = co.id WHERE {$where_sql} ORDER BY {$orderby} {$order}, co.id {$order} LIMIT %d OFFSET %d",
					$per_page,
					( $paged - 1 ) * $per_page
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
			'paged' => $paged,
		);
	}

	/**
	 * Fila lista para la base de datos.
	 *
	 * @param array $data Datos validados.
	 * @return array
	 */
	private static function row( $data ) {
		$row = array();
		foreach ( self::$columns as $column ) {
			$row[ $column ] = isset( $data[ $column ] ) ? $data[ $column ] : ( 'owner_id' === $column ? 0 : '' );
		}
		$row['owner_id'] = (int) $row['owner_id'];

		return $row;
	}

	/**
	 * Formatos de $wpdb para una fila.
	 *
	 * @param array $row Fila.
	 * @return string[]
	 */
	private static function formats( $row ) {
		$formats = array();
		foreach ( array_keys( $row ) as $key ) {
			$formats[] = 'owner_id' === $key ? '%d' : '%s';
		}

		return $formats;
	}
}
