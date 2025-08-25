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
     * Genera el XML del Resumen de Ventas Diarias (Consumo de Folios) para una
     * fecha dada siguiendo el esquema oficial del SII.
     *
     * @param string $date Fecha en formato Y-m-d. Por defecto, el día actual.
     * @return string|false XML generado o false si falla.
     */
    public function generate_rvd_xml( $date = null ) {
        if ( ! $date ) {
            $date = date( 'Y-m-d' );
        }
        $settings      = $this->settings->get_settings();
        $folio_manager = new SII_Boleta_Folio_Manager( $this->settings );
        $totales_tipo  = $folio_manager->get_folios_by_date( $date );
        $caf_info      = $folio_manager->get_caf_info();

        try {
            $doc = new DOMDocument( '1.0', 'ISO-8859-1' );
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
            $caratula->appendChild( $doc->createElement( 'FchResol', $caf_info['FchResol'] ?? date( 'Y-m-d' ) ) );
            $caratula->appendChild( $doc->createElement( 'NroResol', $caf_info['NroResol'] ?? '0' ) );
            $caratula->appendChild( $doc->createElement( 'FchInicio', $date ) );
            $caratula->appendChild( $doc->createElement( 'FchFinal', $date ) );
            $caratula->appendChild( $doc->createElement( 'Correlativo', '1' ) );
            $caratula->appendChild( $doc->createElement( 'SecEnvio', '1' ) );
            $caratula->appendChild( $doc->createElement( 'TmstFirmaEnv', date( 'Y-m-d\TH:i:s' ) ) );
            $documento->appendChild( $caratula );

            foreach ( $totales_tipo as $tipo => $data ) {
                $folios  = $data['folios'];
                sort( $folios );
                $resumen = $doc->createElement( 'Resumen' );
                $resumen->appendChild( $doc->createElement( 'TipoDocumento', $tipo ) );
                $resumen->appendChild( $doc->createElement( 'MntTotal', $data['monto'] ) );
                $emitidos = count( $folios );
                $resumen->appendChild( $doc->createElement( 'FoliosEmitidos', $emitidos ) );
                $resumen->appendChild( $doc->createElement( 'FoliosAnulados', 0 ) );
                $resumen->appendChild( $doc->createElement( 'FoliosUtilizados', $emitidos ) );
                $ranges = $this->calculate_ranges( $folios );
                foreach ( $ranges as $r ) {
                    $rango = $doc->createElement( 'RangoUtilizados' );
                    $rango->appendChild( $doc->createElement( 'Inicial', $r[0] ) );
                    $rango->appendChild( $doc->createElement( 'Final', $r[1] ) );
                    $resumen->appendChild( $rango );
                }
                $documento->appendChild( $resumen );
            }

            return $doc->saveXML();
        } catch ( Exception $e ) {
            return false;
        }
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
        $xsd   = __DIR__ . '/schemas/ConsumoFolio_v10.xsd';
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