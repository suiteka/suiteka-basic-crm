<?php
/**
 * Pantallas y envíos de contactos.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Listado, ficha, formulario y borrado de contactos.
 */
final class Suiteka_Basic_CRM_Admin_Contacts {

	/**
	 * Contacto de la pantalla actual (ficha, edición o borrado).
	 *
	 * @var array|null
	 */
	private static $contact = null;

	/**
	 * Errores de validación por campo.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Valores escritos, para volver a mostrarlos si hay errores.
	 *
	 * @var string[]|null
	 */
	private static $values = null;

	/**
	 * Posibles duplicados detectados al guardar.
	 *
	 * @var array|null
	 */
	private static $duplicates = null;

	/**
	 * IDs de los controles del formulario.
	 *
	 * @var string[]
	 */
	private static $ids = array(
		'first_name'   => 'suiteka-crm-first-name',
		'last_name'    => 'suiteka-crm-last-name',
		'email'        => 'suiteka-crm-email',
		'phone'        => 'suiteka-crm-phone',
		'job_title'    => 'suiteka-crm-job-title',
		'company_name' => 'suiteka-crm-company',
		'status'       => 'suiteka-crm-status',
		'source'       => 'suiteka-crm-source',
		'tags'         => 'suiteka-crm-tags',
		'owner_id'     => 'suiteka-crm-owner',
		'notes'        => 'suiteka-crm-notes',
	);

	/**
	 * Comprueba permisos y procesa envíos antes de imprimir la pantalla.
	 */
	public static function load() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';

		Suiteka_Basic_CRM_Admin::require_cap( $caps::VIEW );

		$action = Suiteka_Basic_CRM_Admin::get_key( 'action' );
		$id     = Suiteka_Basic_CRM_Admin::get_int( 'id' );

		if ( in_array( $action, array( 'view', 'edit', 'delete' ), true ) && $id ) {
			self::$contact = Suiteka_Basic_CRM_Contacts::get( $id );

			if ( self::$contact ) {
				$required = array(
					'view'   => $caps::VIEW_RECORD,
					'edit'   => $caps::EDIT_RECORD,
					'delete' => $caps::DELETE_RECORD,
				);
				Suiteka_Basic_CRM_Admin::require_cap( $required[ $action ], self::$contact['owner_id'] );
			}
		}

		if ( ! Suiteka_Basic_CRM_Admin::is_post() ) {
			return;
		}

