<?php
/**
 * Contactos.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tablas propias.

/**
 * Repositorio y reglas de negocio de contactos.
 */
final class Suiteka_Basic_CRM_Contacts {

	const PER_PAGE = 20;

	/**
	 * Longitudes máximas en caracteres.
	 *
	 * @var int[]
	 */
	public static $max_lengths = array(
		'first_name'   => 191,
		'last_name'    => 191,
		'full_name'    => 191,
		'email'        => 100,
		'phone'        => 50,
		'job_title'    => 100,
		'company_name' => 191,
		'tags'         => 1000,
		'notes'        => 5000,
	);

	/**
	 * Columnas editables (full_name se deriva de nombre y apellidos).
	 *
	 * @var string[]
	 */
	private static $columns = array( 'first_name', 'last_name', 'email', 'phone', 'job_title', 'company_id', 'source', 'status', 'notes', 'owner_id' );

	/**
	 * Estados del contacto.
	 *
	 * @return string[] Clave => etiqueta.
	 */
	public static function statuses() {
		$statuses = array(
			'lead'     => __( 'Potencial', 'suiteka-basic-crm' ),
			'customer' => __( 'Cliente', 'suiteka-basic-crm' ),
			'former'   => __( 'Antiguo cliente', 'suiteka-basic-crm' ),
			'inactive' => __( 'Descartado', 'suiteka-basic-crm' ),
		);

		/**
		 * Filtra los estados disponibles. Las claves deben ser slugs de hasta 32 caracteres.
		 *
		 * @param string[] $statuses Clave => etiqueta.
		 */
		return self::clean_choices( apply_filters( 'suiteka_basic_crm_contact_statuses', $statuses ) );
	}

	/**
	 * Orígenes del contacto.
	 *
	 * @return string[] Clave => etiqueta.
	 */
	public static function sources() {
		$sources = array(
			'web'      => __( 'Web', 'suiteka-basic-crm' ),
			'referral' => __( 'Recomendación', 'suiteka-basic-crm' ),
			'event'    => __( 'Evento', 'suiteka-basic-crm' ),
			'social'   => __( 'Redes sociales', 'suiteka-basic-crm' ),
			'phone'    => __( 'Llamada entrante', 'suiteka-basic-crm' ),
			'email'    => __( 'Correo electrónico', 'suiteka-basic-crm' ),
			'other'    => __( 'Otro', 'suiteka-basic-crm' ),
		);

		/**
		 * Filtra los orígenes disponibles. Las claves deben ser slugs de hasta 32 caracteres.
		 *
		 * @param string[] $sources Clave => etiqueta.
		 */
		return self::clean_choices( apply_filters( 'suiteka_basic_crm_contact_sources', $sources ) );
	}

