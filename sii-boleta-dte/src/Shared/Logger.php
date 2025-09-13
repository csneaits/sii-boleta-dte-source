<?php
namespace Sii\BoletaDte\Shared;

use Sii\BoletaDte\Domain\Logger as LoggerContract;

/**
 * Simple logger with severity levels and daily rotation.
 */
class Logger implements LoggerContract {
    public const INFO  = 'INFO';
    public const WARN  = 'WARN';
    public const ERROR = 'ERROR';

    /**
     * Logs a message to the daily log file.
     */
    public function log( string $level, string $message ): void {
        $upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
        $base_dir   = $upload_dir['basedir'] ?? sys_get_temp_dir();
        $log_dir    = function_exists( 'trailingslashit' ) ? trailingslashit( $base_dir ) . 'sii-boleta-logs' : $base_dir . '/sii-boleta-logs';
        $log_dir    = function_exists( 'apply_filters' ) ? apply_filters( 'sii_boleta_dte_log_dir', $log_dir ) : $log_dir;

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

    public function info( string $message ): void {
        $this->log( self::INFO, $message );
    }

    public function warn( string $message ): void {
        $this->log( self::WARN, $message );
    }

    public function error( string $message ): void {
        $this->log( self::ERROR, $message );
    }
}

class_alias( Logger::class, 'SII_Logger' );
