<?php
/**
 * Historial y formulario de actividades (compartido por contactos y empresas).
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Procesa y muestra las actividades de un contacto o una empresa.
 */
final class Suiteka_Basic_CRM_Admin_Activities {

	/**
	 * Errores del último envío.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Valores escritos en el último envío.
	 *
	 * @var string[]
	 */
	private static $values = array();

	/**
	 * Registro padre según el contexto.
	 *
	 * @param string $context contact|company.
	 * @param int    $id      ID.
	 * @return array|null
	 */
	private static function parent_record( $context, $id ) {
		return 'company' === $context ? Suiteka_Basic_CRM_Companies::get( $id ) : Suiteka_Basic_CRM_Contacts::get( $id );
	}

	/**
	 * Pantalla del registro padre.
	 *
	 * @param string $context contact|company.
	 * @return string
	 */
	private static function page( $context ) {
		return 'company' === $context ? Suiteka_Basic_CRM_Admin::COMPANIES : Suiteka_Basic_CRM_Admin::CONTACTS;
	}

	/**
	 * Añade una nota, llamada o reunión (POST).
	 *
	 * @param string $context contact|company.
	 */
	public static function handle_add( $context ) {
		$id = Suiteka_Basic_CRM_Admin::post_id( 'object_id' );
		check_admin_referer( 'suiteka_crm_add_activity_' . $context . '_' . $id );

		$record = $id ? self::parent_record( $context, $id ) : null;
		if ( ! $record ) {
			Suiteka_Basic_CRM_Admin::redirect( self::page( $context ), array( 'message' => 'not_found' ) );
		}

		Suiteka_Basic_CRM_Admin::require_cap( Suiteka_Basic_CRM_Capabilities::EDIT_RECORD, $record['owner_id'] );

		$prepared = Suiteka_Basic_CRM_Activities::prepare( Suiteka_Basic_CRM_Admin::post_data() );

		if ( ! empty( $prepared['errors'] ) ) {
			self::$errors = $prepared['errors'];
			self::$values = $prepared['values'];
			return;
		}

		$added = Suiteka_Basic_CRM_Activities::add(
			array_merge(
				$prepared['data'],
				array(
					'contact_id' => 'contact' === $context ? $id : 0,
					'company_id' => 'company' === $context ? $id : 0,
				)
			)
		);

		if ( ! $added ) {
			self::$errors = array( 'activity_content' => __( 'No se pudo guardar la actividad. Inténtalo de nuevo.', 'suiteka-basic-crm' ) );
			self::$values = $prepared['values'];
			return;
		}

		Suiteka_Basic_CRM_Admin::redirect(
			self::page( $context ),
			array(
				'action'  => 'view',
				'id'      => $id,
				'message' => 'activity_added',
			)
		);
	}

	/**
	 * Borra una nota, llamada o reunión (POST). Los eventos del sistema no se borran.
	 *
	 * Puede borrarla su autor si puede editar el registro, o quien tenga
	 * suiteka_crm_edit_others.
	 *
	 * @param string $context contact|company (pantalla a la que se vuelve).
	 */
	public static function handle_delete( $context ) {
		$activity_id = Suiteka_Basic_CRM_Admin::post_id( 'activity_id' );
		check_admin_referer( 'suiteka_crm_delete_activity_' . $activity_id );

		$return_id = Suiteka_Basic_CRM_Admin::post_id( 'object_id' );
		$activity  = $activity_id ? Suiteka_Basic_CRM_Activities::get( $activity_id ) : null;

		if ( ! $activity || 'system' === $activity['type'] ) {
			Suiteka_Basic_CRM_Admin::redirect( self::page( $context ), array( 'message' => 'not_found' ) );
		}

		// Registro al que pertenece la actividad (puede ser un contacto mostrado en la ficha de su empresa).
		if ( (int) $activity['contact_id'] ) {
			$owner_record = Suiteka_Basic_CRM_Contacts::get( (int) $activity['contact_id'] );
		} else {
			$owner_record = Suiteka_Basic_CRM_Companies::get( (int) $activity['company_id'] );
		}

		if ( ! $owner_record || ! self::can_delete( $activity, $owner_record ) ) {
			Suiteka_Basic_CRM_Admin::deny();
		}

		$deleted = Suiteka_Basic_CRM_Activities::delete( $activity_id );

		Suiteka_Basic_CRM_Admin::redirect(
			self::page( $context ),
			array(
				'action'  => 'view',
				'id'      => $return_id,
				'message' => $deleted ? 'activity_deleted' : 'activity_delete_error',
			)
		);
	}

