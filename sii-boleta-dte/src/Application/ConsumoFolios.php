<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;

/**
 * Generates Consumo de Folios XML files.
 */
class ConsumoFolios {
    private Settings $settings;
    private FolioManager $folio_manager;
    private Api $api;

    public function __construct( Settings $settings, FolioManager $folio_manager, Api $api ) {
        $this->settings      = $settings;
        $this->folio_manager = $folio_manager;
        $this->api           = $api;
    }

    /**
     * Generates the CDF XML for a given date.
     *
     * @return string|false
     */
    public function generate_cdf_xml( string $fecha ) {
        $settings  = $this->settings->get_settings();
        $caf_paths = $settings['caf_path'] ?? [];
        $rut       = $settings['rut_emisor'] ?? '';
        if ( empty( $caf_paths ) || empty( $rut ) ) {
            return false;
        }
        $xml = new \SimpleXMLElement( '<ConsumoFolios xmlns="http://www.sii.cl/SiiDte"></ConsumoFolios>' );
        $car = $xml->addChild( 'Caratula' );
        $car->addAttribute( 'version', '1.0' );
        $car->addChild( 'RutEmisor', $rut );
        $car->addChild( 'RutEnvia', $rut );
        $car->addChild( 'FchInicio', $fecha );
        $car->addChild( 'FchFinal', $fecha );
        foreach ( $caf_paths as $tipo => $path ) {
            if ( ! file_exists( $path ) ) {
                continue;
            }
            $caf   = simplexml_load_file( $path );
            $range = [ 'D' => (int) $caf->DA->RNG->D, 'H' => (int) $caf->DA->RNG->H ];
            $option_key = 'sii_boleta_dte_last_folio_' . (int) $tipo;
            $last       = function_exists( 'get_option' ) ? (int) get_option( $option_key, $range['D'] - 1 ) : $range['D'] - 1;
            if ( $last < $range['D'] ) {
                continue;
            }
            $emitidos = $last - $range['D'] + 1;
            $res = $xml->addChild( 'Resumen' );
            $res->addAttribute( 'TipoDTE', (int) $tipo );
            $res->addChild( 'FoliosEmitidos', (string) $emitidos );
            $res->addChild( 'FoliosAnulados', '0' );
            $res->addChild( 'FoliosUtilizados', (string) $emitidos );
            $rango = $res->addChild( 'RangoUtilizados' );
            $rango->addChild( 'Inicial', (string) $range['D'] );
            $rango->addChild( 'Final', (string) $last );
        }
        return $xml->asXML();
    }
}

class_alias( ConsumoFolios::class, 'SII_Boleta_Consumo_Folios' );
