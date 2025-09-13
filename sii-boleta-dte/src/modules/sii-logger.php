<?php
/**
 * Simple logger with severity levels and daily rotation.
 *
 * @package SII_Boleta_DTE
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registrador simple con niveles de severidad y rotación diaria.
 */
class SII_Logger {
	public const INFO  = 'INFO';
	public const WARN  = 'WARN';
	public const ERROR = 'ERROR';

	/**
	 * Registra un mensaje en el archivo de log del día.
	 *
	 * @param string $level	  Nivel del log.
	 * @param string $message Mensaje a registrar.
	 */
	public static function log( string $level, string $message ): void {
		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
		$base_dir	= $upload_dir['basedir'] ?? sys_get_temp_dir();
		$log_dir	= function_exists( 'trailingslashit' ) ? trailingslashit( $base_dir ) . 'sii-boleta-logs' : $base_dir . '/sii-boleta-logs';
		$log_dir	= function_exists( 'apply_filters' ) ? apply_filters( 'sii_boleta_dte_log_dir', $log_dir ) : $log_dir;

		if ( ! file_exists( $log_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $log_dir );
			} else {
				mkdir( $log_dir, 0777, true );
			}
		}

		$file = ( function_exists( 'trailingslashit' ) ? trailingslashit( $log_dir ) : $log_dir . '/' ) . 'sii-boleta-' . date( 'Y-m-d' ) . '.log';
		$time = date( 'Y-m-d H:i:s' );
		$line = sprintf( '[%s] %s: %s%s', $time, $level, $message, PHP_EOL );

		$handle = @fopen( $file, 'ab' );
		if ( false === $handle ) {
			return;
		}

		try {
			if ( @flock( $handle, LOCK_EX ) ) {
				fwrite( $handle, $line );
				fflush( $handle );
				@flock( $handle, LOCK_UN );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Atajo para nivel INFO.
	 */
	public static function info( string $message ): void {
		self::log( self::INFO, $message );
	}

	/**
	 * Atajo para nivel WARN.
	 */
	public static function warn( string $message ): void {
		self::log( self::WARN, $message );
	}

	/**
	 * Atajo para nivel ERROR.
	 */
	public static function error( string $message ): void {
		self::log( self::ERROR, $message );
	}
}
