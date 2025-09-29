<?php
define('ABSPATH', __DIR__.'/../');
// Constants for plugin
define('SII_BOLETA_DTE_PATH', __DIR__.'/../');
define('SII_BOLETA_DTE_URL', 'http://example.com/');
define('SII_BOLETA_DTE_VERSION', 'test');
if ( file_exists( SII_BOLETA_DTE_PATH . 'vendor/autoload.php' ) ) {
    require SII_BOLETA_DTE_PATH . 'vendor/autoload.php';
}

if ( ! isset( $GLOBALS['wp_options'] ) ) {
    $GLOBALS['wp_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        return $GLOBALS['wp_options'][ $name ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value ) {
        $GLOBALS['wp_options'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $name, $value, $deprecated = '', $autoload = false ) {
        if ( isset( $GLOBALS['wp_options'][ $name ] ) ) {
            return false;
        }
        $GLOBALS['wp_options'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $name ) {
        unset( $GLOBALS['wp_options'][ $name ] );
        return true;
    }
}
