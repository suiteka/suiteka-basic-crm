<?php
/**
 * Pantallas y envíos de empresas.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Listado, ficha, formulario y borrado de empresas.
 */
final class Suiteka_Basic_CRM_Admin_Companies {

	/**
	 * Empresa de la pantalla actual.
	 *
	 * @var array|null
	 */
	private static $company = null;

	/**
	 * Errores de validación por campo.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Valores escritos.
	 *
	 * @var string[]|null
	 */
	private static $values = null;

	/**
	 * IDs de los controles del formulario.
	 *
	 * @var string[]
	 */
	private static $ids = array(
		'name'     => 'suiteka-crm-company-name',
		'website'  => 'suiteka-crm-company-website',
		'email'    => 'suiteka-crm-company-email',
		'phone'    => 'suiteka-crm-company-phone',
		'city'     => 'suiteka-crm-company-city',
		'owner_id' => 'suiteka-crm-company-owner',
		'notes'    => 'suiteka-crm-company-notes',
	);

	/**
	 * Comprueba permisos y procesa envíos antes de imprimir la pantalla.
	 */
	public static function load() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';

		Suiteka_Basic_CRM_Admin::require_cap( $caps::VIEW );

		$action = Suiteka_Basic_CRM_Admin::get_key( 'action' );
		$id     = Suiteka_Basic_CRM_Admin::get_int( 'id' );

