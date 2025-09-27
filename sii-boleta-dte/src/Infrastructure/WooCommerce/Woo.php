<?php
namespace Sii\BoletaDte\Infrastructure\WooCommerce;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage;

/**
 * Minimal WooCommerce integration: adds document type field, generates DTE on
 * order completion and stores track IDs.
 */
class Woo {
	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

        private const CREDIT_NOTE_TYPE = 61;
        private const DEBIT_NOTE_TYPE  = 56;

        /** Register hooks with WooCommerce. */
        public function register(): void {
                if ( ! function_exists( 'add_action' ) ) {
                                return;
                }
                                // Checkout field handling is delegated to CheckoutFields class.
                                add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
                                add_filter( 'woocommerce_order_actions', array( $this, 'register_manual_actions' ), 10, 2 );
                                add_action( 'woocommerce_order_action_sii_boleta_generate_dte', array( $this, 'handle_manual_dte' ) );
                                add_action( 'woocommerce_order_action_sii_boleta_generate_credit_note', array( $this, 'handle_manual_credit_note' ) );
                                add_action( 'woocommerce_order_action_sii_boleta_generate_debit_note', array( $this, 'handle_manual_debit_note' ) );
                                add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_pdf_download' ), 15, 1 );
        }

                /**
                 * Generates and sends a DTE when an order is completed.
                 */
        public function handle_order_completed( int $order_id ): void {
                $order = $this->get_order( $order_id );
                if ( ! $order ) {
                        return;
                }

                $type = $this->get_order_document_type( $order_id );
                if ( ! $type ) {
                        return;
                }

                $this->generate_document_for_order( $order, (int) $type, '_sii_boleta', __( 'DTE generado automáticamente al completar el pedido.', 'sii-boleta-dte' ), $order_id );
        }

        /**
         * Adds manual actions for generating electronic documents from the order actions dropdown.
         */
        public function register_manual_actions( array $actions, $order ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
                if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
                        return $actions;
                }

                $type = $this->get_order_document_type( (int) $order->get_id() );
                if ( $type ) {
                        $actions['sii_boleta_generate_dte'] = __( 'Generar DTE SII', 'sii-boleta-dte' );
                }

                if ( method_exists( $order, 'has_status' ) && $order->has_status( 'cancelled' ) && $type ) {
                        $actions['sii_boleta_generate_credit_note'] = __( 'Generar Nota de Crédito SII', 'sii-boleta-dte' );
                        $actions['sii_boleta_generate_debit_note']  = __( 'Generar Nota de Débito SII', 'sii-boleta-dte' );
                }

