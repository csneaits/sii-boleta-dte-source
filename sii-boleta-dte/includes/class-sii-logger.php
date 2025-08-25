<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registrador simple con niveles de severidad y rotación diaria.
 */
class SII_Logger {
    const INFO  = 'INFO';
    const WARN  = 'WARN';
    const ERROR = 'ERROR';

    /**
     * Registra un mensaje en el archivo de log del día.
     *
     * @param string $level   Nivel del log.
     * @param string $message Mensaje a registrar.
     */
    public static function log( $level, $message ) {
        $upload_dir = wp_upload_dir();
        $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sii-boleta-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $file = trailingslashit( $log_dir ) . 'sii-boleta-' . date( 'Y-m-d' ) . '.log';
        $time = date( 'Y-m-d H:i:s' );
        $line = sprintf( '[%s] %s: %s%s', $time, $level, $message, PHP_EOL );
        file_put_contents( $file, $line, FILE_APPEND );
    }

    /**
     * Atajo para nivel INFO.
     *
     * @param string $message Mensaje a registrar.
     */
    public static function info( $message ) {
        self::log( self::INFO, $message );
    }

    /**
     * Atajo para nivel WARN.
     *
     * @param string $message Mensaje a registrar.
     */
    public static function warn( $message ) {
        self::log( self::WARN, $message );
    }

    /**
     * Atajo para nivel ERROR.
     *
     * @param string $message Mensaje a registrar.
     */
    public static function error( $message ) {
        self::log( self::ERROR, $message );
    }
}
