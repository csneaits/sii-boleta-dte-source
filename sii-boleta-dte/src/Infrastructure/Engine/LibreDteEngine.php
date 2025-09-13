<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Simplified DTE engine producing XML with basic totals.
 */
class LibreDteEngine implements DteEngine {
    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false ) {
        $tipo  = (int) $tipo_dte;
        $settings = $this->settings->get_settings();
        $caf_paths = $settings['caf_path'] ?? [];
        if ( isset( $caf_paths[ $tipo ] ) ) {
            $caf_file = $caf_paths[ $tipo ];
            if ( ! @simplexml_load_file( $caf_file ) ) {
                return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_invalid_caf', 'Invalid CAF' ) : false;
            }
        }
        $det   = $data['Detalles'] ?? [];
        $neto  = 0;
        $exento = 0;
        foreach ( $det as &$d ) {
            $qty  = (float) ( $d['QtyItem'] ?? 1 );
            $prc  = (int) round( $d['PrcItem'] ?? 0 );
            $d['MontoItem'] = (int) round( $qty * $prc );
            if ( ! empty( $d['IndExe'] ) || 41 === $tipo ) {
                $exento += $d['MontoItem'];
            } else {
                $neto += $d['MontoItem'];
            }
        }
        $mnt_neto = 0;
        $iva = 0;
        if ( $neto > 0 ) {
            $mnt_neto = (int) round( $neto / 1.19 );
            $iva      = $neto - $mnt_neto;
        }
        $mnt_total = $mnt_neto + $iva + $exento;

        $xml = new \SimpleXMLElement( '<EnvioDTE></EnvioDTE>' );
        $doc = $xml->addChild( 'Documento' );
        $enc = $doc->addChild( 'Encabezado' );
        $emi = $enc->addChild( 'Emisor' );
        $emi->addChild( 'RznSoc', $settings['razon_social'] ?? $data['RznSoc'] ?? '' );
        $emi->addChild( 'RUTEmisor', $settings['rut_emisor'] ?? $data['RutEmisor'] ?? '' );
        $rec = $enc->addChild( 'Receptor' );
        $r = $data['Receptor'] ?? [];
        $rec->addChild( 'RznSocRecep', $r['RznSocRecep'] ?? '' );
        $rec->addChild( 'RUTRecep', $r['RUTRecep'] ?? '' );
        $idd = $enc->addChild( 'IdDoc' );
        $idd->addChild( 'FchEmis', $data['FchEmis'] ?? '' );
        $tot = $enc->addChild( 'Totales' );
        if ( $mnt_neto > 0 ) {
            $tot->addChild( 'MntNeto', (string) $mnt_neto );
            $tot->addChild( 'IVA', (string) $iva );
        }
        if ( $exento > 0 ) {
            $tot->addChild( 'MntExe', (string) $exento );
        }
        $tot->addChild( 'MntTotal', (string) $mnt_total );
        foreach ( $det as $d ) {
            $line = $doc->addChild( 'Detalle' );
            $line->addChild( 'NmbItem', $d['NmbItem'] ?? '' );
            $line->addChild( 'QtyItem', (string) ( $d['QtyItem'] ?? 1 ) );
            $line->addChild( 'PrcItem', (string) ( $d['PrcItem'] ?? 0 ) );
            $line->addChild( 'MontoItem', (string) $d['MontoItem'] );
        }
        return $xml->asXML();
    }

    /**
     * Renders a minimal PDF file for tests.
     */
    public function render_pdf( string $xml, array $options = [] ): string {
        $file = tempnam( sys_get_temp_dir(), 'pdf' );
        $content = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>%%EOF";
        file_put_contents( $file, $content );
        return $file;
    }
}

class_alias( LibreDteEngine::class, 'SII_LibreDTE_Engine' );
