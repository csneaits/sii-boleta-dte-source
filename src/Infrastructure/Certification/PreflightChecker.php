<?php
namespace Sii\BoletaDte\Infrastructure\Certification;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

/**
 * Validates minimal requirements before starting a certification run.
 */
class PreflightChecker {
    /**
     * @param array<string,mixed> $plan Saved plan as stored in option 'sii_boleta_cert_plan'.
     * @return array{ok:bool, issues:string[]}
     */
    public function check( Settings $settings, array $plan ): array {
        $issues = array();
        $cfg    = $settings->get_settings();

        // Environment must be certification (0)
        $env = '0';

        // Basic settings
        if ( empty( $cfg['rut_emisor'] ) ) {
            $issues[] = __( 'Falta configurar el RUT emisor en Ajustes.', 'sii-boleta-dte' );
        }
        if ( empty( $cfg['cert_path'] ) ) {
            $issues[] = __( 'Falta configurar el certificado digital (.p12).', 'sii-boleta-dte' );
        }
        if ( empty( $cfg['cert_pass'] ) ) {
            $issues[] = __( 'Falta configurar la contraseña del certificado.', 'sii-boleta-dte' );
        }

        $types = isset( $plan['types'] ) && is_array( $plan['types'] ) ? $plan['types'] : array();
        if ( empty( $types ) ) {
            $issues[] = __( 'No hay tipos seleccionados en el plan.', 'sii-boleta-dte' );
        }

        foreach ( $types as $tipoStr => $cfgTipo ) {
            $tipo = (int) $tipoStr;
            if ( $tipo <= 0 || ! is_array( $cfgTipo ) ) { continue; }

            $ranges = FoliosDb::for_type( $tipo, $env );
            if ( empty( $ranges ) ) {
                $issues[] = sprintf( __( 'No hay rangos de folios cargados en certificación para el tipo %d.', 'sii-boleta-dte' ), $tipo );
                continue;
            }

            $selectedIds = isset( $cfgTipo['ranges'] ) && is_array( $cfgTipo['ranges'] ) ? array_map( 'intval', $cfgTipo['ranges'] ) : array();
            $selected = array();
            foreach ( $ranges as $r ) {
                if ( empty( $selectedIds ) || in_array( (int) $r['id'], $selectedIds, true ) ) {
                    $selected[] = $r;
                }
            }
            if ( empty( $selected ) ) {
                $issues[] = sprintf( __( 'Debes seleccionar al menos un rango de folios para el tipo %d.', 'sii-boleta-dte' ), $tipo );
                continue;
            }

            // Check CAF presence in any selected range
            $hasCaf = false;
            foreach ( $selected as $r ) {
                if ( ! empty( $r['caf'] ) || ! empty( $r['caf_xml'] ) ) { $hasCaf = true; break; }
            }
            if ( ! $hasCaf ) {
                $issues[] = sprintf( __( 'No se encontró CAF cargado en los rangos seleccionados para el tipo %d.', 'sii-boleta-dte' ), $tipo );
            }

            // Emission intent check
            $mode  = isset( $cfgTipo['mode'] ) && 'manual' === $cfgTipo['mode'] ? 'manual' : 'auto';
            $count = isset( $cfgTipo['count'] ) ? max( 0, (int) $cfgTipo['count'] ) : 0;
            $manual = isset( $cfgTipo['manual'] ) ? (string) $cfgTipo['manual'] : '';
            if ( 'manual' === $mode ) {
                if ( '' === trim( $manual ) ) {
                    $issues[] = sprintf( __( 'Seleccionaste modo manual para el tipo %d, pero no ingresaste folios.', 'sii-boleta-dte' ), $tipo );
                }
            } else {
                if ( $count <= 0 ) {
                    $issues[] = sprintf( __( 'Seleccionaste modo automático para el tipo %d, pero la cantidad es 0.', 'sii-boleta-dte' ), $tipo );
                }
            }
        }

        return array( 'ok' => empty( $issues ), 'issues' => $issues );
    }
}
