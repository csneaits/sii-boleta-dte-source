<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Comandos WP-CLI para operaciones del plugin.
 */
class SII_Boleta_CLI {
    /**
     * Consulta el estado de un DTE mediante track ID.
     *
     * ## EXAMPLES
     *
     *     wp sii:dte status --track=12345
     *
     * @param array $args       Argumentos posicionales.
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function dte_status( $args, $assoc_args ) {
        $track = $assoc_args['track'] ?? '';
        if ( empty( $track ) ) {
            WP_CLI::error( 'Debe indicar el track ID mediante --track.' );
        }
        $settings_obj = new SII_Boleta_Settings();
        $settings     = $settings_obj->get_settings();
        $api          = new SII_Boleta_API();
        $data         = $api->get_dte_status(
            $track,
            $settings['environment'],
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );
        if ( is_wp_error( $data ) ) {
            WP_CLI::error( $data->get_error_message() );
        }
        if ( false === $data ) {
            WP_CLI::error( 'Error al consultar el estado del DTE.' );
        }
        WP_CLI::print_value( $data, [ 'format' => 'json' ] );
        WP_CLI::success( 'Consulta completada.' );
    }

    /**
     * Genera y envía el Libro de Boletas para un rango de meses.
     *
     * ## EXAMPLES
     *
     *     wp sii:libro --from=2024-01 --to=2024-03
     *
     * @param array $args       Argumentos posicionales.
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function libro( $args, $assoc_args ) {
        $from = $assoc_args['from'] ?? '';
        $to   = $assoc_args['to'] ?? '';
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}$/', $to ) ) {
            WP_CLI::error( 'Debe indicar rangos válidos con --from=YYYY-MM y --to=YYYY-MM.' );
        }
        $start = $from . '-01';
        $end   = date( 'Y-m-t', strtotime( $to . '-01' ) );
        $settings_obj = new SII_Boleta_Settings();
        $settings     = $settings_obj->get_settings();
        $manager      = new SII_Libro_Boletas( $settings_obj );
        $xml = $manager->generate_libro_xml( $start, $end );
        if ( ! $xml ) {
            WP_CLI::error( 'Error al generar el Libro.' );
        }
        $sent = $manager->send_libro_to_sii(
            $xml,
            $settings['environment'],
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );
        if ( $sent ) {
            WP_CLI::success( 'Libro enviado correctamente.' );
        } else {
            WP_CLI::error( 'Error al enviar el Libro.' );
        }
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'sii dte status', [ 'SII_Boleta_CLI', 'dte_status' ] );
    WP_CLI::add_command( 'sii libro', [ 'SII_Boleta_CLI', 'libro' ] );
}
