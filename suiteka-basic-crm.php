<?php
/**
 * Plugin Name:       Suiteka Basic CRM
 * Plugin URI:        https://suiteka.com/
 * Description:       Gestor básico de contactos en el panel de administración de WordPress.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.0
 * Author:            Suiteka
 * Author URI:        https://suiteka.com/
 * Text Domain:       suiteka-basic-crm
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// El plugin usa su propia tabla: las consultas directas con $wpdb son
// intencionadas y no se cachean porque siempre deben reflejar el estado real.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

define( 'SUITEKA_BASIC_CRM_VERSION', '1.0.0' );
define( 'SUITEKA_BASIC_CRM_DB_VERSION', '1' );

/**
 * Plugin principal: menú de administración y CRUD de contactos.
 */
final class Suiteka_Basic_CRM {

	const CAPABILITY        = 'manage_options';
	const MENU_SLUG         = 'suiteka-crm';
	const NEW_SLUG          = 'suiteka-crm-new';
	const PER_PAGE          = 20;
	const DB_VERSION_OPTION = 'suiteka_basic_crm_db_version';

	/**
	 * Longitudes máximas de los campos de texto (en caracteres).
	 *
	 * @var array
	 */
	private static $max_lengths = array(
		'full_name' => 191,
		'email'     => 100,
		'phone'     => 50,
		'company'   => 191,
		'notes'     => 5000,
	);

	/**
	 * Errores de validación del último envío.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Datos enviados que se vuelven a mostrar si hay errores.
	 *
	 * @var array|null
	 */
	private static $form_data = null;

	/**
	 * Registra los hooks del plugin.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Nombre completo de la tabla de contactos.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'suiteka_crm_contacts';
	}

	/**
	 * Crea o actualiza la tabla de contactos.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			full_name varchar(191) NOT NULL,
			email varchar(100) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			company varchar(191) NOT NULL DEFAULT '',
			notes text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY full_name (full_name),
			KEY email (email),
			KEY company (company),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, SUITEKA_BASIC_CRM_DB_VERSION );
	}

	/**
	 * Instala la tabla si falta o si cambió la versión del esquema.
	 *
	 * Cubre actualizaciones manuales del plugin y sitios de una red
	 * multisitio en los que no se ejecutó el hook de activación.
	 */
	public static function maybe_install() {
		if ( SUITEKA_BASIC_CRM_DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Registra el menú «Suiteka CRM» y sus submenús.
	 */
	public static function register_menu() {
		$list_hook = add_menu_page(
			__( 'Suiteka CRM', 'suiteka-basic-crm' ),
			__( 'Suiteka CRM', 'suiteka-basic-crm' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_main_page' ),
			'dashicons-groups'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Contactos', 'suiteka-basic-crm' ),
			__( 'Contactos', 'suiteka-basic-crm' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_main_page' )
		);

		$new_hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'Añadir contacto', 'suiteka-basic-crm' ),
			__( 'Añadir contacto', 'suiteka-basic-crm' ),
			self::CAPABILITY,
			self::NEW_SLUG,
			array( __CLASS__, 'render_new_page' )
		);

		// Los envíos se procesan antes de imprimir cabeceras para poder redirigir.
		add_action( 'load-' . $list_hook, array( __CLASS__, 'handle_request' ) );
		add_action( 'load-' . $new_hook, array( __CLASS__, 'handle_request' ) );
	}

	/**
	 * Añade el CSS mínimo solo en las pantallas del plugin.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public static function enqueue_styles( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		$css = '.suiteka-crm-search{float:right;margin:8px 0}'
			. '.suiteka-crm-table-wrap{overflow-x:auto;clear:both}.suiteka-crm-table-wrap table{min-width:720px}'
			. '.suiteka-crm-empty{background:#fff;border:1px solid #c3c4c7;padding:24px;margin-top:16px;max-width:640px}'
			. '.suiteka-crm-empty p{font-size:14px}'
			. '.suiteka-crm-delete{background:#fff;border:1px solid #c3c4c7;padding:16px 24px;margin-top:16px;max-width:640px}'
			. '@media screen and (max-width:782px){.suiteka-crm-search{float:none}.suiteka-crm-search input[type=search]{width:100%;margin-bottom:8px}}';

		wp_register_style( 'suiteka-basic-crm', false, array(), SUITEKA_BASIC_CRM_VERSION );
		wp_enqueue_style( 'suiteka-basic-crm' );
		wp_add_inline_style( 'suiteka-basic-crm', $css );
	}

	/**
	 * Procesa los envíos POST de creación, edición y eliminación.
	 */
	public static function handle_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'suiteka-basic-crm' ), 403 );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		// El nonce se comprueba dentro de cada operación, ligado al contacto.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$operation = isset( $_POST['suiteka_crm_operation'] ) ? sanitize_key( wp_unslash( $_POST['suiteka_crm_operation'] ) ) : '';

