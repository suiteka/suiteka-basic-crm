<?php
/**
 * Esquema de la base de datos y migraciones.
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

// Tablas propias del plugin: las consultas directas son intencionadas y no se cachean.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Crea y actualiza las tablas y ejecuta las migraciones de datos.
 *
 * Reglas: dbDelta() solo añade (nunca borra ni renombra columnas); cada paso
 * de migración es idempotente y reanudable; la versión del esquema solo se
 * actualiza si todos los pasos terminan bien.
 */
final class Suiteka_Basic_CRM_Installer {

	const LOCK_OPTION  = 'suiteka_basic_crm_upgrade_lock';
	const LOCK_TIMEOUT = 600;
	const BATCH_SIZE   = 200;

	/**
	 * Instala o actualiza el esquema del sitio actual.
	 *
	 * @return bool True si el esquema quedó en la versión actual.
	 */
	public static function install() {
		if ( ! self::acquire_lock() ) {
			return false;
		}

		$from = (string) get_option( Suiteka_Basic_CRM::DB_VERSION_OPTION, '' );
		$ok   = true;

		self::create_tables();

		// Sitio nuevo o actualización desde 1.0.0 (esquema 1).
		if ( '' === $from || version_compare( $from, '2', '<' ) ) {
			$ok = self::migrate_to_2();
			if ( $ok ) {
				Suiteka_Basic_CRM_Capabilities::grant_to_admin_roles();
			}
		}

		if ( $ok ) {
			update_option( Suiteka_Basic_CRM::DB_VERSION_OPTION, SUITEKA_BASIC_CRM_DB_VERSION );
		}

		self::release_lock();

		return $ok;
	}

