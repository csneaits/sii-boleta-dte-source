<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase encargada de generar y enviar el Libro de Boletas al SII.
 *
 * Recorre los archivos DTE emitidos en un rango de fechas para construir
 * el XML conforme al esquema de "LibroBoletas". También permite validar,
 * firmar y enviar dicho XML utilizando la API del SII.
 */
class SII_Libro_Boletas {

    /**
     * Instancia de configuraciones del plugin.
     *
     * @var SII_Boleta_Settings
     */
    private $settings;

    /**
     * Cliente API reutilizado para generar tokens o enviar el libro.
     *
     * @var SII_Boleta_API
     */
    private $api;

    /**
     * Firmador reutilizado para aplicar firma digital al XML del libro.
     *
     * @var SII_Boleta_Signer
     */
    private $signer;

    /**
     * Constructor.
     *
     * @param SII_Boleta_Settings $settings Instancia de configuraciones.
     */
    public function __construct( SII_Boleta_Settings $settings ) {
        $this->settings = $settings;
        $this->api     = new SII_Boleta_API();
        $this->signer  = new SII_Boleta_Signer();
    }

    /**
     * Genera el XML del Libro de Boletas considerando los DTE emitidos en el
     * rango de fechas indicado.
     *
     * @param string $fecha_inicio Fecha inicial (Y-m-d).
     * @param string $fecha_fin    Fecha final (Y-m-d).
     * @return string|false        XML generado o false si falla.
     */
    public function generate_libro_xml( $fecha_inicio, $fecha_fin ) {
        $settings       = $this->settings->get_settings();
        $folio_manager  = new SII_Boleta_Folio_Manager( $this->settings );
        $caf_info       = $folio_manager->get_caf_info();

        try {
            $xml   = new SimpleXMLElement( '<LibroBoleta version="1.0" xmlns="http://www.sii.cl/SiiDte"></LibroBoleta>' );
            $envio = $xml->addChild( 'EnvioLibro' );
            $envio->addAttribute( 'ID', 'EnvioLibro' );

            // Carátula obligatoria
            $caratula = $envio->addChild( 'Caratula' );
            $caratula->addChild( 'RutEmisorLibro', $settings['rut_emisor'] ?? '' );
            $caratula->addChild( 'RutEnvia', $settings['rut_emisor'] ?? '' );
            $caratula->addChild( 'PeriodoTributario', substr( $fecha_inicio, 0, 7 ) );
            $caratula->addChild( 'FchResol', $caf_info['FchResol'] ?? '' );
            $caratula->addChild( 'NroResol', $caf_info['NroResol'] ?? '' );
            $caratula->addChild( 'TipoLibro', 'ESPECIAL' );
            $caratula->addChild( 'TipoEnvio', 'TOTAL' );

            $upload_dir = wp_upload_dir();
            $base_dir   = trailingslashit( $upload_dir['basedir'] );
            $iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir ) );

