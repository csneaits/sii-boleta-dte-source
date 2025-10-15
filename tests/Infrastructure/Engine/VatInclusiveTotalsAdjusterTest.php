<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Sii\BoletaDte\Infrastructure\Engine\Factory\FacturaDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\Factory\VatInclusiveDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Engine\Xml\TotalsAdjusterInterface;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

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
     * @return array<string,array{int,array<string,int|string>}>
     */
    public static function pdfExampleTotalsProvider(): array {
        $expected = array(
            'MntNeto'  => 1000,
            'IVA'      => 190,
            'MntExe'   => 500,
            'MntTotal' => 1690,
            'TasaIVA'  => '19',
        );

        return array(
            'factura_afecta' => array( 33, $expected ),
            'factura_compra' => array( 46, $expected ),
            'guia_despacho'  => array( 52, $expected ),
            'nota_debito'    => array( 56, $expected ),
            'nota_credito'   => array( 61, $expected ),
        );
    }

    #[DataProvider('vatInclusiveTypesProvider')]
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
                    'PrcItem'   => 1000,
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

        $this->assertSame( 1190, (int) $totals->MntTotal );
        $this->assertSame( 1000, (int) $totals->MntNeto );
        $this->assertSame( 190, (int) $totals->IVA );
    }

    public function test_adjuster_converts_gross_line_amounts_to_net_totals(): void {
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
                    'MntBruto'  => 1,
                ),
            ),
        );

        $xml = $engine->generate_dte_xml( $data, 33, true );
        $this->assertIsString( $xml );

        $document = simplexml_load_string( $xml );
        $this->assertNotFalse( $document );

        $totals = $document->Documento->Encabezado->Totales ?? null;
        $this->assertNotNull( $totals );

        $this->assertSame( 1200, (int) $totals->MntTotal );
        $this->assertSame( 1008, (int) $totals->MntNeto );
        $this->assertSame( 192, (int) $totals->IVA );
    }

    public function test_engine_skips_adjustment_when_not_supported(): void {
        $spyAdjuster = new class() implements TotalsAdjusterInterface {
            public bool $called = false;

            public function adjust( string $xml, array $detalle, int $tipo, ?float $tasaIva, array $globalDiscounts ): string {
                $this->called = true;

                return 'adjusted';
            }

            public function supports( int $tipo ): bool {
                return false;
            }
        };

        $root = __DIR__ . '/../../../resources/yaml/';
        $engine = new LibreDteEngine( new Dummy_Vat_Settings() );

        $factory = new class( $root, $spyAdjuster ) extends FacturaDteDocumentFactory {
            public function __construct( string $templateRoot, private TotalsAdjusterInterface $spy ) {
                parent::__construct( $templateRoot );
            }

            public function createTotalsAdjuster(): TotalsAdjusterInterface {
                return $this->spy;
            }
        };

        $engine->register_document_factory( 33, $factory );

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

        $xml = $engine->generate_dte_xml( $data, 33, true );

        $this->assertIsString( $xml );
        $this->assertFalse( $spyAdjuster->called );
        $this->assertNotSame( 'adjusted', $xml );
    }

    #[DataProvider('pdfExampleTotalsProvider')]
    /**
     * @param array<string,int|string> $expectedTotals
     */
    public function test_pdf_example_totals_match_expected_values( int $tipo, array $expectedTotals ): void {
        $engine = $this->createEngineWithFactories();

        $data = $this->createPdfExampleData();

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

        foreach ( $expectedTotals as $field => $value ) {
            $actual = $totals->{$field} ?? null;
            $this->assertNotNull( $actual, 'Missing field ' . $field . ' for tipo ' . $tipo );

            if ( is_numeric( $value ) ) {
                $this->assertSame( (int) $value, (int) $actual, 'Failed asserting totals for field ' . $field . ' on tipo ' . $tipo );
                continue;
            }

            $this->assertSame( (string) $value, (string) $actual, 'Failed asserting totals for field ' . $field . ' on tipo ' . $tipo );
        }
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

    /**
     * @return array<string,mixed>
     */
    private function createPdfExampleData(): array {
        return array(
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
                    'NmbItem'   => 'Servicio afecto',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1190,
                    'MntBruto'  => 1,
                ),
                array(
                    'NroLinDet' => 2,
                    'NmbItem'   => 'Servicio exento',
                    'QtyItem'   => 1,
                    'PrcItem'   => 500,
                    'IndExe'    => 1,
                ),
            ),
        );
    }
}
