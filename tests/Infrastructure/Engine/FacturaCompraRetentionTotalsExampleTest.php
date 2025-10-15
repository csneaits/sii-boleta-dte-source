<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists('Dummy_Settings_FacturaCompraRetentionTotals') ) {
    class Dummy_Settings_FacturaCompraRetentionTotals extends \Sii\BoletaDte\Infrastructure\WordPress\Settings { private $d; public function __construct($d){$this->d=$d;} public function get_settings(): array { return $this->d; } }
}

class FacturaCompraRetentionTotalsExampleTest extends TestCase {
    protected function setUp(): void { FoliosDb::purge(); FoliosDb::insert(46,1,1000); }
    private function get_settings(){ return new Dummy_Settings_FacturaCompraRetentionTotals([
        'rut_emisor'=>'11111111-1','razon_social'=>'Test','giro'=>'Giro','direccion'=>'Dir','comuna'=>'Santiago'
    ]); }

    public function test_expected_structure_for_full_retention_example(){
        $engine = new LibreDteEngine($this->get_settings());
        $data = [
            'Folio'=>1,'FchEmis'=>'2025-10-08','RutEmisor'=>'11111111-1','RznSoc'=>'Test','GiroEmisor'=>'Giro','DirOrigen'=>'Dir','CmnaOrigen'=>'Santiago',
            'Encabezado'=>['Totales'=>['TasaIVA'=>19]],
            'Receptor'=>['RUTRecep'=>'78103459-2','RznSocRecep'=>'SII','GiroRecep'=>'Gobierno','DirRecep'=>'Santiago','CmnaRecep'=>'Santiago'],
            'Detalles'=>[
                ['NroLinDet'=>1,'NmbItem'=>'Servicio de Infraestructura','QtyItem'=>1,'PrcItem'=>123973,'CodImpAdic'=>'15','Retenedor'=>['IndAgente'=>'1']]
            ],
        ];
        $xml = $engine->generate_dte_xml($data,46,true);
        $this->assertNotFalse($xml,'XML no generado');
        $sx = simplexml_load_string($xml); $this->assertNotFalse($sx,'Parse XML');
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals(123973, intval($tot->MntNeto));
        $this->assertEquals(23555, intval($tot->IVA));
        $this->assertEquals(123973, intval($tot->MntTotal)); // IVA retenido totalmente
        $this->assertEquals(15, intval($tot->ImptoReten->TipoImp));
        $this->assertEquals(19, intval($tot->ImptoReten->TasaImp));
        $this->assertEquals(23555, intval($tot->ImptoReten->MontoImp));
    }
}