	/**
	 * Indica si el usuario actual puede borrar una actividad.
	 *
	 * @param array $activity Actividad.
	 * @param array $record   Registro al que pertenece (con owner_id).
	 * @return bool
	 */
	private static function can_delete( $activity, $record ) {
		if ( 'system' === $activity['type'] || ! current_user_can( Suiteka_Basic_CRM_Capabilities::EDIT_RECORD, (int) $record['owner_id'] ) ) {
			return false;
		}

		return get_current_user_id() === (int) $activity['user_id'] || current_user_can( Suiteka_Basic_CRM_Capabilities::EDIT_OTHERS );
	}

	/**
	 * Avisos de la URL relacionados con actividades.
	 *
	 * @return array
	 */
	public static function messages() {
		return array(
			'activity_added'        => array( 'success', __( 'Actividad añadida al historial.', 'suiteka-basic-crm' ) ),
			'activity_deleted'      => array( 'success', __( 'Actividad eliminada.', 'suiteka-basic-crm' ) ),
			'activity_delete_error' => array( 'error', __( 'No se pudo eliminar la actividad.', 'suiteka-basic-crm' ) ),
		);
	}

	/**
	 * Sección «Historial»: formulario y lista.
	 *
	 * @param string  $context    contact|company.
	 * @param int     $id         ID del registro.
	 * @param array[] $activities Actividades.
	 * @param bool    $can_add    Si el usuario puede añadir actividades.
	 */
	public static function render( $context, $id, $activities, $can_add ) {
		$page   = self::page( $context );
		$errors = self::$errors;
		$values = wp_parse_args(
			self::$values,
			array(
				'activity_type'    => 'note',
				'activity_content' => '',
				'activity_date'    => '',
			)
		);
		$ids    = array(
			'activity_type'    => 'suiteka-crm-activity-type',
			'activity_content' => 'suiteka-crm-activity-content',
			'activity_date'    => 'suiteka-crm-activity-date',
		);
		?>
		<section class="suiteka-crm-history" id="suiteka-crm-history" aria-labelledby="suiteka-crm-history-title">
			<h2 id="suiteka-crm-history-title"><?php esc_html_e( 'Historial', 'suiteka-basic-crm' ); ?></h2>

			<?php if ( $can_add ) : ?>
				<form method="post" action="
				<?php
				echo esc_url(
					Suiteka_Basic_CRM_Admin::url(
						$page,
						array(
							'action' => 'view',
							'id'     => $id,
						)
					)
				);
				?>
											" class="suiteka-crm-activity-form" novalidate>
					<?php wp_nonce_field( 'suiteka_crm_add_activity_' . $context . '_' . $id ); ?>
					<input type="hidden" name="suiteka_crm_operation" value="add_activity">
					<input type="hidden" name="object_id" value="<?php echo esc_attr( $id ); ?>">

					<?php Suiteka_Basic_CRM_Admin::render_error_summary( $errors, $ids ); ?>

					<fieldset>
						<legend><?php esc_html_e( 'Registrar una actividad', 'suiteka-basic-crm' ); ?></legend>
						<p class="suiteka-crm-inline-fields">
							<span>
								<label for="<?php echo esc_attr( $ids['activity_type'] ); ?>"><?php esc_html_e( 'Tipo', 'suiteka-basic-crm' ); ?></label>
								<?php
								$aria = '';
								if ( isset( $errors['activity_type'] ) ) {
									$aria = ' aria-invalid="true" aria-describedby="' . esc_attr( $ids['activity_type'] . '-error' ) . '"';
								}
								Suiteka_Basic_CRM_Admin::select( 'activity_type', $ids['activity_type'], Suiteka_Basic_CRM_Activities::manual_types(), $values['activity_type'], $aria );
								Suiteka_Basic_CRM_Admin::field_error( $errors, 'activity_type', $ids['activity_type'] );
								?>
							</span>
							<span>
								<label for="<?php echo esc_attr( $ids['activity_date'] ); ?>"><?php esc_html_e( 'Fecha (opcional)', 'suiteka-basic-crm' ); ?></label>
								<input type="datetime-local" id="<?php echo esc_attr( $ids['activity_date'] ); ?>" name="activity_date" value="<?php echo esc_attr( $values['activity_date'] ); ?>"<?php Suiteka_Basic_CRM_Admin::aria( $errors, 'activity_date', $ids['activity_date'], 'suiteka-crm-activity-date-hint' ); ?>>
								<span class="description" id="suiteka-crm-activity-date-hint"><?php esc_html_e( 'Si la dejas vacía, se usa la fecha y hora actuales.', 'suiteka-basic-crm' ); ?></span>
								<?php Suiteka_Basic_CRM_Admin::field_error( $errors, 'activity_date', $ids['activity_date'] ); ?>
							</span>
						</p>
						<p>
							<label for="<?php echo esc_attr( $ids['activity_content'] ); ?>"><?php esc_html_e( 'Descripción', 'suiteka-basic-crm' ); ?> <span aria-hidden="true">*</span></label>
							<textarea id="<?php echo esc_attr( $ids['activity_content'] ); ?>" name="activity_content" class="large-text" rows="3" required aria-required="true"<?php Suiteka_Basic_CRM_Admin::aria( $errors, 'activity_content', $ids['activity_content'] ); ?>><?php echo esc_textarea( $values['activity_content'] ); ?></textarea>
							<?php Suiteka_Basic_CRM_Admin::field_error( $errors, 'activity_content', $ids['activity_content'] ); ?>
						</p>
						<p><input type="submit" class="button" value="<?php esc_attr_e( 'Añadir al historial', 'suiteka-basic-crm' ); ?>"></p>
					</fieldset>
				</form>
			<?php endif; ?>

			<?php if ( empty( $activities ) ) : ?>
				<p><?php esc_html_e( 'Todavía no hay actividad registrada.', 'suiteka-basic-crm' ); ?></p>
			<?php else : ?>
				<ol class="suiteka-crm-timeline">
					<?php $records = array(); // Registros ya consultados, para no repetir consultas. ?>
					<?php foreach ( $activities as $activity ) : ?>
						<?php
						$owner_record = null;
						if ( 'system' !== $activity['type'] ) {
							$key = (int) $activity['contact_id'] ? 'contact_' . (int) $activity['contact_id'] : 'company_' . (int) $activity['company_id'];
							if ( ! array_key_exists( $key, $records ) ) {
								$records[ $key ] = (int) $activity['contact_id'] ? Suiteka_Basic_CRM_Contacts::get( (int) $activity['contact_id'] ) : Suiteka_Basic_CRM_Companies::get( (int) $activity['company_id'] );
							}
							$owner_record = $records[ $key ];
						}
						?>
						<li class="suiteka-crm-activity suiteka-crm-activity-<?php echo esc_attr( $activity['type'] ); ?>">
							<p class="suiteka-crm-activity-meta">
								<strong><?php echo esc_html( Suiteka_Basic_CRM_Activities::type_label( $activity['type'] ) ); ?></strong>
								· <time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $activity['occurred_at'] ) . 'Z' ); ?>"><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $activity['occurred_at'] ) ); ?></time>
								<?php if ( (int) $activity['user_id'] ) : ?>
									· <?php echo esc_html( Suiteka_Basic_CRM_Admin::user_name( $activity['user_id'] ) ); ?>
								<?php endif; ?>
								<?php if ( 'company' === $context && (int) $activity['contact_id'] ) : ?>
									· <a href="
									<?php
									echo esc_url(
										Suiteka_Basic_CRM_Admin::url(
											Suiteka_Basic_CRM_Admin::CONTACTS,
											array(
												'action' => 'view',
												'id'     => (int) $activity['contact_id'],
											)
										)
									);
									?>
												"><?php esc_html_e( 'Ver contacto', 'suiteka-basic-crm' ); ?></a>
								<?php endif; ?>
							</p>
							<?php if ( '' !== $activity['event'] ) : ?>
								<p><?php echo esc_html( Suiteka_Basic_CRM_Activities::describe( $activity ) ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $activity['content'] ) : ?>
								<div class="suiteka-crm-activity-content"><?php echo nl2br( esc_html( $activity['content'] ) ); ?></div>
							<?php endif; ?>
							<?php if ( $owner_record && self::can_delete( $activity, $owner_record ) ) : ?>
								<form method="post" action="
								<?php
								echo esc_url(
									Suiteka_Basic_CRM_Admin::url(
										$page,
										array(
											'action' => 'view',
											'id'     => $id,
										)
									)
								);
								?>
															" class="suiteka-crm-activity-delete">
									<?php wp_nonce_field( 'suiteka_crm_delete_activity_' . (int) $activity['id'] ); ?>
									<input type="hidden" name="suiteka_crm_operation" value="delete_activity">
									<input type="hidden" name="activity_id" value="<?php echo esc_attr( (int) $activity['id'] ); ?>">
									<input type="hidden" name="object_id" value="<?php echo esc_attr( $id ); ?>">
									<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Eliminar', 'suiteka-basic-crm' ); ?><span class="screen-reader-text"> <?php esc_html_e( 'esta actividad', 'suiteka-basic-crm' ); ?></span></button>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php if ( count( $activities ) >= Suiteka_Basic_CRM_Activities::LIMIT ) : ?>
					<p class="description">
						<?php
						/* translators: %d: número de actividades mostradas. */
						echo esc_html( sprintf( __( 'Se muestran las %d actividades más recientes.', 'suiteka-basic-crm' ), Suiteka_Basic_CRM_Activities::LIMIT ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}
}
