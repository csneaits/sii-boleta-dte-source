<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gestiona el Resumen de Ventas Diarias (RVD) requerido por la API de
 * boletas del SII. El RVD debe enviarse diariamente con el total de
 * boletas emitidas. Esta clase ofrece un método para generar el archivo
 * correspondiente y otro para enviarlo. La implementación es un boceto
 * pensado para ser ampliado según las necesidades de cada comercio.
 */
class SII_Boleta_RVD_Manager {

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
     * Genera el XML del Resumen de Ventas Diarias para un rango de fechas.
     *
     * @param \DateTimeInterface $from Inicio del rango.
     * @param \DateTimeInterface $to   Fin del rango.
     * @return string|\WP_Error        XML generado o error en caso de fallo.
     */
    public function build_rvd_xml( \DateTimeInterface $from, \DateTimeInterface $to ) {
        $settings      = $this->settings->get_settings();
        $folio_manager = new SII_Boleta_Folio_Manager( $this->settings );
        $folios_tipo   = $this->collect_folios( $from, $to );

        // Obtener datos de resolución desde el CAF del tipo 39 por defecto.
        $caf_info = $folio_manager->get_caf_info( 39 );

        try {
            $tz  = new \DateTimeZone( 'America/Santiago' );
            $now = new \DateTime( 'now', $tz );

            $from_cl = ( clone $from )->setTimezone( $tz );
            $to_cl   = ( clone $to )->setTimezone( $tz );

            $doc               = new DOMDocument( '1.0', 'ISO-8859-1' );
            $doc->formatOutput = false;

            $root = $doc->createElement( 'ConsumoFolios' );
            $root->setAttribute( 'version', '1.0' );
            $root->setAttribute( 'xmlns', 'http://www.sii.cl/SiiDte' );
            $doc->appendChild( $root );

            $documento = $doc->createElement( 'DocumentoConsumoFolios' );
            $documento->setAttribute( 'ID', 'RVD' );
            $root->appendChild( $documento );

            $caratula = $doc->createElement( 'Caratula' );
            $caratula->setAttribute( 'version', '1.0' );
            $caratula->appendChild( $doc->createElement( 'RutEmisor', $settings['rut_emisor'] ) );
            $caratula->appendChild( $doc->createElement( 'RutEnvia', $settings['rut_emisor'] ) );
            $caratula->appendChild( $doc->createElement( 'FchResol', $caf_info['FchResol'] ?? $from_cl->format( 'Y-m-d' ) ) );
            $caratula->appendChild( $doc->createElement( 'NroResol', $caf_info['NroResol'] ?? '0' ) );
            $caratula->appendChild( $doc->createElement( 'FchInicio', $from_cl->format( 'Y-m-d' ) ) );
            $caratula->appendChild( $doc->createElement( 'FchFinal', $to_cl->format( 'Y-m-d' ) ) );
            $caratula->appendChild( $doc->createElement( 'Correlativo', '1' ) );
            $caratula->appendChild( $doc->createElement( 'SecEnvio', '1' ) );
            $caratula->appendChild( $doc->createElement( 'TmstFirmaEnv', $now->format( 'Y-m-d\\TH:i:s' ) ) );
            $documento->appendChild( $caratula );

            if ( empty( $folios_tipo ) ) {
                // RVD sin movimiento.
                $resumen = $doc->createElement( 'Resumen' );
                $resumen->appendChild( $doc->createElement( 'TipoDocumento', 39 ) );
                $resumen->appendChild( $doc->createElement( 'MntNeto', 0 ) );
                $resumen->appendChild( $doc->createElement( 'MntExento', 0 ) );
                $resumen->appendChild( $doc->createElement( 'MntIVA', 0 ) );
                $resumen->appendChild( $doc->createElement( 'MntTotal', 0 ) );
                $resumen->appendChild( $doc->createElement( 'FoliosEmitidos', 0 ) );
                $resumen->appendChild( $doc->createElement( 'FoliosAnulados', 0 ) );
                $resumen->appendChild( $doc->createElement( 'FoliosUtilizados', 0 ) );
                $resumen->appendChild( $doc->createElement( 'FoliosNoUtilizados', 0 ) );
                $documento->appendChild( $resumen );
            } else {
                foreach ( $folios_tipo as $tipo => $data ) {
                    $emitidos = $data['emitidos'];
                    $anulados = $data['anulados'];
                    sort( $emitidos );
                    sort( $anulados );
                    $utilizados = array_merge( $emitidos, $anulados );
                    sort( $utilizados );

                    $resumen = $doc->createElement( 'Resumen' );
                    $resumen->appendChild( $doc->createElement( 'TipoDocumento', $tipo ) );
                    $resumen->appendChild( $doc->createElement( 'MntNeto', $data['monto_neto'] ) );
                    $resumen->appendChild( $doc->createElement( 'MntExento', $data['monto_exento'] ) );
                    $resumen->appendChild( $doc->createElement( 'MntIVA', $data['monto_iva'] ) );
                    $resumen->appendChild( $doc->createElement( 'MntTotal', $data['monto_total'] ) );
                    $resumen->appendChild( $doc->createElement( 'FoliosEmitidos', count( $emitidos ) ) );
                    $resumen->appendChild( $doc->createElement( 'FoliosAnulados', count( $anulados ) ) );
                    $resumen->appendChild( $doc->createElement( 'FoliosUtilizados', count( $utilizados ) ) );

                    // Rangos de folios utilizados (emitidos + anulados).
                    $used_ranges = $this->calculate_ranges( $utilizados );
                    foreach ( $used_ranges as $r ) {
                        $rango = $doc->createElement( 'RangoUtilizados' );
                        $rango->appendChild( $doc->createElement( 'Inicial', $r[0] ) );
                        $rango->appendChild( $doc->createElement( 'Final', $r[1] ) );
                        $resumen->appendChild( $rango );
                    }

                    // Rangos de folios anulados.
                    $anulados_ranges = $this->calculate_ranges( $anulados );
                    foreach ( $anulados_ranges as $r ) {
                        $rango = $doc->createElement( 'RangoAnulados' );
                        $rango->appendChild( $doc->createElement( 'Inicial', $r[0] ) );
                        $rango->appendChild( $doc->createElement( 'Final', $r[1] ) );
                        $resumen->appendChild( $rango );
                    }

                    // Calcular folios no utilizados dentro del rango min/max de los utilizados.
                    $no_utilizados = $this->calculate_missing_numbers( $utilizados );
                    $resumen->appendChild( $doc->createElement( 'FoliosNoUtilizados', count( $no_utilizados ) ) );
                    $unused_ranges = $this->calculate_ranges( $no_utilizados );
                    foreach ( $unused_ranges as $r ) {
                        $rango = $doc->createElement( 'RangoNoUtilizados' );
                        $rango->appendChild( $doc->createElement( 'Inicial', $r[0] ) );
                        $rango->appendChild( $doc->createElement( 'Final', $r[1] ) );
                        $resumen->appendChild( $rango );
                    }

                    $documento->appendChild( $resumen );
                }
            }

            return $doc->saveXML();
        } catch ( Exception $e ) {
            return new \WP_Error( 'sii_boleta_rvd_build_error', $e->getMessage() );
        }
    }