	/**
	 * Sentencias CREATE TABLE para dbDelta().
	 *
	 * @return string[]
	 */
	public static function schema() {
		global $wpdb;

		$collate       = $wpdb->get_charset_collate();
		$contacts      = Suiteka_Basic_CRM::table( 'contacts' );
		$companies     = Suiteka_Basic_CRM::table( 'companies' );
		$tags          = Suiteka_Basic_CRM::table( 'tags' );
		$relationships = Suiteka_Basic_CRM::table( 'tag_relationships' );
		$activities    = Suiteka_Basic_CRM::table( 'activities' );

		// Formato exigido por dbDelta(): un campo por línea y dos espacios tras PRIMARY KEY.
		return array(
			"CREATE TABLE {$contacts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				full_name varchar(191) NOT NULL,
				first_name varchar(191) NOT NULL DEFAULT '',
				last_name varchar(191) NOT NULL DEFAULT '',
				email varchar(100) NOT NULL DEFAULT '',
				phone varchar(50) NOT NULL DEFAULT '',
				job_title varchar(100) NOT NULL DEFAULT '',
				company varchar(191) NOT NULL DEFAULT '',
				company_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(32) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT '',
				notes text NOT NULL,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY full_name (full_name),
				KEY email (email),
				KEY company (company),
				KEY created_at (created_at),
				KEY company_id (company_id),
				KEY owner_id (owner_id),
				KEY status (status),
				KEY updated_at (updated_at)
			) {$collate};",
			"CREATE TABLE {$companies} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				website varchar(191) NOT NULL DEFAULT '',
				email varchar(100) NOT NULL DEFAULT '',
				phone varchar(50) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				notes text NOT NULL,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name (name),
				KEY owner_id (owner_id),
				KEY updated_at (updated_at)
			) {$collate};",
			"CREATE TABLE {$tags} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(50) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name (name)
			) {$collate};",
			"CREATE TABLE {$relationships} (
				tag_id bigint(20) unsigned NOT NULL,
				object_type varchar(20) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (object_type,object_id,tag_id),
				KEY tag_id (tag_id)
			) {$collate};",
			"CREATE TABLE {$activities} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL,
				event varchar(32) NOT NULL DEFAULT '',
				content text NOT NULL,
				meta text NOT NULL,
				contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
				company_id bigint(20) unsigned NOT NULL DEFAULT 0,
				opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				occurred_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY contact_id (contact_id,occurred_at),
				KEY company_id (company_id,occurred_at),
				KEY opportunity_id (opportunity_id)
			) {$collate};",
		);
	}

	/**
	 * Crea las tablas que falten y añade columnas e índices nuevos.
	 */
	public static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema() );
	}

	/**
	 * Migración del esquema 1 (1.0.0) al 2. Idempotente y reanudable.
	 *
	 * @return bool
	 */
	public static function migrate_to_2() {
		global $wpdb;

		$contacts = Suiteka_Basic_CRM::table( 'contacts' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno; sin valores del usuario.

		// 1. El nombre completo pasa a «Nombre»; no se separan apellidos automáticamente.
		$step1 = $wpdb->query( "UPDATE {$contacts} SET first_name = full_name WHERE first_name = '' AND last_name = '' AND full_name <> ''" );

		// 2. Fecha de actualización inicial.
		$step2 = $wpdb->query( "UPDATE {$contacts} SET updated_at = created_at WHERE updated_at IS NULL" );

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $step1 || false === $step2 ) {
			return false;
		}

		// 3. Empresas a partir del texto heredado.
		return self::link_legacy_companies();
	}

	/**
	 * Crea una empresa por cada valor distinto de la columna heredada
	 * `company` y enlaza los contactos que aún no tienen empresa.
	 *
	 * La columna `company` no se modifica. La comparación usa la
	 * intercalación de la tabla, así que «ACME» y «Acme» son la misma empresa.
	 *
	 * @return bool
	 */
	private static function link_legacy_companies() {
		global $wpdb;

		$contacts = Suiteka_Basic_CRM::table( 'contacts' );
		$seen     = array();

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno; valores en prepare().
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT company FROM {$contacts} WHERE company_id = 0 AND company <> '' ORDER BY company LIMIT %d",
					self::BATCH_SIZE
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

			if ( ! is_array( $names ) ) {
				return false;
			}

			$progress = false;

			foreach ( $names as $name ) {
				$key = strtolower( $name );
				if ( isset( $seen[ $key ] ) ) {
					// Ya se intentó en esta ejecución y no se pudo enlazar: evita un bucle infinito.
					continue;
				}
				$seen[ $key ] = true;

				$company_id = self::find_or_create_company( $name );
				if ( ! $company_id ) {
					return false;
				}

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla interno; valores en prepare().
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$contacts} SET company_id = %d WHERE company_id = 0 AND company = %s",
						$company_id,
						$name
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

				if ( false === $updated ) {
					return false;
				}

				$progress = $progress || $updated > 0;
			}
			$full_batch = count( $names ) === self::BATCH_SIZE;
		} while ( $full_batch && $progress );

		return true;
	}

	/**
	 * Devuelve el ID de la empresa con ese nombre, creándola si no existe.
	 *
	 * @param string $name Nombre de la empresa.
	 * @return int 0 si falla.
	 */
	private static function find_or_create_company( $name ) {
		global $wpdb;

		$companies = Suiteka_Basic_CRM::table( 'companies' );
		$contacts  = Suiteka_Basic_CRM::table( 'contacts' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombres de tabla internos; valores en prepare().
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$companies} WHERE name = %s LIMIT 1", $name ) );

		if ( $id ) {
			return $id;
		}

		// Fecha de alta: la del contacto más antiguo de esa empresa.
		$created = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$contacts} WHERE company = %s", $name ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$created = $created ? $created : Suiteka_Basic_CRM::now();

		$inserted = $wpdb->insert(
			$companies,
			array(
				'name'       => $name,
				'notes'      => '',
				'owner_id'   => 0,
				'created_at' => $created,
				'updated_at' => $created,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Toma el bloqueo de migración. add_option() es atómico gracias a la
	 * clave única de option_name.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}

		$since = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $since && time() - $since < self::LOCK_TIMEOUT ) {
			return false;
		}

		// Bloqueo caducado (p. ej. una petición interrumpida): se reemplaza.
		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, time(), '', 'no' );
	}

	/**
	 * Libera el bloqueo de migración.
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
