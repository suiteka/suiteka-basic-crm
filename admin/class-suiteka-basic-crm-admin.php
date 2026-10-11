<?php
/**
 * Menú de administración y utilidades de interfaz comunes.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra el menú y ofrece utilidades a los controladores de cada pantalla.
 */
final class Suiteka_Basic_CRM_Admin {

	const CONTACTS    = 'suiteka-crm';
	const CONTACT_NEW = 'suiteka-crm-new';
	const COMPANIES   = 'suiteka-crm-companies';
	const DUPLICATES  = 'suiteka-crm-duplicates';

	/**
	 * Sufijos de las pantallas del plugin.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Registra los hooks de administración.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Registra el menú «Suiteka CRM» y sus submenús.
	 */
	public static function register_menu() {
		$caps = 'Suiteka_Basic_CRM_Capabilities';

		self::$hooks['contacts'] = add_menu_page(
			__( 'Suiteka CRM', 'suiteka-basic-crm' ),
			__( 'Suiteka CRM', 'suiteka-basic-crm' ),
			$caps::VIEW,
			self::CONTACTS,
			array( 'Suiteka_Basic_CRM_Admin_Contacts', 'render_main' ),
			'dashicons-groups'
		);

		add_submenu_page(
			self::CONTACTS,
			__( 'Contactos', 'suiteka-basic-crm' ),
			__( 'Contactos', 'suiteka-basic-crm' ),
			$caps::VIEW,
			self::CONTACTS,
			array( 'Suiteka_Basic_CRM_Admin_Contacts', 'render_main' )
		);

		self::$hooks['new'] = add_submenu_page(
			self::CONTACTS,
			__( 'Añadir contacto', 'suiteka-basic-crm' ),
			__( 'Añadir contacto', 'suiteka-basic-crm' ),
			$caps::EDIT,
			self::CONTACT_NEW,
			array( 'Suiteka_Basic_CRM_Admin_Contacts', 'render_new' )
		);

		self::$hooks['companies'] = add_submenu_page(
			self::CONTACTS,
			__( 'Empresas', 'suiteka-basic-crm' ),
			__( 'Empresas', 'suiteka-basic-crm' ),
			$caps::VIEW,
			self::COMPANIES,
			array( 'Suiteka_Basic_CRM_Admin_Companies', 'render' )
		);

		self::$hooks['duplicates'] = add_submenu_page(
			self::CONTACTS,
			__( 'Posibles duplicados', 'suiteka-basic-crm' ),
			__( 'Posibles duplicados', 'suiteka-basic-crm' ),
			$caps::EDIT,
			self::DUPLICATES,
			array( 'Suiteka_Basic_CRM_Admin_Duplicates', 'render' )
		);

		// Los envíos y los permisos se procesan antes de imprimir nada, para poder redirigir o denegar.
		add_action( 'load-' . self::$hooks['contacts'], array( 'Suiteka_Basic_CRM_Admin_Contacts', 'load' ) );
		add_action( 'load-' . self::$hooks['new'], array( 'Suiteka_Basic_CRM_Admin_Contacts', 'load' ) );
		add_action( 'load-' . self::$hooks['companies'], array( 'Suiteka_Basic_CRM_Admin_Companies', 'load' ) );
		add_action( 'load-' . self::$hooks['duplicates'], array( 'Suiteka_Basic_CRM_Admin_Duplicates', 'load' ) );
	}

	/**
	 * Encola el CSS solo en las pantallas del plugin.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public static function enqueue_styles( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, self::$hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'suiteka-basic-crm', plugins_url( 'assets/css/admin.css', SUITEKA_BASIC_CRM_FILE ), array(), SUITEKA_BASIC_CRM_VERSION );
	}

	/**
	 * URL de una pantalla del plugin.
	 *
	 * @param string $page Slug de la pantalla.
	 * @param array  $args Parámetros adicionales.
	 * @return string
	 */
	public static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Redirige a una pantalla del plugin y termina.
	 *
	 * @param string $page Slug.
	 * @param array  $args Parámetros.
	 */
	public static function redirect( $page, $args = array() ) {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	/**
	 * Indica si la petición actual es POST.
	 *
	 * @return bool
	 */
	public static function is_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return 'POST' === $method;
	}

