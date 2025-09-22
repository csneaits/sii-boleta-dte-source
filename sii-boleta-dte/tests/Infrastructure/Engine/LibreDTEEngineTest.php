<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings(): array { return $this->data; }
    }
}

class LibreDTEEngineTest extends TestCase {
    public function test_pdf_generated_from_xml_fixture() {
        $settings = new Dummy_Settings([]);
        $engine   = new LibreDteEngine($settings);

        $xml = file_get_contents(__DIR__ . '/../../fixtures/boleta_multidetalle.xml');
        $this->assertNotFalse($xml);

        // Renderiza PDF
        $pdfPath = $engine->render_pdf($xml, []);
        $this->assertIsString($pdfPath);
        $this->assertFileExists($pdfPath);

        // Validar que sea un PDF real
        $content = file_get_contents($pdfPath);
        $this->assertStringStartsWith('%PDF', $content);

        // Copiar a /output/ si quieres conservarlo como archivo visible
        $outputDir = __DIR__ . '/../../output/';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFile = $outputDir . basename($pdfPath);
        copy($pdfPath, $outputFile);

        // Confirmar que fue copiado
        $this->assertFileExists($outputFile);

}

    public function test_render_pdf_resets_mnttotal_before_normalization() {
        $settings = new Dummy_Settings([]);
        $engine   = new LibreDteEngine($settings);

        $xml = file_get_contents(__DIR__ . '/../../fixtures/boleta_multidetalle.xml');
        $this->assertNotFalse($xml);

        $reflection = new \ReflectionClass(LibreDteEngine::class);

        $parseMethod = $reflection->getMethod('parse_document_data_from_xml');
        $parseMethod->setAccessible(true);
        $parsed = $parseMethod->invoke($engine, $xml);

        $this->assertIsArray($parsed);
        $this->assertSame('7100', $parsed['Encabezado']['Totales']['MntTotal']);

        $resetMethod = $reflection->getMethod('reset_total_before_rendering');
        $resetMethod->setAccessible(true);
        $sanitized = $resetMethod->invoke($engine, $parsed);

        $this->assertIsArray($sanitized);
        $this->assertSame(0, $sanitized['Encabezado']['Totales']['MntTotal']);
        $this->assertSame(0, $sanitized['Encabezado']['Totales']['MntNeto']);
        $this->assertSame(0, $sanitized['Encabezado']['Totales']['IVA']);
    }

    public function test_parse_document_data_from_xml_mirrors_emitter_aliases(): void {
        $settings = new Dummy_Settings([]);
        $engine   = new LibreDteEngine($settings);

        $xml = file_get_contents(__DIR__ . '/../../fixtures/boleta_multidetalle.xml');
        $this->assertNotFalse($xml);

        $reflection = new \ReflectionClass(LibreDteEngine::class);
        $parseMethod = $reflection->getMethod('parse_document_data_from_xml');
        $parseMethod->setAccessible(true);

        $parsed = $parseMethod->invoke($engine, $xml);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('Encabezado', $parsed);
        $this->assertIsArray($parsed['Encabezado']);
        $this->assertArrayHasKey('Emisor', $parsed['Encabezado']);
        $this->assertIsArray($parsed['Encabezado']['Emisor']);

        $emisor = $parsed['Encabezado']['Emisor'];

        $this->assertArrayHasKey('RznSocEmisor', $emisor);
        $this->assertSame('SASCO SpA', $emisor['RznSocEmisor']);
        $this->assertArrayHasKey('RznSoc', $emisor);
        $this->assertSame($emisor['RznSocEmisor'], $emisor['RznSoc']);

        $this->assertArrayHasKey('GiroEmisor', $emisor);
        $this->assertSame('Tecnología, Informática y Telecomunicaciones', $emisor['GiroEmisor']);
        $this->assertArrayHasKey('GiroEmis', $emisor);
        $this->assertSame($emisor['GiroEmisor'], $emisor['GiroEmis']);
    }

}
