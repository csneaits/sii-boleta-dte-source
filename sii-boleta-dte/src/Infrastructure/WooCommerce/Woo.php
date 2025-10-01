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
        /**
         * Core plugin instance used to access settings and services.
         *
         * @var Plugin|null
         */
        private ?Plugin $plugin;

        /**
         * @param Plugin|null $plugin Core plugin instance. It can be null when the
         *                            WooCommerce integration is resolved from the
         *                            container without a bootstrapped plugin.
         */
        public function __construct( ?Plugin $plugin ) {
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
                                add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_credit_note_modal' ), 10, 1 );
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
                $context = $this->resolve_refund_context_from_request( $order );

                if ( ! empty( $context['reason'] ) && method_exists( $order, 'get_id' ) ) {
                        $this->update_order_meta( (int) $order->get_id(), '_sii_boleta_credit_note_reason', $context['reason'] );
                }

                if ( isset( $context['refund_id'] ) && method_exists( $order, 'get_id' ) ) {
                        $this->update_order_meta( (int) $order->get_id(), '_sii_boleta_credit_note_refund_id', (string) $context['refund_id'] );
                }

                $this->handle_note_generation( $order, self::CREDIT_NOTE_TYPE, __( 'Nota de crédito generada manualmente.', 'sii-boleta-dte' ), $context );
        }

        /**
         * Handles manual debit note generation for cancelled orders.
         */
        public function handle_manual_debit_note( $order ): void {
                $this->handle_note_generation( $order, self::DEBIT_NOTE_TYPE, __( 'Nota de débito generada manualmente.', 'sii-boleta-dte' ) );
        }

        /**
         * Renders the modal that allows selecting a partial refund before triggering the credit note.
         */
        public function render_credit_note_modal( $order ): void {
                if ( ! $order || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_refunds' ) ) {
                        return;
                }

                $refunds = $order->get_refunds();
                if ( ! is_array( $refunds ) || empty( $refunds ) ) {
                        return;
                }

                $options     = array();
                $order_total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
                foreach ( $refunds as $refund ) {
                        if ( ! is_object( $refund ) || ! method_exists( $refund, 'get_id' ) ) {
                                continue;
                        }

                        $amount = $this->resolve_refund_total_amount( $refund );
                        if ( $amount <= 0 ) {
                                continue;
                        }

                        if ( $order_total > 0 && $amount >= $order_total ) {
                                continue;
                        }

                        $refund_id = (int) $refund->get_id();
                        $reason    = method_exists( $refund, 'get_reason' ) ? trim( (string) $refund->get_reason() ) : '';
                        $label     = sprintf( '#%d - %s', $refund_id, $this->format_amount_for_display( $amount ) );
                        if ( '' !== $reason ) {
                                $label .= ' — ' . $reason;
                        }

                        $options[] = array(
                                'id'    => $refund_id,
                                'label' => $label,
                        );
                }

                if ( empty( $options ) ) {
                        return;
                }

                $modal_id  = 'sii-boleta-credit-note-modal-' . (int) $order->get_id();
                $select_id = $modal_id . '-refund';
                $reason_id = $modal_id . '-reason';
                $codref_id = $modal_id . '-codref';

                static $printed_assets = false;
                if ( ! $printed_assets ) {
                        $printed_assets = true;
                        echo '<style>';
                        echo '.sii-boleta-credit-note-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:100000;}';
                        echo '.sii-boleta-credit-note-modal.is-open{display:flex;}';
                        echo '.sii-boleta-credit-note-modal .sii-boleta-credit-note-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.45);}';
                        echo '.sii-boleta-credit-note-modal .sii-boleta-credit-note-dialog{position:relative;background:#fff;padding:24px;max-width:440px;width:90%;box-shadow:0 20px 40px rgba(0,0,0,0.2);border-radius:6px;}';
                        echo '.sii-boleta-credit-note-dialog h3{margin-top:0;margin-bottom:12px;font-size:18px;}';
                        echo '.sii-boleta-credit-note-dialog p{margin-top:0;margin-bottom:16px;font-size:14px;}';
                        echo '.sii-boleta-credit-note-dialog label{display:block;font-weight:600;margin-bottom:4px;}';
                        echo '.sii-boleta-credit-note-dialog select,.sii-boleta-credit-note-dialog textarea{width:100%;margin-bottom:14px;}';
                        echo '.sii-boleta-credit-note-dialog .sii-boleta-credit-note-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:8px;}';
                        echo '</style>';
                }

                echo '<div class="sii-boleta-credit-note-modal" id="' . esc_attr( $modal_id ) . '" aria-hidden="true" role="dialog" aria-labelledby="' . esc_attr( $modal_id . '-title' ) . '">';
                echo '<div class="sii-boleta-credit-note-backdrop" data-sii-boleta-close="1"></div>';
                echo '<div class="sii-boleta-credit-note-dialog" role="document">';
                echo '<h3 id="' . esc_attr( $modal_id . '-title' ) . '">' . esc_html__( 'Nota de crédito parcial', 'sii-boleta-dte' ) . '</h3>';
                echo '<p>' . esc_html__( 'Selecciona el reembolso parcial y define la glosa que será enviada al SII.', 'sii-boleta-dte' ) . '</p>';
                echo '<label for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Reembolso asociado', 'sii-boleta-dte' ) . '</label>';
                echo '<select id="' . esc_attr( $select_id ) . '" name="' . esc_attr( $select_id ) . '">';
                echo '<option value="">' . esc_html__( 'Selecciona un reembolso', 'sii-boleta-dte' ) . '</option>';
                foreach ( $options as $option ) {
                        echo '<option value="' . esc_attr( (string) $option['id'] ) . '">' . esc_html( $option['label'] ) . '</option>';
                }
                echo '</select>';
                echo '<label for="' . esc_attr( $codref_id ) . '">' . esc_html__( 'Tipo de corrección', 'sii-boleta-dte' ) . '</label>';
                echo '<select id="' . esc_attr( $codref_id ) . '">';
                echo '<option value="4">' . esc_html__( 'Anulación parcial del detalle', 'sii-boleta-dte' ) . '</option>';
                echo '<option value="6">' . esc_html__( 'Descuentos o bonificaciones', 'sii-boleta-dte' ) . '</option>';
                echo '</select>';
                echo '<label for="' . esc_attr( $reason_id ) . '">' . esc_html__( 'Glosa de corrección', 'sii-boleta-dte' ) . '</label>';
                echo '<textarea id="' . esc_attr( $reason_id ) . '" rows="3" placeholder="' . esc_attr( __( 'Describe el motivo del ajuste', 'sii-boleta-dte' ) ) . '"></textarea>';
                echo '<div class="sii-boleta-credit-note-actions">';
                echo '<button type="button" class="button button-primary" data-sii-boleta-confirm="1">' . esc_html__( 'Aplicar reembolso', 'sii-boleta-dte' ) . '</button>';
                echo '<button type="button" class="button" data-sii-boleta-close="1">' . esc_html__( 'Cancelar', 'sii-boleta-dte' ) . '</button>';
                echo '</div>';
                echo '</div>';
                echo '</div>';

                $modal_id_json  = $this->encode_for_js( $modal_id );
                $select_id_json = $this->encode_for_js( $select_id );
                $reason_id_json = $this->encode_for_js( $reason_id );
                $codref_id_json = $this->encode_for_js( $codref_id );
                $action_value   = $this->encode_for_js( 'sii_boleta_generate_credit_note' );

                echo '<script>';
                echo '(function(){';
                echo 'function initializeModal(){';
                echo 'var modal=document.getElementById(' . $modal_id_json . ');';
                echo 'if(!modal){return;}';
                echo 'var refundSelect=document.getElementById(' . $select_id_json . ');';
                echo 'var reasonField=document.getElementById(' . $reason_id_json . ');';
                echo 'var codrefField=document.getElementById(' . $codref_id_json . ');';
                echo 'var orderActions=document.getElementById("order_actions");';
                echo 'if(!orderActions){return;}';
                echo 'var container=orderActions.closest("p")||orderActions.parentElement;';
                echo 'var applyButton=container?container.querySelector("button.button"):null;';
                echo 'if(!applyButton){return;}';
                echo 'var form=document.getElementById("post");';
                echo 'if(!form){return;}';
                echo 'var refundInput=form.querySelector("input[name=\\"sii_boleta_refund_id\\"]");';
                echo 'if(!refundInput){refundInput=document.createElement("input");refundInput.type="hidden";refundInput.name="sii_boleta_refund_id";form.appendChild(refundInput);}';
                echo 'var reasonInput=form.querySelector("input[name=\\"sii_boleta_refund_reason\\"]");';
                echo 'if(!reasonInput){reasonInput=document.createElement("input");reasonInput.type="hidden";reasonInput.name="sii_boleta_refund_reason";form.appendChild(reasonInput);}';
                echo 'var codrefInput=form.querySelector("input[name=\\"sii_boleta_refund_codref\\"]");';
                echo 'if(!codrefInput){codrefInput=document.createElement("input");codrefInput.type="hidden";codrefInput.name="sii_boleta_refund_codref";form.appendChild(codrefInput);}';
                echo 'var allowSubmit=false;';
                echo 'function openModal(){modal.classList.add("is-open");modal.setAttribute("aria-hidden","false");if(refundSelect&&!refundSelect.value){refundSelect.focus();}}';
                echo 'function closeModal(){modal.classList.remove("is-open");modal.setAttribute("aria-hidden","true");}';
                echo 'modal.querySelectorAll("[data-sii-boleta-close]").forEach(function(btn){btn.addEventListener("click",function(ev){ev.preventDefault();closeModal();});});';
                echo 'var confirmButton=modal.querySelector("[data-sii-boleta-confirm]");';
                echo 'if(confirmButton){confirmButton.addEventListener("click",function(ev){ev.preventDefault();if(!refundSelect||!refundSelect.value){refundSelect.focus();return;}refundInput.value=refundSelect.value;reasonInput.value=reasonField?reasonField.value:"";codrefInput.value=codrefField?codrefField.value:"";allowSubmit=true;closeModal();applyButton.click();});}';
                echo 'document.addEventListener("keydown",function(ev){if("Escape"===ev.key&&modal.classList.contains("is-open")){closeModal();}});';
                echo 'applyButton.addEventListener("click",function(ev){if(allowSubmit){allowSubmit=false;return;}if(orderActions&&orderActions.value===' . $action_value . '){ev.preventDefault();openModal();}});';
                echo '}';
                echo 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",initializeModal);}else{initializeModal();}';
                echo '})();';
                echo '</script>';
        }

        private function resolve_refund_context_from_request( $order ): array {
                if ( ! $order || ! method_exists( $order, 'get_refunds' ) ) {
                        return array();
                }

                $refund_id = $this->read_int_from_request( 'sii_boleta_refund_id' );
                if ( $refund_id <= 0 ) {
                        return array();
                }

                $refunds = $order->get_refunds();
                if ( ! is_array( $refunds ) ) {
                        return array();
                }

                $selected = null;
                foreach ( $refunds as $refund ) {
                        if ( ! is_object( $refund ) || ! method_exists( $refund, 'get_id' ) ) {
                                continue;
                        }
                        if ( (int) $refund->get_id() === $refund_id ) {
                                $selected = $refund;
                                break;
                        }
                }

                if ( ! $selected ) {
                        return array();
                }

                $codref = $this->read_int_from_request( 'sii_boleta_refund_codref' );
                if ( ! in_array( $codref, array( 4, 6 ), true ) ) {
                        $codref = 4;
                }

                $reason = $this->read_string_from_request( 'sii_boleta_refund_reason' );

                return array(
                        'refund'    => $selected,
                        'refund_id' => $refund_id,
                        'codref'    => $codref,
                        'reason'    => $reason,
                );
        }

        private function handle_note_generation( $order, int $document_type, string $note_message, array $context = array() ): void {
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
                        (int) $order->get_id(),
                        $context
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

                if ( null === $this->plugin ) {
                        return 0;
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
        private function generate_document_for_order( $order, int $document_type, string $meta_prefix, string $success_note, int $order_id = 0, array $context = array() ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
                if ( ! $order ) {
                        return;
                }

                if ( $order_id <= 0 && method_exists( $order, 'get_id' ) ) {
                        $order_id = (int) $order->get_id();
                }

                if ( $order_id <= 0 ) {
                        return;
                }
                if ( null === $this->plugin ) {
                        return;
                }

                $data = $this->prepare_order_data( $order, $document_type, $order_id, $context );

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
        private function prepare_order_data( $order, int $document_type, int $order_id, array $context = array() ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
                if ( ! $order ) {
                        return array();
                }

                if ( $order_id <= 0 && method_exists( $order, 'get_id' ) ) {
                        $order_id = (int) $order->get_id();
                }

                if ( $order_id <= 0 ) {
                        return array();
                }

                $date          = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' );
                $rut           = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $order_id, 'billing_rut', true ) : '';
                $using_refund  = self::CREDIT_NOTE_TYPE === $document_type && isset( $context['refund'] ) && is_object( $context['refund'] );
                $refund_object = $using_refund ? $context['refund'] : null;

                $items = array();
                if ( $using_refund && $refund_object ) {
                        $items = $this->build_refund_items( $refund_object );
                } elseif ( method_exists( $order, 'get_items' ) ) {
                        $line = 1;
                        foreach ( $order->get_items() as $item ) {
                                if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
                                        continue;
                                }
                                $qty = method_exists( $item, 'get_quantity' ) ? (float) $item->get_quantity() : 1.0;
                                $qty = $qty > 0 ? $qty : 1.0;
                                $total_excluding_tax = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
                                $line_total          = $total_excluding_tax;
                                $unit_price          = $qty > 0 ? $line_total / $qty : $line_total;
                                $items[]    = array(
                                        'NroLinDet' => $line,
                                        'NmbItem'   => (string) $item->get_name(),
                                        'QtyItem'   => $qty,
                                        'PrcItem'   => $unit_price,
                                        'MontoItem' => $line_total,
                                );
                                ++$line;
                        }
                }

                if ( empty( $items ) ) {
                        if ( $using_refund && $refund_object ) {
                                $fallback_total = $this->resolve_refund_total_amount( $refund_object );
                                $tax_total      = $this->abs_float( method_exists( $refund_object, 'get_total_tax' ) ? $refund_object->get_total_tax() : 0.0 );
                                if ( $fallback_total > 0 ) {
                                        $description = trim( (string) ( $context['reason'] ?? '' ) );
                                        if ( '' === $description ) {
                                                $description = __( 'Reembolso parcial del pedido', 'sii-boleta-dte' );
                                        }
                                        $items[] = array(
                                                'NroLinDet' => 1,
                                                'NmbItem'   => $description,
                                                'QtyItem'   => 1,
                                                'PrcItem'   => max( 0.0, $fallback_total - $tax_total ),
                                                'MontoItem' => max( 0.0, $fallback_total - $tax_total ),
                                        );
                                }
                        } elseif ( method_exists( $order, 'get_total' ) ) {
                                $fallback_total = (float) $order->get_total();
                                $tax_total      = method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0;
                                $fallback_net   = max( 0.0, $fallback_total - $tax_total );
                                if ( $fallback_net > 0 ) {
                                        $items[] = array(
                                                'NroLinDet' => 1,
                                                'NmbItem'   => sprintf(
                                                        /* translators: %s: order number used as description fallback. */
                                                        __( 'Pedido #%s', 'sii-boleta-dte' ),
                                                        method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order_id
                                                ),
                                                'QtyItem'   => 1,
                                                'PrcItem'   => $fallback_net,
                                                'MontoItem' => $fallback_net,
                                        );
                                }
                        }
                }

                $totals = array();
                if ( $using_refund && $refund_object ) {
                        $totals = $this->build_refund_totals( $refund_object );
                } elseif ( method_exists( $order, 'get_total' ) ) {
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
                                list( $reference_code, $reference_reason ) = $this->determine_reference_details( $document_type, $using_refund, $context );

                                $data['Referencia'] = array(
                                        array(
                                                'NroLinRef' => 1,
                                                'TpoDocRef' => $original_type,
                                                'FolioRef'  => $order_id,
                                                'CodRef'    => $reference_code,
                                                'RazonRef'  => $reference_reason,
                                        ),
                                );
                        }
                }

                return $data;
        }

        /**
         * Builds detail lines from a refund object.
         */
        private function build_refund_items( $refund ): array {
                $items = array();
                if ( ! is_object( $refund ) || ! method_exists( $refund, 'get_items' ) ) {
                        return $items;
                }

                $line       = 1;
                $item_types = array( 'line_item', 'shipping', 'fee' );
                foreach ( $refund->get_items( $item_types ) as $item ) {
                        if ( ! is_object( $item ) ) {
                                continue;
                        }

                        $amount = $this->abs_float( method_exists( $item, 'get_total' ) ? $item->get_total() : 0.0 );
                        $line_total = $amount;
                        if ( $line_total <= 0 ) {
                                continue;
                        }

                        $quantity = $this->abs_float( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 1.0 );
                        if ( $quantity <= 0 ) {
                                $quantity = 1.0;
                        }

                        $unit_price = $quantity > 0 ? $line_total / $quantity : $line_total;
                        $name = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : __( 'Reembolso', 'sii-boleta-dte' );

                        if ( method_exists( $item, 'get_type' ) ) {
                                $type = (string) $item->get_type();
                                if ( 'shipping' === $type && '' === trim( $name ) ) {
                                        $name = __( 'Costo de envío reembolsado', 'sii-boleta-dte' );
                                } elseif ( 'fee' === $type && '' === trim( $name ) ) {
                                        $name = __( 'Cargo reembolsado', 'sii-boleta-dte' );
                                }
                        }

                        $items[] = array(
                                'NroLinDet' => $line,
                                'NmbItem'   => $name,
                                'QtyItem'   => $quantity,
                                'PrcItem'   => $unit_price,
                                'MontoItem' => $line_total,
                        );
                        ++$line;
                }

                return $items;
        }

        /**
         * Calculates totals for a refund.
         */
        private function build_refund_totals( $refund ): array {
                $total     = $this->resolve_refund_total_amount( $refund );
                $tax_total = $this->abs_float( is_object( $refund ) && method_exists( $refund, 'get_total_tax' ) ? $refund->get_total_tax() : 0.0 );
                $neto      = max( 0.0, $total - $tax_total );

                return array(
                        'MntNeto'  => $neto,
                        'TasaIVA'  => 19,
                        'IVA'      => $tax_total,
                        'MntTotal' => $total,
                );
        }

        private function resolve_refund_total_amount( $refund ): float {
                if ( is_object( $refund ) && method_exists( $refund, 'get_amount' ) ) {
                        return $this->abs_float( $refund->get_amount() );
                }

                if ( is_object( $refund ) && method_exists( $refund, 'get_total' ) ) {
                        return $this->abs_float( $refund->get_total() );
                }

                return 0.0;
        }

        private function determine_reference_details( int $document_type, bool $using_refund, array $context ): array {
                if ( self::CREDIT_NOTE_TYPE === $document_type && $using_refund ) {
                        $code   = isset( $context['codref'] ) && in_array( (int) $context['codref'], array( 4, 6 ), true ) ? (int) $context['codref'] : 4;
                        $reason = trim( (string) ( $context['reason'] ?? '' ) );

                        if ( '' === $reason ) {
                                $reason = 4 === $code
                                        ? __( 'Anulación parcial del pedido', 'sii-boleta-dte' )
                                        : __( 'Ajuste parcial del pedido', 'sii-boleta-dte' );
                        }

                        return array( $code, $reason );
                }

                if ( self::DEBIT_NOTE_TYPE === $document_type ) {
                        return array( 2, __( 'Ajuste de pedido', 'sii-boleta-dte' ) );
                }

                return array( 1, __( 'Anulación de pedido', 'sii-boleta-dte' ) );
        }

        private function read_int_from_request( string $key ): int {
                $value = $this->get_post_value( $key );
                if ( null === $value ) {
                        return 0;
                }

                if ( is_array( $value ) ) {
                        $value = reset( $value );
                }

                if ( function_exists( 'wp_unslash' ) ) {
                        $value = wp_unslash( $value );
                }

                if ( function_exists( 'absint' ) ) {
                        return absint( $value );
                }

                return (int) abs( (float) $value );
        }

        private function read_string_from_request( string $key ): string {
                $value = $this->get_post_value( $key );
                if ( null === $value ) {
                        return '';
                }

                if ( is_array( $value ) ) {
                        $value = reset( $value );
                }

                if ( function_exists( 'wp_unslash' ) ) {
                        $value = wp_unslash( $value );
                }

                if ( function_exists( 'sanitize_text_field' ) ) {
                        return sanitize_text_field( $value );
                }

                return is_scalar( $value ) ? trim( (string) $value ) : '';
        }

        private function get_post_value( string $key ) {
                if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        return $_POST[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                }

                $value = filter_input( INPUT_POST, $key, FILTER_DEFAULT );
                return false === $value ? null : $value;
        }

        private function abs_float( $value ): float {
                if ( is_numeric( $value ) ) {
                        return (float) abs( (float) $value );
                }

                return 0.0;
        }

        private function format_amount_for_display( float $amount ): string {
                $decimals = abs( $amount - round( $amount ) ) < 0.01 ? 0 : 2;
                return number_format( $amount, $decimals, ',', '.' );
        }

        private function encode_for_js( $value ): string {
                $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
                return is_string( $encoded ) ? $encoded : 'null';
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
                if ( null === $this->plugin ) {
                        return false;
                }

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