	/**
	 * Operación solicitada por POST (el nonce se verifica en cada operación).
	 *
	 * @return string
	 */
	public static function operation() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Solo elige la operación; cada una verifica su nonce.
		return isset( $_POST['suiteka_crm_operation'] ) && is_string( $_POST['suiteka_crm_operation'] ) ? sanitize_key( wp_unslash( $_POST['suiteka_crm_operation'] ) ) : '';
	}

	/**
	 * Entero enviado por POST (antes de verificar el nonce, que depende de él).
	 *
	 * @param string $key Campo.
	 * @return int
	 */
	public static function post_id( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- El ID forma parte del nombre del nonce, que se verifica a continuación.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
	}

	/**
	 * Datos POST sin barras invertidas. Llamar solo tras verificar el nonce.
	 *
	 * @return array
	 */
	public static function post_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce ya verificado por quien llama; cada campo se sanea en el validador.
		return wp_unslash( $_POST );
	}

	/**
	 * Texto de la URL (navegación de solo lectura).
	 *
	 * @param string $key Parámetro.
	 * @return string
	 */
	public static function get_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación de solo lectura.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
	}

	/**
	 * Clave de la URL (navegación de solo lectura).
	 *
	 * @param string $key Parámetro.
	 * @return string
	 */
	public static function get_key( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación de solo lectura.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Entero no negativo de la URL (navegación de solo lectura).
	 *
	 * @param string $key Parámetro.
	 * @return int
	 */
	public static function get_int( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación de solo lectura.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
	}

	/**
	 * Deniega el acceso con un 403.
	 *
	 * @param string $message Mensaje opcional.
	 */
	public static function deny( $message = '' ) {
		wp_die(
			esc_html( $message ? $message : __( 'No tienes permisos para realizar esta acción.', 'suiteka-basic-crm' ) ),
			esc_html__( 'Acceso denegado', 'suiteka-basic-crm' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * Deniega el acceso si el usuario no tiene la capacidad.
	 *
	 * @param string $cap      Capacidad o meta-capacidad.
	 * @param int    $owner_id ID del responsable (para meta-capacidades).
	 */
	public static function require_cap( $cap, $owner_id = null ) {
		$allowed = null === $owner_id ? current_user_can( $cap ) : current_user_can( $cap, (int) $owner_id );

		if ( ! $allowed ) {
			self::deny();
		}
	}

	/**
	 * Pantalla de «registro no encontrado».
	 *
	 * @param string $title   Título.
	 * @param string $message Mensaje.
	 * @param string $back    URL de vuelta.
	 */
	public static function render_not_found( $title, $message, $back ) {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Volver al listado', 'suiteka-basic-crm' ) . '</a></p></div>';
	}

	/**
	 * Aviso elegido por el parámetro message de la URL (lista cerrada).
	 *
	 * @param array $messages Código => array( tipo, texto ).
	 */
	public static function render_url_notice( $messages ) {
		$code = self::get_key( 'message' );

		if ( isset( $messages[ $code ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $code ][0] ),
				esc_html( $messages[ $code ][1] )
			);
		}
	}

	/**
	 * Resumen de errores con enlaces a cada campo.
	 *
	 * @param string[] $errors Campo => mensaje.
	 * @param string[] $ids    Campo => ID del control en el formulario.
	 */
	public static function render_error_summary( $errors, $ids ) {
		if ( empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error suiteka-crm-error-summary" role="alert" tabindex="-1" id="suiteka-crm-error-summary">';
		echo '<p><strong>' . esc_html__( 'No se ha guardado. Revisa los siguientes errores:', 'suiteka-basic-crm' ) . '</strong></p><ul>';
		foreach ( $errors as $field => $message ) {
			if ( isset( $ids[ $field ] ) ) {
				echo '<li><a href="#' . esc_attr( $ids[ $field ] ) . '">' . esc_html( $message ) . '</a></li>';
			} else {
				echo '<li>' . esc_html( $message ) . '</li>';
			}
		}
		echo '</ul></div>';
	}

	/**
	 * Atributos ARIA de un control con error.
	 *
	 * @param string[] $errors Errores.
	 * @param string   $field  Campo.
	 * @param string   $id     ID del control.
	 * @param string   $hint   ID de un texto de ayuda opcional.
	 */
	public static function aria( $errors, $field, $id, $hint = '' ) {
		$described = array();

		if ( $hint ) {
			$described[] = $hint;
		}

		if ( isset( $errors[ $field ] ) ) {
			$described[] = $id . '-error';
			echo ' aria-invalid="true"';
		}

		if ( $described ) {
			echo ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"';
		}
	}

	/**
	 * Mensaje de error junto a un control.
	 *
	 * @param string[] $errors Errores.
	 * @param string   $field  Campo.
	 * @param string   $id     ID del control.
	 */
	public static function field_error( $errors, $field, $id ) {
		if ( isset( $errors[ $field ] ) ) {
			echo '<p class="suiteka-crm-field-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( $errors[ $field ] ) . '</p>';
		}
	}

	/**
	 * Lista desplegable.
	 *
	 * @param string $name     Nombre.
	 * @param string $id       ID.
	 * @param array  $options  Valor => etiqueta.
	 * @param string $selected Valor seleccionado.
	 * @param string $extra    Atributos adicionales ya escapados.
	 */
	public static function select( $name, $id, $options, $selected, $extra = '' ) {
		// $extra solo lo construye el propio plugin con valores escapados.
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ver comentario anterior.
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Opciones de responsable para un desplegable.
	 *
	 * @param bool $with_none Incluir «Sin responsable».
	 * @return string[]
	 */
	public static function owner_options( $with_none = true ) {
		$options = array();

		if ( $with_none ) {
			$options['0'] = __( 'Sin responsable', 'suiteka-basic-crm' );
		}

		foreach ( Suiteka_Basic_CRM_Capabilities::assignable_users() as $user ) {
			$options[ (string) $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/**
	 * Nombre visible de un responsable.
	 *
	 * @param int $user_id ID.
	 * @return string
	 */
	public static function user_name( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return __( 'Sin responsable', 'suiteka-basic-crm' );
		}

		$user = get_userdata( $user_id );

		/* translators: %d: ID de un usuario que ya no existe. */
		return $user ? $user->display_name : sprintf( __( 'Usuario eliminado (n.º %d)', 'suiteka-basic-crm' ), $user_id );
	}

	/**
	 * Fecha UTC de la base de datos en la zona y el formato del sitio.
	 *
	 * @param string|null $gmt_date Fecha MySQL en UTC.
	 * @return string
	 */
	public static function format_date( $gmt_date ) {
		if ( empty( $gmt_date ) || '0000-00-00 00:00:00' === $gmt_date ) {
			return '—';
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return mysql2date( $format, get_date_from_gmt( $gmt_date ) );
	}

	/**
	 * Encabezado de columna ordenable con aria-sort.
	 *
	 * @param string $label   Texto.
	 * @param string $key     Clave de ordenación.
	 * @param string $page    Slug de la pantalla.
	 * @param array  $args    Parámetros actuales (filtros, orderby, order).
	 * @param string $initial Orden al pulsar por primera vez (asc|desc).
	 */
	public static function sortable_header( $label, $key, $page, $args, $initial = 'asc' ) {
		$current = isset( $args['orderby'] ) && $key === $args['orderby'];
		$order   = $current && isset( $args['order'] ) ? strtolower( $args['order'] ) : '';
		$next    = $current ? ( 'asc' === $order ? 'desc' : 'asc' ) : $initial;
		$url     = self::url(
			$page,
			array_merge(
				$args,
				array(
					'orderby' => $key,
					'order'   => $next,
				)
			)
		);

		$class = $current ? 'sorted ' . $order : 'sortable ' . ( 'asc' === $initial ? 'desc' : 'asc' );
		$aria  = $current ? ( 'asc' === $order ? 'ascending' : 'descending' ) : '';

		echo '<th scope="col" class="manage-column ' . esc_attr( $class ) . '"' . ( $aria ? ' aria-sort="' . esc_attr( $aria ) . '"' : '' ) . '>';
		echo '<a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span>';
		// Indicador propio: el marcado del núcleo cambió en WordPress 6.3.
		if ( $current ) {
			echo '<span class="suiteka-crm-sort-indicator" aria-hidden="true">' . ( 'asc' === $order ? '&#9650;' : '&#9660;' ) . '</span>';
		}
		echo '<span class="screen-reader-text">' . esc_html( 'asc' === $next ? __( 'Ordenar de forma ascendente.', 'suiteka-basic-crm' ) : __( 'Ordenar de forma descendente.', 'suiteka-basic-crm' ) ) . '</span>';
		echo '</a></th>';
	}

	/**
	 * Paginación con el aspecto del escritorio. Solo conserva los parámetros
	 * indicados (nunca el aviso de la acción anterior).
	 *
	 * @param string $page     Slug de la pantalla.
	 * @param array  $args     Parámetros que se conservan.
	 * @param int    $paged    Página actual.
	 * @param int    $total    Total de elementos.
	 * @param int    $per_page Elementos por página.
	 */
	public static function pagination( $page, $args, $paged, $total, $per_page ) {
		$pages = (int) ceil( $total / $per_page );

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		/* translators: %s: número de elementos. */
		echo '<span class="displaying-num">' . esc_html( sprintf( _n( '%s elemento', '%s elementos', $total, 'suiteka-basic-crm' ), number_format_i18n( $total ) ) ) . '</span>';

		if ( $pages > 1 ) {
			$links = array(
				array( 'first-page', 1, __( 'Primera página', 'suiteka-basic-crm' ), '&laquo;', $paged > 1 ),
				array( 'prev-page', $paged - 1, __( 'Página anterior', 'suiteka-basic-crm' ), '&lsaquo;', $paged > 1 ),
				array( 'next-page', $paged + 1, __( 'Página siguiente', 'suiteka-basic-crm' ), '&rsaquo;', $paged < $pages ),
				array( 'last-page', $pages, __( 'Última página', 'suiteka-basic-crm' ), '&raquo;', $paged < $pages ),
			);

			echo '<nav class="pagination-links" aria-label="' . esc_attr__( 'Paginación', 'suiteka-basic-crm' ) . '">';
			foreach ( $links as $i => $link ) {
				if ( 2 === $i ) {
					/* translators: 1: página actual, 2: total de páginas. */
					echo '<span class="paging-input">' . esc_html( sprintf( __( 'Página %1$s de %2$s', 'suiteka-basic-crm' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) ) . '</span> ';
				}
				list( $class, $target, $text, $symbol, $enabled ) = $link;
				if ( $enabled ) {
					$url = self::url( $page, array_merge( $args, array( 'paged' => $target ) ) );
					echo '<a class="' . esc_attr( $class ) . ' button" href="' . esc_url( $url ) . '"><span class="screen-reader-text">' . esc_html( $text ) . '</span><span aria-hidden="true">' . esc_html( html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) ) . '</span></a> ';
				} else {
					echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . esc_html( html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) ) . '</span> ';
				}
			}
			echo '</nav>';
		}

		echo '</div></div>';
	}
}
