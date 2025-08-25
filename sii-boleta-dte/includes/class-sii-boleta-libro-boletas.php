<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase encargada de generar y enviar el Libro de Boletas al SII.
 *
 * Recorre los archivos DTE emitidos en un rango de fechas para construir
 * el XML conforme al esquema de "LibroBoletas". También permite enviar
 * dicho XML utilizando el token de la API del SII.
 */
class SII_Boleta_Libro_Boletas {

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
        $settings = $this->settings->get_settings();

        try {
            $xml = new SimpleXMLElement( '<LibroBoletas version="1.0" xmlns="http://www.sii.cl/SiiDte"></LibroBoletas>' );
            $xml->addChild( 'FchInicio', $fecha_inicio );
            $xml->addChild( 'FchFin', $fecha_fin );

            $emisor = $xml->addChild( 'Emisor' );
            $emisor->addChild( 'RUTEmisor', $settings['rut_emisor'] ?? '' );
            $emisor->addChild( 'RznSoc', $settings['razon_social'] ?? '' );

            $upload_dir = wp_upload_dir();
            $base_dir   = trailingslashit( $upload_dir['basedir'] );
            $iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir ) );

            $resumenes = [];
            $anulados  = [];

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || ! preg_match( '/DTE_\d+_\d+_\d+\.xml$/', $file->getFilename() ) ) {
                    continue;
                }
                $content = file_get_contents( $file->getPathname() );
                if ( ! $content ) {
                    continue;
                }
                try {
                    $doc      = new SimpleXMLElement( $content );
                    $documento = $doc->Documento;
                    if ( ! $documento ) {
                        continue;
                    }
                    $id_doc = $documento->Encabezado->IdDoc;
                    $fecha  = (string) $id_doc->FchEmis;
                    if ( $fecha < $fecha_inicio || $fecha > $fecha_fin ) {
                        continue;
                    }
                    $tipo   = intval( $id_doc->TipoDTE );
                    $folio  = intval( $id_doc->Folio );
                    $totals = $documento->Encabezado->Totales;
                    $monto  = isset( $totals->MntTotal ) ? intval( $totals->MntTotal ) : 0;

                    $anulado = false;
                    if ( isset( $id_doc->Anulado ) && '1' === (string) $id_doc->Anulado ) {
                        $anulado = true;
                    } elseif ( isset( $documento->Anulado ) && '1' === (string) $documento->Anulado ) {
                        $anulado = true;
                    }

                    if ( $anulado ) {
                        $anulados[] = $folio;
                        continue;
                    }

                    if ( ! isset( $resumenes[ $tipo ] ) ) {
                        $resumenes[ $tipo ] = [ 'monto' => 0, 'folios' => [] ];
                    }
                    $resumenes[ $tipo ]['monto']  += $monto;
                    $resumenes[ $tipo ]['folios'][] = $folio;
                } catch ( Exception $e ) {
                    continue;
                }
            }

            $resumen_periodo = $xml->addChild( 'ResumenPeriodo' );
            foreach ( $resumenes as $tipo => $data ) {
                sort( $data['folios'] );
                $resumen = $resumen_periodo->addChild( 'TotalesTipo' );
                $resumen->addChild( 'TipoDTE', $tipo );
                $resumen->addChild( 'FolioInicial', $data['folios'][0] );
                $resumen->addChild( 'FolioFinal', end( $data['folios'] ) );
                $resumen->addChild( 'FoliosEmitidos', count( $data['folios'] ) );
                $resumen->addChild( 'MntTotal', $data['monto'] );
            }

            if ( ! empty( $anulados ) ) {
                sort( $anulados );
                $anulados_node = $xml->addChild( 'FoliosAnulados' );
                foreach ( $anulados as $folio ) {
                    $anulados_node->addChild( 'Folio', $folio );
                }
            }

            return $xml->asXML();
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Envía el Libro de Boletas al SII utilizando la API.
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
        if ( ! $signed_xml ) {
            return false;
        }

        if ( empty( $token ) ) {
            $token = $this->api->generate_token( $environment, $cert_path, $cert_pass );
        }
        if ( empty( $token ) ) {
            return false;
        }

        $base_url = ( 'production' === $environment )
            ? 'https://api.sii.cl/bolcoreinternetui/api'
            : 'https://maullin.sii.cl/bolcoreinternetui/api';
        $endpoint = $base_url . '/envioLibroBoletas';

        $response = wp_remote_post( $endpoint, [
            'body'    => $signed_xml,
            'headers' => [
                'Content-Type'  => 'application/xml',
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => 60,
        ] );

        return ! is_wp_error( $response );
    }
}

