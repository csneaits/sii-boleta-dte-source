<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\Factory\FacturaDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\Factory\VatInclusiveDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! class_exists( 'Dummy_Vat_Settings' ) ) {
    class Dummy_Vat_Settings extends Settings {
        public function get_settings(): array {
            return array(
                'rut_emisor'   => '11111111-1',
                'razon_social' => 'Test',
                'giro'         => 'Giro',
                'direccion'    => 'Calle 1',
                'comuna'       => 'Santiago',
            );
        }
    }
}

class VatInclusiveTotalsAdjusterTest extends TestCase {
    /**
     * @return array<string,array{int}>
     */
    public static function vatInclusiveTypesProvider(): array {
        return array(
            'factura_compra' => array( 46 ),
            'guia_despacho'  => array( 52 ),
            'nota_debito'    => array( 56 ),
            'nota_credito'   => array( 61 ),
        );
    }

    /**
     * @dataProvider vatInclusiveTypesProvider
     */
    public function test_adjuster_preserves_gross_totals_for_vat_inclusive_documents( int $tipo ): void {
        $engine = $this->createEngineWithFactories();

        $data = array(
            'Folio'      => 1,
            'FchEmis'    => '2024-05-01',
            'Encabezado' => array(
                'Totales' => array(
                    'TasaIVA' => 19,
                ),
            ),
            'Receptor'   => array(
                'RUTRecep'    => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep'    => 'Dir',
                'CmnaRecep'   => 'Comuna',
            ),
            'Detalles'   => array(
                array(
                    'NroLinDet' => 1,
                    'NmbItem'   => 'TEST',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1200,
                ),
            ),
        );

        if ( 61 === $tipo ) {
            $data['Referencia'] = array(
                array(
                    'NroLinRef' => 1,
                    'TpoDocRef' => 33,
                    'FolioRef'  => 123,
                    'CodRef'    => 1,
                    'RazonRef'  => 'Ajuste de prueba',
                ),
            );
        }

        $xml = $engine->generate_dte_xml( $data, $tipo, true );
        $this->assertIsString( $xml );

        $document = simplexml_load_string( $xml );
        $this->assertNotFalse( $document );

        $totals = $document->Documento->Encabezado->Totales ?? null;
        $this->assertNotNull( $totals );

        $this->assertSame( 1200, (int) $totals->MntTotal );
        $this->assertSame( 1008, (int) $totals->MntNeto );
        $this->assertSame( 192, (int) $totals->IVA );
    }

    private function createEngineWithFactories(): LibreDteEngine {
        $engine = new LibreDteEngine( new Dummy_Vat_Settings() );
        $root   = __DIR__ . '/../../../resources/yaml/';

        $factura_factory = new FacturaDteDocumentFactory( $root );
        foreach ( array( 33, 34, 46 ) as $tipo_factura ) {
            $engine->register_document_factory( $tipo_factura, $factura_factory );
        }

        $vat_factory = new VatInclusiveDteDocumentFactory(
            $root,
            array(
                52 => 'documentos_ok/052_guia_despacho',
                56 => 'documentos_ok/056_nota_debito',
                61 => 'documentos_ok/061_nota_credito',
            )
        );
        foreach ( array( 52, 56, 61 ) as $tipo_vat ) {
            $engine->register_document_factory( $tipo_vat, $vat_factory );
        }

        return $engine;
    }
}
