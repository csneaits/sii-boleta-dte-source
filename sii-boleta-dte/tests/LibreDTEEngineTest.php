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
    public function test_pdf_generated_from_xml_fixture() {
        $settings = new Dummy_Settings([]);
        $engine   = new SII_LibreDTE_Engine($settings);

        $xml = file_get_contents(__DIR__ . '/fixtures/boleta_multidetalle.xml');
        $this->assertNotFalse($xml);

        // Renderiza PDF
        $pdfPath = $engine->render_pdf($xml, []);
        $this->assertIsString($pdfPath);
        $this->assertFileExists($pdfPath);

        // Validar que sea un PDF real
        $content = file_get_contents($pdfPath);
        $this->assertStringStartsWith('%PDF', $content);

        // Copiar a /output/ si quieres conservarlo como archivo visible
        $outputDir = __DIR__ . '/output/';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFile = $outputDir . basename($pdfPath);
        copy($pdfPath, $outputFile);

        // Confirmar que fue copiado
        $this->assertFileExists($outputFile);
    
}

}
