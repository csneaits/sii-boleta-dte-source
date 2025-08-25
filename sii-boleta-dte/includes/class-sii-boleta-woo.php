<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Integración con WooCommerce. Este componente escucha los eventos de
 * creación de pedidos para generar boletas, facturas o guías de despacho
 * según sea necesario. Puede ampliarse para ofrecer opciones de selección
 * de documento en la página de checkout, o generar notas de crédito/debito
 * cuando se procesa una devolución.
 */
class SII_Boleta_Woo {

    /**
     * Referencia a la clase núcleo del plugin para acceder a sus métodos.
     *
     * @var SII_Boleta_Core
     */
    private $core;

    /**
     * Constructor. Registra los hooks de WooCommerce.
     *
     * @param SII_Boleta_Core $core Instancia del núcleo del plugin.
     */
    public function __construct( SII_Boleta_Core $core ) {
        $this->core = $core;
        // Hook cuando un pedido se marca como completado. Se puede ajustar a otro evento.
        add_action( 'woocommerce_order_status_completed', [ $this, 'generate_dte_for_order' ], 10, 1 );

        // Agregar campos personalizados a la página de checkout
        add_filter( 'woocommerce_checkout_fields', [ $this, 'add_custom_checkout_fields' ] );
        // Guardar los campos personalizados
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_custom_checkout_fields' ], 10, 2 );
    }

