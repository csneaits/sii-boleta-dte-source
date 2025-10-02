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

// Fallback PSR-4 autoloader for plugin classes when Composer autoload is not present.
// This keeps the plugin usable on installations where 'vendor/' was not installed
// and provides a conservative mapping: Sii\BoletaDte\ -> src/
if ( ! class_exists( '\Sii\BoletaDte\Infrastructure\Plugin' ) ) {
	spl_autoload_register( function ( $class ) {
		$prefix = 'Sii\\BoletaDte\\';
		// Only handle our namespace
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file = SII_BOLETA_DTE_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	} );
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
// Crear tabla de folios manuales.
register_activation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\Persistence\FoliosDb::class, 'install' ) );
// Migrar ajustes y logs de versiones anteriores.
register_activation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\Persistence\SettingsMigration::class, 'migrate' ) );
register_activation_hook( __FILE__, array( \Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorageMigrator::class, 'migrate' ) );

// Número de días que conservamos las copias de debug de PDFs. Se puede redefinir antes de incluir el plugin.
if ( ! defined( 'SII_BOLETA_DTE_DEBUG_RETENTION_DAYS' ) ) {
	define( 'SII_BOLETA_DTE_DEBUG_RETENTION_DAYS', 7 );
}

// Programar / limpiar job de limpieza de copias de debug (rotación)
register_activation_hook( __FILE__, 'sii_boleta_dte_activate_prune_job' );
register_deactivation_hook( __FILE__, 'sii_boleta_dte_deactivate_prune_job' );

// Admin UI: Tools -> SII Boleta DTE Debug Renders
add_action( 'admin_menu', function() {
	if ( function_exists( 'add_management_page' ) ) {
		add_management_page(
			'SII Boleta DTE Debug Renders',
			'SII Boleta DTE Renders',
			'manage_options',
			'sii-boleta-dte-renders',
			'sii_boleta_dte_admin_renders_page'
		);
	}
} );

/**
 * Admin page that lists recent debug renders and exposes a manual prune action.
 */
function sii_boleta_dte_admin_renders_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'sii-boleta-dte' ) );
	}

	$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : null;
	$basedir = $uploads && isset( $uploads['basedir'] ) ? rtrim( $uploads['basedir'], '/\\' ) : '';
	$dir = $basedir ? $basedir . '/sii-boleta-dte/private/last_renders_debug' : '';

	echo '<div class="wrap"><h1>SII Boleta DTE — Debug Renders</h1>';
	echo '<p>Directorio de debug: <code>' . esc_html( $dir ) . '</code></p>';

	// Show next scheduled run (if WP cron available)
	if ( function_exists( 'wp_next_scheduled' ) ) {
		$next = wp_next_scheduled( 'sii_boleta_dte_prune_debug_pdfs' );
		if ( $next ) {
			echo '<p><strong>Siguiente ejecución programada:</strong> ' . esc_html( date( 'Y-m-d H:i:s', $next ) ) . '</p>';
		} else {
			echo '<p><strong>Siguiente ejecución programada:</strong> <em>No programada</em></p>';
		}
	}

	if ( $dir && is_dir( $dir ) ) {
		$files = glob( $dir . '/*.pdf' );
		usort( $files, function( $a, $b ) { return filemtime($b) - filemtime($a); } );
		echo '<h2>Archivos recientes</h2>';
		echo '<table class="widefat"><thead><tr><th>Nombre</th><th>Tamaño</th><th>Modificado</th></tr></thead><tbody>';
		$count = 0;
		foreach ( $files as $f ) {
			$count++;
			$name = basename( $f );
			$size = size_format( filesize( $f ) );
			$mtime = date( 'Y-m-d H:i:s', filemtime( $f ) );
			$link = esc_url( str_replace( ABSPATH, site_url( '/' ), $f ) );
			echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $size ) . '</td><td>' . esc_html( $mtime ) . '</td></tr>';
			if ( $count >= 50 ) break;
		}
		echo '</tbody></table>';
		if ( 0 === $count ) {
			echo '<p>No se encontraron archivos.</p>';
		}
	} else {
		echo '<p>Directorio no encontrado.</p>';
	}

	// Show recent debug.log entries for job execution visibility
	$logs_dir = $basedir ? $basedir . '/sii-boleta-dte/private/logs' : '';
	$log_file = $logs_dir ? $logs_dir . '/debug.log' : '';
	echo '<h2>Registros de depuración (últimas líneas)</h2>';
	if ( $log_file && is_file( $log_file ) ) {
		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( $lines === false ) {
			echo '<p>No se pudo leer el archivo de log.</p>';
		} else {
			$tail = array_slice( $lines, -120 );
			echo '<pre style="max-height: 400px; overflow:auto; background:#fff; padding:8px; border:1px solid #ddd;">' . esc_html( implode( "\n", $tail ) ) . '</pre>';
		}
	} else {
		echo '<p>No se encontró el archivo de logs de depuración.</p>';
	}

	// Manual prune form
	$nonce = wp_create_nonce( 'sii_boleta_dte_prune_now' );
	$action_url = admin_url( 'admin-post.php' );
	echo '<form method="post" action="' . esc_url( $action_url ) . '">';
	echo '<input type="hidden" name="action" value="sii_boleta_dte_prune_now">';
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
	submit_button( 'Ejecutar limpieza ahora', 'secondary' );
	echo '</form>';

	echo '</div>';
}