		if ( 'new' === $action ) {
			Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT );
		} elseif ( in_array( $action, array( 'view', 'edit', 'delete' ), true ) && $id ) {
			self::$company = Suiteka_Basic_CRM_Companies::get( $id );

			if ( self::$company ) {
				$required = array(
					'view'   => $caps::VIEW_RECORD,
					'edit'   => $caps::EDIT_RECORD,
					'delete' => $caps::DELETE_RECORD,
				);
				Suiteka_Basic_CRM_Admin::require_cap( $required[ $action ], self::$company['owner_id'] );
			}
		}

		if ( ! Suiteka_Basic_CRM_Admin::is_post() ) {
			return;
		}

		switch ( Suiteka_Basic_CRM_Admin::operation() ) {
			case 'save_company':
				self::handle_save();
				break;
			case 'delete_company':
				self::handle_delete();
				break;
			case 'add_activity':
				Suiteka_Basic_CRM_Admin_Activities::handle_add( 'company' );
				break;
			case 'delete_activity':
				Suiteka_Basic_CRM_Admin_Activities::handle_delete( 'company' );
				break;
		}
	}

	/**
	 * Crea o actualiza una empresa.
	 */
	private static function handle_save() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';
		$id   = Suiteka_Basic_CRM_Admin::post_id( 'company_id' );

		check_admin_referer( 'suiteka_crm_save_company_' . $id );

		$current = null;
		if ( $id ) {
			$current = Suiteka_Basic_CRM_Companies::get( $id );
			if ( ! $current ) {
				Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::COMPANIES, array( 'message' => 'not_found' ) );
			}
			Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT_RECORD, $current['owner_id'] );
		} else {
			Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT );
		}

		$post     = Suiteka_Basic_CRM_Admin::post_data();
		$prepared = Suiteka_Basic_CRM_Companies::prepare( $post, $id );
		$data     = $prepared['data'];
		$errors   = $prepared['errors'];

		// Si no se envía el campo (p. ej. un formulario antiguo), el responsable no cambia.
		if ( ! current_user_can( $caps::EDIT_OTHERS ) || ! isset( $post['owner_id'] ) ) {
			$data['owner_id'] = $current ? (int) $current['owner_id'] : get_current_user_id();
			unset( $errors['owner_id'] );
		}

		self::$values = $prepared['values'];

		if ( ! empty( $errors ) ) {
			self::$errors = $errors;
			return;
		}

		if ( $id ) {
			$saved   = Suiteka_Basic_CRM_Companies::update( $id, $data );
			$message = 'updated';
		} else {
			$id      = Suiteka_Basic_CRM_Companies::create( $data );
			$saved   = (bool) $id;
			$message = 'created';
		}

		if ( ! $saved ) {
			self::$errors = array( '_form' => __( 'No se pudo guardar la empresa. Inténtalo de nuevo.', 'suiteka-basic-crm' ) );
			return;
		}

		Suiteka_Basic_CRM_Admin::redirect(
			Suiteka_Basic_CRM_Admin::COMPANIES,
			array(
				'action'  => 'view',
				'id'      => $id,
				'message' => $message,
			)
		);
	}

	/**
	 * Elimina una empresa tras la confirmación. Sus contactos se conservan.
	 *
	 * Si tiene contactos de otros responsables, hace falta suiteka_crm_edit_others,
	 * porque el borrado los desvincula.
	 */
	private static function handle_delete() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';
		$id   = Suiteka_Basic_CRM_Admin::post_id( 'company_id' );

		check_admin_referer( 'suiteka_crm_delete_company_' . $id );

		$company = $id ? Suiteka_Basic_CRM_Companies::get( $id ) : null;
		if ( ! $company ) {
			Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::COMPANIES, array( 'message' => 'not_found' ) );
		}

		Suiteka_Basic_CRM_Admin::require_cap( $caps::DELETE_RECORD, $company['owner_id'] );

		if ( ! current_user_can( $caps::EDIT_OTHERS ) && Suiteka_Basic_CRM_Companies::count_contacts_of_others( $id, get_current_user_id() ) > 0 ) {
			Suiteka_Basic_CRM_Admin::deny( __( 'Esta empresa tiene contactos de otros responsables. Pide a quien administra el CRM que la elimine.', 'suiteka-basic-crm' ) );
		}

		$deleted = Suiteka_Basic_CRM_Companies::delete( $id );

		Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::COMPANIES, array( 'message' => $deleted ? 'deleted' : 'delete_error' ) );
	}

	/**
	 * Avisos de la URL.
	 *
	 * @return array
	 */
	private static function messages() {
		return array_merge(
			array(
				'created'      => array( 'success', __( 'Empresa creada correctamente.', 'suiteka-basic-crm' ) ),
				'updated'      => array( 'success', __( 'Empresa actualizada correctamente.', 'suiteka-basic-crm' ) ),
				'deleted'      => array( 'success', __( 'Empresa eliminada correctamente. Sus contactos se han conservado sin empresa.', 'suiteka-basic-crm' ) ),
				'not_found'    => array( 'error', __( 'La empresa no existe o ya fue eliminada.', 'suiteka-basic-crm' ) ),
				'delete_error' => array( 'error', __( 'No se pudo eliminar la empresa. Inténtalo de nuevo.', 'suiteka-basic-crm' ) ),
			),
			Suiteka_Basic_CRM_Admin_Activities::messages()
		);
	}

	/**
	 * Pantalla «Empresas».
	 */
	public static function render() {
		$action = Suiteka_Basic_CRM_Admin::get_key( 'action' );

		if ( 'new' === $action ) {
			self::render_form( null );
			return;
		}

		if ( in_array( $action, array( 'view', 'edit', 'delete' ), true ) ) {
			if ( ! self::$company ) {
				Suiteka_Basic_CRM_Admin::render_not_found( __( 'Empresas', 'suiteka-basic-crm' ), __( 'La empresa no existe o ya fue eliminada.', 'suiteka-basic-crm' ), Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::COMPANIES ) );
				return;
			}

			if ( 'view' === $action ) {
				self::render_view( self::$company );
			} elseif ( 'edit' === $action ) {
				self::render_form( self::$company );
			} else {
				self::render_delete_confirmation( self::$company );
			}
			return;
		}

		self::render_list();
	}

	/**
	 * Listado de empresas.
	 */
	private static function render_list() {
		$caps    = 'Suiteka_Basic_CRM_Capabilities';
		$page    = Suiteka_Basic_CRM_Admin::COMPANIES;
		$search  = Suiteka_Basic_CRM_Admin::get_text( 's' );
		$orderby = Suiteka_Basic_CRM_Admin::get_key( 'orderby' );
		$order   = 'desc' === Suiteka_Basic_CRM_Admin::get_key( 'order' ) ? 'desc' : 'asc';

		if ( ! in_array( $orderby, array( 'name', 'city', 'contacts', 'updated' ), true ) ) {
			$orderby = 'name';
			$order   = 'asc';
		}

		$args = array_filter(
			array(
				's'       => $search,
				'orderby' => $orderby,
				'order'   => $order,
			)
		);

		$result = Suiteka_Basic_CRM_Companies::query(
			array(
				'search'  => $search,
				'scope'   => $caps::scope(),
				'orderby' => $orderby,
				'order'   => $order,
				'paged'   => max( 1, Suiteka_Basic_CRM_Admin::get_int( 'paged' ) ),
			)
		);

		$total   = $result['total'];
		$new_url = Suiteka_Basic_CRM_Admin::url( $page, array( 'action' => 'new' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Empresas', 'suiteka-basic-crm' ); ?></h1>
			<?php if ( current_user_can( $caps::EDIT ) ) : ?>
				<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir empresa', 'suiteka-basic-crm' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php Suiteka_Basic_CRM_Admin::render_url_notice( self::messages() ); ?>

			<?php if ( 0 === $total && '' === $search ) : ?>
				<div class="suiteka-crm-panel">
					<h2><?php esc_html_e( 'Todavía no hay empresas', 'suiteka-basic-crm' ); ?></h2>
					<p><?php esc_html_e( 'Las empresas agrupan a tus contactos. También se crean automáticamente al escribir una empresa nueva en la ficha de un contacto.', 'suiteka-basic-crm' ); ?></p>
					<?php if ( current_user_can( $caps::EDIT ) ) : ?>
						<p><a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary"><?php esc_html_e( 'Añadir la primera empresa', 'suiteka-basic-crm' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="suiteka-crm-filters" role="search" aria-label="<?php esc_attr_e( 'Buscar empresas', 'suiteka-basic-crm' ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>">
					<span class="suiteka-crm-filter">
						<label for="suiteka-crm-company-search"><?php esc_html_e( 'Buscar', 'suiteka-basic-crm' ); ?></label>
						<input type="search" id="suiteka-crm-company-search" name="s" value="<?php echo esc_attr( $search ); ?>" aria-describedby="suiteka-crm-company-search-hint">
						<span class="screen-reader-text" id="suiteka-crm-company-search-hint"><?php esc_html_e( 'Busca por nombre, correo electrónico, ciudad o web.', 'suiteka-basic-crm' ); ?></span>
					</span>
					<span class="suiteka-crm-filter suiteka-crm-filter-submit">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Buscar', 'suiteka-basic-crm' ); ?>">
					</span>
				</form>

				<?php if ( '' !== $search ) : ?>
					<p class="suiteka-crm-results" role="status">
						<?php
						/* translators: %s: número de empresas. */
						echo esc_html( sprintf( _n( '%s empresa coincide con la búsqueda.', '%s empresas coinciden con la búsqueda.', $total, 'suiteka-basic-crm' ), number_format_i18n( $total ) ) );
						?>
						<a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( $page ) ); ?>"><?php esc_html_e( 'Ver todas', 'suiteka-basic-crm' ); ?></a>
					</p>
				<?php endif; ?>

				<?php if ( 0 === $total ) : ?>
					<div class="suiteka-crm-panel"><p><?php esc_html_e( 'No se encontraron empresas que coincidan con la búsqueda.', 'suiteka-basic-crm' ); ?></p></div>
				<?php else : ?>
					<div class="suiteka-crm-table-wrap" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Tabla de empresas', 'suiteka-basic-crm' ); ?>">
						<table class="widefat striped suiteka-crm-table">
							<caption class="screen-reader-text"><?php esc_html_e( 'Empresas', 'suiteka-basic-crm' ); ?></caption>
							<thead>
								<tr>
									<?php Suiteka_Basic_CRM_Admin::sortable_header( __( 'Nombre', 'suiteka-basic-crm' ), 'name', $page, $args ); ?>
									<th scope="col"><?php esc_html_e( 'Web', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></th>
									<?php
									Suiteka_Basic_CRM_Admin::sortable_header( __( 'Ciudad', 'suiteka-basic-crm' ), 'city', $page, $args );
									Suiteka_Basic_CRM_Admin::sortable_header( __( 'Contactos', 'suiteka-basic-crm' ), 'contacts', $page, $args, 'desc' );
									?>
									<?php if ( current_user_can( $caps::VIEW_OTHERS ) ) : ?>
										<th scope="col"><?php esc_html_e( 'Responsable', 'suiteka-basic-crm' ); ?></th>
									<?php endif; ?>
									<?php Suiteka_Basic_CRM_Admin::sortable_header( __( 'Actualizada', 'suiteka-basic-crm' ), 'updated', $page, $args, 'desc' ); ?>
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
											<strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $item['name'] ); ?></a></strong>
											<div class="row-actions visible">
												<span class="view"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Ver', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['name'] ); ?></span></a></span>
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
																					"><?php esc_html_e( 'Editar', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['name'] ); ?></span></a></span>
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
																					" class="submitdelete"><?php esc_html_e( 'Eliminar', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['name'] ); ?></span></a></span>
												<?php endif; ?>
											</div>
										</td>
										<td>
											<?php if ( '' !== $item['website'] ) : ?>
												<a href="<?php echo esc_url( $item['website'] ); ?>" rel="noopener noreferrer" target="_blank"><?php echo esc_html( preg_replace( '#^https?://#i', '', $item['website'] ) ); ?><span class="screen-reader-text"> <?php esc_html_e( '(se abre en una pestaña nueva)', 'suiteka-basic-crm' ); ?></span></a>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $item['phone'] ); ?></td>
										<td><?php echo esc_html( $item['city'] ); ?></td>
										<td>
											<a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACTS, array( 'company_id' => $item_id ) ) ); ?>">
												<?php echo esc_html( number_format_i18n( (int) $item['contacts_count'] ) ); ?>
												<span class="screen-reader-text">
													<?php
													/* translators: %s: nombre de la empresa. */
													echo esc_html( sprintf( __( 'contactos de %s', 'suiteka-basic-crm' ), $item['name'] ) );
													?>
												</span>
											</a>
										</td>
										<?php if ( current_user_can( $caps::VIEW_OTHERS ) ) : ?>
											<td><?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $item['owner_id'] ) ); ?></td>
										<?php endif; ?>
										<td><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $item['updated_at'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php Suiteka_Basic_CRM_Admin::pagination( $page, $args, $result['paged'], $total, Suiteka_Basic_CRM_Companies::PER_PAGE ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Ficha de la empresa: datos, contactos e historial.
	 *
	 * @param array $company Empresa.
	 */
	private static function render_view( $company ) {
		$caps     = 'Suiteka_Basic_CRM_Capabilities';
		$id       = (int) $company['id'];
		$page     = Suiteka_Basic_CRM_Admin::COMPANIES;
		$scope    = $caps::scope();
		$can_edit = current_user_can( $caps::EDIT_RECORD, (int) $company['owner_id'] );
		$contacts = Suiteka_Basic_CRM_Contacts::query(
			array(
				'company_id' => $id,
				'scope'      => $scope,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'per_page'   => 50,
			)
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $company['name'] ); ?></h1>
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

			<p><a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( $page ) ); ?>">&larr; <?php esc_html_e( 'Volver a empresas', 'suiteka-basic-crm' ); ?></a></p>

			<div class="suiteka-crm-columns">
				<div>
					<section class="suiteka-crm-panel" aria-labelledby="suiteka-crm-company-details-title">
						<h2 id="suiteka-crm-company-details-title"><?php esc_html_e( 'Datos de la empresa', 'suiteka-basic-crm' ); ?></h2>
						<dl class="suiteka-crm-details">
							<dt><?php esc_html_e( 'Web', 'suiteka-basic-crm' ); ?></dt>
							<dd>
								<?php if ( $company['website'] ) : ?>
									<a href="<?php echo esc_url( $company['website'] ); ?>" rel="noopener noreferrer" target="_blank"><?php echo esc_html( $company['website'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(se abre en una pestaña nueva)', 'suiteka-basic-crm' ); ?></span></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</dd>
							<dt><?php esc_html_e( 'Correo electrónico', 'suiteka-basic-crm' ); ?></dt>
							<dd>
								<?php if ( $company['email'] ) : ?>
									<a href="<?php echo esc_url( 'mailto:' . $company['email'] ); ?>"><?php echo esc_html( $company['email'] ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</dd>
							<dt><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></dt>
							<dd><?php echo esc_html( $company['phone'] ? $company['phone'] : '—' ); ?></dd>
							<dt><?php esc_html_e( 'Ciudad', 'suiteka-basic-crm' ); ?></dt>
							<dd><?php echo esc_html( $company['city'] ? $company['city'] : '—' ); ?></dd>
							<dt><?php esc_html_e( 'Responsable', 'suiteka-basic-crm' ); ?></dt>
							<dd><?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $company['owner_id'] ) ); ?></dd>
							<dt><?php esc_html_e( 'Creada', 'suiteka-basic-crm' ); ?></dt>
							<dd><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $company['created_at'] ) ); ?></dd>
							<dt><?php esc_html_e( 'Actualizada', 'suiteka-basic-crm' ); ?></dt>
							<dd><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $company['updated_at'] ) ); ?></dd>
						</dl>

						<?php if ( '' !== $company['notes'] ) : ?>
							<h3><?php esc_html_e( 'Notas', 'suiteka-basic-crm' ); ?></h3>
							<div class="suiteka-crm-notes"><?php echo nl2br( esc_html( $company['notes'] ) ); ?></div>
						<?php endif; ?>

						<?php if ( current_user_can( $caps::DELETE_RECORD, (int) $company['owner_id'] ) ) : ?>
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
																		" class="submitdelete"><?php esc_html_e( 'Eliminar empresa', 'suiteka-basic-crm' ); ?></a></p>
						<?php endif; ?>
					</section>

					<section class="suiteka-crm-panel" aria-labelledby="suiteka-crm-company-contacts-title">
						<h2 id="suiteka-crm-company-contacts-title">
							<?php
							/* translators: %s: número de contactos. */
							echo esc_html( sprintf( __( 'Contactos (%s)', 'suiteka-basic-crm' ), number_format_i18n( $contacts['total'] ) ) );
							?>
						</h2>
						<?php if ( empty( $contacts['items'] ) ) : ?>
							<p><?php esc_html_e( 'Esta empresa no tiene contactos que puedas ver.', 'suiteka-basic-crm' ); ?></p>
						<?php else : ?>
							<ul class="suiteka-crm-list">
								<?php foreach ( $contacts['items'] as $contact ) : ?>
									<li>
										<a href="
										<?php
										echo esc_url(
											Suiteka_Basic_CRM_Admin::url(
												Suiteka_Basic_CRM_Admin::CONTACTS,
												array(
													'action' => 'view',
													'id' => (int) $contact['id'],
												)
											)
										);
										?>
													"><?php echo esc_html( $contact['full_name'] ); ?></a>
										<?php if ( '' !== $contact['job_title'] ) : ?>
											<span class="description">— <?php echo esc_html( $contact['job_title'] ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php if ( $contacts['total'] > count( $contacts['items'] ) ) : ?>
								<p><a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACTS, array( 'company_id' => $id ) ) ); ?>"><?php esc_html_e( 'Ver todos los contactos', 'suiteka-basic-crm' ); ?></a></p>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( current_user_can( $caps::EDIT ) ) : ?>
							<p><a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::CONTACT_NEW, array( 'company_id' => $id ) ) ); ?>" class="button"><?php esc_html_e( 'Añadir contacto en esta empresa', 'suiteka-basic-crm' ); ?></a></p>
						<?php endif; ?>
					</section>
				</div>

				<div class="suiteka-crm-panel">
					<?php Suiteka_Basic_CRM_Admin_Activities::render( 'company', $id, Suiteka_Basic_CRM_Activities::for_company( $id, $scope ), $can_edit ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Formulario de creación o edición.
	 *
	 * @param array|null $company Empresa existente o null.
	 */
	private static function render_form( $company ) {
		$caps   = 'Suiteka_Basic_CRM_Capabilities';
		$id     = $company ? (int) $company['id'] : 0;
		$ids    = self::$ids;
		$errors = self::$errors;
		$labels = Suiteka_Basic_CRM_Companies::labels();
		$max    = Suiteka_Basic_CRM_Companies::$max_lengths;
		$values = array(
			'name'     => '',
			'website'  => '',
			'email'    => '',
			'phone'    => '',
			'city'     => '',
			'owner_id' => (string) get_current_user_id(),
			'notes'    => '',
		);

		if ( null !== self::$values ) {
			$values = array_merge( $values, array_intersect_key( self::$values, $values ) );
		} elseif ( $company ) {
			$values = array_merge( $values, array_intersect_key( $company, $values ) );
		}

		$page = Suiteka_Basic_CRM_Admin::COMPANIES;

		if ( $id ) {
			$title      = __( 'Editar empresa', 'suiteka-basic-crm' );
			$action_url = Suiteka_Basic_CRM_Admin::url(
				$page,
				array(
					'action' => 'edit',
					'id'     => $id,
				)
			);
			$cancel_url = Suiteka_Basic_CRM_Admin::url(
				$page,
				array(
					'action' => 'view',
					'id'     => $id,
				)
			);
		} else {
			$title      = __( 'Añadir empresa', 'suiteka-basic-crm' );
			$action_url = Suiteka_Basic_CRM_Admin::url( $page, array( 'action' => 'new' ) );
			$cancel_url = Suiteka_Basic_CRM_Admin::url( $page );
		}

		$fields = array(
			'name'    => array( 'text', true ),
			'website' => array( 'url', false ),
			'email'   => array( 'email', false ),
			'phone'   => array( 'tel', false ),
			'city'    => array( 'text', false ),
		);
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
				<?php wp_nonce_field( 'suiteka_crm_save_company_' . $id ); ?>
				<input type="hidden" name="suiteka_crm_operation" value="save_company">
				<input type="hidden" name="company_id" value="<?php echo esc_attr( $id ); ?>">

				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $key => $field ) : ?>
						<?php list( $type, $required ) = $field; ?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $ids[ $key ] ); ?>">
									<?php echo esc_html( $labels[ $key ] ); ?>
									<?php if ( $required ) : ?>
										<span aria-hidden="true">*</span>
									<?php endif; ?>
								</label>
							</th>
							<td>
								<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $ids[ $key ] ); ?>" name="<?php echo esc_attr( $key ); ?>" class="regular-text" maxlength="<?php echo esc_attr( $max[ $key ] ); ?>" value="<?php echo esc_attr( $values[ $key ] ); ?>"<?php echo $required ? ' required aria-required="true"' : ''; ?><?php Suiteka_Basic_CRM_Admin::aria( $errors, $key, $ids[ $key ] ); ?>>
								<?php Suiteka_Basic_CRM_Admin::field_error( $errors, $key, $ids[ $key ] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
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
								<output id="<?php echo esc_attr( $ids['owner_id'] ); ?>"><?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $company ? $company['owner_id'] : get_current_user_id() ) ); ?></output>
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
				</table>

				<p><?php esc_html_e( 'Los campos marcados con * son obligatorios.', 'suiteka-basic-crm' ); ?></p>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php echo $id ? esc_attr__( 'Guardar cambios', 'suiteka-basic-crm' ) : esc_attr__( 'Crear empresa', 'suiteka-basic-crm' ); ?>">
					<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'suiteka-basic-crm' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Confirmación de borrado.
	 *
	 * @param array $company Empresa.
	 */
	private static function render_delete_confirmation( $company ) {
		$id       = (int) $company['id'];
		$contacts = Suiteka_Basic_CRM_Companies::count_contacts( $id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Eliminar empresa', 'suiteka-basic-crm' ); ?></h1>

			<div class="suiteka-crm-panel suiteka-crm-narrow">
				<p>
					<?php
					/* translators: %s: nombre de la empresa. */
					echo esc_html( sprintf( __( '¿Seguro que quieres eliminar la empresa «%s»? Esta acción no se puede deshacer.', 'suiteka-basic-crm' ), $company['name'] ) );
					?>
				</p>
				<?php if ( $contacts > 0 ) : ?>
					<p>
						<?php
						/* translators: %s: número de contactos. */
						echo esc_html( sprintf( _n( 'Tiene %s contacto: no se eliminará, quedará sin empresa.', 'Tiene %s contactos: no se eliminarán, quedarán sin empresa.', $contacts, 'suiteka-basic-crm' ), number_format_i18n( $contacts ) ) );
						?>
					</p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::COMPANIES ) ); ?>">
					<?php wp_nonce_field( 'suiteka_crm_delete_company_' . $id ); ?>
					<input type="hidden" name="suiteka_crm_operation" value="delete_company">
					<input type="hidden" name="company_id" value="<?php echo esc_attr( $id ); ?>">
					<p>
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Sí, eliminar empresa', 'suiteka-basic-crm' ); ?>">
						<a href="
						<?php
						echo esc_url(
							Suiteka_Basic_CRM_Admin::url(
								Suiteka_Basic_CRM_Admin::COMPANIES,
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
