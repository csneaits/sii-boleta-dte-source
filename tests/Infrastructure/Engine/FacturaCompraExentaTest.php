<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists( 'Dummy_Settings_FacturaCompraExenta' ) ) {
    class Dummy_Settings_FacturaCompraExenta extends Settings {
        private $data; public function __construct(array $d){$this->data=$d;} public function get_settings(): array {return $this->data;}
    }
}

class FacturaCompraExentaTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge(); FoliosDb::insert(46,1,1000);
    }
    private function get_settings(){
        return new Dummy_Settings_FacturaCompraExenta([
            'rut_emisor'=>'11111111-1','razon_social'=>'Test','giro'=>'Giro','direccion'=>'Calle 1','comuna'=>'Santiago'
        ]);
    }

    public function test_line_exenta_creates_mntexe_without_iva(){
        $engine = new LibreDteEngine($this->get_settings());
        $data = [
            'Folio'=>1,'FchEmis'=>'2025-10-07','RutEmisor'=>'11111111-1','RznSoc'=>'Test','GiroEmisor'=>'Giro','DirOrigen'=>'Calle 1','CmnaOrigen'=>'Santiago',
            'Encabezado'=>['Totales'=>['TasaIVA'=>19]],
            'Receptor'=>['RUTRecep'=>'22222222-2','RznSocRecep'=>'Cliente','DirRecep'=>'Dir','CmnaRecep'=>'Comuna'],
            'Detalles'=>[
                ['NroLinDet'=>1,'NmbItem'=>'Servicio Exento','QtyItem'=>1,'PrcItem'=>10000,'IndExe'=>1],
            ],
        ];
        $xml = $engine->generate_dte_xml($data,46,true);
        $this->assertNotFalse($xml);
        $sx = simplexml_load_string($xml); $this->assertNotFalse($sx);
        $tot = $sx->Documento->Encabezado->Totales;
        $this->assertEquals(10000, intval($tot->MntExe));
        // IVA y MntNeto deberían estar vacíos o cero
        $this->assertTrue(!isset($tot->MntNeto) || intval($tot->MntNeto)===0);
        $this->assertTrue(!isset($tot->IVA) || intval($tot->IVA)===0);
        $this->assertEquals(10000, intval($tot->MntTotal));
    }
}
