<?php
/**
 * Etiquetas.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tablas propias.

/**
 * Etiquetas libres y su relación con los registros del CRM.
 */
final class Suiteka_Basic_CRM_Tags {

	const MAX_LENGTH = 50;
	const MAX_TAGS   = 20;

	/**
	 * Convierte un texto separado por comas en una lista de etiquetas.
	 *
	 * @param string $text Etiquetas separadas por comas (ya saneadas).
	 * @return string[]|WP_Error Nombres únicos (sin distinguir mayúsculas) o error.
	 */
	public static function parse( $text ) {
		$names = array();

		foreach ( explode( ',', (string) $text ) as $name ) {
			$name = trim( preg_replace( '/\s+/', ' ', $name ) );
			if ( '' === $name ) {
				continue;
			}
			if ( mb_strlen( $name ) > self::MAX_LENGTH ) {
				/* translators: %d: número máximo de caracteres. */
				return new WP_Error( 'tag_too_long', sprintf( __( 'Cada etiqueta puede tener como máximo %d caracteres.', 'suiteka-basic-crm' ), self::MAX_LENGTH ) );
			}
			// Si se repite con otras mayúsculas, se conserva la primera forma escrita.
			if ( ! isset( $names[ strtolower( $name ) ] ) ) {
				$names[ strtolower( $name ) ] = $name;
			}
		}

		if ( count( $names ) > self::MAX_TAGS ) {
			/* translators: %d: número máximo de etiquetas. */
			return new WP_Error( 'too_many_tags', sprintf( __( 'Puedes asignar como máximo %d etiquetas.', 'suiteka-basic-crm' ), self::MAX_TAGS ) );
		}

		return array_values( $names );
	}

	/**
	 * Sustituye las etiquetas de un registro.
	 *
	 * @param string   $object_type Tipo de registro (contact).
	 * @param int      $object_id   ID del registro.
	 * @param string[] $names       Etiquetas.
	 * @return bool True si cambió algo.
	 */
	public static function set_for_object( $object_type, $object_id, $names ) {
		global $wpdb;

		$relationships = Suiteka_Basic_CRM::table( 'tag_relationships' );
		$current       = self::get_for_object( $object_type, $object_id );
		$wanted        = array();

		foreach ( $names as $name ) {
			$id = self::get_or_create_id( $name );
			if ( $id ) {
				$wanted[ $id ] = $name;
			}
		}

		$add    = array_diff_key( $wanted, $current );
		$remove = array_diff_key( $current, $wanted );

		foreach ( array_keys( $remove ) as $tag_id ) {
			$wpdb->delete(
				$relationships,
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'tag_id'      => $tag_id,
				),
				array( '%s', '%d', '%d' )
			);
		}

		foreach ( array_keys( $add ) as $tag_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno; valores en prepare().
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$relationships} (tag_id, object_type, object_id) VALUES (%d, %s, %d)", $tag_id, $object_type, $object_id ) );
		}

		return ! empty( $add ) || ! empty( $remove );
	}

	/**
	 * Etiquetas de un registro.
	 *
	 * @param string $object_type Tipo de registro.
	 * @param int    $object_id   ID del registro.
	 * @return string[] Nombres indexados por ID de etiqueta, en orden alfabético.
	 */
	public static function get_for_object( $object_type, $object_id ) {
		$map = self::get_for_objects( $object_type, array( $object_id ) );

		return isset( $map[ $object_id ] ) ? $map[ $object_id ] : array();
	}

	/**
	 * Etiquetas de varios registros con una sola consulta.
	 *
	 * @param string $object_type Tipo de registro.
	 * @param int[]  $object_ids  IDs.
	 * @return array Mapa ID de registro => (ID de etiqueta => nombre).
	 */
	public static function get_for_objects( $object_type, $object_ids ) {
		global $wpdb;

		$object_ids = array_filter( array_map( 'absint', (array) $object_ids ) );
		if ( empty( $object_ids ) ) {
			return array();
		}

		$tags          = Suiteka_Basic_CRM::table( 'tags' );
		$relationships = Suiteka_Basic_CRM::table( 'tag_relationships' );
		$in            = implode( ',', $object_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombres de tabla internos e IDs convertidos con absint().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.object_id, t.id, t.name FROM {$relationships} r INNER JOIN {$tags} t ON t.id = r.tag_id WHERE r.object_type = %s AND r.object_id IN ({$in}) ORDER BY t.name ASC",
				$object_type
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->object_id ][ (int) $row->id ] = $row->name;
		}

		return $map;
	}

	/**
	 * Etiquetas en uso por registros visibles para el usuario.
	 *
	 * @param string   $object_type Tipo de registro.
	 * @param int|null $scope       Ámbito de visibilidad (ver Capabilities::scope()).
	 * @return string[] Nombres indexados por ID.
	 */
	public static function in_use( $object_type, $scope = null ) {
		global $wpdb;

		$tags          = Suiteka_Basic_CRM::table( 'tags' );
		$relationships = Suiteka_Basic_CRM::table( 'tag_relationships' );
		$owner_sql     = '';

		if ( null !== $scope && 'contact' === $object_type ) {
			$contacts = Suiteka_Basic_CRM::table( 'contacts' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno.
			$owner_sql = $wpdb->prepare( " AND r.object_id IN (SELECT id FROM {$contacts} WHERE owner_id = %d AND owner_id <> 0)", $scope );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Nombres de tabla internos; $owner_sql ya está preparado.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT t.id, t.name FROM {$tags} t INNER JOIN {$relationships} r ON r.tag_id = t.id WHERE r.object_type = %s{$owner_sql} ORDER BY t.name ASC",
				$object_type
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$list = array();
		foreach ( (array) $rows as $row ) {
			$list[ (int) $row->id ] = $row->name;
		}

		return $list;
	}

	/**
	 * Quita todas las etiquetas de un registro.
	 *
	 * @param string $object_type Tipo de registro.
	 * @param int    $object_id   ID.
	 */
	public static function delete_for_object( $object_type, $object_id ) {
		global $wpdb;

		$wpdb->delete(
			Suiteka_Basic_CRM::table( 'tag_relationships' ),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * ID de una etiqueta por nombre, creándola si no existe.
	 *
	 * @param string $name Nombre.
	 * @return int
	 */
	private static function get_or_create_id( $name ) {
		global $wpdb;

		$tags = Suiteka_Basic_CRM::table( 'tags' );

		// INSERT IGNORE evita errores si otra petición la crea a la vez (índice único).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno; valores en prepare().
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$tags} (name) VALUES (%s)", $name ) );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tags} WHERE name = %s LIMIT 1", $name ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}
}
