<?php
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends SII_Boleta_Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings() { return $this->data; }
    }
}

class LibreDTEEngineTest extends TestCase {
    public function test_associative_detalles_are_reindexed() {
        $settings = new Dummy_Settings([
            'caf_path' => [39 => __DIR__ . '/fixtures/caf39.xml']
        ]);
        $engine   = new SII_LibreDTE_Engine( $settings );

        $data = [
            'Folio' => 1,
            'FchEmis' => '2024-01-01',
            'RutEmisor' => '11111111-1',
            'RznSoc' => 'Emisor',
            'GiroEmisor' => 'Giro',
            'DirOrigen' => 'Dir',
            'CmnaOrigen' => 'Santiago',
            'Receptor' => [
                'RUTRecep' => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep' => 'Dir',
                'CmnaRecep' => 'Santiago',
            ],
            'Detalles' => [
                'item1' => [ 'NmbItem' => 'Uno', 'QtyItem' => 1, 'PrcItem' => 1000 ],
                'item2' => [ 'NmbItem' => 'Dos', 'QtyItem' => 2, 'PrcItem' => 500 ],
            ],
        ];

        $xml = $engine->generate_dte_xml( $data, 39, true );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $this->assertEquals( 2, count( $sx->Documento->Detalle ) );
        $this->assertEquals( 'Uno', (string) $sx->Documento->Detalle[0]->NmbItem );
        $this->assertEquals( 'Dos', (string) $sx->Documento->Detalle[1]->NmbItem );
    }

    public function test_pdf_is_generated_with_details() {
        $settings = new Dummy_Settings([
            'caf_path' => [39 => __DIR__ . '/fixtures/caf39.xml']
        ]);
        $engine   = new SII_LibreDTE_Engine( $settings );

        $data = [
            'Folio'     => 1,
            'FchEmis'   => '2024-01-01',
            'RutEmisor' => '11111111-1',
            'RznSoc'    => 'Emisor',
            'GiroEmisor'=> 'Giro',
            'DirOrigen' => 'Dir',
            'CmnaOrigen'=> 'Santiago',
            'Receptor'  => [
                'RUTRecep'    => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep'    => 'Dir',
                'CmnaRecep'   => 'Santiago',
            ],
            'Detalles'  => [
                'item1' => [ 'NmbItem' => 'Uno', 'QtyItem' => 1, 'PrcItem' => 1000 ],
                'item2' => [ 'NmbItem' => 'Dos', 'QtyItem' => 2, 'PrcItem' => 500 ],
            ],
        ];

        $xml     = $engine->generate_dte_xml( $data, 39, true );
        $pdfPath = $engine->render_pdf( $xml, [] );

        $this->assertIsString( $pdfPath );
        $this->assertFileExists( $pdfPath );
        $content = file_get_contents( $pdfPath );
        $this->assertStringStartsWith( '%PDF', $content );

        $outDir = __DIR__ . '/output';
        if ( ! is_dir( $outDir ) ) {
            mkdir( $outDir, 0777, true );
        }
        copy( $pdfPath, $outDir . '/boleta_from_array.pdf' );
    }

    public function test_pdf_generated_from_xml_fixture() {
        $settings = new Dummy_Settings( [] );
        $engine   = new SII_LibreDTE_Engine( $settings );

        $xml = file_get_contents( __DIR__ . '/fixtures/boleta_multidetalle.xml' );
        $this->assertNotFalse( $xml );

        $pdfPath = $engine->render_pdf( $xml, [] );

        $this->assertIsString( $pdfPath );
        $this->assertFileExists( $pdfPath );
        $content = file_get_contents( $pdfPath );
        $this->assertStringStartsWith( '%PDF', $content );

        $outDir = __DIR__ . '/output';
        if ( ! is_dir( $outDir ) ) {
            mkdir( $outDir, 0777, true );
        }
        copy( $pdfPath, $outDir . '/boleta_from_xml_fixture.pdf' );
    }
}
