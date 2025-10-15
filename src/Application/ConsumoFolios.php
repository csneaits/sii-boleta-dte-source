<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

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
                $settings = $this->settings->get_settings();
                $rut      = $settings['rut_emisor'] ?? '';
                if ( empty( $rut ) ) {
                        return false;
                }

                $environment = $this->settings->get_environment();
                $ranges      = FoliosDb::all( $environment );
                if ( empty( $ranges ) ) {
                        return false;
                }

                $grouped = array();
                foreach ( $ranges as $range ) {
                        $tipo = (int) $range['tipo'];
                        if ( ! isset( $grouped[ $tipo ] ) ) {
                                $grouped[ $tipo ] = array();
                        }
                        $grouped[ $tipo ][] = $range;
                }

                $xml = new \SimpleXMLElement( '<ConsumoFolios xmlns="http://www.sii.cl/SiiDte"></ConsumoFolios>' );
                $car = $xml->addChild( 'Caratula' );
                $car->addAttribute( 'version', '1.0' );
                $car->addChild( 'RutEmisor', $rut );
                $car->addChild( 'RutEnvia', $rut );
                $car->addChild( 'FchInicio', $fecha );
                $car->addChild( 'FchFinal', $fecha );

                foreach ( $grouped as $tipo => $tipo_ranges ) {
                        $last = Settings::get_last_folio_value( (int) $tipo, $environment );
                        foreach ( $tipo_ranges as $range ) {
                                $desde = (int) $range['desde'];
                                $hasta = (int) $range['hasta'] - 1;
                                if ( $last < $desde ) {
                                        continue;
                                }
                                $utilizado_hasta = min( $last, $hasta );
                                if ( $utilizado_hasta < $desde ) {
                                        continue;
                                }
                                $emitidos = $utilizado_hasta - $desde + 1;
                                $res      = $xml->addChild( 'Resumen' );
                                $res->addAttribute( 'TipoDTE', (string) (int) $tipo );
                                $res->addChild( 'FoliosEmitidos', (string) $emitidos );
                                $res->addChild( 'FoliosAnulados', '0' );
                                $res->addChild( 'FoliosUtilizados', (string) $emitidos );
                                $rango = $res->addChild( 'RangoUtilizados' );
                                $rango->addChild( 'Inicial', (string) $desde );
                                $rango->addChild( 'Final', (string) $utilizado_hasta );
                        }
                }

                return $xml->asXML();
        }
}

class_alias( ConsumoFolios::class, 'SII_Boleta_Consumo_Folios' );