    /**
     * Método de compatibilidad. Genera el RVD para un día específico.
     *
     * @param string $date Fecha en formato Y-m-d. Por defecto, el día actual.
     * @return string|false
     */
    public function generate_rvd_xml( $date = null ) {
        if ( ! $date ) {
            $date = date( 'Y-m-d' );
        }
        $tz   = new \DateTimeZone( 'America/Santiago' );
        $from = new \DateTimeImmutable( $date . ' 00:00:00', $tz );
        $to   = new \DateTimeImmutable( $date . ' 23:59:59', $tz );
        $xml  = $this->build_rvd_xml( $from, $to );
        if ( is_wp_error( $xml ) ) {
            return false;
        }
        return $xml;
    }

    /**
     * Recolecta folios y montos de los DTE en un rango de fechas.
     *
     * @param \DateTimeInterface $from Fecha inicial.
     * @param \DateTimeInterface $to   Fecha final.
     * @return array
     */
    private function collect_folios( \DateTimeInterface $from, \DateTimeInterface $to ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir ) );

        $from_date = $from->format( 'Y-m-d' );
        $to_date   = $to->format( 'Y-m-d' );

        $totales = [];

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && preg_match( '/DTE_\d+_\d+_\d+\.xml$/', $file->getFilename() ) ) {
                $content = file_get_contents( $file->getPathname() );
                if ( ! $content ) {
                    continue;
                }
                try {
                    $doc      = new SimpleXMLElement( $content );
                    $doc_node = $doc->Documento;
                    if ( ! $doc_node ) {
                        continue;
                    }
                    $idDoc = $doc_node->Encabezado->IdDoc;
                    $fecha = (string) $idDoc->FchEmis;
                    if ( $fecha < $from_date || $fecha > $to_date ) {
                        continue;
                    }
                    $tipo       = intval( $idDoc->TipoDTE );
                    $folio      = intval( $idDoc->Folio );
                    $totales_xml = $doc_node->Encabezado->Totales;
                    $mnt_neto    = isset( $totales_xml->MntNeto ) ? intval( $totales_xml->MntNeto ) : 0;
                    $mnt_exe     = isset( $totales_xml->MntExe ) ? intval( $totales_xml->MntExe ) : 0;
                    $mnt_iva     = isset( $totales_xml->IVA ) ? intval( $totales_xml->IVA ) : ( isset( $totales_xml->MntIVA ) ? intval( $totales_xml->MntIVA ) : 0 );
                    $mnt_total   = isset( $totales_xml->MntTotal ) ? intval( $totales_xml->MntTotal ) : 0;

                    if ( ! isset( $totales[ $tipo ] ) ) {
                        $totales[ $tipo ] = [
                            'emitidos'     => [],
                            'anulados'     => [],
                            'monto_neto'   => 0,
                            'monto_exento' => 0,
                            'monto_iva'    => 0,
                            'monto_total'  => 0,
                        ];
                    }

                    $anulado = false;
                    if ( ( isset( $idDoc->Anulado ) && '1' === (string) $idDoc->Anulado ) || ( isset( $doc_node->Anulado ) && '1' === (string) $doc_node->Anulado ) ) {
                        $anulado = true;
                    }

                    if ( $anulado ) {
                        $totales[ $tipo ]['anulados'][] = $folio;
                    } else {
                        $sign = ( 61 === $tipo ) ? -1 : 1;
                        $totales[ $tipo ]['emitidos'][]   = $folio;
                        $totales[ $tipo ]['monto_neto']   += $sign * $mnt_neto;
                        $totales[ $tipo ]['monto_exento'] += $sign * $mnt_exe;
                        $totales[ $tipo ]['monto_iva']    += $sign * $mnt_iva;
                        $totales[ $tipo ]['monto_total']  += $sign * $mnt_total;
                    }
                } catch ( Exception $e ) {
                    continue;
                }
            }
        }

        return $totales;
    }

    /**
     * Genera rangos consecutivos a partir de una lista de folios.
     *
     * @param array $folios Lista de folios utilizados.
     * @return array Arreglo de rangos [[inicio, fin], ...].
     */
    private function calculate_ranges( array $folios ) {
        $ranges = [];
        $start  = null;
        $end    = null;
        foreach ( $folios as $folio ) {
            if ( null === $start ) {
                $start = $end = $folio;
                continue;
            }
            if ( $folio === $end + 1 ) {
                $end = $folio;
                continue;
            }
            $ranges[] = [ $start, $end ];
            $start    = $end = $folio;
        }
        if ( null !== $start ) {
            $ranges[] = [ $start, $end ];
        }
        return $ranges;
    }

    /**
     * Calcula los folios faltantes dentro del rango de folios utilizados.
     *
     * @param array $used Lista de folios utilizados (incluye emitidos y anulados).
     * @return array Lista de folios no utilizados.
     */
    private function calculate_missing_numbers( array $used ) {
        $missing = [];
        $count   = count( $used );
        if ( 0 === $count ) {
            return $missing;
        }
        $start    = $used[0];
        $end      = $used[ $count - 1 ];
        $used_set = array_flip( $used );
        for ( $i = $start; $i <= $end; $i++ ) {
            if ( ! isset( $used_set[ $i ] ) ) {
                $missing[] = $i;
            }
        }
        return $missing;
    }

    /**
     * Valida el XML del RVD contra el XSD oficial.
     *
     * @param string $xml Contenido XML a validar.
     * @return bool True si es válido, false en caso contrario.
     */
    public function validate_rvd_xml( $xml ) {
        $doc = new DOMDocument();
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        libxml_use_internal_errors( true );
        $xsd   = __DIR__ . '/xml/schemas/consumo_folios.xsd';
        $valid = $doc->schemaValidate( $xsd );
        libxml_clear_errors();
        return $valid;
    }

    /**
     * Envía el resumen de ventas al SII utilizando la API. Este método es
     * un ejemplo y no contempla el protocolo de envío real, que puede
     * requerir tokens o endpoints distintos.
     *
     * @param string $rvd_xml    Contenido XML del resumen.
     * @param string $environment 'test' o 'production'.
     * @param string $token       Token de autenticación.
     * @param string $cert_path   Ruta al certificado PFX para generar el token si falta.
     * @param string $cert_pass   Contraseña del certificado.
     * @return bool True si se envía con éxito, false en caso de error.
     */
    public function send_rvd_to_sii( $rvd_xml, $environment = 'test', $token = '', $cert_path = '', $cert_pass = '' ) {
        $signer = new SII_Boleta_Signer();
        if ( $cert_path && $cert_pass ) {
            $rvd_xml = $signer->sign_rvd_xml( $rvd_xml, $cert_path, $cert_pass );
        }

        if ( ! $rvd_xml || ! $this->validate_rvd_xml( $rvd_xml ) ) {
            return false;
        }

        $api = new SII_Boleta_API();
        if ( empty( $token ) ) {
            $token = $api->generate_token( $environment, $cert_path, $cert_pass );
        }
        if ( empty( $token ) ) {
            return false;
        }

        $result = $api->send_rvd_to_sii( $rvd_xml, $environment, $token );
        if ( is_wp_error( $result ) ) {
            return false;
        }

        return true;
    }
}