	/**
	 * Etiquetas de los campos.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			'first_name'   => __( 'Nombre', 'suiteka-basic-crm' ),
			'last_name'    => __( 'Apellidos', 'suiteka-basic-crm' ),
			'email'        => __( 'Correo electrónico', 'suiteka-basic-crm' ),
			'phone'        => __( 'Teléfono', 'suiteka-basic-crm' ),
			'job_title'    => __( 'Cargo', 'suiteka-basic-crm' ),
			'company_id'   => __( 'Empresa', 'suiteka-basic-crm' ),
			'company_name' => __( 'Empresa', 'suiteka-basic-crm' ),
			'source'       => __( 'Origen', 'suiteka-basic-crm' ),
			'status'       => __( 'Estado', 'suiteka-basic-crm' ),
			'tags'         => __( 'Etiquetas', 'suiteka-basic-crm' ),
			'notes'        => __( 'Notas', 'suiteka-basic-crm' ),
			'owner_id'     => __( 'Responsable', 'suiteka-basic-crm' ),
		);
	}

	/**
	 * Sanea y valida el formulario de contacto.
	 *
	 * No resuelve la empresa (ver resolve_company()) ni aplica permisos.
	 *
	 * @param array $input Datos sin barras invertidas.
	 * @return array { data: array, errors: string[], values: string[] }
	 */
	public static function prepare( $input ) {
		$v = new Suiteka_Basic_CRM_Validator( $input );
		$l = self::labels();
		$m = self::$max_lengths;

		$data = array(
			'first_name'   => $v->text( 'first_name', $l['first_name'], $m['first_name'], true ),
			'last_name'    => $v->text( 'last_name', $l['last_name'], $m['last_name'] ),
			'email'        => $v->email( 'email', $l['email'], $m['email'] ),
			'phone'        => $v->phone( 'phone', $l['phone'], $m['phone'] ),
			'job_title'    => $v->text( 'job_title', $l['job_title'], $m['job_title'] ),
			'company_name' => $v->text( 'company_name', $l['company_name'], $m['company_name'] ),
			'source'       => $v->choice( 'source', $l['source'], array_merge( array( '' ), array_keys( self::sources() ) ) ),
			'status'       => $v->choice( 'status', $l['status'], array_merge( array( '' ), array_keys( self::statuses() ) ) ),
			'notes'        => $v->textarea( 'notes', $l['notes'], $m['notes'] ),
			'owner_id'     => $v->id( 'owner_id', $l['owner_id'] ),
			'tags'         => array(),
		);

		$full_name = self::full_name( $data['first_name'], $data['last_name'] );
		if ( mb_strlen( $full_name ) > $m['full_name'] ) {
			/* translators: %d: número máximo de caracteres. */
			$v->add_error( 'last_name', sprintf( __( 'Nombre y apellidos juntos no pueden superar %d caracteres.', 'suiteka-basic-crm' ), $m['full_name'] ) );
		}

		$tags = Suiteka_Basic_CRM_Tags::parse( $v->text( 'tags', $l['tags'], $m['tags'] ) );
		if ( is_wp_error( $tags ) ) {
			$v->add_error( 'tags', $tags->get_error_message() );
		} else {
			$data['tags'] = $tags;
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
	 * Resuelve el texto del campo «Empresa».
	 *
	 * @param string   $name  Nombre escrito.
	 * @param int|null $scope Ámbito de visibilidad del usuario.
	 * @return array { id: int, create: bool, error: string }
	 */
	public static function resolve_company( $name, $scope ) {
		$result = array(
			'id'     => 0,
			'create' => false,
			'error'  => '',
		);

		if ( '' === $name ) {
			return $result;
		}

		$company = Suiteka_Basic_CRM_Companies::find_by_name( $name );

		if ( ! $company ) {
			$result['create'] = true;
			return $result;
		}

		$owner = (int) $company['owner_id'];
		if ( null !== $scope && ( 0 === $owner || $owner !== (int) $scope ) ) {
			$result['error'] = __( 'Ya existe una empresa con ese nombre, pero no tienes acceso a ella. Pide a quien administra el CRM que te la asigne.', 'suiteka-basic-crm' );
			return $result;
		}

		$result['id'] = (int) $company['id'];

		return $result;
	}

	/**
	 * Nombre completo derivado.
	 *
	 * @param string $first Nombre.
	 * @param string $last  Apellidos.
	 * @return string
	 */
	public static function full_name( $first, $last ) {
		return trim( $first . ' ' . $last );
	}

	/**
	 * Obtiene un contacto con el nombre de su empresa.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table     = Suiteka_Basic_CRM::table( 'contacts' );
		$companies = Suiteka_Basic_CRM::table( 'companies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombres de tabla internos.
		return $wpdb->get_row( $wpdb->prepare( "SELECT c.*, co.name AS company_name, co.owner_id AS company_owner_id FROM {$table} c LEFT JOIN {$companies} co ON co.id = c.company_id WHERE c.id = %d", $id ), ARRAY_A );
	}

	/**
	 * Crea un contacto con datos ya validados y con company_id resuelto.
	 *
	 * @param array $data Datos (ver prepare()).
	 * @return int ID o 0 si falla.
	 */
	public static function create( $data ) {
		global $wpdb;

		$row               = self::row( $data );
		$row['created_at'] = Suiteka_Basic_CRM::now();
		$row['updated_at'] = $row['created_at'];

		if ( ! $wpdb->insert( Suiteka_Basic_CRM::table( 'contacts' ), $row, self::formats( $row ) ) ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		if ( ! empty( $data['tags'] ) ) {
			Suiteka_Basic_CRM_Tags::set_for_object( 'contact', $id, $data['tags'] );
		}

		Suiteka_Basic_CRM_Activities::log( 'created', $id, 0 );

		return $id;
	}

	/**
	 * Actualiza un contacto con datos ya validados.
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
			if ( 'full_name' !== $key && (string) $current[ $key ] !== (string) $value ) {
				$changed[] = $key;
			}
		}

		if ( isset( $data['tags'] ) && Suiteka_Basic_CRM_Tags::set_for_object( 'contact', $id, $data['tags'] ) ) {
			$changed[] = 'tags';
		}

		if ( empty( $changed ) ) {
			return true;
		}

		$row['updated_at'] = Suiteka_Basic_CRM::now();

		if ( false === $wpdb->update( Suiteka_Basic_CRM::table( 'contacts' ), $row, array( 'id' => $id ), self::formats( $row ), array( '%d' ) ) ) {
			return false;
		}

		Suiteka_Basic_CRM_Activities::log( 'updated', $id, 0, array( 'fields' => $changed ) );

		return true;
	}

	/**
	 * Borra un contacto, sus etiquetas y las actividades que solo le pertenecen.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		if ( ! $wpdb->delete( Suiteka_Basic_CRM::table( 'contacts' ), array( 'id' => $id ), array( '%d' ) ) ) {
			return false;
		}

		Suiteka_Basic_CRM_Tags::delete_for_object( 'contact', $id );
		Suiteka_Basic_CRM_Activities::detach_contact( $id );

		return true;
	}

	/**
	 * Lista paginada con búsqueda, filtros y ordenación.
	 *
	 * @param array $args {
	 *     Argumentos de la consulta.
	 *
	 *     @type string   $search     Texto en nombre, correo, teléfono o empresa.
	 *     @type string   $status     Clave de estado ('' = todos, '-' = sin clasificar).
	 *     @type string   $source     Clave de origen ('' = todos, '-' = sin especificar).
	 *     @type int      $company_id Empresa (0 = todas).
	 *     @type int      $tag_id     Etiqueta (0 = todas).
	 *     @type int|null $owner      Responsable concreto (null = todos, 0 = sin responsable).
	 *     @type int|null $scope      Ámbito de visibilidad (Capabilities::scope()).
	 *     @type string   $orderby    name|email|company|status|created|updated.
	 *     @type string   $order      ASC|DESC.
	 *     @type int      $paged      Página (desde 1; se ajusta al máximo).
	 *     @type int      $per_page   Elementos por página.
	 * }
	 * @return array { items: array[], total: int, paged: int }
	 */
	public static function query( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'status'     => '',
				'source'     => '',
				'company_id' => 0,
				'tag_id'     => 0,
				'owner'      => null,
				'scope'      => null,
				'orderby'    => 'created',
				'order'      => 'DESC',
				'paged'      => 1,
				'per_page'   => self::PER_PAGE,
			)
		);

		$table         = Suiteka_Basic_CRM::table( 'contacts' );
		$companies     = Suiteka_Basic_CRM::table( 'companies' );
		$relationships = Suiteka_Basic_CRM::table( 'tag_relationships' );
		$where         = array( '1=1' );

		if ( null !== $args['scope'] ) {
			$where[] = $wpdb->prepare( 'c.owner_id = %d AND c.owner_id <> 0', $args['scope'] );
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(c.full_name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR co.name LIKE %s)', $like, $like, $like, $like );
		}
		if ( '' !== $args['status'] ) {
			$where[] = $wpdb->prepare( 'c.status = %s', '-' === $args['status'] ? '' : $args['status'] );
		}
		if ( '' !== $args['source'] ) {
			$where[] = $wpdb->prepare( 'c.source = %s', '-' === $args['source'] ? '' : $args['source'] );
		}
		if ( $args['company_id'] ) {
			$where[] = $wpdb->prepare( 'c.company_id = %d', $args['company_id'] );
		}
		if ( $args['tag_id'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
			$where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$relationships} r WHERE r.object_type = 'contact' AND r.object_id = c.id AND r.tag_id = %d)", $args['tag_id'] );
		}
		if ( null !== $args['owner'] ) {
			$where[] = $wpdb->prepare( 'c.owner_id = %d', $args['owner'] );
		}

		$where_sql = implode( ' AND ', $where );

		$orderby_map = array(
			'name'    => 'c.full_name',
			'email'   => 'c.email',
			'company' => 'co.name',
			'status'  => 'c.status',
			'created' => 'c.created_at',
			'updated' => 'c.updated_at',
		);
		$orderby     = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'c.created_at';
		$order       = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page    = max( 1, (int) $args['per_page'] );
		$from        = "{$table} c LEFT JOIN {$companies} co ON co.id = c.company_id";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Nombres de tabla internos; las condiciones ya están preparadas y el orden sale de una lista blanca.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}" );

