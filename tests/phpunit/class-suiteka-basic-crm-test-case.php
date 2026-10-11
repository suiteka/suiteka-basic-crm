<?php
/**
 * Clase base de las pruebas.
 *
 * @package Suiteka_Basic_CRM
 */

/**
 * Excepción lanzada en lugar de redirigir, para poder comprobar el destino.
 */
class Suiteka_Basic_CRM_Test_Redirect extends Exception {}

/**
 * Utilidades comunes: usuarios con distintos permisos, registros y peticiones simuladas.
 */
abstract class Suiteka_Basic_CRM_Test_Case extends WP_UnitTestCase {

	/**
	 * Administrador.
	 *
	 * @var int
	 */
	protected static $admin;

	/**
	 * Comercial: solo ve, edita y borra lo suyo.
	 *
	 * @var int
	 */
	protected static $sales;

	/**
	 * Segundo comercial.
	 *
	 * @var int
	 */
	protected static $sales2;

	/**
	 * Editor de WordPress sin acceso al CRM.
	 *
	 * @var int
	 */
	protected static $editor;

	/**
	 * Crea los usuarios compartidos por las pruebas de la clase.
	 *
	 * @param WP_UnitTest_Factory $factory Factoría.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		add_role(
			'suiteka_test_sales',
			'Comercial (pruebas)',
			array(
				'read'                                 => true,
				Suiteka_Basic_CRM_Capabilities::VIEW   => true,
				Suiteka_Basic_CRM_Capabilities::EDIT   => true,
				Suiteka_Basic_CRM_Capabilities::DELETE => true,
			)
		);

		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$sales  = $factory->user->create( array( 'role' => 'suiteka_test_sales' ) );
		self::$sales2 = $factory->user->create( array( 'role' => 'suiteka_test_sales' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Borra el rol de pruebas.
	 */
	public static function wpTearDownAfterClass() {
		remove_role( 'suiteka_test_sales' );
	}

	/**
	 * Prepara cada prueba.
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ), 1 );
		wp_set_current_user( self::$admin );
	}

	/**
	 * Limpia el estado global de cada prueba.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		foreach ( array( 'Suiteka_Basic_CRM_Admin_Contacts', 'Suiteka_Basic_CRM_Admin_Companies', 'Suiteka_Basic_CRM_Admin_Activities', 'Suiteka_Basic_CRM_Admin_Duplicates' ) as $class ) {
			$this->reset_static( $class );
		}

		parent::tear_down();
	}

	/**
	 * Convierte una redirección en excepción.
	 *
	 * @param string $location Destino.
	 * @throws Suiteka_Basic_CRM_Test_Redirect Siempre.
	 */
	public function catch_redirect( $location ) {
		throw new Suiteka_Basic_CRM_Test_Redirect( $location );
	}

	/**
	 * Restablece las propiedades estáticas de un controlador.
	 *
	 * @param string $class_name Clase.
	 */
	protected function reset_static( $class_name ) {
		$reflection = new ReflectionClass( $class_name );
		$defaults   = $reflection->getDefaultProperties();

		foreach ( $reflection->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
			$property->setAccessible( true );
			$property->setValue( null, $defaults[ $property->getName() ] );
		}
	}

	/**
	 * Crea un contacto directamente en el repositorio.
	 *
	 * @param array $data Datos.
	 * @return int
	 */
	protected function make_contact( $data = array() ) {
		$id = Suiteka_Basic_CRM_Contacts::create(
			array_merge(
				array(
					'first_name' => 'Contacto',
					'last_name'  => 'Prueba ' . wp_generate_password( 6, false ),
					'owner_id'   => self::$admin,
				),
				$data
			)
		);
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	/**
	 * Crea una empresa directamente en el repositorio.
	 *
	 * @param array $data Datos.
	 * @return int
	 */
	protected function make_company( $data = array() ) {
		$id = Suiteka_Basic_CRM_Companies::create(
			array_merge(
				array(
					'name'     => 'Empresa ' . wp_generate_password( 8, false ),
					'owner_id' => self::$admin,
				),
				$data
			)
		);
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	/**
	 * Simula una petición a una pantalla del plugin y devuelve la redirección, si la hay.
	 *
	 * @param string      $controller   Clase del controlador.
	 * @param array       $get          Parámetros de la URL.
	 * @param array|null  $post         Datos POST (null para GET).
	 * @param string|null $nonce_action Acción del nonce (null = nonce inválido).
	 * @return string|null URL de destino o null si no redirige.
	 */
	protected function request( $controller, $get = array(), $post = null, $nonce_action = null ) {
		$_GET = $get;

		if ( null === $post ) {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = array();
		} else {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$post['_wpnonce']          = null === $nonce_action ? 'nonce-invalido' : wp_create_nonce( $nonce_action );
			$_POST                     = wp_slash( $post );
		}

		$_REQUEST = array_merge( $_GET, $_POST );

		try {
			call_user_func( array( $controller, 'load' ) );
		} catch ( Suiteka_Basic_CRM_Test_Redirect $redirect ) {
			return $redirect->getMessage();
		}

		return null;
	}

	/**
	 * Captura la salida de una pantalla.
	 *
	 * @param callable $render Función que imprime.
	 * @return string
	 */
	protected function render( $render ) {
		ob_start();
		call_user_func( $render );

		return ob_get_clean();
	}

	/**
	 * Lee un parámetro de una URL.
	 *
	 * @param string $url URL.
	 * @param string $key Parámetro.
	 * @return string|null
	 */
	protected function query_arg( $url, $key ) {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		return isset( $args[ $key ] ) ? $args[ $key ] : null;
	}
}
