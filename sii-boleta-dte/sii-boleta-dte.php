<?php
/*
Plugin Name:       SII Boleta DTE
Description:       Plugin modular para la emisión de boletas, facturas, notas de crédito y débito electrónicas con integración al Servicio de Impuestos Internos (SII) de Chile. Permite configurar certificados, gestionar folios, generar el timbre electrónico (TED) y firmar digitalmente los documentos. Incluye integración con WooCommerce, generación del Resumen de Ventas Diarias (RVD) y soporte para distintos tipos de DTE. Para la creación del código PDF417 se utiliza un marcador de posición; se recomienda instalar una librería especializada para la versión de producción.
Version:           1.0.0
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

/**
 * Clase principal del plugin. Encargada de inicializar componentes y cargar dependencias.
 *
 * Siguiendo el patrón de diseño utilizado en el plugin de ejemplo proporcionado
 * (carpeta `csneaits-asistent-ia`), esta clase se limita a orquestar el registro
 * de dependencias y ganchos (hooks). La funcionalidad específica se delega a
 * clases especializadas ubicadas en el directorio `includes/`.
 */
final class SII_Boleta_DTE {

    /**
     * Constructor. Engancha la inicialización a `plugins_loaded`.
     */
    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    /**
     * Método que se ejecuta tras la carga de plugins. Se encarga de cargar
     * dependencias, inicializar internacionalización y crear instancias de
     * componentes clave como la configuración, manejador de folios, etc.
     */
    public function init() {
        // Cargar traducciones
        load_plugin_textdomain( 'sii-boleta-dte', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Cargar archivos de clase
        $this->load_dependencies();

        // Instanciar la clase núcleo que configura todas las funcionalidades
        $core = new SII_Boleta_Core();
        // Instanciar el manejador de cron para registrar el callback del evento
        new SII_Boleta_Cron( $core->get_settings() );

    }

    /**
     * Carga las dependencias del plugin. Todos los `require_once` se
     * agrupan en este método para mantener el constructor ligero y mejorar
     * la legibilidad del código.
     */
    private function load_dependencies() {
        $includes_path = SII_BOLETA_DTE_PATH . 'includes/';

        require_once $includes_path . 'class-sii-boleta-core.php';
        require_once $includes_path . 'class-sii-boleta-settings.php';
        require_once $includes_path . 'class-sii-boleta-folio-manager.php';
        require_once $includes_path . 'class-sii-boleta-xml-generator.php';
        require_once $includes_path . 'class-sii-boleta-signer.php';
        require_once $includes_path . 'class-sii-boleta-api.php';
        require_once $includes_path . 'class-sii-boleta-pdf.php';
        require_once $includes_path . 'class-sii-boleta-rvd-manager.php';
        require_once $includes_path . 'class-sii-boleta-cron.php';
        require_once $includes_path . 'class-sii-boleta-woo.php';

        // Incluir librería xmlseclibs para firmar digitalmente los XML
        require_once $includes_path . 'libs/xmlseclibs.php';
    }
}

// Ejecutar el plugin si estamos en el área de administración o en frontend.
new SII_Boleta_DTE();

// Registrar hooks de activación y desactivación fuera de la clase para programar
// los eventos cron cuando se active o desactive el plugin. Estos hooks no
// pueden declararse dentro de un método porque necesitan ejecutarse en el
// momento de activación del plugin.
register_activation_hook( __FILE__, [ 'SII_Boleta_Cron', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SII_Boleta_Cron', 'deactivate' ] );

if ( ! function_exists( 'sii_boleta_write_log' ) ) {
    /**
     * Escribe un mensaje en el archivo de registro del plugin. Crea el
     * directorio y archivo de log si no existen. Los mensajes se almacenan
     * junto a una marca de tiempo para facilitar la depuración.
     *
     * @param string $message Mensaje a registrar.
     */
    function sii_boleta_write_log( $message ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            // Solo registrar si el modo debug está activo para no llenar el disco.
            return;
        }
        $upload_dir = wp_upload_dir();
        $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sii-boleta-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $log_file = trailingslashit( $log_dir ) . 'sii-boleta.log';
        $time     = date( 'Y-m-d H:i:s' );
        $line     = sprintf( "[%s] %s\n", $time, $message );
        file_put_contents( $log_file, $line, FILE_APPEND );
    }
}