    /**
     * Genera un DTE para un pedido de WooCommerce. En este ejemplo se
     * genera siempre una boleta (tipo 39) cuando el pedido se completa.
     * Las configuraciones adicionales para facturas u otras notas pueden
     * implementarse según los datos del pedido y preferencias del usuario.
     *
     * @param int $order_id ID del pedido de WooCommerce.
     */
    public function generate_dte_for_order( $order_id ) {
        if ( ! $order_id ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        // Preparar datos de receptor
        // Recoger datos del checkout
        $billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        // Campos personalizados
        $rut_receptor = $order->get_meta( '_sii_rut_recep', true );
        $doc_type     = $order->get_meta( '_sii_dte_type', true );
        // Si no se especificó, por defecto boleta
        $tipo_dte     = (int) ( $doc_type ?: 39 );
        // Validar RUT y utilizar uno genérico si es inválido
        if ( $rut_receptor && ! $this->is_valid_rut( $rut_receptor ) ) {
            $order->add_order_note( __( 'RUT del receptor inválido, se usó 66666666-6.', 'sii-boleta-dte' ) );
            $rut_receptor = '66666666-6';
        }

        // Dirección y comuna receptor
        $dir_recep   = $order->get_billing_address_1();
        $cmna_recep  = $order->get_billing_city();

        // Descripción: listado de productos o número de pedido
        $description = 'Pedido #' . $order->get_order_number();
        // Calcular total
        $total = (float) $order->get_total();
        // Construir detalles por producto
        $detalles = [];
        $line     = 1;
        foreach ( $order->get_items() as $item ) {
            $qty        = $item->get_quantity();
            $line_total = (float) $item->get_total();
            $price      = $qty > 0 ? $line_total / $qty : 0;
            $detalles[] = [
                'NroLinDet' => $line++,
                'NmbItem'   => $item->get_name(),
                'QtyItem'   => $qty,
                'PrcItem'   => $price,
                'MontoItem' => round( $line_total ),
            ];
        }
        if ( empty( $detalles ) ) {
            $detalles[] = [
                'NroLinDet' => 1,
                'NmbItem'   => $description,
                'QtyItem'   => 1,
                'PrcItem'   => $total,
                'MontoItem' => round( $total ),
            ];
        }

        // Generar folio para el tipo de DTE seleccionado
        $folio = $this->core->get_folio_manager()->get_next_folio( $tipo_dte );
        if ( is_wp_error( $folio ) ) {
            $order->add_order_note( $folio->get_error_message() );
            return;
        }
        if ( ! $folio ) {
            return;
        }
        $settings = $this->core->get_settings()->get_settings();
        // Construir data
        $dte_data = [
            'TipoDTE'    => $tipo_dte,
            'Folio'      => $folio,
            'FchEmis'    => date( 'Y-m-d' ),
            'RutEmisor'  => $settings['rut_emisor'],
            'RznSoc'     => $settings['razon_social'],
            'GiroEmisor' => $settings['giro'],
            'DirOrigen'  => $settings['direccion'],
            'CmnaOrigen' => $settings['comuna'],
            'Receptor'   => [
                'RUTRecep'    => $rut_receptor ?: '66666666-6',
                'RznSocRecep' => $billing_name,
                'DirRecep'    => $dir_recep,
                'CmnaRecep'   => $cmna_recep,
            ],
            'Detalles'   => $detalles,
        ];
        // Generar y firmar
        $xml    = $this->core->get_xml_generator()->generate_dte_xml( $dte_data, $tipo_dte );
        if ( is_wp_error( $xml ) || ! $xml ) {
            $order->add_order_note( is_wp_error( $xml ) ? $xml->get_error_message() : __( 'Error al generar el XML del DTE.', 'sii-boleta-dte' ) );
            return;
        }
        $signed = $this->core->get_signer()->sign_dte_xml( $xml, $settings['cert_path'], $settings['cert_pass'] );
        if ( ! $signed ) {
            return;
        }
        // Guardar archivo XML
        $upload_dir = wp_upload_dir();
        $file_name  = 'Woo_DTE_' . $tipo_dte . '_' . $folio . '_' . time() . '.xml';
        $file_path  = trailingslashit( $upload_dir['basedir'] ) . $file_name;
        file_put_contents( $file_path, $signed );
        // Enviar al SII
        $track_id = $this->core->get_api()->send_dte_to_sii(
            $file_path,
            $settings['environment'],
            $settings['api_token'],
            $settings['cert_path'],
            $settings['cert_pass']
        );
        if ( is_wp_error( $track_id ) ) {
            $order->add_order_note( $track_id->get_error_message() );
            $track_id = false;
        }
        // Generar representación PDF y enviarla por correo
        $pdf_path = $this->core->get_pdf()->generate_pdf_representation( $signed, $settings );
        if ( $pdf_path ) {
            $to      = $order->get_billing_email();
            $subject = sprintf( __( 'Boleta electrónica de tu pedido #%s', 'sii-boleta-dte' ), $order->get_order_number() );
            $message = __( 'Adjuntamos la representación de tu boleta electrónica.', 'sii-boleta-dte' );
            if ( $to ) {
                wp_mail( $to, $subject, $message, [], [ $pdf_path ] );
                $order->add_order_note( __( 'Boleta electrónica enviada al cliente por correo electrónico.', 'sii-boleta-dte' ) );
            }
        }
        // Guardar metadatos
        $order->update_meta_data( '_sii_dte_type', $tipo_dte );
        $order->update_meta_data( '_sii_boleta_folio', $folio );
        if ( $track_id ) {
            $order->update_meta_data( '_sii_boleta_track_id', $track_id );
        }
        $order->save();
    }

    /**
     * Añade campos personalizados al formulario de checkout para solicitar el RUT
     * del receptor y el tipo de documento a emitir.
     *
     * @param array $fields Campos actuales del checkout.
     * @return array Campos modificados.
     */
    public function add_custom_checkout_fields( $fields ) {
        // Campo RUT
        $fields['billing']['sii_rut_recep'] = [
            'type'        => 'text',
            'label'       => __( 'RUT para DTE', 'sii-boleta-dte' ),
            'placeholder' => '12.345.678-9',
            'required'    => false,
            'priority'    => 90,
        ];
        // Campo tipo de documento
        $settings = $this->core->get_settings()->get_settings();
        $enabled  = isset( $settings['enabled_dte_types'] ) && is_array( $settings['enabled_dte_types'] ) ? $settings['enabled_dte_types'] : [ '39', '33', '34', '52', '56', '61' ];
        $labels   = [
            '39' => __( 'Boleta Electrónica', 'sii-boleta-dte' ),
            '33' => __( 'Factura Electrónica', 'sii-boleta-dte' ),
            '34' => __( 'Factura Exenta', 'sii-boleta-dte' ),
            '52' => __( 'Guía de Despacho', 'sii-boleta-dte' ),
            '56' => __( 'Nota de Débito Electrónica', 'sii-boleta-dte' ),
            '61' => __( 'Nota de Crédito Electrónica', 'sii-boleta-dte' ),
        ];
        $options = [];
        foreach ( $enabled as $code ) {
            if ( isset( $labels[ $code ] ) ) {
                $options[ $code ] = $labels[ $code ];
            }
        }
        if ( empty( $options ) ) {
            $options = [ '39' => $labels['39'] ];
        }
        $default = array_key_exists( '39', $options ) ? '39' : array_key_first( $options );
        $fields['billing']['sii_dte_type'] = [
            'type'     => 'select',
            'label'    => __( 'Tipo de Documento', 'sii-boleta-dte' ),
            'options'  => $options,
            'default'  => $default,
            'required' => false,
            'priority' => 91,
        ];
        return $fields;
    }

    /**
     * Guarda los campos personalizados del checkout en los metadatos del
     * pedido.
     *
     * @param WC_Order $order Objeto de pedido.
     * @param array    $data  Datos recibidos del checkout.
     */
    public function save_custom_checkout_fields( $order, $data ) {
        if ( isset( $_POST['sii_rut_recep'] ) ) {
            $order->update_meta_data( '_sii_rut_recep', sanitize_text_field( $_POST['sii_rut_recep'] ) );
        }
        if ( isset( $_POST['sii_dte_type'] ) ) {
            $order->update_meta_data( '_sii_dte_type', sanitize_text_field( $_POST['sii_dte_type'] ) );
        }
    }

    /**
     * Valida un RUT chileno comprobando su dígito verificador.
     *
     * @param string $rut RUT a validar.
     * @return bool Verdadero si el RUT es válido.
     */
    private function is_valid_rut( $rut ) {
        $rut = preg_replace( '/[^0-9kK]/', '', $rut );
        if ( strlen( $rut ) < 2 ) {
            return false;
        }
        $dv  = strtoupper( substr( $rut, -1 ) );
        $num = substr( $rut, 0, -1 );
        $sum = 0;
        $fac = 2;
        for ( $i = strlen( $num ) - 1; $i >= 0; $i-- ) {
            $sum += $num[ $i ] * $fac;
            $fac = ( $fac === 7 ) ? 2 : $fac + 1;
        }
        $res = 11 - ( $sum % 11 );
        if ( 11 === $res ) {
            $res = '0';
        } elseif ( 10 === $res ) {
            $res = 'K';
        } else {
            $res = (string) $res;
        }
        return $res === $dv;
    }
}
