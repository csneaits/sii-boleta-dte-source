<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

use Sii\BoletaDte\Infrastructure\Settings;

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

        $current = get_option( Settings::OPTION_NAME, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        // Legacy CAF paths stored separately.
        $caf_paths = get_option( 'sii_boleta_dte_caf_paths', array() );
        if ( is_array( $caf_paths ) && ! empty( $caf_paths ) ) {
            $current['caf_path'] = $caf_paths;
        }

        // Encrypt certificate password if present.
        if ( isset( $current['cert_pass'] ) && ! empty( $current['cert_pass'] ) ) {
            $current['cert_pass'] = base64_encode( (string) $current['cert_pass'] );
        }

        update_option( Settings::OPTION_NAME, $current );
    }
}

class_alias( SettingsMigration::class, 'SII_Boleta_Settings_Migration' );
