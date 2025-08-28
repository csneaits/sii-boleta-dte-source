<?php
/**
 * Autoloader for plugin classes.
 *
 * @package SII_Boleta_DTE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'SII_' ) ) {
			return;
		}
		$filename = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$file     = __DIR__ . '/' . $filename;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
