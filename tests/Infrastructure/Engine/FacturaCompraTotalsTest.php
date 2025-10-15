<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists( 'Dummy_Settings_FacturaCompra' ) ) {
    class Dummy_Settings_FacturaCompra extends \Sii\BoletaDte\Infrastructure\WordPress\Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings(): array { return $this->data; }
    }
}

class FacturaCompraTotalsTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
        FoliosDb::insert( 46, 1, 1000 );
    }

    private function get_settings() {
        return new Dummy_Settings_FacturaCompra([
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Test',
            'giro' => 'Giro',
            'direccion' => 'Calle 1',
            'comuna' => 'Santiago',
        ]);
    }

    public function test_factura_compra_net_price_not_flagged_gross() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data = [
            'Folio' => 1,
            'FchEmis' => '2025-10-07',
            'RutEmisor' => '11111111-1',
            'RznSoc' => 'Test',
            'GiroEmisor' => 'Giro',
            'DirOrigen' => 'Calle 1',
            'CmnaOrigen' => 'Santiago',
            'Encabezado' => [
                'Totales' => [ 'TasaIVA' => 19 ],
            ],
            'Receptor' => [
                'RUTRecep' => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep' => 'Dir',
                'CmnaRecep' => 'Comuna',
            ],
            'Detalles' => [
                [ 'NroLinDet'=>1, 'NmbItem'=>'Item', 'QtyItem'=>1, 'PrcItem'=>45000 ],
            ],
        ];
        $xml = $engine->generate_dte_xml( $data, 46, true );
        $this->assertNotFalse( $xml );
        $sx = simplexml_load_string( $xml );
        $this->assertNotFalse( $sx );
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals( 45000, intval( $tot->MntNeto ) );
        $this->assertEquals( 8550, intval( $tot->IVA ) );
        $this->assertEquals( 53550, intval( $tot->MntTotal ) );
    }
}
