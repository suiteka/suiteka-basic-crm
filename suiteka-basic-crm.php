<?php
/**
 * Plugin Name:       Suiteka Basic CRM
 * Plugin URI:        https://suiteka.com/
 * Description:       CRM ligero para el escritorio de WordPress: contactos, empresas, etiquetas e historial de actividades.
 * Version:           1.1.0
 * Requires at least: 5.2
 * Requires PHP:      7.0
 * Author:            Suiteka
 * Author URI:        https://suiteka.com/
 * Text Domain:       suiteka-basic-crm
 *
 * @package Suiteka_Basic_CRM
 */

defined( 'ABSPATH' ) || exit;

define( 'SUITEKA_BASIC_CRM_VERSION', '1.1.0' );
define( 'SUITEKA_BASIC_CRM_DB_VERSION', '2' );
define( 'SUITEKA_BASIC_CRM_FILE', __FILE__ );
define( 'SUITEKA_BASIC_CRM_DIR', plugin_dir_path( __FILE__ ) );

require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-capabilities.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-validator.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-installer.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-tags.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-activities.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-companies.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-contacts.php';
require_once SUITEKA_BASIC_CRM_DIR . 'includes/class-suiteka-basic-crm-duplicates.php';

if ( is_admin() ) {
	require_once SUITEKA_BASIC_CRM_DIR . 'admin/class-suiteka-basic-crm-admin.php';
	require_once SUITEKA_BASIC_CRM_DIR . 'admin/class-suiteka-basic-crm-admin-activities.php';
	require_once SUITEKA_BASIC_CRM_DIR . 'admin/class-suiteka-basic-crm-admin-contacts.php';
	require_once SUITEKA_BASIC_CRM_DIR . 'admin/class-suiteka-basic-crm-admin-companies.php';
	require_once SUITEKA_BASIC_CRM_DIR . 'admin/class-suiteka-basic-crm-admin-duplicates.php';
}

/**
 * Fachada del plugin: arranque, nombres de tabla e instalación.
 */
final class Suiteka_Basic_CRM {

	const DB_VERSION_OPTION = 'suiteka_basic_crm_db_version';

	/**
	 * Tablas propias del plugin (sin prefijo).
	 *
	 * @var string[]
	 */
	private static $tables = array( 'contacts', 'companies', 'tags', 'tag_relationships', 'activities' );

	/**
	 * Registra los hooks del plugin.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_filter( 'map_meta_cap', array( 'Suiteka_Basic_CRM_Capabilities', 'map_meta_cap' ), 10, 4 );

		if ( is_admin() ) {
			Suiteka_Basic_CRM_Admin::init();
		}
	}

	/**
	 * Nombre completo de una tabla del plugin.
	 *
	 * @param string $name Nombre corto: contacts, companies, tags, tag_relationships o activities.
	 * @return string
	 * @throws InvalidArgumentException Si la tabla no es del plugin.
	 */
	public static function table( $name = 'contacts' ) {
		global $wpdb;

		if ( ! in_array( $name, self::$tables, true ) ) {
			throw new InvalidArgumentException( 'Tabla desconocida.' );
		}

		return $wpdb->prefix . 'suiteka_crm_' . $name;
	}

	/**
	 * Nombres cortos de todas las tablas del plugin.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return self::$tables;
	}

	/**
	 * Nombre de la tabla de contactos. Se mantiene por compatibilidad con 1.0.0.
	 *
	 * @return string
	 */
	public static function table_name() {
		return self::table( 'contacts' );
	}

	/**
	 * Crea o actualiza las tablas y migra los datos (hook de activación).
	 */
	public static function install() {
		Suiteka_Basic_CRM_Installer::install();
	}

	/**
	 * Instala o migra si cambió la versión del esquema.
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
	 * Fecha y hora actuales en UTC con formato MySQL.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}
}

register_activation_hook( __FILE__, array( 'Suiteka_Basic_CRM', 'install' ) );
Suiteka_Basic_CRM::init();
