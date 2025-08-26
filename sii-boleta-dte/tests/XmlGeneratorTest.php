<?php
use PHPUnit\Framework\TestCase;

class Dummy_Settings extends SII_Boleta_Settings {
    private $data;
    public function __construct( array $data ) { $this->data = $data; }
    public function get_settings() { return $this->data; }
}

class Mock_XML_Generator extends SII_Boleta_XML_Generator {
    protected function generate_ted( array $data, $caf_path ) {
        return '<TED><FRMT>mock</FRMT></TED>';
    }
}

class XmlGeneratorTest extends TestCase {
    public function test_neto_iva_calculation_and_ted() {
        $settings = new Dummy_Settings([
            'caf_path'  => [ 33 => __DIR__ . '/fixtures/caf.xml' ],
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Test',
            'giro' => 'Giro',
            'direccion' => 'Calle 1',
            'comuna' => 'Santiago',
        ]);
        $generator = new Mock_XML_Generator( $settings );
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
        $xml = $generator->generate_dte_xml( $data, 33, false );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 1000, intval( $tot->MntNeto ) );
        $this->assertEquals( 190, intval( $tot->IVA ) );
        $this->assertEquals( 500, intval( $tot->MntExe ) );
        $this->assertEquals( 1690, intval( $tot->MntTotal ) );
        $sumLines = 0;
        foreach ( $sx->Documento->Detalle as $d ) { $sumLines += intval( $d->MontoItem ); }
        $this->assertEquals( intval( $tot->MntTotal ), $sumLines );
        $this->assertNotEmpty( $sx->Documento->TED );
    }
}
