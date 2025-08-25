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
     * Genera el XML del Resumen de Ventas Diarias para una fecha dada. Este
     * método recopila las boletas emitidas en la fecha indicada y crea un
     * documento con la estructura exigida por el SII. Por simplicidad,
     * actualmente el método genera un XML vacío con la fecha y emisor.
     *
     * @param string $date Fecha en formato Y-m-d. Por defecto, el día actual.
     * @return string|false XML del resumen o false si falla.
     */
    public function generate_rvd_xml( $date = null ) {
        if ( ! $date ) {
            $date = date( 'Y-m-d' );
        }
        $settings = $this->settings->get_settings();
        try {
            $xml = new SimpleXMLElement( '<RVD version="1.0" xmlns="http://www.sii.cl/SiiDte"></RVD>' );
            $xml->addChild( 'FchRVD', $date );
            $emisor = $xml->addChild( 'Emisor' );
            $emisor->addChild( 'RUTEmisor', $settings['rut_emisor'] );
            $emisor->addChild( 'RznSoc', $settings['razon_social'] );
            $emisor->addChild( 'GiroEmisor', $settings['giro'] );

            // Recorrer los archivos DTE generados en la carpeta de uploads y sumar montos del día
            $upload_dir   = wp_upload_dir();
            $base_dir     = trailingslashit( $upload_dir['basedir'] );
            $totales_tipo = [];
            $iterator     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir ) );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() && preg_match( '/DTE_\d+_\d+_\d+\.xml$/', $file->getFilename() ) ) {
                    $content = file_get_contents( $file->getPathname() );
                    if ( ! $content ) {
                        continue;
                    }
                    try {
                        $doc = new SimpleXMLElement( $content );
                        $doc_node = $doc->Documento;
                        if ( ! $doc_node ) {
                            continue;
                        }
                        $idDoc = $doc_node->Encabezado->IdDoc;
                        $fecha = (string) $idDoc->FchEmis;
                        $tipo  = intval( $idDoc->TipoDTE );
                        if ( $fecha !== $date ) {
                            continue;
                        }
                        $folio  = intval( $idDoc->Folio );
                        $totals = $doc_node->Encabezado->Totales;
                        $monto_total = 0;
                        if ( isset( $totals->MntTotal ) ) {
                            $monto_total = intval( $totals->MntTotal );
                        }
                        if ( ! isset( $totales_tipo[ $tipo ] ) ) {
                            $totales_tipo[ $tipo ] = [ 'monto' => 0, 'folios' => [] ];
                        }
                        $totales_tipo[ $tipo ]['monto']  += $monto_total;
                        $totales_tipo[ $tipo ]['folios'][] = $folio;
                    } catch ( Exception $e ) {
                        continue;
                    }
                }
            }
            // Crear nodos para cada tipo de documento
            foreach ( $totales_tipo as $tipo => $data ) {
                sort( $data['folios'] );
                $resumen = $xml->addChild( 'Resumen' );
                $resumen->addChild( 'TipoDTE', $tipo );
                $resumen->addChild( 'FolioInicial', $data['folios'][0] );
                $resumen->addChild( 'FolioFinal', end( $data['folios'] ) );
                $resumen->addChild( 'FoliosEmitidos', count( $data['folios'] ) );
                $resumen->addChild( 'Totales', $data['monto'] );
            }
            return $xml->asXML();
        } catch ( Exception $e ) {
            return false;
        }
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
        $api = new SII_Boleta_API();
        if ( empty( $token ) ) {
            $token = $api->generate_token( $environment, $cert_path, $cert_pass );
        }
        if ( empty( $token ) ) {
            return false;
        }
        $base_url = ( 'production' === $environment )
            ? 'https://api.sii.cl/bolcoreinternetui/api'
            : 'https://maullin.sii.cl/bolcoreinternetui/api';
        $endpoint = $base_url . '/envioRVD';
        $response = wp_remote_post( $endpoint, [
            'body'    => $rvd_xml,
            'headers' => [
                'Content-Type'  => 'application/xml',
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => 60,
        ] );
        return ! is_wp_error( $response );
    }
}