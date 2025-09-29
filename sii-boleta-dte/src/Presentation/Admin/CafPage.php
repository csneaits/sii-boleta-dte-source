<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Admin page to manage folio ranges.
 */
class CafPage {
    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /** Registers hooks if needed. */
    public function register(): void {}

    /**
     * Renders the folio management page.
     */
    public function render_page(): void {
        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $environment = $this->settings->get_environment();
        $ranges      = FoliosDb::all( $environment );
        usort(
            $ranges,
            function ( $a, $b ) {
                if ( $a['tipo'] === $b['tipo'] ) {
                    return $a['desde'] <=> $b['desde'];
                }
                return $a['tipo'] <=> $b['tipo'];
            }
        );
        $types = $this->supported_types();

        AdminStyles::open_container( 'sii-folios-page' );
        echo '<h1>' . esc_html__( 'Folios / CAFs', 'sii-boleta-dte' ) . '</h1>';
        $environment_label = '1' === $environment ? __( 'Producción', 'sii-boleta-dte' ) : __( 'Certificación', 'sii-boleta-dte' );
        echo '<p class="sii-admin-subtitle">' . esc_html__( 'Registra manualmente los rangos de folios autorizados. El sistema validará que los folios emitidos pertenezcan a un rango configurado.', 'sii-boleta-dte' ) . '</p>';
        echo '<p class="description">' . sprintf( esc_html__( 'Ambiente activo: %s. Los rangos configurados son independientes por ambiente.', 'sii-boleta-dte' ), esc_html( $environment_label ) ) . '</p>';
        echo '<div class="sii-admin-actions"><button type="button" class="button button-primary" id="sii-boleta-add-folio">' . esc_html__( 'Agregar folios', 'sii-boleta-dte' ) . '</button></div>';

        echo '<div class="sii-admin-card sii-admin-card--table">';
        echo '<table class="wp-list-table widefat fixed striped" id="sii-boleta-folios-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Tipo', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'Rango', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'Consumidos', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'Restantes', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'CAF', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'Estado', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'Creado', 'sii-boleta-dte' ) . '</th>';
        echo '<th>' . esc_html__( 'Acciones', 'sii-boleta-dte' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $ranges ) ) {
            echo '<tr><td colspan="8">' . esc_html__( 'Aún no se han registrado rangos de folios.', 'sii-boleta-dte' ) . '</td></tr>';
        } else {
            foreach ( $ranges as $range ) {
                $tipo       = (int) $range['tipo'];
                $desde      = (int) $range['desde'];
                $hasta_raw  = (int) $range['hasta'];
                $hasta      = $hasta_raw - 1;
                if ( $hasta < $desde ) {
                    $hasta = $desde;
                }
                $cantidad   = max( 0, $hasta_raw - $desde );
                $last       = Settings::get_last_folio_value( $tipo, $environment );
                $consumidos = 0;
                if ( $last >= $desde ) {
                    $consumidos = min( $cantidad, max( 0, $last - $desde + 1 ) );
                }
                $restantes = max( 0, $cantidad - $consumidos );
                $estado    = $restantes > 0 ? __( 'Vigente', 'sii-boleta-dte' ) : __( 'Agotado', 'sii-boleta-dte' );
                $type_label = $types[ $tipo ] ?? (string) $tipo;
                $created = esc_html( $range['created_at'] ?? '' );
                $caf_name = isset( $range['caf_filename'] ) ? (string) $range['caf_filename'] : '';
                $caf_name = '' !== $caf_name ? $caf_name : ( ! empty( $range['caf'] ) ? __( 'CAF cargado', 'sii-boleta-dte' ) : '' );
                $caf_uploaded = isset( $range['caf_uploaded_at'] ) ? (string) $range['caf_uploaded_at'] : '';
                $caf_label = '' !== $caf_name ? $caf_name : __( 'Pendiente', 'sii-boleta-dte' );
                echo '<tr data-id="' . (int) $range['id'] . '" data-tipo="' . esc_attr( (string) $tipo ) . '" data-desde="' . esc_attr( (string) $desde ) . '" data-cantidad="' . esc_attr( (string) $cantidad ) . '" data-caf-name="' . esc_attr( $caf_name ) . '" data-caf-uploaded="' . esc_attr( $caf_uploaded ) . '">';
                echo '<td>' . esc_html( $type_label ) . '</td>';
                echo '<td>' . esc_html( $desde . ' - ' . $hasta ) . '</td>';
                echo '<td>' . (int) $consumidos . '</td>';
                echo '<td>' . (int) $restantes . '</td>';
                echo '<td>' . esc_html( $caf_label ) . '</td>';
                echo '<td>' . esc_html( $estado ) . '</td>';
                echo '<td>' . $created . '</td>';
                echo '<td>';
                echo '<button type="button" class="button-link sii-boleta-edit-folio">' . esc_html__( 'Editar', 'sii-boleta-dte' ) . '</button> | ';
                echo '<button type="button" class="button-link-delete sii-boleta-delete-folio">' . esc_html__( 'Eliminar', 'sii-boleta-dte' ) . '</button>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';

        AdminStyles::close_container();

        $this->render_modal();
    }

    /**
     * Prints the modal markup used to add/edit ranges.
     */
    private function render_modal(): void {
        echo '<div id="sii-boleta-folio-modal" class="hidden" aria-hidden="true">';
        echo '<div class="sii-boleta-modal-backdrop"></div>';
        echo '<div class="sii-boleta-modal">';
        echo '<h2 id="sii-boleta-folio-modal-title"></h2>';
        echo '<form id="sii-boleta-folio-form">';
        if ( function_exists( 'wp_nonce_field' ) ) {
            wp_nonce_field( 'sii_boleta_caf', 'sii_boleta_caf_nonce' );
        }
        echo '<input type="hidden" name="action" value="sii_boleta_dte_save_folio_range" />';
        echo '<input type="hidden" name="id" id="sii-boleta-folio-id" value="0" />';
        echo '<label for="sii-boleta-folio-type">' . esc_html__( 'Tipo de documento', 'sii-boleta-dte' ) . '</label>';
        echo '<select name="tipo" id="sii-boleta-folio-type">';
        foreach ( $this->supported_types() as $code => $label ) {
            echo '<option value="' . (int) $code . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<label for="sii-boleta-folio-start">' . esc_html__( 'Folio inicial', 'sii-boleta-dte' ) . '</label>';
        echo '<input type="number" name="start" id="sii-boleta-folio-start" min="1" required />';
        echo '<label for="sii-boleta-folio-quantity">' . esc_html__( 'Cantidad de folios', 'sii-boleta-dte' ) . '</label>';
        echo '<input type="number" name="quantity" id="sii-boleta-folio-quantity" min="1" required />';
        echo '<label for="sii-boleta-folio-end">' . esc_html__( 'Folio final', 'sii-boleta-dte' ) . '</label>';
        echo '<input type="number" id="sii-boleta-folio-end" readonly />';
        echo '<label for="sii-boleta-folio-caf">' . esc_html__( 'Archivo CAF', 'sii-boleta-dte' ) . '</label>';
        echo '<input type="file" name="caf_file" id="sii-boleta-folio-caf" accept=".xml,.caf" />';
        echo '<p class="description" id="sii-boleta-folio-caf-info">' . esc_html__( 'Aún no se ha cargado un CAF para este rango.', 'sii-boleta-dte' ) . '</p>';
        echo '<div class="sii-boleta-modal-actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar', 'sii-boleta-dte' ) . '</button>';
        echo '<button type="button" class="button" id="sii-boleta-folio-cancel">' . esc_html__( 'Cancelar', 'sii-boleta-dte' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * @return array<int,string>
     */
    private function supported_types(): array {
        return array(
            33 => __( 'Factura', 'sii-boleta-dte' ),
            34 => __( 'Factura Exenta', 'sii-boleta-dte' ),
            46 => __( 'Factura de Compra', 'sii-boleta-dte' ),
            39 => __( 'Boleta', 'sii-boleta-dte' ),
            41 => __( 'Boleta Exenta', 'sii-boleta-dte' ),
            52 => __( 'Guía de Despacho', 'sii-boleta-dte' ),
            56 => __( 'Nota de Débito', 'sii-boleta-dte' ),
            61 => __( 'Nota de Crédito', 'sii-boleta-dte' ),
        );
    }
}

class_alias( CafPage::class, 'SII_Boleta_Caf_Page' );
