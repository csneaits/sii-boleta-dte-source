<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists( 'Dummy_Settings_FacturaCompraForeign' ) ) {
    class Dummy_Settings_FacturaCompraForeign extends Settings {
        private $data; public function __construct(array $d){$this->data=$d;} public function get_settings(): array {return $this->data;}
    }
}

class FacturaCompraForeignGlosaTest extends TestCase {
    protected function setUp(): void { FoliosDb::purge(); FoliosDb::insert(46,1,1000); }
    private function get_settings(){ return new Dummy_Settings_FacturaCompraForeign([
        'rut_emisor'=>'11111111-1','razon_social'=>'Test','giro'=>'Giro','direccion'=>'Calle 1','comuna'=>'Santiago'
    ]); }

    public function test_term_pago_glosa_pass_through_when_foreign_added_externally(){
        // NOTA: GenerateDtePage añade la glosa; aquí simulamos que ya está en datos.
        $engine = new LibreDteEngine($this->get_settings());
        $glosa = 'Proveedor extranjero | País: USA | ID: EXT123 | Observación';
        $data = [
            'Folio'=>1,'FchEmis'=>'2025-10-07','RutEmisor'=>'11111111-1','RznSoc'=>'Test','GiroEmisor'=>'Giro','DirOrigen'=>'Calle 1','CmnaOrigen'=>'Santiago',
            'Encabezado'=>['IdDoc'=>['TermPagoGlosa'=>$glosa],'Totales'=>['TasaIVA'=>19]],
            'Receptor'=>['RUTRecep'=>'22222222-2','RznSocRecep'=>'Cliente','DirRecep'=>'Dir','CmnaRecep'=>'Comuna'],
            'Detalles'=>[
                ['NroLinDet'=>1,'NmbItem'=>'Item','QtyItem'=>1,'PrcItem'=>1000],
            ],
        ];
        $xml = $engine->generate_dte_xml($data,46,true);
        $this->assertNotFalse($xml);
        $sx = simplexml_load_string($xml); $this->assertNotFalse($sx);
        $got = (string)$sx->Documento->Encabezado->IdDoc->TermPagoGlosa;
        $this->assertStringContainsString('Proveedor extranjero', $got);
        $this->assertStringContainsString('USA', $got);
        $this->assertStringContainsString('EXT123', $got);
    }
}
