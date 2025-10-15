<?php
namespace Sii\BoletaDte\Infrastructure\Certification;

/**
 * Stores timestamps that indicate certification milestones completed by the user.
 */
class ProgressTracker {
        public const OPTION_TOKEN = 'sii_boleta_dte_progress_token';
        public const OPTION_API = 'sii_boleta_dte_progress_api';
        public const OPTION_TEST_SEND = 'sii_boleta_dte_progress_cert_send';

        /**
         * Persists the given timestamp (or the current time) for an option name.
         */
        public static function mark( string $option, ?int $timestamp = null ): void {
                $value = $timestamp ?? time();
                if ( function_exists( 'update_option' ) ) {
                        update_option( $option, $value );
                        return;
                }

                if ( isset( $GLOBALS['test_options'] ) && is_array( $GLOBALS['test_options'] ) ) {
                        $GLOBALS['test_options'][ $option ] = $value;
                        return;
                }

                if ( ! isset( $GLOBALS['wp_options'] ) || ! is_array( $GLOBALS['wp_options'] ) ) {
                        $GLOBALS['wp_options'] = array();
                }

                $GLOBALS['wp_options'][ $option ] = $value;
        }

        /**
         * Retrieves the stored timestamp for an option, returning 0 when missing.
         */
        public static function last_timestamp( string $option ): int {
                if ( function_exists( 'get_option' ) ) {
                        $value = get_option( $option, 0 );
                        return is_numeric( $value ) ? (int) $value : 0;
                }

                if ( isset( $GLOBALS['test_options'][ $option ] ) ) {
                        return (int) $GLOBALS['test_options'][ $option ];
                }

                if ( isset( $GLOBALS['wp_options'][ $option ] ) ) {
                        return (int) $GLOBALS['wp_options'][ $option ];
                }

                return 0;
        }
}

class_alias( ProgressTracker::class, 'SII_Boleta_Certification_ProgressTracker' );
