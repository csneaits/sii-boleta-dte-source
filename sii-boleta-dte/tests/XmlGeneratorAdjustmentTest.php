<?php
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends SII_Boleta_Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings() { return $this->data; }
    }
}

class Mock_XML_Generator_Adjust extends SII_Boleta_XML_Generator {
    protected function generate_ted( array $data, $caf_path ) {
        return '<TED><FRMT>mock</FRMT></TED>';
    }
}

class XmlGeneratorAdjustmentTest extends TestCase {
    public function test_totals_consistency_and_last_line_adjustment() {
        $settings = new Dummy_Settings([
            'caf_path'  => [ 33 => __DIR__ . '/fixtures/caf.xml' ],
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Test',
            'giro' => 'Giro',
            'direccion' => 'Calle 1',
            'comuna' => 'Santiago',
        ]);
        $generator = new Mock_XML_Generator_Adjust( $settings );
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
                [ 'NroLinDet'=>1, 'NmbItem'=>'A', 'QtyItem'=>1, 'PrcItem'=>1001 ],
                [ 'NroLinDet'=>2, 'NmbItem'=>'B', 'QtyItem'=>1, 'PrcItem'=>1001 ],
                [ 'NroLinDet'=>3, 'NmbItem'=>'Exento', 'QtyItem'=>1, 'PrcItem'=>500, 'IndExe'=>1 ],
            ],
        ];
        $xml = $generator->generate_dte_xml( $data, 33, false );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $tot = $sx->Documento->Encabezado->Totales;
        $sum = 0;
        foreach ( $sx->Documento->Detalle as $d ) { $sum += intval( $d->MontoItem ); }
        $this->assertEquals( intval( $tot->MntTotal ), $sum );
        $this->assertEquals( intval( $tot->MntNeto ) + intval( $tot->IVA ) + intval( $tot->MntExe ), intval( $tot->MntTotal ) );
    }
}