// Handle manual prune via admin-post
add_action( 'admin_post_sii_boleta_dte_prune_now', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para ejecutar esta acción.', 'sii-boleta-dte' ) );
	}
	check_admin_referer( 'sii_boleta_dte_prune_now' );
	do_action( 'sii_boleta_dte_prune_debug_pdfs' );
	wp_redirect( admin_url( 'tools.php?page=sii-boleta-dte-renders&prune=1' ) );
	exit;
} );

/**
 * Programar el evento diario que pruna las copias de debug.
 */
function sii_boleta_dte_activate_prune_job() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'sii_boleta_dte_prune_debug_pdfs' ) ) {
		wp_schedule_event( time(), 'daily', 'sii_boleta_dte_prune_debug_pdfs' );
	}
}

/**
 * Eliminar el evento programado al desactivar el plugin.
 */
function sii_boleta_dte_deactivate_prune_job() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'sii_boleta_dte_prune_debug_pdfs' );
	}
}

/**
 * Handler del cron que elimina PDFs de debugging más antiguos que el periodo de retención.
 */
add_action( 'sii_boleta_dte_prune_debug_pdfs', 'sii_boleta_dte_prune_debug_pdfs_handler' );
function sii_boleta_dte_prune_debug_pdfs_handler() {
	// Intentar obtener el directorio de uploads
	if ( ! function_exists( 'wp_upload_dir' ) ) {
		return;
	}
	$uploads = wp_upload_dir();
	// Target debug folder only so definitive PDFs are unaffected.
	$dir = trailingslashit( $uploads['basedir'] ) . 'sii-boleta-dte/private/last_renders_debug';
	if ( ! is_dir( $dir ) ) {
		// Registrar intento sin directorio
		update_option( 'sii_boleta_dte_prune_debug_last_run', time(), false );
		update_option( 'sii_boleta_dte_prune_debug_last_count', 0, false );
		return;
	}

	// Permitir override desde ajustes si existe (guardado en la opción principal de settings)
	$settings = get_option( \Sii\BoletaDte\Infrastructure\Settings::OPTION_NAME, array() );
	$conf_retention = isset( $settings['debug_retention_days'] ) ? (int) $settings['debug_retention_days'] : 0;
	if ( $conf_retention < 1 ) { $conf_retention = 0; }
	$retention_days = $conf_retention > 0 ? $conf_retention : ( defined( 'SII_BOLETA_DTE_DEBUG_RETENTION_DAYS' ) ? SII_BOLETA_DTE_DEBUG_RETENTION_DAYS : 7 );
	$threshold = time() - ( $retention_days * DAY_IN_SECONDS );

	$files = glob( $dir . '/*.pdf' );
	$count_deleted = 0;
	if ( ! $files ) {
		update_option( 'sii_boleta_dte_prune_debug_last_run', time(), false );
		update_option( 'sii_boleta_dte_prune_debug_last_count', 0, false );
		return;
	}
	foreach ( $files as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}
		// Safety: only remove files that look like debug/preview renders by filename.
		// This prevents accidental deletion of final PDFs stored elsewhere or with different names.
		$base = basename( $file );
		if ( ! preg_match( '/(render_|debug_|tmp_|preview_|test_|_temp|last_render)/i', $base ) ) {
			// Skip files that don't match debug naming patterns.
			continue;
		}

		$mtime = filemtime( $file );
		if ( $mtime !== false && $mtime < $threshold ) {
			if ( @unlink( $file ) ) {
				$count_deleted++;
				if ( function_exists( 'sii_boleta_write_log' ) ) {
					sii_boleta_write_log( "Pruned debug PDF: $file", 'INFO' );
				} else {
					error_log( "[sii-boleta-dte] Pruned debug PDF: $file" );
				}
			}
		}
	}
	update_option( 'sii_boleta_dte_prune_debug_last_run', time(), false );
	update_option( 'sii_boleta_dte_prune_debug_last_count', $count_deleted, false );
}

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
