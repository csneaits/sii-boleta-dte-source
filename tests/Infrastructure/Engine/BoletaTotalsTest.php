<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends \Sii\BoletaDte\Infrastructure\WordPress\Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings(): array { return $this->data; }
    }
}

class BoletaTotalsTest extends TestCase {

    protected function setUp(): void {
        FoliosDb::purge();
        FoliosDb::insert( 39, 1, 1000 );
        FoliosDb::insert( 41, 1, 1000 );
    }

    private function get_settings() {
        return new Dummy_Settings([
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Test',
            'giro' => 'Giro',
            'direccion' => 'Calle 1',
            'comuna' => 'Santiago',
        ]);
    }

    public function test_boleta_afecta_totals() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data   = $this->createPdfExampleDocumentData();
        $xml = $engine->generate_dte_xml( $data, 39, true );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 1000, intval( $tot->MntNeto ) );
        $this->assertEquals( 190, intval( $tot->IVA ) );
        $this->assertEquals( 500, intval( $tot->MntExe ) );
        $this->assertEquals( 1690, intval( $tot->MntTotal ) );
        $this->assertEquals( '19', strval( $tot->TasaIVA ) );
    }

    public function test_boleta_exenta_totals() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data = [
            'Folio' => 2,
            'FchEmis' => '2024-05-01',
            'RutEmisor' => '11111111-1',
            'RznSoc' => 'Test',
            'GiroEmisor' => 'Giro',
            'DirOrigen' => 'Calle 1',
            'CmnaOrigen' => 'Santiago',
            'Receptor' => [
                'RUTRecep' => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep' => 'Dir',
                'CmnaRecep' => 'Comuna',
            ],
            'Detalles' => [
                [ 'NroLinDet'=>1, 'NmbItem'=>'Exento', 'QtyItem'=>1, 'PrcItem'=>500, 'IndExe'=>1 ],
            ],
        ];
        $xml = $engine->generate_dte_xml( $data, 41, true );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 500, intval( $tot->MntExe ) );
        $this->assertEquals( 500, intval( $tot->MntTotal ) );
        $this->assertEquals( 0, count( $tot->MntNeto ) );
        $this->assertEquals( 0, count( $tot->IVA ) );
    }

    public function test_boleta_rounding_totals_multiple_items() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data = [
            'Folio' => 3,
            'FchEmis' => '2024-05-01',
            'RutEmisor' => '11111111-1',
            'RznSoc' => 'Test',
            'GiroEmisor' => 'Giro',
            'DirOrigen' => 'Calle 1',
            'CmnaOrigen' => 'Santiago',
            'Receptor' => [
                'RUTRecep' => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep' => 'Dir',
                'CmnaRecep' => 'Comuna',
            ],
            'Detalles' => [
                [ 'NroLinDet'=>1, 'NmbItem'=>'A', 'QtyItem'=>1, 'PrcItem'=>1200, 'MntBruto'=>1 ],
                [ 'NroLinDet'=>2, 'NmbItem'=>'B', 'QtyItem'=>1, 'PrcItem'=>1200, 'MntBruto'=>1 ],
            ],
        ];
        $xml = $engine->generate_dte_xml( $data, 39, true );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 2017, intval( $tot->MntNeto ) );
        $this->assertEquals( 383, intval( $tot->IVA ) );
        $this->assertEquals( 2400, intval( $tot->MntTotal ) );
    }

    public function test_boleta_and_factura_pdf_totals_are_aligned() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data   = $this->createPdfExampleDocumentData();

        $boletaXml  = $engine->generate_dte_xml( $data, 39, true );
        $facturaXml = $engine->generate_dte_xml( $data, 33, true );

        $this->assertNotFalse( $boletaXml );
        $this->assertNotFalse( $facturaXml );

        $boletaDocument = simplexml_load_string( $boletaXml );
        $facturaDocument = simplexml_load_string( $facturaXml );

        $this->assertNotFalse( $boletaDocument );
        $this->assertNotFalse( $facturaDocument );

        $boletaTotals  = $boletaDocument->Documento->Encabezado->Totales;
        $facturaTotals = $facturaDocument->Documento->Encabezado->Totales;

        $fields = [
            'MntNeto'  => 1000,
            'IVA'      => 190,
            'MntExe'   => 500,
            'MntTotal' => 1690,
        ];

        foreach ( $fields as $field => $expected ) {
            $this->assertEquals( $expected, intval( $boletaTotals->{$field} ), 'Boleta field ' . $field . ' mismatch' );
            $this->assertEquals( $expected, intval( $facturaTotals->{$field} ), 'Factura field ' . $field . ' mismatch' );
        }

        $this->assertEquals( strval( $boletaTotals->TasaIVA ), strval( $facturaTotals->TasaIVA ) );
        $this->assertEquals( '19', strval( $boletaTotals->TasaIVA ) );
    }

    private function createPdfExampleDocumentData(): array {
        return [
            'Folio' => 1,
            'FchEmis' => '2024-05-01',
            'RutEmisor' => '11111111-1',
            'RznSoc' => 'Test',
            'GiroEmisor' => 'Giro',
            'DirOrigen' => 'Calle 1',
            'CmnaOrigen' => 'Santiago',
            'Encabezado' => [
                'Totales' => [
                    'TasaIVA' => 19,
                ],
            ],
            'Receptor' => [
                'RUTRecep' => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep' => 'Dir',
                'CmnaRecep' => 'Comuna',
            ],
            'Detalles' => [
                [ 'NroLinDet'=>1, 'NmbItem'=>'Servicio afecto', 'QtyItem'=>1, 'PrcItem'=>1190, 'MntBruto'=>1 ],
                [ 'NroLinDet'=>2, 'NmbItem'=>'Servicio exento', 'QtyItem'=>1, 'PrcItem'=>500, 'IndExe'=>1 ],
            ],
        ];
    }

}