		if ( 'save' === $operation ) {
			self::handle_save();
		} elseif ( 'delete' === $operation ) {
			self::handle_delete();
		}
	}

	/**
	 * Crea o actualiza un contacto.
	 */
	private static function handle_save() {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Se verifica justo debajo.
		$id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		check_admin_referer( 'suiteka_crm_save_contact_' . $id );

		if ( $id && ! self::get_contact( $id ) ) {
			self::redirect_to_list( 'not_found' );
		}

		$data   = self::sanitize_contact( wp_unslash( $_POST ) );
		$errors = self::validate_contact( $data );

		if ( ! empty( $errors ) ) {
			self::$errors    = $errors;
			self::$form_data = $data;
			return;
		}

		$data['email'] = sanitize_email( $data['email'] );
		$formats       = array( '%s', '%s', '%s', '%s', '%s' );

		if ( $id ) {
			$result  = $wpdb->update( self::table_name(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$message = 'updated';
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			$formats[]          = '%s';
			$result             = $wpdb->insert( self::table_name(), $data, $formats );
			$message            = 'created';
		}

		if ( false === $result ) {
			self::$errors    = array( __( 'No se pudo guardar el contacto. Inténtalo de nuevo.', 'suiteka-basic-crm' ) );
			self::$form_data = $data;
			return;
		}

		self::redirect_to_list( $message );
	}

	/**
	 * Elimina un contacto tras la confirmación enviada por POST.
	 */
	private static function handle_delete() {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Se verifica justo debajo.
		$id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		check_admin_referer( 'suiteka_crm_delete_contact_' . $id );

		if ( ! $id || ! self::get_contact( $id ) ) {
			self::redirect_to_list( 'not_found' );
		}

		$result = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );

		self::redirect_to_list( $result ? 'deleted' : 'delete_error' );
	}

	/**
	 * Limpia los campos del formulario.
	 *
	 * @param array $input Datos sin barras invertidas añadidas.
	 * @return array
	 */
	private static function sanitize_contact( $input ) {
		$field = function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : '';
		};

		return array(
			'full_name' => sanitize_text_field( $field( 'full_name' ) ),
			'email'     => sanitize_text_field( $field( 'email' ) ),
			'phone'     => sanitize_text_field( $field( 'phone' ) ),
			'company'   => sanitize_text_field( $field( 'company' ) ),
			'notes'     => sanitize_textarea_field( $field( 'notes' ) ),
		);
	}

	/**
	 * Valida los datos ya limpiados.
	 *
	 * @param array $data Datos del contacto.
	 * @return string[] Mensajes de error.
	 */
	private static function validate_contact( $data ) {
		$errors = array();

		if ( '' === $data['full_name'] ) {
			$errors[] = __( 'El nombre completo es obligatorio.', 'suiteka-basic-crm' );
		}

		if ( '' !== $data['email'] && ! is_email( $data['email'] ) ) {
			$errors[] = __( 'El correo electrónico no es válido.', 'suiteka-basic-crm' );
		}

		if ( '' !== $data['phone'] && ! preg_match( '/^[0-9+()\-.\s]+$/', $data['phone'] ) ) {
			$errors[] = __( 'El teléfono solo puede contener números, espacios y los signos + ( ) - .', 'suiteka-basic-crm' );
		}

		$labels = array(
			'full_name' => __( 'Nombre completo', 'suiteka-basic-crm' ),
			'email'     => __( 'Correo electrónico', 'suiteka-basic-crm' ),
			'phone'     => __( 'Teléfono', 'suiteka-basic-crm' ),
			'company'   => __( 'Empresa', 'suiteka-basic-crm' ),
			'notes'     => __( 'Notas', 'suiteka-basic-crm' ),
		);

		foreach ( self::$max_lengths as $key => $max ) {
			if ( mb_strlen( $data[ $key ] ) > $max ) {
				/* translators: 1: nombre del campo, 2: número máximo de caracteres. */
				$errors[] = sprintf( __( 'El campo «%1$s» no puede superar %2$d caracteres.', 'suiteka-basic-crm' ), $labels[ $key ], $max );
			}
		}

		return $errors;
	}

	/**
	 * Obtiene un contacto por su ID.
	 *
	 * @param int $id ID del contacto.
	 * @return array|null
	 */
	private static function get_contact( $id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de la tabla no procede del usuario.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Obtiene una página de contactos y el total, con búsqueda opcional.
	 *
	 * @param string $search Texto de búsqueda.
	 * @param int    $paged  Página actual (desde 1).
	 * @return array { items: array[], total: int }
	 */
	private static function query_contacts( $search, $paged ) {
		global $wpdb;

		$table = self::table_name();
		$items = array();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de la tabla no procede del usuario; los valores van en prepare().
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE full_name LIKE %s OR email LIKE %s OR company LIKE %s", $like, $like, $like )
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		if ( $total > 0 ) {
			$max_page = (int) ceil( $total / self::PER_PAGE );
			$offset   = ( min( $paged, $max_page ) - 1 ) * self::PER_PAGE;

			if ( '' !== $search ) {
				$items = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, full_name, email, phone, company, created_at FROM {$table} WHERE full_name LIKE %s OR email LIKE %s OR company LIKE %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
						$like,
						$like,
						$like,
						self::PER_PAGE,
						$offset
					),
					ARRAY_A
				);
			} else {
				$items = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, full_name, email, phone, company, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
						self::PER_PAGE,
						$offset
					),
					ARRAY_A
				);
			}
		}
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * URL de una pantalla del plugin.
	 *
	 * @param array $args Parámetros adicionales.
	 * @return string
	 */
	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Redirige al listado con un código de mensaje y termina la ejecución.
	 *
	 * @param string $message Código de mensaje.
	 */
	private static function redirect_to_list( $message ) {
		wp_safe_redirect( self::page_url( array( 'message' => $message ) ) );
		exit;
	}

	/**
	 * Muestra el aviso correspondiente al código de la URL y los errores de validación.
	 */
	private static function render_notices() {
		$messages = array(
			'created'      => array( 'success', __( 'Contacto creado correctamente.', 'suiteka-basic-crm' ) ),
			'updated'      => array( 'success', __( 'Contacto actualizado correctamente.', 'suiteka-basic-crm' ) ),
			'deleted'      => array( 'success', __( 'Contacto eliminado correctamente.', 'suiteka-basic-crm' ) ),
			'not_found'    => array( 'error', __( 'El contacto no existe o ya fue eliminado.', 'suiteka-basic-crm' ) ),
			'delete_error' => array( 'error', __( 'No se pudo eliminar el contacto. Inténtalo de nuevo.', 'suiteka-basic-crm' ) ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo selecciona un mensaje fijo.
		$code = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';

		if ( isset( $messages[ $code ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $code ][0] ),
				esc_html( $messages[ $code ][1] )
			);
		}

		if ( ! empty( self::$errors ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Revisa los siguientes errores:', 'suiteka-basic-crm' ) . '</p><ul>';
			foreach ( self::$errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Pantalla «Contactos»: listado, edición o confirmación de eliminación.
	 */
	public static function render_main_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'suiteka-basic-crm' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navegación de solo lectura.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable

		if ( in_array( $action, array( 'edit', 'delete' ), true ) ) {
			$contact = $id ? self::get_contact( $id ) : null;

			if ( ! $contact ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Contactos', 'suiteka-basic-crm' ) . '</h1>';
				echo '<div class="notice notice-error"><p>' . esc_html__( 'El contacto no existe o ya fue eliminado.', 'suiteka-basic-crm' ) . '</p></div>';
				echo '<p><a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Volver a contactos', 'suiteka-basic-crm' ) . '</a></p></div>';
				return;
			}

			if ( 'edit' === $action ) {
				self::render_form( $contact );
			} else {
				self::render_delete_confirmation( $contact );
			}
			return;
		}

		self::render_list();
	}

	/**
	 * Pantalla «Añadir contacto».
	 */
	public static function render_new_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'suiteka-basic-crm' ), 403 );
		}

		self::render_form( null );
	}

	/**
	 * Listado de contactos con búsqueda y paginación.
	 */
	private static function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Búsqueda y paginación de solo lectura.
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$result      = self::query_contacts( $search, $paged );
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		$paged       = $total_pages ? min( $paged, $total_pages ) : 1;
		$new_url     = add_query_arg( 'page', self::NEW_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Contactos', 'suiteka-basic-crm' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir contacto', 'suiteka-basic-crm' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<?php if ( 0 === $total && '' === $search ) : ?>
				<div class="suiteka-crm-empty">
					<h2><?php esc_html_e( 'Todavía no tienes contactos', 'suiteka-basic-crm' ); ?></h2>
					<p><?php esc_html_e( 'Guarda aquí a tus clientes y personas de contacto: nombre, correo electrónico, teléfono, empresa y notas. Después podrás buscarlos y editarlos desde esta pantalla.', 'suiteka-basic-crm' ); ?></p>
					<p><a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary"><?php esc_html_e( 'Añadir el primer contacto', 'suiteka-basic-crm' ); ?></a></p>
				</div>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="suiteka-crm-search" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
					<label class="screen-reader-text" for="suiteka-crm-search-input"><?php esc_html_e( 'Buscar contactos', 'suiteka-basic-crm' ); ?></label>
					<input type="search" id="suiteka-crm-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Nombre, correo o empresa', 'suiteka-basic-crm' ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Buscar', 'suiteka-basic-crm' ); ?>">
				</form>

				<?php if ( '' !== $search ) : ?>
					<p>
						<?php
						/* translators: 1: texto buscado, 2: número de resultados. */
						echo esc_html( sprintf( _n( 'Resultados para «%1$s»: %2$d contacto.', 'Resultados para «%1$s»: %2$d contactos.', $total, 'suiteka-basic-crm' ), $search, $total ) );
						?>
						<a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Ver todos', 'suiteka-basic-crm' ); ?></a>
					</p>
				<?php endif; ?>

				<?php if ( 0 === $total ) : ?>
					<div class="suiteka-crm-empty">
						<p><?php esc_html_e( 'No se encontraron contactos que coincidan con la búsqueda.', 'suiteka-basic-crm' ); ?></p>
					</div>
				<?php else : ?>
					<div class="suiteka-crm-table-wrap">
						<table class="widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Nombre completo', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Correo electrónico', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Empresa', 'suiteka-basic-crm' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Fecha de creación', 'suiteka-basic-crm' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result['items'] as $contact ) : ?>
									<?php
									$edit_url   = self::page_url(
										array(
											'action' => 'edit',
											'id'     => (int) $contact['id'],
										)
									);
									$delete_url = self::page_url(
										array(
											'action' => 'delete',
											'id'     => (int) $contact['id'],
										)
									);
									?>
									<tr>
										<td>
											<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $contact['full_name'] ); ?></a></strong>
											<div class="row-actions visible">
												<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Editar', 'suiteka-basic-crm' ); ?></a> | </span>
												<span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete"><?php esc_html_e( 'Eliminar', 'suiteka-basic-crm' ); ?></a></span>
											</div>
										</td>
										<td>
											<?php if ( '' !== $contact['email'] ) : ?>
												<a href="<?php echo esc_url( 'mailto:' . $contact['email'] ); ?>"><?php echo esc_html( $contact['email'] ); ?></a>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $contact['phone'] ); ?></td>
										<td><?php echo esc_html( $contact['company'] ); ?></td>
										<td><?php echo esc_html( self::format_date( $contact['created_at'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav bottom">
							<div class="tablenav-pages">
								<span class="displaying-num">
									<?php
									/* translators: %d: número total de contactos. */
									echo esc_html( sprintf( _n( '%d contacto', '%d contactos', $total, 'suiteka-basic-crm' ), $total ) );
									?>
								</span>
								<?php
								$base_args = '' !== $search ? array( 's' => rawurlencode( $search ) ) : array();
								echo wp_kses_post(
									paginate_links(
										array(
											'base'      => add_query_arg( 'paged', '%#%', self::page_url( $base_args ) ),
											'format'    => '',
											'current'   => $paged,
											'total'     => $total_pages,
											'prev_text' => '&laquo;',
											'next_text' => '&raquo;',
										)
									)
								);
								?>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Formulario de creación o edición.
	 *
	 * @param array|null $contact Contacto existente o null para uno nuevo.
	 */
	private static function render_form( $contact ) {
		$id     = $contact ? (int) $contact['id'] : 0;
		$values = array(
			'full_name' => '',
			'email'     => '',
			'phone'     => '',
			'company'   => '',
			'notes'     => '',
		);

		if ( null !== self::$form_data ) {
			$values = array_merge( $values, array_intersect_key( self::$form_data, $values ) );
		} elseif ( $contact ) {
			$values = array_merge( $values, array_intersect_key( $contact, $values ) );
		}

		if ( $id ) {
			$title      = __( 'Editar contacto', 'suiteka-basic-crm' );
			$action_url = self::page_url(
				array(
					'action' => 'edit',
					'id'     => $id,
				)
			);
		} else {
			$title      = __( 'Añadir contacto', 'suiteka-basic-crm' );
			$action_url = add_query_arg( 'page', self::NEW_SLUG, admin_url( 'admin.php' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php self::render_notices(); ?>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>" novalidate>
				<?php wp_nonce_field( 'suiteka_crm_save_contact_' . $id ); ?>
				<input type="hidden" name="suiteka_crm_operation" value="save">
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( $id ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="suiteka-crm-full-name"><?php esc_html_e( 'Nombre completo', 'suiteka-basic-crm' ); ?> <span aria-hidden="true">*</span></label></th>
						<td><input type="text" id="suiteka-crm-full-name" name="full_name" class="regular-text" maxlength="<?php echo esc_attr( self::$max_lengths['full_name'] ); ?>" value="<?php echo esc_attr( $values['full_name'] ); ?>" required aria-required="true"></td>
					</tr>
					<tr>
						<th scope="row"><label for="suiteka-crm-email"><?php esc_html_e( 'Correo electrónico', 'suiteka-basic-crm' ); ?></label></th>
						<td><input type="email" id="suiteka-crm-email" name="email" class="regular-text" maxlength="<?php echo esc_attr( self::$max_lengths['email'] ); ?>" value="<?php echo esc_attr( $values['email'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="suiteka-crm-phone"><?php esc_html_e( 'Teléfono', 'suiteka-basic-crm' ); ?></label></th>
						<td><input type="tel" id="suiteka-crm-phone" name="phone" class="regular-text" maxlength="<?php echo esc_attr( self::$max_lengths['phone'] ); ?>" value="<?php echo esc_attr( $values['phone'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="suiteka-crm-company"><?php esc_html_e( 'Empresa', 'suiteka-basic-crm' ); ?></label></th>
						<td><input type="text" id="suiteka-crm-company" name="company" class="regular-text" maxlength="<?php echo esc_attr( self::$max_lengths['company'] ); ?>" value="<?php echo esc_attr( $values['company'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="suiteka-crm-notes"><?php esc_html_e( 'Notas', 'suiteka-basic-crm' ); ?></label></th>
						<td><textarea id="suiteka-crm-notes" name="notes" class="large-text" rows="6"><?php echo esc_textarea( $values['notes'] ); ?></textarea></td>
					</tr>
					<?php if ( $contact ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Fecha de creación', 'suiteka-basic-crm' ); ?></th>
							<td><?php echo esc_html( self::format_date( $contact['created_at'] ) ); ?></td>
						</tr>
					<?php endif; ?>
				</table>

				<p><?php esc_html_e( 'Los campos marcados con * son obligatorios.', 'suiteka-basic-crm' ); ?></p>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php echo $id ? esc_attr__( 'Guardar cambios', 'suiteka-basic-crm' ) : esc_attr__( 'Crear contacto', 'suiteka-basic-crm' ); ?>">
					<a href="<?php echo esc_url( self::page_url() ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'suiteka-basic-crm' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Pantalla de confirmación de eliminación.
	 *
	 * @param array $contact Contacto que se va a eliminar.
	 */
	private static function render_delete_confirmation( $contact ) {
		$id = (int) $contact['id'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Eliminar contacto', 'suiteka-basic-crm' ); ?></h1>

			<div class="suiteka-crm-delete">
				<p>
					<?php
					/* translators: %s: nombre del contacto. */
					echo esc_html( sprintf( __( '¿Seguro que quieres eliminar el contacto «%s»? Esta acción no se puede deshacer.', 'suiteka-basic-crm' ), $contact['full_name'] ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
					<?php wp_nonce_field( 'suiteka_crm_delete_contact_' . $id ); ?>
					<input type="hidden" name="suiteka_crm_operation" value="delete">
					<input type="hidden" name="contact_id" value="<?php echo esc_attr( $id ); ?>">
					<p>
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Sí, eliminar contacto', 'suiteka-basic-crm' ); ?>">
						<a href="<?php echo esc_url( self::page_url() ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'suiteka-basic-crm' ); ?></a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Convierte una fecha UTC guardada en la base de datos a la zona y formato del sitio.
	 *
	 * @param string $gmt_date Fecha en formato MySQL (UTC).
	 * @return string
	 */
	private static function format_date( $gmt_date ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return mysql2date( $format, get_date_from_gmt( $gmt_date ) );
	}
}

register_activation_hook( __FILE__, array( 'Suiteka_Basic_CRM', 'install' ) );
Suiteka_Basic_CRM::init();