		switch ( Suiteka_Basic_CRM_Admin::operation() ) {
			case 'save':
				self::handle_save();
				break;
			case 'delete':
				self::handle_delete();
				break;
			case 'add_activity':
				Suiteka_Basic_CRM_Admin_Activities::handle_add( 'contact' );
				break;
			case 'delete_activity':
				Suiteka_Basic_CRM_Admin_Activities::handle_delete( 'contact' );
				break;
		}
	}

	/**
	 * Crea o actualiza un contacto.
	 */
	private static function handle_save() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';
		$id   = Suiteka_Basic_CRM_Admin::post_id( 'contact_id' );

		check_admin_referer( 'suiteka_crm_save_contact_' . $id );

		$current = null;
		if ( $id ) {
			$current = Suiteka_Basic_CRM_Contacts::get( $id );
			if ( ! $current ) {
				Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::CONTACTS, array( 'message' => 'not_found' ) );
			}
			Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT_RECORD, $current['owner_id'] );
		} else {
			Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT );
		}

		$post     = Suiteka_Basic_CRM_Admin::post_data();
		$prepared = Suiteka_Basic_CRM_Contacts::prepare( $post );
		$data     = $prepared['data'];
		$errors   = $prepared['errors'];
		$scope    = $caps::scope();

		// Sin edit_others no se elige responsable: el propio usuario al crear, el actual al editar.
		// Si no se envía el campo (p. ej. un formulario antiguo), el responsable no cambia.
		if ( ! current_user_can( $caps::EDIT_OTHERS ) || ! isset( $post['owner_id'] ) ) {
			$data['owner_id'] = $current ? (int) $current['owner_id'] : get_current_user_id();
			unset( $errors['owner_id'] );
		}

		$company = array(
			'id'     => 0,
			'create' => false,
		);
		if ( ! isset( $errors['company_name'] ) ) {
			$company = Suiteka_Basic_CRM_Contacts::resolve_company( $data['company_name'], $scope );

			// Se permite conservar una empresa ya enlazada aunque no sea visible para el usuario.
			if ( '' !== $company['error'] && $current && (int) $current['company_id'] && 0 === strcasecmp( (string) $current['company_name'], $data['company_name'] ) ) {
				$company = array(
					'id'     => (int) $current['company_id'],
					'create' => false,
					'error'  => '',
				);
			}

			if ( '' !== $company['error'] ) {
				$errors['company_name'] = $company['error'];
			}
		}

		self::$values = $prepared['values'];

		if ( ! empty( $errors ) ) {
			self::$errors = $errors;
			return;
		}

		// Duplicados: al crear, o al editar si cambian nombre, correo o teléfono.
		$confirmed = isset( $post['confirm_duplicates'] ) && '1' === $post['confirm_duplicates'];
		if ( ! $confirmed && self::identity_changed( $current, $data ) ) {
			$duplicates = Suiteka_Basic_CRM_Duplicates::find_for( $data, $id, $scope );
			if ( ! empty( $duplicates['visible'] ) || $duplicates['hidden'] > 0 ) {
				self::$duplicates = $duplicates;
				return;
			}
		}

		$company_created = false;
		if ( $company['create'] ) {
			$data['company_id'] = Suiteka_Basic_CRM_Companies::create(
				array(
					'name'     => $data['company_name'],
					'owner_id' => $data['owner_id'],
				)
			);
			if ( ! $data['company_id'] ) {
				self::$errors = array( 'company_name' => __( 'No se pudo crear la empresa. Inténtalo de nuevo.', 'suiteka-basic-crm' ) );
				return;
			}
			$company_created = true;
		} else {
			$data['company_id'] = $company['id'];
		}

		if ( $id ) {
			$saved   = Suiteka_Basic_CRM_Contacts::update( $id, $data );
			$message = 'updated';
		} else {
			$id      = Suiteka_Basic_CRM_Contacts::create( $data );
			$saved   = (bool) $id;
			$message = 'created';
		}

		if ( ! $saved ) {
			self::$errors = array( '_form' => __( 'No se pudo guardar el contacto. Inténtalo de nuevo.', 'suiteka-basic-crm' ) );
			return;
		}

		Suiteka_Basic_CRM_Admin::redirect(
			Suiteka_Basic_CRM_Admin::CONTACTS,
			array(
				'action'  => 'view',
				'id'      => $id,
				'message' => $company_created ? $message . '_company' : $message,
			)
		);
	}

	/**
	 * Indica si cambian los datos que identifican al contacto.
	 *
	 * @param array|null $current Contacto actual o null si es nuevo.
	 * @param array      $data    Datos nuevos.
	 * @return bool
	 */
	private static function identity_changed( $current, $data ) {
		if ( ! $current ) {
			return true;
		}

		return 0 !== strcasecmp( $current['full_name'], Suiteka_Basic_CRM_Contacts::full_name( $data['first_name'], $data['last_name'] ) )
			|| 0 !== strcasecmp( $current['email'], $data['email'] )
			|| Suiteka_Basic_CRM_Duplicates::phone_digits( $current['phone'] ) !== Suiteka_Basic_CRM_Duplicates::phone_digits( $data['phone'] );
	}

	/**
	 * Elimina un contacto tras la confirmación enviada por POST.
	 */
	private static function handle_delete() {
		$id = Suiteka_Basic_CRM_Admin::post_id( 'contact_id' );
		check_admin_referer( 'suiteka_crm_delete_contact_' . $id );

		$contact = $id ? Suiteka_Basic_CRM_Contacts::get( $id ) : null;
		if ( ! $contact ) {
			Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::CONTACTS, array( 'message' => 'not_found' ) );
		}

		Suiteka_Basic_CRM_Admin::require_cap( Suiteka_Basic_CRM_Capabilities::DELETE_RECORD, $contact['owner_id'] );

		$deleted = Suiteka_Basic_CRM_Contacts::delete( $id );

		Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::CONTACTS, array( 'message' => $deleted ? 'deleted' : 'delete_error' ) );
	}

	/**
	 * Avisos de la URL.
	 *
	 * @return array
	 */
	private static function messages() {
		return array_merge(
			array(
				'created'         => array( 'success', __( 'Contacto creado correctamente.', 'suiteka-basic-crm' ) ),
				'created_company' => array( 'success', __( 'Contacto creado correctamente. También se ha creado su empresa.', 'suiteka-basic-crm' ) ),
				'updated'         => array( 'success', __( 'Contacto actualizado correctamente.', 'suiteka-basic-crm' ) ),
				'updated_company' => array( 'success', __( 'Contacto actualizado correctamente. También se ha creado su empresa.', 'suiteka-basic-crm' ) ),
				'deleted'         => array( 'success', __( 'Contacto eliminado correctamente.', 'suiteka-basic-crm' ) ),
				'merged'          => array( 'success', __( 'Contactos combinados correctamente.', 'suiteka-basic-crm' ) ),
				'not_found'       => array( 'error', __( 'El contacto no existe o ya fue eliminado.', 'suiteka-basic-crm' ) ),
				'delete_error'    => array( 'error', __( 'No se pudo eliminar el contacto. Inténtalo de nuevo.', 'suiteka-basic-crm' ) ),
			),
			Suiteka_Basic_CRM_Admin_Activities::messages()
		);
	}

	/**
	 * Pantalla «Contactos»: listado, ficha, edición o confirmación de borrado.
	 */
	public static function render_main() {
		$action = Suiteka_Basic_CRM_Admin::get_key( 'action' );

		if ( in_array( $action, array( 'view', 'edit', 'delete' ), true ) ) {
			if ( ! self::$contact ) {
				Suiteka_Basic_CRM_Admin::render_not_found( __( 'Contactos', 'suiteka-basic-crm' ), __( 'El contacto no existe o ya fue eliminado.', 'suiteka-basic-crm' ), Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACTS ) );
				return;
			}

			if ( 'view' === $action ) {
				self::render_view( self::$contact );
			} elseif ( 'edit' === $action ) {
				self::render_form( self::$contact );
			} else {
				self::render_delete_confirmation( self::$contact );
			}
			return;
		}

		self::render_list();
	}

	/**
	 * Pantalla «Añadir contacto».
	 */
	public static function render_new() {
		self::render_form( null );
	}

	/**
	 * Filtros y orden del listado leídos de la URL.
	 *
	 * @return array
	 */
	private static function list_args() {
		$owner = Suiteka_Basic_CRM_Admin::get_text( 'owner' );

		$args = array(
			's'          => Suiteka_Basic_CRM_Admin::get_text( 's' ),
			'status'     => Suiteka_Basic_CRM_Admin::get_text( 'status' ),
			'source'     => Suiteka_Basic_CRM_Admin::get_text( 'source' ),
			'company_id' => Suiteka_Basic_CRM_Admin::get_int( 'company_id' ),
			'tag_id'     => Suiteka_Basic_CRM_Admin::get_int( 'tag_id' ),
			'owner'      => ctype_digit( $owner ) && current_user_can( Suiteka_Basic_CRM_Capabilities::VIEW_OTHERS ) ? $owner : '',
			'orderby'    => Suiteka_Basic_CRM_Admin::get_key( 'orderby' ),
			'order'      => Suiteka_Basic_CRM_Admin::get_key( 'order' ),
		);

		if ( ! in_array( $args['status'], array_merge( array( '', '-' ), array_keys( Suiteka_Basic_CRM_Contacts::statuses() ) ), true ) ) {
			$args['status'] = '';
		}
		if ( ! in_array( $args['source'], array_merge( array( '', '-' ), array_keys( Suiteka_Basic_CRM_Contacts::sources() ) ), true ) ) {
			$args['source'] = '';
		}
		if ( ! in_array( $args['orderby'], array( 'name', 'email', 'company', 'status', 'created', 'updated' ), true ) ) {
			$args['orderby'] = 'created';
			$args['order']   = 'desc';
		}
		$args['order'] = 'asc' === $args['order'] ? 'asc' : 'desc';

		// Solo se conservan en las URL los parámetros con valor.
		return array_filter(
			$args,
			function ( $value ) {
				return '' !== $value && 0 !== $value;
			}
		);
	}

	/**
	 * Listado de contactos con búsqueda, filtros, ordenación y paginación.
	 */
	private static function render_list() {
		$caps     = 'Suiteka_Basic_CRM_Capabilities';
		$page     = Suiteka_Basic_CRM_Admin::CONTACTS;
		$args     = self::list_args();
		$scope    = $caps::scope();
		$filtered = count( array_diff_key( $args, array_flip( array( 'orderby', 'order' ) ) ) ) > 0;
		$get      = function ( $key, $fallback = '' ) use ( $args ) {
			return isset( $args[ $key ] ) ? $args[ $key ] : $fallback;
		};

		$result = Suiteka_Basic_CRM_Contacts::query(
			array(
				'search'     => $get( 's' ),
				'status'     => $get( 'status' ),
				'source'     => $get( 'source' ),
				'company_id' => (int) $get( 'company_id', 0 ),
				'tag_id'     => (int) $get( 'tag_id', 0 ),
				'owner'      => '' === $get( 'owner' ) ? null : (int) $get( 'owner' ),
				'scope'      => $scope,
				'orderby'    => $get( 'orderby' ),
				'order'      => $get( 'order' ),
				'paged'      => max( 1, Suiteka_Basic_CRM_Admin::get_int( 'paged' ) ),
			)
		);

		$total    = $result['total'];
		$statuses = Suiteka_Basic_CRM_Contacts::statuses();
		$sources  = Suiteka_Basic_CRM_Contacts::sources();
		$tags     = Suiteka_Basic_CRM_Tags::in_use( 'contact', $scope );
		$company  = $get( 'company_id' ) ? Suiteka_Basic_CRM_Companies::get( (int) $get( 'company_id' ) ) : null;
		$new_url  = Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACT_NEW );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Contactos', 'suiteka-basic-crm' ); ?></h1>
			<?php if ( current_user_can( $caps::EDIT ) ) : ?>
				<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir contacto', 'suiteka-basic-crm' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php Suiteka_Basic_CRM_Admin::render_url_notice( self::messages() ); ?>

			<?php if ( 0 === $total && ! $filtered ) : ?>
				<div class="suiteka-crm-panel">
					<h2><?php esc_html_e( 'Todavía no tienes contactos', 'suiteka-basic-crm' ); ?></h2>
					<p><?php esc_html_e( 'Guarda aquí a tus clientes y personas de contacto: nombre, empresa, correo electrónico, teléfono, estado, etiquetas y notas. Después podrás buscarlos, filtrarlos y ver su historial.', 'suiteka-basic-crm' ); ?></p>
					<?php if ( current_user_can( $caps::EDIT ) ) : ?>
						<p><a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary"><?php esc_html_e( 'Añadir el primer contacto', 'suiteka-basic-crm' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="suiteka-crm-filters" role="search" aria-label="<?php esc_attr_e( 'Buscar y filtrar contactos', 'suiteka-basic-crm' ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>">
					<?php if ( $get( 'company_id' ) ) : ?>
						<input type="hidden" name="company_id" value="<?php echo esc_attr( $get( 'company_id' ) ); ?>">
					<?php endif; ?>
					<?php if ( $get( 'orderby' ) ) : ?>
						<input type="hidden" name="orderby" value="<?php echo esc_attr( $get( 'orderby' ) ); ?>">
						<input type="hidden" name="order" value="<?php echo esc_attr( $get( 'order' ) ); ?>">
					<?php endif; ?>

					<span class="suiteka-crm-filter">
						<label for="suiteka-crm-search-input"><?php esc_html_e( 'Buscar', 'suiteka-basic-crm' ); ?></label>
						<input type="search" id="suiteka-crm-search-input" name="s" value="<?php echo esc_attr( $get( 's' ) ); ?>" aria-describedby="suiteka-crm-search-hint">
						<span class="screen-reader-text" id="suiteka-crm-search-hint"><?php esc_html_e( 'Busca por nombre, correo electrónico, teléfono o empresa.', 'suiteka-basic-crm' ); ?></span>
					</span>
					<span class="suiteka-crm-filter">
						<label for="suiteka-crm-filter-status"><?php esc_html_e( 'Estado', 'suiteka-basic-crm' ); ?></label>
						<?php Suiteka_Basic_CRM_Admin::select( 'status', 'suiteka-crm-filter-status', array_merge( array( '' => __( 'Todos', 'suiteka-basic-crm' ) ), $statuses, array( '-' => __( 'Sin clasificar', 'suiteka-basic-crm' ) ) ), $get( 'status' ) ); ?>
					</span>
					<span class="suiteka-crm-filter">
						<label for="suiteka-crm-filter-source"><?php esc_html_e( 'Origen', 'suiteka-basic-crm' ); ?></label>
						<?php Suiteka_Basic_CRM_Admin::select( 'source', 'suiteka-crm-filter-source', array_merge( array( '' => __( 'Todos', 'suiteka-basic-crm' ) ), $sources, array( '-' => __( 'Sin especificar', 'suiteka-basic-crm' ) ) ), $get( 'source' ) ); ?>
					</span>
					<?php if ( $tags ) : ?>
						<span class="suiteka-crm-filter">
							<label for="suiteka-crm-filter-tag"><?php esc_html_e( 'Etiqueta', 'suiteka-basic-crm' ); ?></label>
							<?php Suiteka_Basic_CRM_Admin::select( 'tag_id', 'suiteka-crm-filter-tag', array( '' => __( 'Todas', 'suiteka-basic-crm' ) ) + $tags, $get( 'tag_id' ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( current_user_can( $caps::VIEW_OTHERS ) ) : ?>
						<span class="suiteka-crm-filter">
							<label for="suiteka-crm-filter-owner"><?php esc_html_e( 'Responsable', 'suiteka-basic-crm' ); ?></label>
							<?php Suiteka_Basic_CRM_Admin::select( 'owner', 'suiteka-crm-filter-owner', array( '' => __( 'Todos', 'suiteka-basic-crm' ) ) + Suiteka_Basic_CRM_Admin::owner_options(), $get( 'owner' ) ); ?>
						</span>
					<?php endif; ?>
					<span class="suiteka-crm-filter suiteka-crm-filter-submit">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Filtrar', 'suiteka-basic-crm' ); ?>">
					</span>
				</form>

				<?php if ( $filtered ) : ?>
					<p class="suiteka-crm-results" role="status">
						<?php
						if ( $company ) {
							/* translators: 1: nombre de la empresa, 2: número de contactos. */
							echo esc_html( sprintf( _n( 'Contactos de «%1$s»: %2$s resultado.', 'Contactos de «%1$s»: %2$s resultados.', $total, 'suiteka-basic-crm' ), $company['name'], number_format_i18n( $total ) ) );
						} else {
							/* translators: %s: número de contactos. */
							echo esc_html( sprintf( _n( '%s contacto coincide con la búsqueda.', '%s contactos coinciden con la búsqueda.', $total, 'suiteka-basic-crm' ), number_format_i18n( $total ) ) );
						}
						?>
						<a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( $page ) ); ?>"><?php esc_html_e( 'Quitar filtros', 'suiteka-basic-crm' ); ?></a>
					</p>
				<?php endif; ?>

				<?php if ( 0 === $total ) : ?>
					<div class="suiteka-crm-panel">
						<p><?php esc_html_e( 'No se encontraron contactos que coincidan con la búsqueda.', 'suiteka-basic-crm' ); ?></p>
					</div>
				<?php else : ?>
					<div class="suiteka-crm-table-wrap" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Tabla de contactos', 'suiteka-basic-crm' ); ?>">
						<table class="widefat striped suiteka-crm-table">
							<caption class="screen-reader-text"><?php esc_html_e( 'Contactos', 'suiteka-basic-crm' ); ?></caption>
							<thead>
								<tr>
									<?php
									Suiteka_Basic_CRM_Admin::sortable_header( __( 'Nombre', 'suiteka-basic-crm' ), 'name', $page, $args );
									Suiteka_Basic_CRM_Admin::sortable_header( __( 'Correo electrónico', 'suiteka-basic-crm' ), 'email', $page, $args );
									?>
									<th scope="col"><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></th>
									<?php
									Suiteka_Basic_CRM_Admin::sortable_header( __( 'Empresa', 'suiteka-basic-crm' ), 'company', $page, $args );
									Suiteka_Basic_CRM_Admin::sortable_header( __( 'Estado', 'suiteka-basic-crm' ), 'status', $page, $args );
									?>
									<th scope="col"><?php esc_html_e( 'Etiquetas', 'suiteka-basic-crm' ); ?></th>
									<?php if ( current_user_can( $caps::VIEW_OTHERS ) ) : ?>
										<th scope="col"><?php esc_html_e( 'Responsable', 'suiteka-basic-crm' ); ?></th>
									<?php endif; ?>
									<?php Suiteka_Basic_CRM_Admin::sortable_header( __( 'Actualizado', 'suiteka-basic-crm' ), 'updated', $page, $args, 'desc' ); ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result['items'] as $item ) : ?>
									<?php
									$item_id  = (int) $item['id'];
									$view_url = Suiteka_Basic_CRM_Admin::url(
										$page,
										array(
											'action' => 'view',
											'id'     => $item_id,
										)
									);
									?>
									<tr>
										<td>
											<strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $item['full_name'] ); ?></a></strong>
											<?php if ( '' !== $item['job_title'] ) : ?>
												<br><span class="description"><?php echo esc_html( $item['job_title'] ); ?></span>
											<?php endif; ?>
											<div class="row-actions visible">
												<span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Ver', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['full_name'] ); ?></span></a></span>
												<?php if ( current_user_can( $caps::EDIT_RECORD, (int) $item['owner_id'] ) ) : ?>
													| <span class="edit"><a href="
													<?php
													echo esc_url(
														Suiteka_Basic_CRM_Admin::url(
															$page,
															array(
																'action' => 'edit',
																'id' => $item_id,
															)
														)
													);
													?>
																					"><?php esc_html_e( 'Editar', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['full_name'] ); ?></span></a></span>
												<?php endif; ?>
												<?php if ( current_user_can( $caps::DELETE_RECORD, (int) $item['owner_id'] ) ) : ?>
													| <span class="trash"><a href="
													<?php
													echo esc_url(
														Suiteka_Basic_CRM_Admin::url(
															$page,
															array(
																'action' => 'delete',
																'id' => $item_id,
															)
														)
													);
													?>
																					" class="submitdelete"><?php esc_html_e( 'Eliminar', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['full_name'] ); ?></span></a></span>
												<?php endif; ?>
											</div>
										</td>
										<td>
											<?php if ( '' !== $item['email'] ) : ?>
												<a href="<?php echo esc_url( 'mailto:' . $item['email'] ); ?>"><?php echo esc_html( $item['email'] ); ?></a>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $item['phone'] ); ?></td>
										<td><?php self::render_company_link( $item ); ?></td>
										<td><?php echo esc_html( isset( $statuses[ $item['status'] ] ) ? $statuses[ $item['status'] ] : '' ); ?></td>
										<td><?php echo esc_html( implode( ', ', $item['tags'] ) ); ?></td>
										<?php if ( current_user_can( $caps::VIEW_OTHERS ) ) : ?>
											<td><?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $item['owner_id'] ) ); ?></td>
										<?php endif; ?>
										<td><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $item['updated_at'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php Suiteka_Basic_CRM_Admin::pagination( $page, $args, $result['paged'], $total, Suiteka_Basic_CRM_Contacts::PER_PAGE ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Nombre de la empresa, enlazado solo si el usuario puede verla.
	 *
	 * @param array $contact Contacto con company_id, company_name y company_owner_id.
	 */
	private static function render_company_link( $contact ) {
		if ( empty( $contact['company_id'] ) || null === $contact['company_name'] ) {
			return;
		}

		if ( current_user_can( Suiteka_Basic_CRM_Capabilities::VIEW_RECORD, (int) $contact['company_owner_id'] ) ) {
			$url = Suiteka_Basic_CRM_Admin::url(
				Suiteka_Basic_CRM_Admin::COMPANIES,
				array(
					'action' => 'view',
					'id'     => (int) $contact['company_id'],
				)
			);
			echo '<a href="' . esc_url( $url ) . '">' . esc_html( $contact['company_name'] ) . '</a>';
		} else {
			echo esc_html( $contact['company_name'] );
		}
	}

	/**
	 * Ficha del contacto con su historial.
	 *
	 * @param array $contact Contacto.
	 */
	private static function render_view( $contact ) {
		$caps     = 'Suiteka_Basic_CRM_Capabilities';
		$id       = (int) $contact['id'];
		$page     = Suiteka_Basic_CRM_Admin::CONTACTS;
		$statuses = Suiteka_Basic_CRM_Contacts::statuses();
		$sources  = Suiteka_Basic_CRM_Contacts::sources();
		$tags     = Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $id );
		$can_edit = current_user_can( $caps::EDIT_RECORD, (int) $contact['owner_id'] );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $contact['full_name'] ); ?></h1>
			<?php if ( $can_edit ) : ?>
				<a href="
				<?php
				echo esc_url(
					Suiteka_Basic_CRM_Admin::url(
						$page,
						array(
							'action' => 'edit',
							'id'     => $id,
						)
					)
				);
				?>
							" class="page-title-action"><?php esc_html_e( 'Editar', 'suiteka-basic-crm' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php Suiteka_Basic_CRM_Admin::render_url_notice( self::messages() ); ?>

			<p><a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( $page ) ); ?>">&larr; <?php esc_html_e( 'Volver a contactos', 'suiteka-basic-crm' ); ?></a></p>

			<div class="suiteka-crm-columns">
				<section class="suiteka-crm-panel" aria-labelledby="suiteka-crm-details-title">
					<h2 id="suiteka-crm-details-title"><?php esc_html_e( 'Datos del contacto', 'suiteka-basic-crm' ); ?></h2>
					<dl class="suiteka-crm-details">
						<dt><?php esc_html_e( 'Nombre', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( $contact['first_name'] ); ?></dd>
						<dt><?php esc_html_e( 'Apellidos', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( $contact['last_name'] ? $contact['last_name'] : '—' ); ?></dd>
						<dt><?php esc_html_e( 'Cargo', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( $contact['job_title'] ? $contact['job_title'] : '—' ); ?></dd>
						<dt><?php esc_html_e( 'Empresa', 'suiteka-basic-crm' ); ?></dt>
						<dd>
							<?php
							if ( $contact['company_id'] && null !== $contact['company_name'] ) {
								self::render_company_link( $contact );
							} else {
								echo '—';
							}
							?>
						</dd>
						<dt><?php esc_html_e( 'Correo electrónico', 'suiteka-basic-crm' ); ?></dt>
						<dd>
							<?php if ( $contact['email'] ) : ?>
								<a href="<?php echo esc_url( 'mailto:' . $contact['email'] ); ?>"><?php echo esc_html( $contact['email'] ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></dt>
						<dd>
							<?php if ( $contact['phone'] ) : ?>
								<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $contact['phone'] ) ); ?>"><?php echo esc_html( $contact['phone'] ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Estado', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( isset( $statuses[ $contact['status'] ] ) ? $statuses[ $contact['status'] ] : __( 'Sin clasificar', 'suiteka-basic-crm' ) ); ?></dd>
						<dt><?php esc_html_e( 'Origen', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( isset( $sources[ $contact['source'] ] ) ? $sources[ $contact['source'] ] : __( 'Sin especificar', 'suiteka-basic-crm' ) ); ?></dd>
						<dt><?php esc_html_e( 'Etiquetas', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( $tags ? implode( ', ', $tags ) : '—' ); ?></dd>
						<dt><?php esc_html_e( 'Responsable', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $contact['owner_id'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Creado', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $contact['created_at'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Actualizado', 'suiteka-basic-crm' ); ?></dt>
						<dd><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $contact['updated_at'] ) ); ?></dd>
					</dl>

					<?php if ( '' !== $contact['notes'] ) : ?>
						<h3><?php esc_html_e( 'Notas', 'suiteka-basic-crm' ); ?></h3>
						<div class="suiteka-crm-notes"><?php echo nl2br( esc_html( $contact['notes'] ) ); ?></div>
					<?php endif; ?>

					<?php if ( current_user_can( $caps::DELETE_RECORD, (int) $contact['owner_id'] ) ) : ?>
						<p class="suiteka-crm-danger-link"><a href="
						<?php
						echo esc_url(
							Suiteka_Basic_CRM_Admin::url(
								$page,
								array(
									'action' => 'delete',
									'id'     => $id,
								)
							)
						);
						?>
																	" class="submitdelete"><?php esc_html_e( 'Eliminar contacto', 'suiteka-basic-crm' ); ?></a></p>
					<?php endif; ?>
				</section>

				<div class="suiteka-crm-panel">
					<?php Suiteka_Basic_CRM_Admin_Activities::render( 'contact', $id, Suiteka_Basic_CRM_Activities::for_contact( $id ), $can_edit ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Formulario de creación o edición.
	 *
	 * @param array|null $contact Contacto existente o null para uno nuevo.
	 */
	private static function render_form( $contact ) {
		$caps   = 'Suiteka_Basic_CRM_Capabilities';
		$id     = $contact ? (int) $contact['id'] : 0;
		$ids    = self::$ids;
		$errors = self::$errors;
		$labels = Suiteka_Basic_CRM_Contacts::labels();
		$max    = Suiteka_Basic_CRM_Contacts::$max_lengths;
		$values = array(
			'first_name'   => '',
			'last_name'    => '',
			'email'        => '',
			'phone'        => '',
			'job_title'    => '',
			'company_name' => '',
			'status'       => '',
			'source'       => '',
			'tags'         => '',
			'owner_id'     => (string) get_current_user_id(),
			'notes'        => '',
		);

		if ( null !== self::$values ) {
			$values = array_merge( $values, array_intersect_key( self::$values, $values ) );
		} elseif ( $contact ) {
			$values                 = array_merge( $values, array_intersect_key( $contact, $values ) );
			$values['company_name'] = (string) $contact['company_name'];
			$values['tags']         = implode( ', ', Suiteka_Basic_CRM_Tags::get_for_object( 'contact', $id ) );
		} else {
			// Nuevo contacto desde la ficha de una empresa visible.
			$company_id = Suiteka_Basic_CRM_Admin::get_int( 'company_id' );
			$company    = $company_id ? Suiteka_Basic_CRM_Companies::get( $company_id ) : null;
			if ( $company && current_user_can( $caps::VIEW_RECORD, (int) $company['owner_id'] ) ) {
				$values['company_name'] = $company['name'];
			}
		}

		if ( $id ) {
			$title      = __( 'Editar contacto', 'suiteka-basic-crm' );
			$action_url = Suiteka_Basic_CRM_Admin::url(
				Suiteka_Basic_CRM_Admin::CONTACTS,
				array(
					'action' => 'edit',
					'id'     => $id,
				)
			);
			$cancel_url = Suiteka_Basic_CRM_Admin::url(
				Suiteka_Basic_CRM_Admin::CONTACTS,
				array(
					'action' => 'view',
					'id'     => $id,
				)
			);
		} else {
			$title      = __( 'Añadir contacto', 'suiteka-basic-crm' );
			$action_url = Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACT_NEW );
			$cancel_url = Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACTS );
		}

		$text_field = function ( $key, $type = 'text', $required = false, $hint = '' ) use ( $ids, $errors, $labels, $max, $values ) {
			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $ids[ $key ] ); ?>"><?php echo esc_html( $labels[ $key ] ); ?><?php echo $required ? ' <span aria-hidden="true">*</span>' : ''; ?></label>
				</th>
				<td>
					<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $ids[ $key ] ); ?>" name="<?php echo esc_attr( $key ); ?>" class="regular-text" maxlength="<?php echo esc_attr( $max[ $key ] ); ?>" value="<?php echo esc_attr( $values[ $key ] ); ?>"<?php echo $required ? ' required aria-required="true"' : ''; ?><?php echo 'company_name' === $key ? ' list="suiteka-crm-company-list" autocomplete="off"' : ''; ?><?php Suiteka_Basic_CRM_Admin::aria( $errors, $key, $ids[ $key ], $hint ? $ids[ $key ] . '-hint' : '' ); ?>>
					<?php if ( $hint ) : ?>
						<p class="description" id="<?php echo esc_attr( $ids[ $key ] . '-hint' ); ?>"><?php echo esc_html( $hint ); ?></p>
					<?php endif; ?>
					<?php Suiteka_Basic_CRM_Admin::field_error( $errors, $key, $ids[ $key ] ); ?>
				</td>
			</tr>
			<?php
		};

		$existing_tags = Suiteka_Basic_CRM_Tags::in_use( 'contact', $caps::scope() );
		$tags_hint     = __( 'Sepáralas con comas.', 'suiteka-basic-crm' );
		if ( $existing_tags ) {
			/* translators: %s: lista de etiquetas existentes. */
			$tags_hint .= ' ' . sprintf( __( 'En uso: %s.', 'suiteka-basic-crm' ), implode( ', ', array_slice( $existing_tags, 0, 30 ) ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php
			if ( isset( $errors['_form'] ) ) {
				echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $errors['_form'] ) . '</p></div>';
				unset( $errors['_form'] );
			}
			Suiteka_Basic_CRM_Admin::render_error_summary( $errors, $ids );
			?>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>" novalidate>
				<?php wp_nonce_field( 'suiteka_crm_save_contact_' . $id ); ?>
				<input type="hidden" name="suiteka_crm_operation" value="save">
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( $id ); ?>">

				<?php if ( null !== self::$duplicates ) : ?>
					<?php self::render_duplicates_warning( self::$duplicates ); ?>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<?php
					$text_field( 'first_name', 'text', true );
					$text_field( 'last_name' );
					$text_field( 'email', 'email' );
					$text_field( 'phone', 'tel' );
					$text_field( 'job_title' );
					$text_field( 'company_name', 'text', false, __( 'Escribe el nombre de una empresa existente o uno nuevo: si no existe, se creará al guardar.', 'suiteka-basic-crm' ) );
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $ids['status'] ); ?>"><?php esc_html_e( 'Estado', 'suiteka-basic-crm' ); ?></label></th>
						<td>
							<?php
							$aria = isset( $errors['status'] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $ids['status'] . '-error' ) . '"' : '';
							Suiteka_Basic_CRM_Admin::select( 'status', $ids['status'], array( '' => __( 'Sin clasificar', 'suiteka-basic-crm' ) ) + Suiteka_Basic_CRM_Contacts::statuses(), $values['status'], $aria );
							Suiteka_Basic_CRM_Admin::field_error( $errors, 'status', $ids['status'] );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $ids['source'] ); ?>"><?php esc_html_e( 'Origen', 'suiteka-basic-crm' ); ?></label></th>
						<td>
							<?php
							$aria = isset( $errors['source'] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $ids['source'] . '-error' ) . '"' : '';
							Suiteka_Basic_CRM_Admin::select( 'source', $ids['source'], array( '' => __( 'Sin especificar', 'suiteka-basic-crm' ) ) + Suiteka_Basic_CRM_Contacts::sources(), $values['source'], $aria );
							Suiteka_Basic_CRM_Admin::field_error( $errors, 'source', $ids['source'] );
							?>
						</td>
					</tr>
					<?php $text_field( 'tags', 'text', false, $tags_hint ); ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $ids['owner_id'] ); ?>"><?php esc_html_e( 'Responsable', 'suiteka-basic-crm' ); ?></label></th>
						<td>
							<?php if ( current_user_can( $caps::EDIT_OTHERS ) ) : ?>
								<?php
								$aria = isset( $errors['owner_id'] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $ids['owner_id'] . '-error' ) . '"' : '';
								Suiteka_Basic_CRM_Admin::select( 'owner_id', $ids['owner_id'], Suiteka_Basic_CRM_Admin::owner_options(), $values['owner_id'], $aria );
								Suiteka_Basic_CRM_Admin::field_error( $errors, 'owner_id', $ids['owner_id'] );
								?>
							<?php else : ?>
								<output id="<?php echo esc_attr( $ids['owner_id'] ); ?>"><?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $contact ? $contact['owner_id'] : get_current_user_id() ) ); ?></output>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $ids['notes'] ); ?>"><?php esc_html_e( 'Notas', 'suiteka-basic-crm' ); ?></label></th>
						<td>
							<textarea id="<?php echo esc_attr( $ids['notes'] ); ?>" name="notes" class="large-text" rows="6"<?php Suiteka_Basic_CRM_Admin::aria( $errors, 'notes', $ids['notes'] ); ?>><?php echo esc_textarea( $values['notes'] ); ?></textarea>
							<?php Suiteka_Basic_CRM_Admin::field_error( $errors, 'notes', $ids['notes'] ); ?>
						</td>
					</tr>
					<?php if ( $contact ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Creado', 'suiteka-basic-crm' ); ?></th>
							<td><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $contact['created_at'] ) ); ?></td>
						</tr>
					<?php endif; ?>
				</table>

				<datalist id="suiteka-crm-company-list">
					<?php foreach ( Suiteka_Basic_CRM_Companies::names( $caps::scope() ) as $name ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>"></option>
					<?php endforeach; ?>
				</datalist>

				<p><?php esc_html_e( 'Los campos marcados con * son obligatorios.', 'suiteka-basic-crm' ); ?></p>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php echo $id ? esc_attr__( 'Guardar cambios', 'suiteka-basic-crm' ) : esc_attr__( 'Crear contacto', 'suiteka-basic-crm' ); ?>">
					<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'suiteka-basic-crm' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Aviso de posibles duplicados con opción de guardar igualmente.
	 *
	 * @param array $duplicates { visible: array[], hidden: int }.
	 */
	private static function render_duplicates_warning( $duplicates ) {
		?>
		<div class="notice notice-warning suiteka-crm-duplicates-warning" role="alert">
			<p><strong><?php esc_html_e( 'Todavía no se ha guardado: puede que este contacto ya exista.', 'suiteka-basic-crm' ); ?></strong></p>
			<p><?php esc_html_e( 'Coincide el nombre, el correo electrónico o el teléfono con:', 'suiteka-basic-crm' ); ?></p>
			<ul>
				<?php foreach ( $duplicates['visible'] as $row ) : ?>
					<li>
						<a href="
						<?php
						echo esc_url(
							Suiteka_Basic_CRM_Admin::url(
								Suiteka_Basic_CRM_Admin::CONTACTS,
								array(
									'action' => 'view',
									'id'     => (int) $row['id'],
								)
							)
						);
						?>
									" target="_blank" rel="noopener"><?php echo esc_html( $row['full_name'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(se abre en una pestaña nueva)', 'suiteka-basic-crm' ); ?></span></a>
						<?php echo esc_html( implode( ' · ', array_filter( array( $row['email'], $row['phone'] ) ) ) ); ?>
					</li>
				<?php endforeach; ?>
				<?php if ( $duplicates['hidden'] > 0 ) : ?>
					<li>
						<?php
						/* translators: %d: número de contactos que el usuario no puede ver. */
						echo esc_html( sprintf( _n( '%d contacto al que no tienes acceso.', '%d contactos a los que no tienes acceso.', $duplicates['hidden'], 'suiteka-basic-crm' ), $duplicates['hidden'] ) );
						?>
					</li>
				<?php endif; ?>
			</ul>
			<p><?php esc_html_e( 'Revisa los datos y vuelve a pulsar «Guardar», o guárdalo igualmente si es otra persona. Más adelante podrás combinar duplicados desde «Posibles duplicados».', 'suiteka-basic-crm' ); ?></p>
			<p><button type="submit" name="confirm_duplicates" value="1" class="button"><?php esc_html_e( 'Guardar de todos modos', 'suiteka-basic-crm' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * Confirmación de borrado.
	 *
	 * @param array $contact Contacto.
	 */
	private static function render_delete_confirmation( $contact ) {
		$id = (int) $contact['id'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Eliminar contacto', 'suiteka-basic-crm' ); ?></h1>

			<div class="suiteka-crm-panel suiteka-crm-narrow">
				<p>
					<?php
					/* translators: %s: nombre del contacto. */
					echo esc_html( sprintf( __( '¿Seguro que quieres eliminar el contacto «%s»? También se eliminarán sus etiquetas y su historial. Esta acción no se puede deshacer.', 'suiteka-basic-crm' ), $contact['full_name'] ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACTS ) ); ?>">
					<?php wp_nonce_field( 'suiteka_crm_delete_contact_' . $id ); ?>
					<input type="hidden" name="suiteka_crm_operation" value="delete">
					<input type="hidden" name="contact_id" value="<?php echo esc_attr( $id ); ?>">
					<p>
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Sí, eliminar contacto', 'suiteka-basic-crm' ); ?>">
						<a href="
						<?php
						echo esc_url(
							Suiteka_Basic_CRM_Admin::url(
								Suiteka_Basic_CRM_Admin::CONTACTS,
								array(
									'action' => 'view',
									'id'     => $id,
								)
							)
						);
						?>
									" class="button"><?php esc_html_e( 'Cancelar', 'suiteka-basic-crm' ); ?></a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
