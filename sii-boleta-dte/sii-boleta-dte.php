<?php
/*
Plugin Name:       SII Boleta DTE
Description:       Plugin modular para la emisión de boletas, facturas, guías de despacho y notas de crédito o débito electrónicas con integración al Servicio de Impuestos Internos (SII) de Chile. Permite configurar certificados, gestionar folios, generar el timbre electrónico (TED) y firmar digitalmente los documentos. Incluye integración con WooCommerce, generación del Resumen de Ventas Diarias (RVD) y soporte para distintos tipos de DTE. Incorpora una librería local para generar el código de barras PDF417 sin depender de servicios externos.
Version:           1.0.0
Requires PHP:      8.4
Author:            Tu Nombre
Text Domain:       sii-boleta-dte
Domain Path:       /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

// Definición de constantes del plugin para su ruta y URL.
define( 'SII_BOLETA_DTE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SII_BOLETA_DTE_URL', plugin_dir_url( __FILE__ ) );
define( 'SII_BOLETA_DTE_VERSION', '1.0.0' );

// Verificar versión mínima de PHP.
if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
	function sii_boleta_dte_php_version_error() {
		wp_die( esc_html__( 'SII Boleta DTE requiere PHP 8.4 o superior.', 'sii-boleta-dte' ) );
	}
	register_activation_hook( __FILE__, 'sii_boleta_dte_php_version_error' );
	return;
}

// Eliminar datos en la desinstalación.
register_uninstall_hook( __FILE__, 'sii_boleta_dte_uninstall' );
function sii_boleta_dte_uninstall() {
	delete_option( 'sii_boleta_dte_settings' );
	delete_option( 'sii_boleta_dte_rvd_sent_dates' );
}

// Autoload de Composer
if ( file_exists( SII_BOLETA_DTE_PATH . 'vendor/autoload.php' ) ) {
	require_once SII_BOLETA_DTE_PATH . 'vendor/autoload.php';
} elseif ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'vendor/autoload.php' ) ) {
	require_once ABSPATH . 'vendor/autoload.php';
} elseif ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/vendor/autoload.php' ) ) {
	require_once WP_CONTENT_DIR . '/vendor/autoload.php';
}

// Cargar autoload de Composer desde ubicaciones comunes
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// CLI commands autoloaded via Composer classes
	class_exists( '\\Sii\\BoletaDte\\Infrastructure\\Cli\\Cli' );
}

// Eliminado soporte PDF nativo: se usa renderer LibreDTE

/**
 * Clase principal del plugin. Encargada de inicializar componentes y cargar dependencias.
 *
 * Siguiendo el patrón de diseño utilizado en el plugin de ejemplo proporcionado
 * (carpeta `csneaits-asistent-ia`), esta clase se limita a orquestar el registro
 * de dependencias y ganchos (hooks). La funcionalidad específica se delega a
 * clases especializadas ubicadas en el directorio `src/`.
 */
final class SII_Boleta_DTE {

	/**
	 * Constructor. Engancha la inicialización a `plugins_loaded`.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Método que se ejecuta tras la carga de plugins. Se encarga de cargar
	 * dependencias, inicializar internacionalización y crear instancias de
	 * componentes clave como la configuración, manejador de folios, etc.
	 */
	public function init() {
		// Cargar traducciones
		load_plugin_textdomain( 'sii-boleta-dte', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Instanciar la clase núcleo que configura todas las funcionalidades
		$core = new \Sii\BoletaDte\Infrastructure\Plugin();
		// Instanciar el manejador de cron para registrar el callback del evento
		new \Sii\BoletaDte\Infrastructure\Cron( $core->get_settings() );
	}
}

// Ejecutar el plugin si estamos en el área de administración o en frontend.
new SII_Boleta_DTE();

// Registrar hooks de activación y desactivación fuera de la clase para programar
// los eventos cron cuando se active o desactive el plugin. Estos hooks no
// pueden declararse dentro de un método porque necesitan ejecutarse en el
// momento de activación del plugin.
register_activation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\Cron::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\Cron::class, 'deactivate' ) );
// Crear tabla de log consolidado al activar el plugin.
register_activation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\Persistence\LogDb::class, 'install' ) );
// Migrar ajustes y logs de versiones anteriores.
register_activation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\Persistence\SettingsMigration::class, 'migrate' ) );

if ( ! function_exists( 'sii_boleta_write_log' ) ) {
	/**
	 * Escribe un mensaje en el archivo de registro del plugin utilizando
	 * la clase SII_Logger. Los mensajes se almacenan junto a una marca
	 * de tiempo y se rota el archivo diariamente.
	 *
	 * @param string $message Mensaje a registrar.
	 * @param string $level   Nivel del log (INFO, WARN, ERROR).
	 */
	function sii_boleta_write_log( $message, $level = 'INFO' ) {
		$settings        = get_option( \Sii\BoletaDte\Infrastructure\Settings::OPTION_NAME, array() );
		$logging_enabled = ! empty( $settings['enable_logging'] );
		if ( ! $logging_enabled && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) {
			// Solo registrar si el modo debug está activo o si se habilitó explícitamente en los ajustes.
			return;
		}
		\Sii\BoletaDte\Infrastructure\Factory\Container::init();
		$logger = \Sii\BoletaDte\Infrastructure\Factory\Container::get( \Sii\BoletaDte\Domain\Logger::class );
		$logger->log( strtoupper( $level ), $message );
	}
}
