<?php
/**
 * Capacidades del CRM y reglas de acceso por registro.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define las capacidades propias, traduce las meta-capacidades por registro
 * y calcula qué registros puede ver el usuario actual.
 */
final class Suiteka_Basic_CRM_Capabilities {

	const VIEW          = 'suiteka_crm_view';
	const VIEW_OTHERS   = 'suiteka_crm_view_others';
	const EDIT          = 'suiteka_crm_edit';
	const EDIT_OTHERS   = 'suiteka_crm_edit_others';
	const DELETE        = 'suiteka_crm_delete';
	const DELETE_OTHERS = 'suiteka_crm_delete_others';
	const IMPORT        = 'suiteka_crm_import';
	const EXPORT        = 'suiteka_crm_export';
	const MANAGE        = 'suiteka_crm_manage';

	// Meta-capacidades: reciben como argumento el ID del responsable del registro.
	const VIEW_RECORD   = 'suiteka_crm_view_record';
	const EDIT_RECORD   = 'suiteka_crm_edit_record';
	const DELETE_RECORD = 'suiteka_crm_delete_record';

	/**
	 * Todas las capacidades primitivas del CRM.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::VIEW,
			self::VIEW_OTHERS,
			self::EDIT,
			self::EDIT_OTHERS,
			self::DELETE,
			self::DELETE_OTHERS,
			self::IMPORT,
			self::EXPORT,
			self::MANAGE,
		);
	}

	/**
	 * Traduce las meta-capacidades por registro a capacidades primitivas.
	 *
	 * Un registro es propio si su responsable es el usuario. Los registros
	 * sin responsable (0) se tratan como ajenos.
	 *
	 * @param string[] $caps    Capacidades primitivas requeridas.
	 * @param string   $cap     Capacidad solicitada.
	 * @param int      $user_id Usuario.
	 * @param array    $args    Argumentos: [0] ID del responsable del registro.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( self::VIEW_RECORD, self::EDIT_RECORD, self::DELETE_RECORD ), true ) ) {
			return $caps;
		}

		$owner_id = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 0;
		$own      = $owner_id > 0 && (int) $user_id === $owner_id;

		switch ( $cap ) {
			case self::VIEW_RECORD:
				return $own ? array( self::VIEW ) : array( self::VIEW, self::VIEW_OTHERS );
			case self::EDIT_RECORD:
				return $own ? array( self::EDIT ) : array( self::EDIT, self::VIEW_OTHERS, self::EDIT_OTHERS );
			default:
				return $own ? array( self::DELETE ) : array( self::DELETE, self::VIEW_OTHERS, self::DELETE_OTHERS );
		}
	}

	/**
	 * Ámbito de visibilidad del usuario actual para las consultas.
	 *
	 * @return int|null Null si puede ver todos los registros; si no, el ID
	 *                  del usuario (las consultas excluyen además owner_id 0).
	 */
	public static function scope() {
		if ( current_user_can( self::VIEW_OTHERS ) ) {
			return null;
		}

		return get_current_user_id();
	}

	/**
	 * Indica si un usuario puede ser responsable de registros.
	 *
	 * @param int $user_id ID del usuario (0 = sin responsable).
	 * @return bool
	 */
	public static function is_assignable_owner( $user_id ) {
		$user_id = (int) $user_id;

		if ( 0 === $user_id ) {
			return true;
		}

		return $user_id > 0 && user_can( $user_id, self::VIEW );
	}

	/**
	 * Usuarios que pueden ser responsables, ordenados por nombre.
	 *
	 * @return WP_User[]
	 */
	public static function assignable_users() {
		$roles = array();

		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( $role->has_cap( self::VIEW ) ) {
				$roles[] = $name;
			}
		}

		$users = array();

		if ( ! empty( $roles ) ) {
			$users = get_users(
				array(
					'role__in' => $roles,
					'orderby'  => 'display_name',
					'order'    => 'ASC',
					'number'   => 500,
				)
			);
		}

		// El usuario actual siempre puede asignarse a sí mismo (p. ej. superadministradores).
		$current = wp_get_current_user();
		if ( $current->exists() && current_user_can( self::VIEW ) ) {
			$ids = wp_list_pluck( $users, 'ID' );
			if ( ! in_array( $current->ID, array_map( 'intval', $ids ), true ) ) {
				$users[] = $current;
			}
		}

		return $users;
	}

	/**
	 * Concede todas las capacidades a los roles que tienen manage_options.
	 *
	 * Así, quien accedía al CRM en 1.0.0 sigue accediendo igual.
	 */
	public static function grant_to_admin_roles() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( ! $role->has_cap( 'manage_options' ) ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Retira las capacidades del CRM de todos los roles.
	 */
	public static function remove_from_all_roles() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
