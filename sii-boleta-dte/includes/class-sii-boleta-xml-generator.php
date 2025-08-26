<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generador de XML para DTE. Esta clase construye la estructura XML
 * requerida por el SII según el tipo de documento (Boleta, Nota de Crédito,
 * Nota de Débito). También incorpora el timbre electrónico (TED) basado en
 * el archivo CAF proporcionado en la configuración.
 */
class SII_Boleta_XML_Generator {

    /**
     * Instancia de configuraciones del plugin.
     *
     * @var SII_Boleta_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param SII_Boleta_Settings $settings Instancia de configuraciones.
     */
    public function __construct( SII_Boleta_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Genera el XML de un DTE en formato SimpleXMLElement. Devuelve el
     * contenido como string. Si ocurre un error, devuelve false.
     *
     * @param array $data    Datos necesarios para el DTE (ver SII_Boleta_Core::ajax_generate_dte).
     * @param int   $tipo_dte Tipo de DTE solicitado.
     * @param bool  $preview  Si es true, omite la validación de CAF/TED para previsualización.
     * @return string|\WP_Error|false
     */
    public function generate_dte_xml( array $data, $tipo_dte, $preview = false ) {
        $settings  = $this->settings->get_settings();
        $caf_paths = $settings['caf_path'] ?? [];
        $caf_path  = $caf_paths[ $tipo_dte ] ?? '';
        if ( ! $preview && ( ! $caf_path || ! file_exists( $caf_path ) ) ) {
            return new \WP_Error( 'sii_boleta_missing_caf', sprintf( __( 'No se encontró CAF para el tipo de DTE %s.', 'sii-boleta-dte' ), $tipo_dte ) );
        }
        $data['TipoDTE'] = $tipo_dte;

        try {
            $xml = new SimpleXMLElement( '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte"></DTE>' );
            $documento = $xml->addChild( 'Documento' );
            $documento->addAttribute( 'ID', 'DTE' . $data['Folio'] );

            // Encabezado
            $encabezado = $documento->addChild( 'Encabezado' );
            $id_doc = $encabezado->addChild( 'IdDoc' );
            $id_doc->addChild( 'TipoDTE', $tipo_dte );
            $id_doc->addChild( 'Folio', $data['Folio'] );
            $id_doc->addChild( 'FchEmis', $data['FchEmis'] );
            if ( ! empty( $data['MedioPago'] ) ) {
                $id_doc->addChild( 'MedioPago', $data['MedioPago'] );
            }

            $emisor = $encabezado->addChild( 'Emisor' );
            $emisor->addChild( 'RUTEmisor', $data['RutEmisor'] );
            $emisor->addChild( 'RznSoc', $data['RznSoc'] );
            $emisor->addChild( 'GiroEmis', $data['GiroEmisor'] );
            $emisor->addChild( 'DirOrigen', $data['DirOrigen'] );
            $emisor->addChild( 'CmnaOrigen', $data['CmnaOrigen'] );

            $receptor = $encabezado->addChild( 'Receptor' );
            $receptor->addChild( 'RUTRecep', $data['Receptor']['RUTRecep'] );
            $receptor->addChild( 'RznSocRecep', $data['Receptor']['RznSocRecep'] );
            $receptor->addChild( 'DirRecep', $data['Receptor']['DirRecep'] );
            $receptor->addChild( 'CmnaRecep', $data['Receptor']['CmnaRecep'] );
            if ( ! empty( $data['Receptor']['CorreoRecep'] ) ) {
                $receptor->addChild( 'CorreoRecep', $data['Receptor']['CorreoRecep'] );
            }
            if ( ! empty( $data['Receptor']['TelefonoRecep'] ) ) {
                $receptor->addChild( 'TelefonoRecep', $data['Receptor']['TelefonoRecep'] );
            }

            // Totales
            $monto_total  = 0;
            $monto_exento = 0;
            $desc_total   = 0;
            $rec_total    = 0;
            $neto_total   = 0;
            $iva_total    = 0;
            $last_afecto  = null;
            foreach ( $data['Detalles'] as $index => &$detalle ) {
                $qty   = floatval( $detalle['QtyItem'] );
                $price = floatval( $detalle['PrcItem'] );
                $desc  = isset( $detalle['DescuentoMonto'] ) ? floatval( $detalle['DescuentoMonto'] ) : 0;
                $rec   = isset( $detalle['RecargoMonto'] ) ? floatval( $detalle['RecargoMonto'] ) : 0;
                $monto_item           = intval( ( $qty * $price ) - $desc + $rec );
                $detalle['MontoItem'] = $monto_item;
                $monto_total         += $monto_item;
                if ( ! empty( $detalle['IndExe'] ) ) {
                    $monto_exento += $monto_item;
                } else {
                    // Calcular neto e IVA por línea para mantener consistencia
                    $neto_linea = intval( $monto_item / 1.19 );
                    $iva_linea  = $monto_item - $neto_linea;
                    $neto_total += $neto_linea;
                    $iva_total  += $iva_linea;
                    $last_afecto = $index;
                }
                $desc_total += intval( $desc );
                $rec_total  += intval( $rec );
            }
            unset( $detalle );

            // Ajuste por diferencias de redondeo hasta $1
            $monto_afecto = $monto_total - $monto_exento;
            $diff         = $monto_afecto - ( $neto_total + $iva_total );
            if ( 0 !== $diff && null !== $last_afecto && isset( $data['Detalles'][ $last_afecto ] ) ) {
                $data['Detalles'][ $last_afecto ]['MontoItem'] += $diff;
                $monto_total += $diff;
                $neto_total  += $diff;
            }

            $totales = $encabezado->addChild( 'Totales' );
            $tipo_dte = intval( $data['TipoDTE'] );
            // Para facturas afectas (33) se debe desglosar en neto e IVA
            if ( $tipo_dte === 33 ) {
                $totales->addChild( 'MntNeto', $neto_total );
                $totales->addChild( 'IVA', $iva_total );
                if ( $monto_exento > 0 ) {
                    $totales->addChild( 'MntExe', $monto_exento );
                }
                $totales->addChild( 'MntTotal', $monto_total );
            } elseif ( $tipo_dte === 34 ) {
                // Factura exenta, total exento
                $totales->addChild( 'MntExe', $monto_total );
                $totales->addChild( 'MntTotal', $monto_total );
            } elseif ( $tipo_dte === 39 ) {
                // Boleta afecta: separar neto e IVA
                $totales->addChild( 'MntNeto', $neto_total );
                $totales->addChild( 'IVA', $iva_total );
                if ( $monto_exento > 0 ) {
                    $totales->addChild( 'MntExe', $monto_exento );
                }
                $totales->addChild( 'MntTotal', $monto_total );
            } elseif ( $tipo_dte === 41 ) {
                // Boleta exenta, total exento
                $totales->addChild( 'MntExe', $monto_total );
                $totales->addChild( 'MntTotal', $monto_total );
            } else {
                // Boletas y notas utilizan solo MntTotal
                $totales->addChild( 'MntTotal', $monto_total );
            }
            if ( $desc_total > 0 ) {
                $totales->addChild( 'DescTotal', $desc_total );
            }
            if ( $rec_total > 0 ) {
                $totales->addChild( 'RecargoTotal', $rec_total );
            }

            if ( $monto_total > $this->get_high_value_threshold() ) {
                if ( empty( $data['Receptor']['RUTRecep'] ) || empty( $data['Receptor']['CorreoRecep'] ) ) {
                    return new \WP_Error( 'sii_boleta_high_value_contact', __( 'Boletas superiores a 135 UF deben ser nominativas e incluir contacto.', 'sii-boleta-dte' ) );
                }
            }

            // Detalles
            foreach ( $data['Detalles'] as $detalle ) {
                $det = $documento->addChild( 'Detalle' );
                $det->addChild( 'NroLinDet', $detalle['NroLinDet'] );
                $det->addChild( 'NmbItem', $detalle['NmbItem'] );
                $det->addChild( 'QtyItem', $detalle['QtyItem'] );
                $det->addChild( 'PrcItem', $detalle['PrcItem'] );
                if ( ! empty( $detalle['IndExe'] ) ) {
                    $det->addChild( 'IndExe', 1 );
                }
                if ( isset( $detalle['DescuentoMonto'] ) ) {
                    $det->addChild( 'DescuentoMonto', $detalle['DescuentoMonto'] );
                }
                if ( isset( $detalle['RecargoMonto'] ) ) {
                    $det->addChild( 'RecargoMonto', $detalle['RecargoMonto'] );
                }
                $det->addChild( 'MontoItem', $detalle['MontoItem'] );
            }

            // Referencias (solo para notas de crédito, débito e incluso facturas)
            if ( ! empty( $data['Referencias'] ) && is_array( $data['Referencias'] ) ) {
                foreach ( $data['Referencias'] as $ref ) {
                    $referencia = $documento->addChild( 'Referencia' );
                    $referencia->addChild( 'TpoDocRef', $ref['TpoDocRef'] );
                    $referencia->addChild( 'FolioRef', $ref['FolioRef'] );
                    $referencia->addChild( 'FchRef', $ref['FchRef'] );
                    if ( isset( $ref['RazonRef'] ) ) {
                        $referencia->addChild( 'RazonRef', $ref['RazonRef'] );
                    }
                }
            }

            // Generar TED a menos que estemos en modo previsualización
            if ( ! $preview ) {
                $ted = $this->generate_ted( $data, $caf_path );
                if ( $ted ) {
                    // TED debe incluirse como nuevo elemento a nivel de Documento (no dentro de Detalle)
                    $ted_node = new SimpleXMLElement( $ted );
                    // Importar el TED al contexto del documento
                    $dom1 = dom_import_simplexml( $documento );
                    $dom2 = dom_import_simplexml( $ted_node );
                    $dom1->appendChild( $dom1->ownerDocument->importNode( $dom2, true ) );
                }
            }

            return $xml->asXML();
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Genera el timbre electrónico (TED) para un DTE. Esta implementación
     * simplifica el proceso utilizando el certificado configurado para firmar
     * los datos clave. El TED incluye el CAF completo y las firmas
     * correspondientes. Aunque no cubre todos los matices de la norma del SII,
     * proporciona una base funcional que puede ampliarse en el futuro.
     *
     * @param array  $data    Datos del DTE.
     * @param string $caf_path Ruta al archivo CAF.
     * @return string XML del TED o false si falla.
     */
    protected function generate_ted( array $data, $caf_path ) {
        // Cargar configuración y certificado
        $settings = $this->settings->get_settings();
        $cert_path = $settings['cert_path'];
        $cert_pass = $settings['cert_pass'];
        if ( ! $cert_path || ! file_exists( $cert_path ) ) {
            return false;
        }
        // Leer el CAF completo como string
        $caf_content = file_get_contents( $caf_path );

        // Construir el nodo DD utilizando DOMDocument para evitar
        // concatenaciones frágiles y asegurar una firma estable.
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dd  = $dom->createElement( 'DD' );
        $dom->appendChild( $dd );

        $dd->appendChild( $dom->createElement( 'RE', $data['RutEmisor'] ) );
        $dd->appendChild( $dom->createElement( 'TD', $data['TipoDTE'] ) );
        $dd->appendChild( $dom->createElement( 'F', $data['Folio'] ) );
        $dd->appendChild( $dom->createElement( 'FE', $data['FchEmis'] ) );
        $dd->appendChild( $dom->createElement( 'RR', $data['Receptor']['RUTRecep'] ) );

        // RSR se limita a 40 caracteres según la normativa
        $rsr = mb_substr( mb_strtoupper( $data['Receptor']['RznSocRecep'], 'UTF-8' ), 0, 40 );
        $dd->appendChild( $dom->createElement( 'RSR', $rsr ) );

        $monto_total = 0;
        $item_names  = [];
        foreach ( $data['Detalles'] as $detalle ) {
            $monto_total += intval( $detalle['MontoItem'] );
            $item_names[] = $detalle['NmbItem'];
        }
        $dd->appendChild( $dom->createElement( 'MNT', $monto_total ) );

        // IT1 se construye concatenando nombres de ítems, separados por coma,
        // en mayúsculas y limitado a 40 caracteres.
        $it1        = implode( ', ', $item_names );
        $it1        = mb_strtoupper( $it1, 'UTF-8' );
        $it1        = mb_substr( $it1, 0, 40 );
        $dd->appendChild( $dom->createElement( 'IT1', $it1 ) );

        // Importar el nodo <CAF> completo dentro de <DD>
        $caf_dom = new DOMDocument();
        $caf_dom->loadXML( $caf_content );
        $caf_node = $caf_dom->getElementsByTagName( 'CAF' )->item( 0 );
        if ( $caf_node ) {
            $dd->appendChild( $dom->importNode( $caf_node, true ) );
        }

        $dd->appendChild( $dom->createElement( 'TSTED', date( 'Y-m-d\TH:i:s' ) ) );

        // Canonicalizar DD para firmarlo
        $dd_string = $dom->C14N();

        // Firmar el DD utilizando el certificado y la clave privada
        $pkcs12 = file_get_contents( $cert_path );
        if ( ! openssl_pkcs12_read( $pkcs12, $creds, $cert_pass ) ) {
            return false;
        }
        $private_key = $creds['pkey'];
        $firma       = '';
        // Usamos SHA1 (requisito tradicional del SII) para la firma de TED
        if ( ! openssl_sign( $dd_string, $firma, $private_key, OPENSSL_ALGO_SHA1 ) ) {
            return false;
        }
        $frmt = base64_encode( $firma );

        // Construir el TED completo
        $ted  = '<TED version="1.0">';
        $ted .= $dom->saveXML( $dom->documentElement );
        $ted .= '<FRMT algoritmo="SHA1withRSA">' . $frmt . '</FRMT>';
        $ted .= '</TED>';

        return $ted;
    }

    /**
     * Threshold in pesos for requiring nominative invoices (135 UF approx).
     *
     * @return int
     */
    protected function get_high_value_threshold() {
        // Valor UF aproximado $30.000 CLP.
        return 135 * 30000;
    }
}
