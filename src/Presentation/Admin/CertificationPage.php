<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\WordPress\Settings as Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

/**
 * Admin page to configure and start SII certification with selectable folio ranges.
 */
class CertificationPage {
    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function register(): void {
        if ( function_exists( 'add_submenu_page' ) ) {
            \add_submenu_page(
                'sii-boleta-dte',
                \__( 'Certificación SII', 'sii-boleta-dte' ),
                \__( 'Certificación', 'sii-boleta-dte' ),
                'manage_options',
                'sii-boleta-dte-cert',
                array( $this, 'render_page' )
            );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $env = '0'; // Certification environment only for this page

        $notice = '';
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['sii_cert_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            // Basic nonce check when WP helpers exist
            if ( function_exists( 'check_admin_referer' ) ) {
                \call_user_func( 'check_admin_referer', 'sii_boleta_cert', 'sii_boleta_cert_nonce' );
            }

            $plan    = $this->sanitize_plan( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $plan['meta'] = array(
                'created_at'  => gmdate( 'c' ),
                'environment' => $env,
            );
            if ( function_exists( 'update_option' ) ) {
                \update_option( 'sii_boleta_cert_plan', $plan, false );
            } else {
                $GLOBALS['wp_options']['sii_boleta_cert_plan'] = $plan; // fallback in tests
            }
            $action = isset( $_POST['sii_cert_action'] ) ? (string) $_POST['sii_cert_action'] : 'save'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( 'save_run' === $action ) {
                try {
                    $checker = \Sii\BoletaDte\Infrastructure\Factory\Container::get( \Sii\BoletaDte\Infrastructure\Certification\PreflightChecker::class );
                    $result  = is_object( $checker ) ? (array) $checker->check( $this->settings, $plan ) : array( 'ok' => true, 'issues' => array() );
                    if ( empty( $result['ok'] ) ) {
                        $issues = (array) ( $result['issues'] ?? array() );
                        $notice = __( 'No se pudo iniciar. Revisa los requisitos:', 'sii-boleta-dte' ) . ' ' . implode( ' ', array_map( 'esc_html', $issues ) );
                    } else {
                        $runner = \Sii\BoletaDte\Infrastructure\Factory\Container::get( \Sii\BoletaDte\Infrastructure\Certification\CertificationRunner::class );
                        $started = is_object( $runner ) ? (bool) $runner->run( $plan ) : false;
                        if ( $started ) {
                            $notice = __( 'Plan guardado e iniciado. Revisa la pestaña Cola en el Panel de control.', 'sii-boleta-dte' );
                        } else {
                            $notice = __( 'Plan guardado, pero no se encontraron folios para procesar.', 'sii-boleta-dte' );
                        }
                    }
                } catch ( \Throwable $e ) {
                    $notice = __( 'Plan guardado, pero falló el inicio automático.', 'sii-boleta-dte' );
                }
            } else {
                $notice = __( 'Plan de certificación guardado. Puedes iniciarlo cuando quieras.', 'sii-boleta-dte' );
            }
        }

        $ranges = $this->get_grouped_ranges( $env );
        $saved  = $this->get_saved_plan();

        AdminStyles::open_container( 'sii-certification' );
        echo '<h1>' . esc_html__( 'Certificación SII', 'sii-boleta-dte' ) . '</h1>';
        echo '<p>' . esc_html__( 'Selecciona los rangos de folios y/o folios exactos que quieres usar durante la certificación. Esto aplica solo al ambiente de certificación.', 'sii-boleta-dte' ) . '</p>';
        if ( '' !== $notice ) {
            echo '<div class="updated notice"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<form method="post">';
        if ( function_exists( 'wp_nonce_field' ) ) { \wp_nonce_field( 'sii_boleta_cert', 'sii_boleta_cert_nonce' ); }
        echo '<input type="hidden" name="sii_cert_action" value="save" />';

        foreach ( $ranges as $type => $rows ) {
            $type = (int) $type;
            $label = $this->label_for_type( $type );
            echo '<div class="sii-section">';
            echo '<h2>' . esc_html( $label ) . ' <small>(' . (int) $type . ')</small></h2>';
            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'Sin rangos configurados en certificación para este tipo.', 'sii-boleta-dte' ) . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__( 'Usar', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Desde', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Hasta', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'CAF', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Cargado', 'sii-boleta-dte' ) . '</th>';
                echo '</tr></thead><tbody>';
                $selected = isset( $saved['types'][ $type ]['ranges'] ) && is_array( $saved['types'][ $type ]['ranges'] ) ? $saved['types'][ $type ]['ranges'] : array();
                foreach ( $rows as $row ) {
                    $checked = in_array( (int) $row['id'], array_map( 'intval', $selected ), true ) ? ' checked' : '';
                    echo '<tr>';
                    echo '<td><input type="checkbox" name="types[' . (int) $type . '][ranges][]" value="' . (int) $row['id'] . '"' . $checked . ' /></td>';
                    echo '<td>' . (int) $row['desde'] . '</td>';
                    echo '<td>' . (int) $row['hasta'] . '</td>';
                    $has_caf = isset( $row['caf'] ) && '' !== trim( (string) $row['caf'] );
                    $caf_lbl = $has_caf ? ( $row['caf_filename'] ?: 'CAF' ) : __( 'Sin CAF', 'sii-boleta-dte' );
                    echo '<td>' . esc_html( (string) $caf_lbl ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['caf_uploaded_at'] ?? '' ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';

                $mode   = isset( $saved['types'][ $type ]['mode'] ) ? (string) $saved['types'][ $type ]['mode'] : 'auto';
                $count  = isset( $saved['types'][ $type ]['count'] ) ? (int) $saved['types'][ $type ]['count'] : 0;
                $manual = isset( $saved['types'][ $type ]['manual'] ) ? (string) $saved['types'][ $type ]['manual'] : '';
                echo '<p style="margin-top:10px;">';
                echo '<label><input type="radio" name="types[' . (int) $type . '][mode]" value="auto"' . ( 'auto' === $mode ? ' checked' : '' ) . ' /> ' . esc_html__( 'Automático: usar N folios siguientes', 'sii-boleta-dte' ) . '</label> ';
                echo '<input type="number" min="0" step="1" name="types[' . (int) $type . '][count]" value="' . (int) $count . '" style="width:80px;" />';
                echo '<br />';
                echo '<label><input type="radio" name="types[' . (int) $type . '][mode]" value="manual"' . ( 'manual' === $mode ? ' checked' : '' ) . ' /> ' . esc_html__( 'Manual: folios exactos (separados por coma o salto de línea)', 'sii-boleta-dte' ) . '</label>';
                echo '<br />';
                echo '<textarea name="types[' . (int) $type . '][manual]" rows="3" cols="60" placeholder="100,101,102">' . esc_textarea( $manual ) . '</textarea>';
                echo '</p>';
            }
            echo '</div>';
        }

        $flags = isset( $saved['flags'] ) && is_array( $saved['flags'] ) ? $saved['flags'] : array();
        $dry   = ! empty( $flags['dryRun'] );
        $retry = array_key_exists( 'retryOnConflict', $flags ) ? (bool) $flags['retryOnConflict'] : true;
        $recibo_stage = isset( $flags['reciboStage'] ) ? (string) $flags['reciboStage'] : '';
        $rut_proveedor = isset( $flags['rut_proveedor'] ) ? (string) $flags['rut_proveedor'] : '';
        echo '<h2>' . esc_html__( 'Opciones', 'sii-boleta-dte' ) . '</h2>';
        echo '<label><input type="checkbox" name="flags[dryRun]" value="1"' . ( $dry ? ' checked' : '' ) . ' /> ' . esc_html__( 'Simulación (no marcar folios como usados)', 'sii-boleta-dte' ) . '</label><br />';
        echo '<label><input type="checkbox" name="flags[retryOnConflict]" value="1"' . ( $retry ? ' checked' : '' ) . ' /> ' . esc_html__( 'Reintentar con el folio siguiente si hay conflicto', 'sii-boleta-dte' ) . '</label>';
        echo '<div style="margin-top:10px;">';
        echo '<label>' . esc_html__( 'Etapa de Recibo (opcional)', 'sii-boleta-dte' ) . '</label> ';
        echo '<select name="flags[reciboStage]">';
        $stages = array( '' => __( '— No especificar —', 'sii-boleta-dte' ), 'mercaderias' => __( 'Mercaderías', 'sii-boleta-dte' ), 'recepcion' => __( 'Recepción', 'sii-boleta-dte' ), 'aceptacion' => __( 'Aceptación', 'sii-boleta-dte' ) );
        foreach ( $stages as $key => $lbl ) {
            $sel = ( $key === $recibo_stage ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $key ) . '"' . $sel . '>' . esc_html( $lbl ) . '</option>';
        }
        echo '</select>';
        echo '<br />';
        echo '<label>' . esc_html__( 'RUT proveedor (para recibo por proveedor)', 'sii-boleta-dte' ) . '</label> ';
        echo '<input type="text" name="flags[rut_proveedor]" value="' . esc_attr( $rut_proveedor ) . '" placeholder="76.123.456-7" />';
        echo '</div>';

        echo '<p style="margin-top:15px;">';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar plan', 'sii-boleta-dte' ) . '</button> ';
        echo '<button type="submit" name="sii_cert_action" value="save_run" class="button">' . esc_html__( 'Guardar e iniciar', 'sii-boleta-dte' ) . '</button>';
        echo '</p>';
        echo '</form>';

        AdminStyles::close_container();
    }

    /** @return array<int,array<int,array<string,mixed>>> ranges grouped by type */
    private function get_grouped_ranges( string $environment ): array {
        $by_type = array();
        $ranges  = FoliosDb::all( $environment );
        foreach ( $ranges as $row ) {
            $t = (int) $row['tipo'];
            if ( ! isset( $by_type[ $t ] ) ) {
                $by_type[ $t ] = array();
            }
            $by_type[ $t ][] = $row;
        }
        ksort( $by_type );
        return $by_type;
    }

    /** @param array<string,mixed> $post */
    private function sanitize_plan( array $post ): array {
        $out = array( 'types' => array(), 'flags' => array() );
        $env = '0';
        $valid_ids = array();
        foreach ( FoliosDb::all( $env ) as $row ) {
            $valid_ids[ (int) $row['id'] ] = true;
        }

        $types = isset( $post['types'] ) && is_array( $post['types'] ) ? $post['types'] : array();
        foreach ( $types as $t => $cfg ) {
            $type = (int) $t;
            if ( ! is_array( $cfg ) ) { continue; }
            $ranges = array();
            if ( isset( $cfg['ranges'] ) && is_array( $cfg['ranges'] ) ) {
                foreach ( $cfg['ranges'] as $id ) {
                    $id = (int) $id;
                    if ( isset( $valid_ids[ $id ] ) ) { $ranges[] = $id; }
                }
            }
            $mode   = isset( $cfg['mode'] ) && 'manual' === $cfg['mode'] ? 'manual' : 'auto';
            $count  = isset( $cfg['count'] ) ? max( 0, (int) $cfg['count'] ) : 0;
            $manual = isset( $cfg['manual'] ) ? (string) $cfg['manual'] : '';
            $out['types'][ $type ] = array(
                'ranges' => $ranges,
                'mode'   => $mode,
                'count'  => $count,
                'manual' => $this->sanitize_manual_folios( $manual ),
            );
        }

        $flags = isset( $post['flags'] ) && is_array( $post['flags'] ) ? $post['flags'] : array();
        $reciboStage = isset( $flags['reciboStage'] ) ? (string) $flags['reciboStage'] : '';
        $rutProveedor = isset( $flags['rut_proveedor'] ) ? (string) $flags['rut_proveedor'] : '';
        // Sanitizar RUT proveedor simple (dejar dígitos, k y guión)
        $rutProveedor = strtolower( preg_replace( '/[^0-9kK\-]/', '', $rutProveedor ) ?? '' );
        $out['flags'] = array(
            'dryRun'          => empty( $flags['dryRun'] ) ? 0 : 1,
            'retryOnConflict' => empty( $flags['retryOnConflict'] ) ? 0 : 1,
            'reciboStage'     => $reciboStage,
            'rut_proveedor'   => $rutProveedor,
        );

        return $out;
    }

    private function sanitize_manual_folios( string $raw ): string {
        $raw = trim( $raw );
        if ( '' === $raw ) { return ''; }
        $raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
        $parts = preg_split( '/[\s,]+/', $raw ) ?: array();
        $nums  = array();
        foreach ( $parts as $p ) {
            if ( ctype_digit( $p ) ) { $nums[] = (string) (int) $p; }
        }
        return implode( ',', array_unique( $nums ) );
    }

    /** @return array<string,mixed> */
    private function get_saved_plan(): array {
        $plan = array();
        if ( function_exists( 'get_option' ) ) {
            $plan = (array) \get_option( 'sii_boleta_cert_plan', array() );
        } elseif ( isset( $GLOBALS['wp_options']['sii_boleta_cert_plan'] ) ) {
            $plan = (array) $GLOBALS['wp_options']['sii_boleta_cert_plan'];
        }
        return $plan;
    }

    private function label_for_type( int $type ): string {
        $map = array(
            33 => __( 'Factura electrónica', 'sii-boleta-dte' ),
            34 => __( 'Factura no afecta o exenta', 'sii-boleta-dte' ),
            39 => __( 'Boleta electrónica', 'sii-boleta-dte' ),
            41 => __( 'Boleta exenta electrónica', 'sii-boleta-dte' ),
            52 => __( 'Guía de despacho', 'sii-boleta-dte' ),
            56 => __( 'Nota de débito', 'sii-boleta-dte' ),
            61 => __( 'Nota de crédito', 'sii-boleta-dte' ),
        );
        return $map[ $type ] ?? ( 'DTE ' . $type );
    }
}

class_alias( CertificationPage::class, 'SII_Boleta_Certification_Page' );
