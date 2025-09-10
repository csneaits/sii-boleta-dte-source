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
        $settings = new Dummy_Settings([]);
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

}
