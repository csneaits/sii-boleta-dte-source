<?php
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends SII_Boleta_Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings() { return $this->data; }
    }
}

class Mock_XML_Generator_Boleta extends SII_Boleta_XML_Generator {
    protected function generate_ted( array $data, $caf_path ) {
        return '<TED><FRMT>mock</FRMT></TED>';
    }
}

class BoletaTotalsTest extends TestCase {

    private function get_settings() {
        return new Dummy_Settings([
            'caf_path'  => [
                39 => __DIR__ . '/fixtures/caf.xml',
                41 => __DIR__ . '/fixtures/caf.xml',
            ],
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Test',
            'giro' => 'Giro',
            'direccion' => 'Calle 1',
            'comuna' => 'Santiago',
        ]);
    }

    public function test_boleta_afecta_totals() {
        $generator = new Mock_XML_Generator_Boleta( $this->get_settings() );
        $data = [
            'Folio' => 1,
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
                [ 'NroLinDet'=>1, 'NmbItem'=>'Afecto', 'QtyItem'=>1, 'PrcItem'=>1190 ],
                [ 'NroLinDet'=>2, 'NmbItem'=>'Exento', 'QtyItem'=>1, 'PrcItem'=>500, 'IndExe'=>1 ],
            ],
        ];
        $xml = $generator->generate_dte_xml( $data, 39, false );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 1000, intval( $tot->MntNeto ) );
        $this->assertEquals( 190, intval( $tot->IVA ) );
        $this->assertEquals( 500, intval( $tot->MntExe ) );
        $this->assertEquals( 1690, intval( $tot->MntTotal ) );
    }

    public function test_boleta_exenta_totals() {
        $generator = new Mock_XML_Generator_Boleta( $this->get_settings() );
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
        $xml = $generator->generate_dte_xml( $data, 41, false );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 500, intval( $tot->MntExe ) );
        $this->assertEquals( 500, intval( $tot->MntTotal ) );
        $this->assertEquals( 0, count( $tot->MntNeto ) );
        $this->assertEquals( 0, count( $tot->IVA ) );
    }
}