            $resumenes = [];
            $detalles  = [];

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || ! preg_match( '/DTE_\d+_\d+_\d+\.xml$/', $file->getFilename() ) ) {
                    continue;
                }
                $content = file_get_contents( $file->getPathname() );
                if ( ! $content ) {
                    continue;
                }
                try {
                    $doc       = new SimpleXMLElement( $content );
                    $documento = $doc->Documento;
                    if ( ! $documento ) {
                        continue;
                    }
                    $id_doc = $documento->Encabezado->IdDoc;
                    $fecha  = (string) $id_doc->FchEmis;
                    if ( $fecha < $fecha_inicio || $fecha > $fecha_fin ) {
                        continue;
                    }
                    $tipo    = intval( $id_doc->TipoDTE );
                    $folio   = intval( $id_doc->Folio );
                    $totals  = $documento->Encabezado->Totales;
                    $mnt_exe = isset( $totals->MntExe ) ? intval( $totals->MntExe ) : 0;
                    $mnt_neto = isset( $totals->MntNeto ) ? intval( $totals->MntNeto ) : 0;
                    $tasa_iva = isset( $totals->TasaIVA ) ? floatval( $totals->TasaIVA ) : 0;
                    $mnt_iva  = isset( $totals->IVA ) ? intval( $totals->IVA ) : 0;
                    $mnt_total = isset( $totals->MntTotal ) ? intval( $totals->MntTotal ) : 0;

                    $anulado = false;
                    if ( isset( $id_doc->Anulado ) && '1' === (string) $id_doc->Anulado ) {
                        $anulado = true;
                    } elseif ( isset( $documento->Anulado ) && '1' === (string) $documento->Anulado ) {
                        $anulado = true;
                    }

                    $detalles[] = [
                        'TpoDoc'    => $tipo,
                        'FolioDoc'  => $folio,
                        'Anulado'   => $anulado,
                        'FchEmiDoc' => $fecha,
                        'MntExe'    => $mnt_exe,
                        'MntTotal'  => $mnt_total,
                    ];

                    if ( ! isset( $resumenes[ $tipo ] ) ) {
                        $resumenes[ $tipo ] = [
                            'TotDoc'       => 0,
                            'TotMntExe'    => 0,
                            'TotMntNeto'   => 0,
                            'TasaIVA'      => $tasa_iva,
                            'TotMntIVA'    => 0,
                            'TotMntTotal'  => 0,
                            'TotAnulado'   => 0,
                        ];
                    }

                    if ( $anulado ) {
                        $resumenes[ $tipo ]['TotAnulado']++;
                        continue;
                    }

                    $resumenes[ $tipo ]['TotDoc']++;
                    $resumenes[ $tipo ]['TotMntExe']   += $mnt_exe;
                    $resumenes[ $tipo ]['TotMntNeto']  += $mnt_neto;
                    $resumenes[ $tipo ]['TotMntIVA']   += $mnt_iva;
                    $resumenes[ $tipo ]['TotMntTotal'] += $mnt_total;
                } catch ( Exception $e ) {
                    continue;
                }
            }

            if ( ! empty( $resumenes ) ) {
                $resumen_seg = $envio->addChild( 'ResumenSegmento' );
                foreach ( $resumenes as $tipo => $data ) {
                    $totales_segmento = $resumen_seg->addChild( 'TotalesSegmento' );
                    $totales_segmento->addChild( 'TpoDoc', $tipo );
                    if ( $data['TotAnulado'] > 0 ) {
                        $totales_segmento->addChild( 'TotAnulado', $data['TotAnulado'] );
                    }
                    $tot_serv = $totales_segmento->addChild( 'TotalesServicio' );
                    $tot_serv->addChild( 'TpoServ', 3 );
                    $tot_serv->addChild( 'TotDoc', $data['TotDoc'] );
                    if ( $data['TotMntExe'] > 0 ) {
                        $tot_serv->addChild( 'TotMntExe', $data['TotMntExe'] );
                    }
                    $tot_serv->addChild( 'TotMntNeto', $data['TotMntNeto'] );
                    if ( $data['TasaIVA'] > 0 ) {
                        $tot_serv->addChild( 'TasaIVA', number_format( $data['TasaIVA'], 2, '.', '' ) );
                    }
                    $tot_serv->addChild( 'TotMntIVA', $data['TotMntIVA'] );
                    $tot_serv->addChild( 'TotMntTotal', $data['TotMntTotal'] );
                }
            }

            foreach ( $detalles as $det ) {
                $det_node = $envio->addChild( 'Detalle' );
                $det_node->addChild( 'TpoDoc', $det['TpoDoc'] );
                $det_node->addChild( 'FolioDoc', $det['FolioDoc'] );
                if ( $det['Anulado'] ) {
                    $det_node->addChild( 'Anulado', 'A' );
                }
                $det_node->addChild( 'FchEmiDoc', $det['FchEmiDoc'] );
                if ( $det['MntExe'] > 0 ) {
                    $det_node->addChild( 'MntExe', $det['MntExe'] );
                }
                $det_node->addChild( 'MntTotal', $det['MntTotal'] );
            }

            $envio->addChild( 'TmstFirma', date( 'c' ) );

            return $xml->asXML();
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Valida el XML del libro contra el XSD oficial.
     *
     * @param string $xml Contenido XML a validar.
     * @return bool True si es válido, false en caso contrario.
     */
    public function validate_libro_xml( $xml ) {
        $doc = new DOMDocument();
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        libxml_use_internal_errors( true );
        $xsd   = __DIR__ . '/xml/schemas/libro_boletas.xsd';
        $valid = $doc->schemaValidate( $xsd );
        libxml_clear_errors();
        return $valid;
    }

    /**
     * Firma y envía el Libro de Boletas al SII utilizando la API.
     *
     * @param string $xml         Contenido XML del libro.
     * @param string $environment Ambiente de destino ('test' o 'production').
     * @param string $token       Token de autenticación.
     * @param string $cert_path   Ruta al certificado para generar token si falta.
     * @param string $cert_pass   Contraseña del certificado.
     * @return bool               True en caso de éxito, false si falla.
     */
    public function send_libro_to_sii( $xml, $environment = 'test', $token = '', $cert_path = '', $cert_pass = '' ) {
        if ( empty( $xml ) ) {
            return false;
        }

        $signed_xml = $this->signer->sign_libro_xml( $xml, $cert_path, $cert_pass );
        if ( ! $signed_xml || ! $this->validate_libro_xml( $signed_xml ) ) {
            return false;
        }

        if ( empty( $token ) ) {
            $token = $this->api->generate_token( $environment, $cert_path, $cert_pass );
        }
        if ( empty( $token ) ) {
            return false;
        }

        $response = $this->api->send_libro_to_sii( $signed_xml, $environment, $token );
        return ! is_wp_error( $response );
    }
}
