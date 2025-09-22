<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

/**
 * Migrates legacy plugin settings to the structure used by the fullLibreDte
 * branch.
 */
class SettingsMigration {
    /**
     * Executes the migration.
     */
    public static function migrate(): void {
        if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
            return;
        }

        if ( get_option( 'sii_boleta_dte_migrated' ) ) {
            return;
        }

        $current = get_option( Settings::OPTION_NAME, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        FoliosDb::install();

        // Legacy CAF paths stored separately.
        $caf_paths = get_option( 'sii_boleta_dte_caf_paths', array() );
        if ( is_array( $caf_paths ) && ! empty( $caf_paths ) ) {
            foreach ( $caf_paths as $tipo => $path ) {
                self::maybe_import_caf_path( (int) $tipo, (string) $path );
            }
            delete_option( 'sii_boleta_dte_caf_paths' );
        }

        // Legacy per-DTE CAF options (e.g. sii_boleta_dte_caf_39).
        foreach ( array( 33, 39, 41, 52 ) as $tipo ) {
            $opt = get_option( 'sii_boleta_dte_caf_' . $tipo );
            if ( $opt ) {
                self::maybe_import_caf_path( (int) $tipo, (string) $opt );
                delete_option( 'sii_boleta_dte_caf_' . $tipo );
            }
        }

        if ( isset( $current['cafs'] ) && is_array( $current['cafs'] ) ) {
            foreach ( $current['cafs'] as $caf ) {
                $tipo  = isset( $caf['tipo'] ) ? (int) $caf['tipo'] : 0;
                $desde = isset( $caf['desde'] ) ? (int) $caf['desde'] : 0;
                $hasta = isset( $caf['hasta'] ) ? (int) $caf['hasta'] : 0;
                if ( $tipo && $desde && $hasta && $desde <= $hasta && ! FoliosDb::overlaps( $tipo, $desde, $hasta ) ) {
                    FoliosDb::insert( $tipo, $desde, $hasta );
                }
            }
        }

        // Encrypt certificate password if present.
        if ( isset( $current['cert_pass'] ) && ! empty( $current['cert_pass'] ) ) {
            $current['cert_pass'] = Settings::encrypt( (string) $current['cert_pass'] );
        }

        unset( $current['cafs'], $current['caf_path'] );

        update_option( Settings::OPTION_NAME, $current );
        self::migrate_logs();
        update_option( 'sii_boleta_dte_migrated', 1 );
    }

    /**
     * Attempts to import a CAF XML file by reading its folio range.
     */
    private static function maybe_import_caf_path( int $tipo, string $path ): void {
        if ( ! $tipo || '' === $path ) {
            return;
        }
        if ( ! file_exists( $path ) ) {
            return;
        }
        $xml = @simplexml_load_file( $path );
        if ( ! $xml || ! isset( $xml->CAF->DA->RNG->D, $xml->CAF->DA->RNG->H ) ) {
            return;
        }
        $desde = (int) $xml->CAF->DA->RNG->D;
        $hasta = (int) $xml->CAF->DA->RNG->H;
        if ( $desde && $hasta && $desde <= $hasta && ! FoliosDb::overlaps( $tipo, $desde, $hasta ) ) {
            FoliosDb::insert( $tipo, $desde, $hasta );
        }
    }

    /**
     * Migrates legacy log files into the database.
     */
    private static function migrate_logs(): void {
        $upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => sys_get_temp_dir() );
        $base_dir   = $upload_dir['basedir'] ?? sys_get_temp_dir();
        $log_dir    = ( function_exists( 'trailingslashit' ) ? trailingslashit( $base_dir ) : $base_dir . '/' ) . 'sii-boleta-logs';
        if ( ! is_dir( $log_dir ) ) {
            return;
        }
        foreach ( glob( $log_dir . '/sii-boleta-*.log' ) as $file ) {
            $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( ! is_array( $lines ) ) {
                continue;
            }
            foreach ( $lines as $line ) {
                if ( preg_match( '/^\[(.+)\]\s+(\w+):\s+(.*)$/', $line, $m ) ) {
                    LogDb::add_entry( '', strtolower( $m[2] ), $m[3] );
                }
            }
        }
    }
}

class_alias( SettingsMigration::class, 'SII_Boleta_Settings_Migration' );
