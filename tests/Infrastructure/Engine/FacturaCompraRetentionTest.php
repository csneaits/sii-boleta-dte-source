<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists( 'Dummy_Settings_FacturaCompraRetention' ) ) {
    class Dummy_Settings_FacturaCompraRetention extends \Sii\BoletaDte\Infrastructure\WordPress\Settings {
        private $data; public function __construct( array $d ){ $this->data=$d; }
        public function get_settings(): array { return $this->data; }
    }
}

class FacturaCompraRetentionTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
        FoliosDb::insert( 46, 1, 1000 );
    }
    private function get_settings() {
        return new Dummy_Settings_FacturaCompraRetention([
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Test',
            'giro' => 'Giro',
            'direccion' => 'Calle 1',
            'comuna' => 'Santiago',
        ]);
    }

    private function build_base_data( array $detalles ): array {
        return [
            'Folio' => 1,
            'FchEmis' => '2025-10-07',
            'RutEmisor' => '11111111-1',
            'RznSoc' => 'Test',
            'GiroEmisor' => 'Giro',
            'DirOrigen' => 'Calle 1',
            'CmnaOrigen' => 'Santiago',
            'Encabezado' => [ 'Totales' => [ 'TasaIVA' => 19 ] ],
            'Receptor' => [ 'RUTRecep' => '22222222-2','RznSocRecep'=>'Cliente','DirRecep'=>'Dir','CmnaRecep'=>'Comuna' ],
            'Detalles' => $detalles,
        ];
    }

    public function test_retencion_total_code_15_present_without_affecting_totals() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data = $this->build_base_data([
            [ 'NroLinDet'=>1,'NmbItem'=>'Item','QtyItem'=>1,'PrcItem'=>10000,'CodImpAdic'=>'15','Retenedor'=>['IndAgente'=>'1'] ],
        ]);
        $xml = $engine->generate_dte_xml( $data, 46, true );
        $this->assertNotFalse($xml);
        $sx = simplexml_load_string($xml);
        $this->assertNotFalse($sx);
        $det = $sx->Documento->Detalle;
        $this->assertEquals('15', (string)$det->CodImpAdic);
        $this->assertEquals('1', (string)$det->Retenedor->IndAgente);
        $tot = $sx->Documento->Encabezado->Totales;
    // Con retención total 15 al 19%, el IVA retenido (1900) debe aparecer en ImptoReten y MntTotal = Neto
    $this->assertEquals(10000, intval($tot->MntNeto));
    $this->assertEquals(1900, intval($tot->IVA));
    $this->assertEquals(10000, intval($tot->MntTotal));
    $this->assertEquals(15, intval($tot->ImptoReten->TipoImp));
    $this->assertEquals(19, intval($tot->ImptoReten->TasaImp));
    $this->assertEquals(1900, intval($tot->ImptoReten->MontoImp));
    }

    public function test_retencion_parcial_code_30_present() {
        $engine = new LibreDteEngine( $this->get_settings() );
        $data = $this->build_base_data([
            [ 'NroLinDet'=>1,'NmbItem'=>'Item','QtyItem'=>2,'PrcItem'=>5000,'CodImpAdic'=>'30','Retenedor'=>['IndAgente'=>'1'] ],
        ]);
        $xml = $engine->generate_dte_xml( $data, 46, true );
        $this->assertNotFalse($xml);
        $sx = simplexml_load_string($xml);
        $this->assertNotFalse($sx);
        $det = $sx->Documento->Detalle;
        $this->assertEquals('30', (string)$det->CodImpAdic);
        $this->assertEquals('1', (string)$det->Retenedor->IndAgente);
        $tot = $sx->Documento->Encabezado->Totales;
    // Código 30 (10%) retiene 1000 sobre base neta 10000 => IVA pagadero = 1900 - 1000 = 900; total = 10000 + 900 = 10900
    $this->assertEquals(10000, intval($tot->MntNeto));
    $this->assertEquals(1900, intval($tot->IVA));
    $this->assertEquals(10900, intval($tot->MntTotal));
    $this->assertEquals(30, intval($tot->ImptoReten->TipoImp));
    $this->assertEquals(10, intval($tot->ImptoReten->TasaImp));
    $this->assertEquals(1000, intval($tot->ImptoReten->MontoImp));
    }
}
