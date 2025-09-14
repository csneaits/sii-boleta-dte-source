<?php
namespace Sii\BoletaDte\Infrastructure\WooCommerce;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\TokenManager;

/**
 * Minimal WooCommerce integration: adds document type field, generates DTE on
 * order completion and stores track IDs.
 */
class Woo {
    private Plugin $plugin;

    public function __construct( Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /** Register hooks with WooCommerce. */
    public function register(): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'woocommerce_after_order_notes', array( $this, 'render_document_type_field' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_document_type' ) );
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
    }

    /**
     * Displays document type selector at checkout.
     */
    public function render_document_type_field( $checkout ): void {
        $types   = $this->get_enabled_document_types();
        $options = '';
        foreach ( $types as $code => $label ) {
            $options .= '<option value="' . esc_attr( (string) $code ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '<p class="form-row"><label>' . esc_html__( 'Document type', 'sii-boleta-dte' ) . '</label><select name="sii_boleta_doc_type">' . $options . '</select></p>';
    }

    /** Saves selected document type to order meta. */
    public function save_document_type( int $order_id ): void {
        if ( ! isset( $_POST['sii_boleta_doc_type'] ) ) {
            return;
        }
        $type = sanitize_text_field( (string) $_POST['sii_boleta_doc_type'] );
        if ( function_exists( 'update_post_meta' ) ) {
            update_post_meta( $order_id, '_sii_boleta_doc_type', $type );
        }
    }

    /**
     * Generates and sends a DTE when an order is completed.
     */
    public function handle_order_completed( int $order_id ): void {
        if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
            return;
        }
        $type = get_post_meta( $order_id, '_sii_boleta_doc_type', true );
        if ( ! $type ) {
            return;
        }
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        $data  = array();
        if ( $order && method_exists( $order, 'get_total' ) ) {
            $data['Detalles'] = array();
            $data['Folio']    = $order_id;
            $data['FchEmis']  = date( 'Y-m-d' );
            $data['Detalle']  = array();
        }
        $engine = $this->plugin->get_engine();
        $xml    = $engine->generate_dte_xml( $data, (int) $type );
        if ( ! is_string( $xml ) ) {
            return;
        }
        $file = tempnam( sys_get_temp_dir(), 'dte' );
        file_put_contents( $file, $xml );
        $token_manager = new TokenManager( $this->plugin->get_api(), $this->plugin->get_settings() );
        $token    = $token_manager->get_token( 'boleta' );
        $track_id = $this->plugin->get_api()->send_dte_to_sii( $file, 'boleta', $token );
        if ( ! is_wp_error( $track_id ) ) {
            update_post_meta( $order_id, '_sii_boleta_track_id', $track_id );
        }
        unlink( $file );
    }

    /**
     * Returns enabled document types from settings.
     *
     * @return array<int,string>
     */
    private function get_enabled_document_types(): array {
        $settings = $this->plugin->get_settings()->get_settings();
        $enabled  = $settings['enabled_types'] ?? array( 39, 33 );
        $labels   = array(
            33 => __( 'Factura', 'sii-boleta-dte' ),
            39 => __( 'Boleta', 'sii-boleta-dte' ),
        );
        $types = array();
        foreach ( $enabled as $code ) {
            if ( isset( $labels[ $code ] ) ) {
                $types[ $code ] = $labels[ $code ];
            }
        }
        return $types;
    }
}

class_alias( Woo::class, 'SII_Boleta_Woo' );
