<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! class_exists('Dummy_Settings_PdfRetention') ) {
    class Dummy_Settings_PdfRetention extends Settings { private $d; public function __construct($d){$this->d=$d;} public function get_settings(): array { return $this->d; } }
}

class PdfRetentionRenderTest extends TestCase {
    protected function setUp(): void { FoliosDb::purge(); FoliosDb::insert(46,1,1000); }
    private function settings(){ return new Dummy_Settings_PdfRetention([
        'rut_emisor'=>'11111111-1','razon_social'=>'Test','giro'=>'Giro','direccion'=>'Dir','comuna'=>'Santiago'
    ]); }

    public function test_pdf_contains_retention_labels(){
        $engine = new LibreDteEngine($this->settings());
        $data = [
            'Folio'=>1,'FchEmis'=>'2025-10-08','RutEmisor'=>'11111111-1','RznSoc'=>'Test','GiroEmisor'=>'Giro','DirOrigen'=>'Dir','CmnaOrigen'=>'Santiago',
            'Encabezado'=>['Totales'=>['TasaIVA'=>19]],
            'Receptor'=>['RUTRecep'=>'12345678-5','RznSocRecep'=>'Cliente','DirRecep'=>'Dir','CmnaRecep'=>'Comuna'],
            'Detalles'=>[
                ['NroLinDet'=>1,'NmbItem'=>'Servicio','QtyItem'=>1,'PrcItem'=>10000,'CodImpAdic'=>'15','Retenedor'=>['IndAgente'=>'1']]
            ],
        ];
    // Generar XML primero y luego renderizar PDF
    $xml = $engine->generate_dte_xml($data,46,true);
    $this->assertNotFalse($xml,'No se generó XML para PDF');
    $pdfFile = $engine->render_pdf($xml,[]);
        $this->assertFileExists($pdfFile);
        $contents = @file_get_contents($pdfFile);
        $this->assertNotFalse($contents,'No se pudo leer PDF');
        // Búsquedas heurísticas (el PDF puede comprimir, pero la plantilla estándar suele dejar los strings en claro)
        $this->assertStringContainsString('IVA retenido', $contents);
        $this->assertStringContainsString('IVA pagadero', $contents);
        $this->assertStringContainsString('Retención 15 (19%)', $contents);
    }
}