		$max_page = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( max( 1, (int) $args['paged'] ), $max_page );
		$items    = array();

		if ( $total > 0 ) {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.full_name, c.email, c.phone, c.job_title, c.company_id, c.status, c.source, c.owner_id, c.created_at, c.updated_at, co.name AS company_name, co.owner_id AS company_owner_id FROM {$from} WHERE {$where_sql} ORDER BY {$orderby} {$order}, c.id {$order} LIMIT %d OFFSET %d",
					$per_page,
					( $paged - 1 ) * $per_page
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$items = is_array( $items ) ? $items : array();
		$tags  = Suiteka_Basic_CRM_Tags::get_for_objects( 'contact', wp_list_pluck( $items, 'id' ) );

		foreach ( $items as $i => $item ) {
			$items[ $i ]['tags'] = isset( $tags[ (int) $item['id'] ] ) ? $tags[ (int) $item['id'] ] : array();
		}

		return array(
			'items' => $items,
			'total' => $total,
			'paged' => $paged,
		);
	}

	/**
	 * Número de contactos visibles.
	 *
	 * @param int|null $scope Ámbito de visibilidad.
	 * @return int
	 */
	public static function count( $scope = null ) {
		$result = self::query(
			array(
				'scope'    => $scope,
				'per_page' => 1,
			)
		);

		return $result['total'];
	}

	/**
	 * Fila lista para la base de datos, con full_name derivado.
	 *
	 * @param array $data Datos validados.
	 * @return array
	 */
	private static function row( $data ) {
		$row = array();
		foreach ( self::$columns as $column ) {
			$row[ $column ] = isset( $data[ $column ] ) ? $data[ $column ] : '';
		}
		$row['company_id'] = (int) $row['company_id'];
		$row['owner_id']   = (int) $row['owner_id'];
		$row['full_name']  = self::full_name( $row['first_name'], $row['last_name'] );

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
			$formats[] = in_array( $key, array( 'company_id', 'owner_id' ), true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Limpia una lista de opciones filtrada por terceros.
	 *
	 * @param mixed $choices Clave => etiqueta.
	 * @return string[]
	 */
	private static function clean_choices( $choices ) {
		$clean = array();
		foreach ( (array) $choices as $key => $label ) {
			$key = sanitize_key( $key );
			if ( '' !== $key && strlen( $key ) <= 32 && is_string( $label ) ) {
				$clean[ $key ] = $label;
			}
		}

		return $clean;
	}
}
