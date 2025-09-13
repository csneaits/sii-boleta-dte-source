<?php
/**
 * Plugin Name:       SII Boleta DTE
 * Description:       Plugin para la emisión de documentos tributarios electrónicos utilizando una arquitectura hexagonal.
 * Version:           1.0.0
 * Requires PHP:      8.1
 * Author:            Tu Nombre
 * Text Domain:       sii-boleta-dte
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

define( 'SII_BOLETA_DTE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SII_BOLETA_DTE_URL', plugin_dir_url( __FILE__ ) );
define( 'SII_BOLETA_DTE_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
function sii_boleta_dte_php_version_error() {
wp_die( esc_html__( 'SII Boleta DTE requiere PHP 8.1 o superior.', 'sii-boleta-dte' ) );
}
register_activation_hook( __FILE__, 'sii_boleta_dte_php_version_error' );
return;
}

register_uninstall_hook(
__FILE__,
function() {
delete_option( 'sii_boleta_dte_settings' );
delete_option( 'sii_boleta_dte_rvd_sent_dates' );
}
);

if ( file_exists( SII_BOLETA_DTE_PATH . 'vendor/autoload.php' ) ) {
require_once SII_BOLETA_DTE_PATH . 'vendor/autoload.php';
} elseif ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'vendor/autoload.php' ) ) {
require_once ABSPATH . 'vendor/autoload.php';
} elseif ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/vendor/autoload.php' ) ) {
require_once WP_CONTENT_DIR . '/vendor/autoload.php';
}

add_action(
'plugins_loaded',
function() {
load_plugin_textdomain(
'sii-boleta-dte',
false,
dirname( plugin_basename( __FILE__ ) ) . '/languages'
);
new \Sii\BoletaDte\Core\Plugin();
}
);

