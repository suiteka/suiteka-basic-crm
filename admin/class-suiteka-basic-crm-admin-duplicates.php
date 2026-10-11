<?php
/**
 * Pantalla de posibles duplicados y combinación.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lista grupos de posibles duplicados y combina dos contactos tras una vista previa.
 */
final class Suiteka_Basic_CRM_Admin_Duplicates {

	/**
	 * Contactos que se comparan (pantalla de combinación).
	 *
	 * @var array[]
	 */
	private static $pair = array();

	/**
	 * Error de la última combinación.
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * Comprueba permisos y procesa la combinación.
	 */
	public static function load() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';

		Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT );

		if ( 'merge' === Suiteka_Basic_CRM_Admin::get_key( 'action' ) ) {
			$a = Suiteka_Basic_CRM_Admin::get_int( 'a' );
			$b = Suiteka_Basic_CRM_Admin::get_int( 'b' );

			if ( $a && $b && $a !== $b ) {
				$first  = Suiteka_Basic_CRM_Contacts::get( $a );
				$second = Suiteka_Basic_CRM_Contacts::get( $b );

				if ( $first && $second ) {
					Suiteka_Basic_CRM_Admin::require_cap( $caps::VIEW_RECORD, $first['owner_id'] );
					Suiteka_Basic_CRM_Admin::require_cap( $caps::VIEW_RECORD, $second['owner_id'] );
					self::$pair = array( $first, $second );
				}
			}
		}

		if ( Suiteka_Basic_CRM_Admin::is_post() && 'merge' === Suiteka_Basic_CRM_Admin::operation() ) {
			self::handle_merge();
		}
	}

	/**
	 * Combina dos contactos (POST).
	 */
	private static function handle_merge() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';
		$a    = Suiteka_Basic_CRM_Admin::post_id( 'contact_a' );
		$b    = Suiteka_Basic_CRM_Admin::post_id( 'contact_b' );

		check_admin_referer( 'suiteka_crm_merge_contacts_' . min( $a, $b ) . '_' . max( $a, $b ) );

		$keep_id = Suiteka_Basic_CRM_Admin::post_id( 'keep' );
		if ( ! in_array( $keep_id, array( $a, $b ), true ) || $a === $b ) {
			self::$error = __( 'Elige qué contacto quieres conservar.', 'suiteka-basic-crm' );
			return;
		}

		$discard_id = $keep_id === $a ? $b : $a;
		$keep       = Suiteka_Basic_CRM_Contacts::get( $keep_id );
		$discard    = Suiteka_Basic_CRM_Contacts::get( $discard_id );

		if ( ! $keep || ! $discard ) {
			Suiteka_Basic_CRM_Admin::redirect( Suiteka_Basic_CRM_Admin::DUPLICATES, array( 'message' => 'not_found' ) );
		}

		Suiteka_Basic_CRM_Admin::require_cap( $caps::EDIT_RECORD, $keep['owner_id'] );
		Suiteka_Basic_CRM_Admin::require_cap( $caps::DELETE_RECORD, $discard['owner_id'] );

		$result = Suiteka_Basic_CRM_Duplicates::merge( $keep_id, $discard_id );

		if ( is_wp_error( $result ) ) {
			self::$error = $result->get_error_message();
			return;
		}

		Suiteka_Basic_CRM_Admin::redirect(
			Suiteka_Basic_CRM_Admin::CONTACTS,
			array(
				'action'  => 'view',
				'id'      => $keep_id,
				'message' => 'merged',
			)
		);
	}

	/**
	 * Pantalla «Posibles duplicados».
	 */
	public static function render() {
		if ( 'merge' === Suiteka_Basic_CRM_Admin::get_key( 'action' ) ) {
			if ( empty( self::$pair ) ) {
				Suiteka_Basic_CRM_Admin::render_not_found( __( 'Combinar contactos', 'suiteka-basic-crm' ), __( 'Uno de los contactos no existe o ya fue combinado.', 'suiteka-basic-crm' ), Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::DUPLICATES ) );
				return;
			}
			self::render_merge( self::$pair[0], self::$pair[1] );
			return;
		}

		self::render_groups();
	}

	/**
	 * Lista de grupos de posibles duplicados.
	 */
	private static function render_groups() {
		$groups   = Suiteka_Basic_CRM_Duplicates::groups( Suiteka_Basic_CRM_Capabilities::scope() );
		$criteria = array(
			'email' => __( 'Mismo correo electrónico', 'suiteka-basic-crm' ),
			'phone' => __( 'Mismo teléfono', 'suiteka-basic-crm' ),
			'name'  => __( 'Mismo nombre', 'suiteka-basic-crm' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Posibles duplicados', 'suiteka-basic-crm' ); ?></h1>

			<?php
			Suiteka_Basic_CRM_Admin::render_url_notice(
				array(
					'not_found' => array( 'error', __( 'Uno de los contactos no existe o ya fue combinado.', 'suiteka-basic-crm' ) ),
				)
			);
			?>

			<p><?php esc_html_e( 'Contactos que coinciden en el correo electrónico, el teléfono (solo se comparan los dígitos) o el nombre completo. Nada se combina automáticamente: revisa cada caso y elige qué contacto conservar.', 'suiteka-basic-crm' ); ?></p>

			<?php if ( empty( $groups ) ) : ?>
				<div class="suiteka-crm-panel"><p><?php esc_html_e( 'No se han encontrado posibles duplicados.', 'suiteka-basic-crm' ); ?></p></div>
			<?php endif; ?>

			<?php foreach ( $groups as $index => $group ) : ?>
				<?php
				$first      = $group['contacts'][0];
				$heading_id = 'suiteka-crm-group-' . $index;
				?>
				<section class="suiteka-crm-panel" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
					<h2 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $criteria[ $group['criterion'] ] . ': ' . $group['value'] ); ?></h2>
					<div class="suiteka-crm-table-wrap" role="region" tabindex="0" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
						<table class="widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Nombre', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Correo electrónico', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Creado', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Acción', 'suiteka-basic-crm' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $group['contacts'] as $i => $contact ) : ?>
									<tr>
										<td><a href="
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
														"><?php echo esc_html( $contact['full_name'] ); ?></a></td>
										<td><?php echo esc_html( $contact['email'] ); ?></td>
										<td><?php echo esc_html( $contact['phone'] ); ?></td>
										<td><?php echo esc_html( Suiteka_Basic_CRM_Admin::format_date( $contact['created_at'] ) ); ?></td>
										<td>
											<?php if ( $i > 0 ) : ?>
												<?php
												$merge_url = Suiteka_Basic_CRM_Admin::url(
													Suiteka_Basic_CRM_Admin::DUPLICATES,
													array(
														'action' => 'merge',
														'a'      => (int) $first['id'],
														'b'      => (int) $contact['id'],
													)
												);
												?>
												<a href="<?php echo esc_url( $merge_url ); ?>">
													<?php
													/* translators: %s: nombre del primer contacto del grupo. */
													echo esc_html( sprintf( __( 'Comparar con «%s»', 'suiteka-basic-crm' ), $first['full_name'] ) );
													?>
												</a>
											<?php else : ?>
												<?php esc_html_e( 'El más antiguo', 'suiteka-basic-crm' ); ?>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Vista previa y formulario de combinación.
	 *
	 * @param array $a Primer contacto.
	 * @param array $b Segundo contacto.
	 */
	private static function render_merge( $a, $b ) {
		$statuses = Suiteka_Basic_CRM_Contacts::statuses();
		$sources  = Suiteka_Basic_CRM_Contacts::sources();
		$keep_a   = Suiteka_Basic_CRM_Duplicates::preview( $a, $b );
		$keep_b   = Suiteka_Basic_CRM_Duplicates::preview( $b, $a );
		$tags     = array(
			'a' => Suiteka_Basic_CRM_Tags::get_for_object( 'contact', (int) $a['id'] ),
			'b' => Suiteka_Basic_CRM_Tags::get_for_object( 'contact', (int) $b['id'] ),
		);
		$all_tags = implode( ', ', array_unique( array_merge( $tags['a'], $tags['b'] ) ) );

		// Nombre de empresa de cada combinación posible.
		$company = function ( $company_id ) use ( $a, $b ) {
			foreach ( array( $a, $b ) as $row ) {
				if ( (int) $row['company_id'] === (int) $company_id && $company_id ) {
					return (string) $row['company_name'];
				}
			}
			return '';
		};

		$rows = array(
			__( 'Nombre', 'suiteka-basic-crm' )      => array( $a['full_name'], $b['full_name'], $keep_a['full_name'], $keep_b['full_name'] ),
			__( 'Correo electrónico', 'suiteka-basic-crm' ) => array( $a['email'], $b['email'], $keep_a['email'], $keep_b['email'] ),
			__( 'Teléfono', 'suiteka-basic-crm' )    => array( $a['phone'], $b['phone'], $keep_a['phone'], $keep_b['phone'] ),
			__( 'Cargo', 'suiteka-basic-crm' )       => array( $a['job_title'], $b['job_title'], $keep_a['job_title'], $keep_b['job_title'] ),
			__( 'Empresa', 'suiteka-basic-crm' )     => array( $company( $a['company_id'] ), $company( $b['company_id'] ), $company( $keep_a['company_id'] ), $company( $keep_b['company_id'] ) ),
			__( 'Estado', 'suiteka-basic-crm' )      => array_map(
				function ( $key ) use ( $statuses ) {
					return isset( $statuses[ $key ] ) ? $statuses[ $key ] : '';
				},
				array( $a['status'], $b['status'], $keep_a['status'], $keep_b['status'] )
			),
			__( 'Origen', 'suiteka-basic-crm' )      => array_map(
				function ( $key ) use ( $sources ) {
					return isset( $sources[ $key ] ) ? $sources[ $key ] : '';
				},
				array( $a['source'], $b['source'], $keep_a['source'], $keep_b['source'] )
			),
			__( 'Responsable', 'suiteka-basic-crm' ) => array_map( array( 'Suiteka_Basic_CRM_Admin', 'user_name' ), array( $a['owner_id'], $b['owner_id'], $keep_a['owner_id'], $keep_b['owner_id'] ) ),
			__( 'Etiquetas', 'suiteka-basic-crm' )   => array( implode( ', ', $tags['a'] ), implode( ', ', $tags['b'] ), $all_tags, $all_tags ),
			__( 'Creado', 'suiteka-basic-crm' )      => array_map( array( 'Suiteka_Basic_CRM_Admin', 'format_date' ), array( $a['created_at'], $b['created_at'], $keep_a['created_at'], $keep_b['created_at'] ) ),
		);

		$a_id = (int) $a['id'];
		$b_id = (int) $b['id'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Combinar contactos', 'suiteka-basic-crm' ); ?></h1>

			<?php if ( self::$error ) : ?>
				<div class="notice notice-error" role="alert"><p><?php echo esc_html( self::$error ); ?></p></div>
			<?php endif; ?>

			<div class="suiteka-crm-panel">
				<h2><?php esc_html_e( 'Qué ocurre al combinar', 'suiteka-basic-crm' ); ?></h2>
				<ul class="suiteka-crm-list ul-disc">
					<li><?php esc_html_e( 'Se conserva el contacto que elijas, con su nombre.', 'suiteka-basic-crm' ); ?></li>
					<li><?php esc_html_e( 'Sus campos vacíos se completan con los del otro contacto; los campos con valor no se sobrescriben.', 'suiteka-basic-crm' ); ?></li>
					<li><?php esc_html_e( 'Las etiquetas se unen y todo el historial pasa al contacto conservado.', 'suiteka-basic-crm' ); ?></li>
					<li><?php esc_html_e( 'Las notas del otro contacto se guardan como una nota en el historial.', 'suiteka-basic-crm' ); ?></li>
					<li><?php esc_html_e( 'El otro contacto se elimina. Esta acción no se puede deshacer.', 'suiteka-basic-crm' ); ?></li>
				</ul>
			</div>

			<div class="suiteka-crm-table-wrap" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Comparación de los dos contactos', 'suiteka-basic-crm' ); ?>">
				<table class="widefat striped suiteka-crm-merge">
					<caption class="screen-reader-text"><?php esc_html_e( 'Comparación de los dos contactos y resultado de cada opción', 'suiteka-basic-crm' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Campo', 'suiteka-basic-crm' ); ?></th>
							<?php /* translators: %d: ID del contacto. */ ?>
							<th scope="col"><?php echo esc_html( sprintf( __( 'Contacto A (n.º %d)', 'suiteka-basic-crm' ), $a_id ) ); ?></th>
							<?php /* translators: %d: ID del contacto. */ ?>
							<th scope="col"><?php echo esc_html( sprintf( __( 'Contacto B (n.º %d)', 'suiteka-basic-crm' ), $b_id ) ); ?></th>
							<th scope="col"><?php esc_html_e( 'Resultado si conservas A', 'suiteka-basic-crm' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Resultado si conservas B', 'suiteka-basic-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $label => $cells ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<?php foreach ( $cells as $cell ) : ?>
									<td><?php echo esc_html( '' !== (string) $cell ? $cell : '—' ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Notas', 'suiteka-basic-crm' ); ?></th>
							<td><?php echo esc_html( '' !== $a['notes'] ? wp_trim_words( $a['notes'], 30 ) : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $b['notes'] ? wp_trim_words( $b['notes'], 30 ) : '—' ); ?></td>
							<td><?php esc_html_e( 'Las de A; las de B pasan al historial', 'suiteka-basic-crm' ); ?></td>
							<td><?php esc_html_e( 'Las de B; las de A pasan al historial', 'suiteka-basic-crm' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<form method="post" action="
			<?php
			echo esc_url(
				Suiteka_Basic_CRM_Admin::url(
					Suiteka_Basic_CRM_Admin::DUPLICATES,
					array(
						'action' => 'merge',
						'a'      => $a_id,
						'b'      => $b_id,
					)
				)
			);
			?>
										" class="suiteka-crm-panel">
				<?php wp_nonce_field( 'suiteka_crm_merge_contacts_' . min( $a_id, $b_id ) . '_' . max( $a_id, $b_id ) ); ?>
				<input type="hidden" name="suiteka_crm_operation" value="merge">
				<input type="hidden" name="contact_a" value="<?php echo esc_attr( $a_id ); ?>">
				<input type="hidden" name="contact_b" value="<?php echo esc_attr( $b_id ); ?>">
				<fieldset>
					<legend><strong><?php esc_html_e( '¿Qué contacto quieres conservar?', 'suiteka-basic-crm' ); ?></strong></legend>
					<p>
						<label><input type="radio" name="keep" value="<?php echo esc_attr( $a_id ); ?>" checked> <?php echo esc_html( sprintf( 'A: %s', $a['full_name'] ) ); ?></label><br>
						<label><input type="radio" name="keep" value="<?php echo esc_attr( $b_id ); ?>"> <?php echo esc_html( sprintf( 'B: %s', $b['full_name'] ) ); ?></label>
					</p>
				</fieldset>
				<p>
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Combinar contactos', 'suiteka-basic-crm' ); ?>">
					<a href="<?php echo esc_url( Suiteka_Basic_CRM_Admin::url( Suiteka_Basic_CRM_Admin::DUPLICATES ) ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'suiteka-basic-crm' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}
}
