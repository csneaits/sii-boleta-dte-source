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
     * Genera el XML de un DTE en formato SimpleXMLElement. Devuelve el
     * contenido como string. Si ocurre un error, devuelve false.
     *
     * @param array $data Datos necesarios para el DTE (ver SII_Boleta_Core::ajax_generate_dte).
     * @return string|false
     */
    public function generate_dte_xml( array $data ) {
        $settings = ( new SII_Boleta_Settings() )->get_settings();
        $caf_path = $settings['caf_path'];
        if ( ! $caf_path || ! file_exists( $caf_path ) ) {
            return false;
        }

        try {
            $xml = new SimpleXMLElement( '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte"></DTE>' );
            $documento = $xml->addChild( 'Documento' );
            $documento->addAttribute( 'ID', 'DTE' . $data['Folio'] );

            // Encabezado
            $encabezado = $documento->addChild( 'Encabezado' );
            $id_doc = $encabezado->addChild( 'IdDoc' );
            $id_doc->addChild( 'TipoDTE', $data['TipoDTE'] );
            $id_doc->addChild( 'Folio', $data['Folio'] );
            $id_doc->addChild( 'FchEmis', $data['FchEmis'] );

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

            // Totales
            $monto_total = 0;
            foreach ( $data['Detalles'] as $detalle ) {
                // Asegurarse de que MontoItem está definido correctamente
                $monto_total += intval( $detalle['MontoItem'] );
            }
            $totales = $encabezado->addChild( 'Totales' );
            $tipo_dte = intval( $data['TipoDTE'] );
            // Para facturas afectas (33) se debe desglosar en neto e IVA
            if ( $tipo_dte === 33 ) {
                $neto = round( $monto_total / 1.19 );
                $iva  = $monto_total - $neto;
                $totales->addChild( 'MntNeto', $neto );
                $totales->addChild( 'IVA', $iva );
                $totales->addChild( 'MntTotal', $monto_total );
            } elseif ( $tipo_dte === 34 ) {
                // Factura exenta, total exento
                $totales->addChild( 'MntExe', $monto_total );
                $totales->addChild( 'MntTotal', $monto_total );
            } else {
                // Boletas y notas utilizan solo MntTotal
                $totales->addChild( 'MntTotal', $monto_total );
            }

            // Detalles
            foreach ( $data['Detalles'] as $detalle ) {
                $det = $documento->addChild( 'Detalle' );
                $det->addChild( 'NroLinDet', $detalle['NroLinDet'] );
                $det->addChild( 'NmbItem', $detalle['NmbItem'] );
                $det->addChild( 'QtyItem', $detalle['QtyItem'] );
                $det->addChild( 'PrcItem', $detalle['PrcItem'] );
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

            // Generar TED
            $ted = $this->generate_ted( $data, $caf_path );
            if ( $ted ) {
                // TED debe incluirse como nuevo elemento a nivel de Documento (no dentro de Detalle)
                $ted_node = new SimpleXMLElement( $ted );
                // Importar el TED al contexto del documento
                $dom1 = dom_import_simplexml( $documento );
                $dom2 = dom_import_simplexml( $ted_node );
                $dom1->appendChild( $dom1->ownerDocument->importNode( $dom2, true ) );
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
    private function generate_ted( array $data, $caf_path ) {
        // Cargar configuración y certificado
        $settings = ( new SII_Boleta_Settings() )->get_settings();
        $cert_path = $settings['cert_path'];
        $cert_pass = $settings['cert_pass'];
        if ( ! $cert_path || ! file_exists( $cert_path ) ) {
            return false;
        }
        // Leer el CAF completo como string
        $caf_content = file_get_contents( $caf_path );
        $caf_xml     = new SimpleXMLElement( $caf_content );
        // Extraer el fragmento <CAF> para incluirlo en el TED
        $caf_fragment = $caf_xml->asXML();

        // Construir el nodo DD con los campos mínimos
        $dd  = '<DD>';
        $dd .= '<RE>'  . $data['RutEmisor']               . '</RE>';
        $dd .= '<TD>'  . $data['TipoDTE']                 . '</TD>';
        $dd .= '<F>'   . $data['Folio']                   . '</F>';
        $dd .= '<FE>'  . $data['FchEmis']                 . '</FE>';
        $dd .= '<RR>'  . $data['Receptor']['RUTRecep']    . '</RR>';
        // RSR se limita a 40 caracteres según la normativa
        $rsr = substr( strtoupper( $data['Receptor']['RznSocRecep'] ), 0, 40 );
        $dd .= '<RSR>' . $rsr                             . '</RSR>';
        $monto_total = 0;
        foreach ( $data['Detalles'] as $detalle ) {
            $monto_total += intval( $detalle['MontoItem'] );
        }
        $dd .= '<MNT>' . $monto_total                     . '</MNT>';
        // Se utiliza el nombre del primer ítem como IT1 (puede ampliarse a concatenar otros)
        $dd .= '<IT1>' . substr( $data['Detalles'][0]['NmbItem'], 0, 40 ) . '</IT1>';
        $dd .= $caf_fragment;
        $dd .= '<TSTED>' . date( 'Y-m-d\TH:i:s' ) . '</TSTED>';
        $dd .= '</DD>';

        // Firmar el DD utilizando el certificado y la clave privada
        $pkcs12 = file_get_contents( $cert_path );
        if ( ! openssl_pkcs12_read( $pkcs12, $creds, $cert_pass ) ) {
            return false;
        }
        $private_key = $creds['pkey'];
        $firma       = '';
        // Usamos SHA1 (requisito tradicional del SII) para la firma de TED
        if ( ! openssl_sign( $dd, $firma, $private_key, OPENSSL_ALGO_SHA1 ) ) {
            return false;
        }
        $frmt = base64_encode( $firma );

        // Construir el TED completo
        $ted  = '<TED version="1.0">';
        $ted .= $dd;
        $ted .= '<FRMT algoritmo="SHA1withRSA">' . $frmt . '</FRMT>';
        $ted .= '</TED>';

        return $ted;
    }
}