                return $actions;
        }

        /**
         * Handles manual DTE generation action.
         */
        public function handle_manual_dte( $order ): void {
                if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
                        return;
                }

                $type = $this->get_order_document_type( (int) $order->get_id() );
                if ( ! $type ) {
                        return;
                }

                $this->generate_document_for_order( $order, (int) $type, '_sii_boleta', __( 'DTE generado manualmente desde WooCommerce.', 'sii-boleta-dte' ), (int) $order->get_id() );
        }

        /**
         * Handles manual credit note generation for cancelled orders.
         */
        public function handle_manual_credit_note( $order ): void {
                $this->handle_note_generation( $order, self::CREDIT_NOTE_TYPE, __( 'Nota de crédito generada manualmente.', 'sii-boleta-dte' ) );
        }

        /**
         * Handles manual debit note generation for cancelled orders.
         */
        public function handle_manual_debit_note( $order ): void {
                $this->handle_note_generation( $order, self::DEBIT_NOTE_TYPE, __( 'Nota de débito generada manualmente.', 'sii-boleta-dte' ) );
        }

        private function handle_note_generation( $order, int $document_type, string $note_message ): void {
                if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
                        return;
                }

                if ( ! method_exists( $order, 'has_status' ) || ! $order->has_status( 'cancelled' ) ) {
                        return;
                }

                $this->generate_document_for_order(
                        $order,
                        $document_type,
                        self::CREDIT_NOTE_TYPE === $document_type ? '_sii_boleta_credit_note' : '_sii_boleta_debit_note',
                        $note_message,
                        (int) $order->get_id()
                );
        }

        private function get_order( int $order_id ) {
                if ( ! function_exists( 'wc_get_order' ) ) {
                        return null;
                }

                return wc_get_order( $order_id );
        }

        private function get_order_document_type( int $order_id ): int {
                $type = 0;

                if ( function_exists( 'get_post_meta' ) ) {
                        $type = (int) get_post_meta( $order_id, '_sii_boleta_doc_type', true );
                        if ( $type > 0 ) {
                                return $type;
                        }
                }

                $settings = $this->plugin->get_settings();
                if ( is_object( $settings ) && method_exists( $settings, 'get_settings' ) ) {
                        $config  = $settings->get_settings();
                        $enabled = $this->normalize_enabled_types( $config['enabled_types'] ?? array() );

                        if ( empty( $enabled ) ) {
                                return 0;
                        }

                        if ( in_array( 39, $enabled, true ) ) {
                                $this->update_order_meta( $order_id, '_sii_boleta_doc_type', '39' );
                                return 39;
                        }

                        $resolved = (int) $enabled[0];
                        if ( $resolved > 0 ) {
                                $this->update_order_meta( $order_id, '_sii_boleta_doc_type', (string) $resolved );
                        }

                        return $resolved;
                }

                return 0;
        }

        /**
         * Generates a document for an order and persists the result in post meta.
         */
        private function generate_document_for_order( $order, int $document_type, string $meta_prefix, string $success_note, int $order_id = 0 ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
                if ( ! $order ) {
                        return;
                }

                if ( $order_id <= 0 && method_exists( $order, 'get_id' ) ) {
                        $order_id = (int) $order->get_id();
                }

                if ( $order_id <= 0 ) {
                        return;
                }
                $data     = $this->prepare_order_data( $order, $document_type, $order_id );

                if ( empty( $data ) ) {
                        $this->add_order_note( $order, __( 'No fue posible preparar los datos del pedido para el DTE.', 'sii-boleta-dte' ) );
                        return;
                }

                $preview_mode = $this->should_preview_only();

                $engine = $this->plugin->get_engine();
                $xml    = $engine->generate_dte_xml( $data, $document_type, $preview_mode );
                if ( ! is_string( $xml ) || '' === trim( $xml ) ) {
                        $this->add_order_note( $order, __( 'No fue posible generar el XML del documento tributario.', 'sii-boleta-dte' ) );
                        return;
                }

                if ( $preview_mode ) {
                        $pdf_generator = $this->plugin->get_pdf_generator();
                        $pdf           = $pdf_generator->generate( $xml );
                        if ( ! is_string( $pdf ) || '' === $pdf ) {
                                $this->add_order_note( $order, __( 'No fue posible generar el PDF de previsualización.', 'sii-boleta-dte' ) );
                                return;
                        }

                        $stored_pdf = $this->persist_pdf_for_order( $pdf, $order, $document_type, $order_id );
                        $pdf_path   = $stored_pdf['path'] ?? $pdf;
                        $pdf_key    = $stored_pdf['key'] ?? '';
                        $pdf_nonce  = $stored_pdf['nonce'] ?? '';

                        if ( '' !== $pdf_key && '' !== $pdf_nonce ) {
                                $this->update_order_meta( $order_id, $meta_prefix . '_pdf_key', $pdf_key );
                                $this->update_order_meta( $order_id, $meta_prefix . '_pdf_nonce', $pdf_nonce );
                        }

                        $this->clear_legacy_pdf_meta( $order_id, $meta_prefix );
                        $this->update_order_meta( $order_id, $meta_prefix . '_track_id', '' );

                        $note = __( 'Se generó una previsualización del documento sin enviarlo al SII (modo prueba).', 'sii-boleta-dte' );
                        $preview_link = $this->build_pdf_download_link( $order_id, $meta_prefix, $pdf_key, $pdf_nonce );
                        if ( '' !== $preview_link ) {
                                $note .= ' ' . sprintf(
                                        /* translators: %s: URL pointing to the generated PDF preview. */
                                        __( 'Puedes revisarla en: %s', 'sii-boleta-dte' ),
                                        $preview_link
                                );
                        }

                        $this->add_order_note( $order, $note );
                        $this->send_document_email( $order, $pdf_path, $document_type, true, $preview_link );
                        return;
                }

                $file = tempnam( sys_get_temp_dir(), 'dte' );
                if ( false === $file ) {
                        $this->add_order_note( $order, __( 'No fue posible crear un archivo temporal para el DTE.', 'sii-boleta-dte' ) );
                        return;
                }

                file_put_contents( $file, $xml );

                $token_manager = new TokenManager( $this->plugin->get_api(), $this->plugin->get_settings() );
                $token         = $token_manager->get_token( 'boleta' );
                $track_id      = $this->plugin->get_api()->send_dte_to_sii( $file, 'boleta', $token );

                $error_message = '';
                if ( function_exists( 'is_wp_error' ) && is_wp_error( $track_id ) ) {
                        $error_message = method_exists( $track_id, 'get_error_message' ) ? $track_id->get_error_message() : '';
                } elseif ( ! is_string( $track_id ) || '' === $track_id ) {
                        $error_message = __( 'La respuesta del SII no incluyó un track ID válido.', 'sii-boleta-dte' );
                }

                if ( '' === $error_message ) {
                        $this->update_order_meta( $order_id, $meta_prefix . '_track_id', $track_id );
                }

                $pdf_generator = $this->plugin->get_pdf_generator();
                $pdf           = $pdf_generator->generate( $xml );
                if ( is_string( $pdf ) && '' !== $pdf ) {
                        $stored_pdf = $this->persist_pdf_for_order( $pdf, $order, $document_type, $order_id );
                        $pdf_path   = $stored_pdf['path'] ?? $pdf;
                        $pdf_key    = $stored_pdf['key'] ?? '';
                        $pdf_nonce  = $stored_pdf['nonce'] ?? '';

                        if ( '' !== $pdf_key && '' !== $pdf_nonce ) {
                                $this->update_order_meta( $order_id, $meta_prefix . '_pdf_key', $pdf_key );
                                $this->update_order_meta( $order_id, $meta_prefix . '_pdf_nonce', $pdf_nonce );
                        }

                        $this->clear_legacy_pdf_meta( $order_id, $meta_prefix );

                        $download_link = $this->build_pdf_download_link( $order_id, $meta_prefix, $pdf_key, $pdf_nonce );
                        $this->send_document_email( $order, $pdf_path, $document_type, false, $download_link );
                }

                if ( file_exists( $file ) ) {
                        unlink( $file );
                }

                if ( '' === $error_message ) {
                        $this->add_order_note( $order, $success_note );
                } else {
                        $this->add_order_note(
                                $order,
                                sprintf(
                                        /* translators: %s: error message returned by the SII API. */
                                        __( 'Error al enviar el documento tributario al SII: %s', 'sii-boleta-dte' ),
                                        $error_message
                                )
                        );
                }
        }

        /**
         * Builds the DTE payload from a WooCommerce order.
         */
        private function prepare_order_data( $order, int $document_type, int $order_id ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
                if ( ! $order ) {
                        return array();
                }

                if ( $order_id <= 0 && method_exists( $order, 'get_id' ) ) {
                        $order_id = (int) $order->get_id();
                }

                if ( $order_id <= 0 ) {
                        return array();
                }

                $date     = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' );
                $rut      = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $order_id, 'billing_rut', true ) : '';

                $items = array();
                if ( method_exists( $order, 'get_items' ) ) {
                        $line = 1;
                        foreach ( $order->get_items() as $item ) {
                                if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
                                        continue;
                                }
                                $qty = method_exists( $item, 'get_quantity' ) ? (float) $item->get_quantity() : 1.0;
                                $prc = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
                                $unit_price = $qty > 0 ? $prc / $qty : $prc;
                                $items[]    = array(
                                        'NroLinDet' => $line,
                                        'NmbItem'   => (string) $item->get_name(),
                                        'QtyItem'   => $qty,
                                        'PrcItem'   => $unit_price,
                                        'MontoItem' => $prc,
                                );
                                ++$line;
                        }
                }

                if ( empty( $items ) && method_exists( $order, 'get_total' ) ) {
                        $fallback_total = (float) $order->get_total();
                        if ( $fallback_total > 0 ) {
                                $items[] = array(
                                        'NroLinDet' => 1,
                                        'NmbItem'   => sprintf(
                                                /* translators: %s: order number used as description fallback. */
                                                __( 'Pedido #%s', 'sii-boleta-dte' ),
                                                method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order_id
                                        ),
                                        'QtyItem'   => 1,
                                        'PrcItem'   => $fallback_total,
                                        'MontoItem' => $fallback_total,
                                );
                        }
                }

                $totals = array();
                if ( method_exists( $order, 'get_total' ) ) {
                        $total     = (float) $order->get_total();
                        $tax_total = method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0;
                        $neto      = max( 0, $total - $tax_total );
                        $totals    = array(
                                'MntNeto'   => $neto,
                                'TasaIVA'   => 19,
                                'IVA'       => $tax_total,
                                'MntTotal'  => $total,
                        );
                }

                if ( empty( $items ) ) {
                        return array();
                }

                $receptor = array(
                        'RUTRecep'    => $rut,
                        'RznSocRecep' => method_exists( $order, 'get_formatted_billing_full_name' )
                                ? (string) $order->get_formatted_billing_full_name()
                                : trim( (string) ( method_exists( $order, 'get_billing_first_name' ) ? $order->get_billing_first_name() : '' ) . ' ' . (string) ( method_exists( $order, 'get_billing_last_name' ) ? $order->get_billing_last_name() : '' ) ),
                        'GiroRecep'   => '',
                        'Contacto'    => method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '',
                        'CorreoRecep' => method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '',
                        'DirRecep'    => method_exists( $order, 'get_billing_address_1' ) ? (string) $order->get_billing_address_1() : '',
                        'CmnaRecep'   => method_exists( $order, 'get_billing_city' ) ? (string) $order->get_billing_city() : '',
                );

                $data = array(
                        'Folio'     => $order_id,
                        'FchEmis'   => $date,
                        'Detalles'  => $items,
                        'Receptor'  => $receptor,
                        'Encabezado'=> array(
                                'IdDoc' => array(
                                        'TipoDTE' => $document_type,
                                        'Folio'   => $order_id,
                                        'FchEmis' => $date,
                                ),
                                'Totales' => $totals,
                        ),
                );

                if ( in_array( $document_type, array( self::CREDIT_NOTE_TYPE, self::DEBIT_NOTE_TYPE ), true ) ) {
                        $original_type = $this->get_order_document_type( $order_id );
                        if ( $original_type > 0 ) {
                                $data['Referencia'] = array(
                                        array(
                                                'NroLinRef' => 1,
                                                'TpoDocRef' => $original_type,
                                                'FolioRef'  => $order_id,
                                                'CodRef'    => self::CREDIT_NOTE_TYPE === $document_type ? 1 : 2,
                                                'RazonRef'  => self::CREDIT_NOTE_TYPE === $document_type
                                                        ? __( 'Anulación de pedido', 'sii-boleta-dte' )
                                                        : __( 'Ajuste de pedido', 'sii-boleta-dte' ),
                                        ),
                                );
                        }
                }

                return $data;
        }

        private function update_order_meta( int $order_id, string $meta_key, $value ): void {
                if ( function_exists( 'update_post_meta' ) ) {
                        update_post_meta( $order_id, $meta_key, $value );
                }

                global $meta; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if ( ! is_array( $meta ) ) {
                        $meta = array();
                }

                if ( ! isset( $meta[ $order_id ] ) || ! is_array( $meta[ $order_id ] ) ) {
                        $meta[ $order_id ] = array();
                }

                $meta[ $order_id ][ $meta_key ] = $value;
        }

        private function send_document_email( $order, string $pdf_path, int $document_type, bool $preview = false, string $download_url = '' ): void {
                if ( ! function_exists( 'wp_mail' ) || ! $order || ! method_exists( $order, 'get_billing_email' ) ) {
                        return;
                }

                $email = (string) $order->get_billing_email();
                if ( '' === $email ) {
                        return;
                }

                if ( ! file_exists( $pdf_path ) ) {
                        return;
                }

                $subject_template = $preview
                        ? __( 'Previsualización del documento tributario electrónico para el pedido #%1$s (%2$s)', 'sii-boleta-dte' )
                        : __( 'Documento tributario electrónico para el pedido #%1$s (%2$s)', 'sii-boleta-dte' );

                $subject = sprintf(
                        /* translators: %1$s: order number, %2$s: document type. */
                        $subject_template,
                        method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id(),
                        $document_type
                );

                $message = $preview
                        ? __( 'Se adjunta la previsualización del documento tributario electrónico asociada a su compra. No ha sido enviada al SII.', 'sii-boleta-dte' )
                        : __( 'Se adjunta el documento tributario electrónico asociado a su compra.', 'sii-boleta-dte' );

                if ( '' !== $download_url ) {
                        $link = function_exists( 'esc_url' ) ? esc_url( $download_url ) : $download_url;
                        $message .= '<br />' . sprintf(
                                /* translators: %s: URL pointing to the generated PDF download. */
                                __( 'También puede descargarlo en: %s', 'sii-boleta-dte' ),
                                $link
                        );
                }

                $headers = array( 'Content-Type: text/html; charset=UTF-8' );

                wp_mail( $email, $subject, $message, $headers, array( $pdf_path ) );
        }

        /**
         * Moves the generated PDF to the secure storage directory and returns
         * the new path alongside its key and nonce.
         *
         * @return array{path:string,key:string,nonce:string}
         */
        private function persist_pdf_for_order( string $path, $order, int $document_type, int $order_id ): array {
                $result = array(
                        'path'  => $path,
                        'key'   => '',
                        'nonce' => '',
                );

                if ( '' === $path || ! file_exists( $path ) ) {
                        return $result;
                }

                $stored = PdfStorage::store( $path );
                if ( empty( $stored['key'] ) ) {
                        return $result;
                }

                return $stored;
        }

        private function add_order_note( $order, string $message ): void {
                if ( $order && method_exists( $order, 'add_order_note' ) ) {
                        $order->add_order_note( $message );
                }
        }

        private function build_pdf_download_link( int $order_id, string $meta_prefix, string $key, string $nonce ): string {
                if ( $order_id <= 0 ) {
                        return '';
                }

                $key   = trim( strtolower( (string) $key ) );
                $nonce = trim( strtolower( (string) $nonce ) );

                if ( '' === $key || '' === $nonce ) {
                        return '';
                }

                $type = $this->sanitize_meta_prefix( $meta_prefix );
                if ( '' === $type ) {
                        return '';
                }

                $params = array(
                        'action'   => 'sii_boleta_dte_view_pdf',
                        'order_id' => $order_id,
                        'key'      => $key,
                        'nonce'    => $nonce,
                        'type'     => $type,
                );

                $base = $this->get_ajax_endpoint_base();
                if ( '' === $base ) {
                        $base = 'admin-ajax.php';
                }

                $separator = str_contains( $base, '?' ) ? '&' : '?';

                return $base . $separator . http_build_query( $params );
        }

        private function get_ajax_endpoint_base(): string {
                if ( function_exists( 'admin_url' ) ) {
                        return admin_url( 'admin-ajax.php' );
                }

                if ( function_exists( 'site_url' ) ) {
                        $base = rtrim( (string) site_url(), '/\\' );
                        if ( '' !== $base ) {
                                return $base . '/wp-admin/admin-ajax.php';
                        }
                }

                return '';
        }

        private function sanitize_meta_prefix( string $meta_prefix ): string {
                $meta_prefix = strtolower( (string) $meta_prefix );

                return preg_replace( '/[^a-z0-9_]/', '', $meta_prefix ) ?? '';
        }

        private function get_order_meta_value( int $order_id, string $meta_key ): string {
                if ( $order_id <= 0 || '' === $meta_key ) {
                        return '';
                }

                if ( function_exists( 'get_post_meta' ) ) {
                        $value = get_post_meta( $order_id, $meta_key, true );
                        if ( is_scalar( $value ) ) {
                                return (string) $value;
                        }
                }

                global $meta; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if ( isset( $meta[ $order_id ][ $meta_key ] ) ) {
                        return (string) $meta[ $order_id ][ $meta_key ];
                }

                return '';
        }

        private function clear_legacy_pdf_meta( int $order_id, string $meta_prefix ): void {
                foreach ( array( '_pdf', '_pdf_path', '_pdf_url' ) as $suffix ) {
                        $this->delete_order_meta( $order_id, $meta_prefix . $suffix );
                }
        }

        private function delete_order_meta( int $order_id, string $meta_key ): void {
                if ( $order_id <= 0 || '' === $meta_key ) {
                        return;
                }

                if ( function_exists( 'delete_post_meta' ) ) {
                        delete_post_meta( $order_id, $meta_key );
                }

                global $meta; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if ( isset( $meta[ $order_id ][ $meta_key ] ) ) {
                        unset( $meta[ $order_id ][ $meta_key ] );
                }
        }

        /**
         * Renders a PDF download link in the customer order details page.
         */
        public function render_customer_pdf_download( $order ): void {
                if ( ! $order ) {
                        return;
                }

                $order_id = 0;

                if ( method_exists( $order, 'get_id' ) ) {
                        $order_id = (int) $order->get_id();
                } elseif ( method_exists( $order, 'get_order_number' ) ) {
                        $number = (string) $order->get_order_number();
                        if ( is_numeric( $number ) ) {
                                $order_id = (int) $number;
                        }
                }

                if ( $order_id <= 0 ) {
                        return;
                }

                $pdf_key   = $this->get_order_meta_value( $order_id, '_sii_boleta_pdf_key' );
                $pdf_nonce = $this->get_order_meta_value( $order_id, '_sii_boleta_pdf_nonce' );
                $pdf_url   = $this->build_pdf_download_link( $order_id, '_sii_boleta', $pdf_key, $pdf_nonce );

                if ( '' === $pdf_url ) {
                        return;
                }

                $link          = function_exists( 'esc_url' ) ? esc_url( $pdf_url ) : $pdf_url;
                $section_title = function_exists( 'esc_html__' ) ? esc_html__( 'Documento tributario electrónico', 'sii-boleta-dte' ) : __( 'Documento tributario electrónico', 'sii-boleta-dte' );
                $button_label  = function_exists( 'esc_html__' ) ? esc_html__( 'Descargar PDF del DTE', 'sii-boleta-dte' ) : __( 'Descargar PDF del DTE', 'sii-boleta-dte' );

                echo '<section class="woocommerce-order-details sii-boleta-dte-documents">';
                echo '<h2>' . $section_title . '</h2>';
                echo '<p><a class="button" href="' . $link . '" target="_blank" rel="noopener noreferrer">' . $button_label . '</a></p>';
                echo '</section>';
        }

        /**
         * Normalises enabled type lists from settings or meta values.
         *
         * @param mixed $raw Raw enabled types value.
         * @return array<int>
         */
        private function normalize_enabled_types( $raw ): array {
                if ( ! is_array( $raw ) ) {
                        return array();
                }

                $codes = array();
                foreach ( $raw as $key => $value ) {
                        if ( is_int( $key ) ) {
                                $codes[] = (int) $value;
                        } else {
                                $codes[] = (int) $key;
                        }
                }

                $codes = array_filter( array_map( 'intval', $codes ) );

                return array_values( array_unique( $codes ) );
        }

        private function should_preview_only(): bool {
                $settings = $this->plugin->get_settings();
                if ( ! is_object( $settings ) ) {
                        return false;
                }

                if ( method_exists( $settings, 'is_woocommerce_preview_only_enabled' ) ) {
                        return (bool) $settings->is_woocommerce_preview_only_enabled();
                }

                return false;
        }
}

class_alias( Woo::class, 'SII_Boleta_Woo' );
