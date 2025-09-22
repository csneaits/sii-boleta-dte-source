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

        if ( self::get_option_value( 'sii_boleta_dte_migrated' ) ) {
            return;
        }

        $current = self::get_option_value( Settings::OPTION_NAME, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        FoliosDb::install();

        $environment = isset( $current['environment'] ) ? (string) $current['environment'] : '0';
        $active_env  = Settings::normalize_environment( $environment );

        // Legacy CAF paths stored separately.
        $caf_paths = self::get_option_value( 'sii_boleta_dte_caf_paths', array() );
        if ( is_array( $caf_paths ) && ! empty( $caf_paths ) ) {
            self::delete_option_value( 'sii_boleta_dte_caf_paths' );
        }

        // Legacy per-DTE CAF options (e.g. sii_boleta_dte_caf_39).
        foreach ( array( 33, 39, 41, 52 ) as $tipo ) {
            if ( self::get_option_value( 'sii_boleta_dte_caf_' . $tipo ) ) {
                self::delete_option_value( 'sii_boleta_dte_caf_' . $tipo );
            }
        }

        if ( isset( $current['cafs'] ) && is_array( $current['cafs'] ) ) {
            foreach ( $current['cafs'] as $caf ) {
                $tipo  = isset( $caf['tipo'] ) ? (int) $caf['tipo'] : 0;
                $desde = isset( $caf['desde'] ) ? (int) $caf['desde'] : 0;
                $hasta = isset( $caf['hasta'] ) ? (int) $caf['hasta'] : 0;
                if ( $tipo && $desde && $hasta && $desde <= $hasta && ! FoliosDb::overlaps( $tipo, $desde, $hasta ) ) {
                    FoliosDb::insert( $tipo, $desde, $hasta, $active_env );
                }
            }
        }

        // Encrypt certificate password if present.
        if ( isset( $current['cert_pass'] ) && ! empty( $current['cert_pass'] ) ) {
            $current['cert_pass'] = Settings::encrypt( (string) $current['cert_pass'] );
        }

        unset( $current['cafs'], $current['caf_path'] );

        self::update_option_value( Settings::OPTION_NAME, $current );
        self::migrate_logs();
        self::update_option_value( 'sii_boleta_dte_migrated', 1 );
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

    /**
     * Retrieves an option value while remaining compatible with test stubs.
     *
     * @param mixed $default Fallback when the option is not defined.
     * @return mixed
     */
    private static function get_option_value( string $name, $default = false ) {
        $sentinel = new \stdClass();
        if ( function_exists( 'get_option' ) ) {
            $value = get_option( $name, $sentinel );
            if ( $value !== $sentinel ) {
                return $value;
            }
        }
        if ( isset( $GLOBALS['wp_options'] ) && array_key_exists( $name, $GLOBALS['wp_options'] ) ) {
            return $GLOBALS['wp_options'][ $name ];
        }
        return $default;
    }

    /**
     * Updates an option value both in WordPress and in the test globals.
     *
     * @param mixed $value
     */
    private static function update_option_value( string $name, $value ): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( $name, $value );
        }
        if ( isset( $GLOBALS['wp_options'] ) ) {
            $GLOBALS['wp_options'][ $name ] = $value;
        }
    }

    /**
     * Deletes an option value for both WordPress and the test globals.
     */
    private static function delete_option_value( string $name ): void {
        if ( function_exists( 'delete_option' ) ) {
            delete_option( $name );
        }
        if ( isset( $GLOBALS['wp_options'] ) ) {
            unset( $GLOBALS['wp_options'][ $name ] );
        }
    }
}

class_alias( SettingsMigration::class, 'SII_Boleta_Settings_Migration' );
