<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Engine\Caf\CafProviderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\BoletaDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\Factory\DteDocumentFactoryRegistry;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends \Sii\BoletaDte\Infrastructure\WordPress\Settings {
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
        $this->assertSame(7100, $sanitized['Encabezado']['Totales']['MntTotal']);
        $this->assertSame(7100, $sanitized['Encabezado']['Totales']['MntNeto']);
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

    public function test_preview_ignores_real_caf_ranges(): void {
        FoliosDb::install();
        $rangeId = FoliosDb::insert(39, 1, 100, 'test');
        $cafPath = __DIR__ . '/../../fixtures/caf39.xml';
        $cafXml  = file_get_contents($cafPath);
        $this->assertIsString($cafXml);
        FoliosDb::store_caf($rangeId, $cafXml, 'caf39.xml');

        $settings = new Dummy_Settings([
            'environment'   => 'test',
            'rut_emisor'    => '76192083-9',
            'razon_social'  => 'Empresa de Prueba',
            'giro'          => 'Servicios',
            'direccion'     => 'Direccion 123',
            'comuna'        => 'Santiago',
        ]);

        $engine = new LibreDteEngine($settings);

        $data = [
            'Folio'    => 0,
            'FchEmis'  => '2024-01-01',
            'Receptor' => [
                'RUTRecep'    => '66666666-6',
                'RznSocRecep' => 'Cliente Prueba',
                'GiroRecep'   => 'Comercio',
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => 'Producto',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1000,
                    'MontoItem' => 1000,
                ],
            ],
        ];

        $xml = $engine->generate_dte_xml($data, 39, true);

        $this->assertIsString($xml);
        $this->assertNotSame('', $xml);
        $this->assertStringContainsString('<CAF', $xml);
    }

    public function test_preview_mode_skips_missing_caf_validation(): void {
        FoliosDb::install();

        $settings = new Dummy_Settings([
            'environment'   => 'test',
            'rut_emisor'    => '76192083-9',
            'razon_social'  => 'Empresa de Prueba',
            'giro'          => 'Servicios',
            'direccion'     => 'Direccion 123',
            'comuna'        => 'Santiago',
        ]);

        $engine = new LibreDteEngine($settings);

        $data = [
            'Folio'    => 25,
            'FchEmis'  => '2024-01-01',
            'Receptor' => [
                'RUTRecep'    => '66666666-6',
                'RznSocRecep' => 'Cliente Prueba',
                'GiroRecep'   => 'Comercio',
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => 'Producto',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1000,
                    'MontoItem' => 1000,
                ],
            ],
        ];

        $xml = $engine->generate_dte_xml($data, 39, true);

        $this->assertIsString($xml);
        $this->assertNotSame('', $xml);
    }

    public function test_register_document_factory_overrides_factory_for_tipo(): void {
        $settings = new Dummy_Settings([]);
        $engine   = new LibreDteEngine($settings);

        $reflection = new \ReflectionClass(LibreDteEngine::class);
        $registryProperty = $reflection->getProperty('documentFactoryRegistry');
        $registryProperty->setAccessible(true);

        $registry = $registryProperty->getValue($engine);

        $this->assertInstanceOf(DteDocumentFactoryRegistry::class, $registry);

        $defaultFactory = $registry->getFactory(39);
        $this->assertInstanceOf(BoletaDteDocumentFactory::class, $defaultFactory);

        $templatesRoot = dirname(__DIR__, 3) . '/resources/yaml/';
        $boletaFactory = new BoletaDteDocumentFactory($templatesRoot);

        $engine->register_document_factory(39, $boletaFactory);

        $this->assertSame($boletaFactory, $registry->getFactory(39));

        $template = $boletaFactory->createTemplateLoader()->load(39);
        $this->assertSame(39, $template['Encabezado']['IdDoc']['TipoDTE'] ?? null);
    }

    public function test_generate_xml_with_flattened_caf_is_normalized(): void {
        FoliosDb::install();
        $rangeId = FoliosDb::insert(39, 1, 20, 'test');

        $settings = new Dummy_Settings([
            'environment'   => 'test',
            'rut_emisor'    => '76192083-9',
            'razon_social'  => 'Empresa de Prueba',
            'giro'          => 'Servicios',
            'direccion'     => 'Direccion 123',
            'comuna'        => 'Santiago',
        ]);

        $engine = new LibreDteEngine($settings);

        $reflection    = new \ReflectionClass(LibreDteEngine::class);
        $providerProp  = $reflection->getProperty('cafProvider');
        $providerProp->setAccessible(true);
        $cafProvider = $providerProp->getValue($engine);

        $this->assertInstanceOf(CafProviderInterface::class, $cafProvider);

        $emisorEntity = new Emisor('76192083-9', 'Empresa de Prueba');
        $cafBag       = $cafProvider->resolve(39, 1, true, $emisorEntity, 'test');
        $cafXml       = $cafBag->getCaf()->getXml();

        $pattern = '/<(RSASK|RSAPUBK)>(.*?)<\/\1>/s';

        $withBreaks = preg_replace_callback(
            $pattern,
            static function ( array $matches ): string {
                $block = trim($matches[2]);
                if (!preg_match('/^(-----BEGIN [^-]+-----)(.*?)(-----END [^-]+-----)$/s', $block, $pemParts)) {
                    return $matches[0];
                }

                $body     = preg_replace('/[^A-Za-z0-9+\/=]/', '', $pemParts[2]);
                $chunked  = chunk_split($body, 64, "\n");
                $chunked  = rtrim($chunked, "\n");
                $formatted = $pemParts[1] . "\n" . $chunked . "\n" . $pemParts[3];

                return '<' . $matches[1] . '>' . "\n" . $formatted . "\n" . '</' . $matches[1] . '>';
            },
            $cafXml
        );

        $this->assertIsString($withBreaks);
        $this->assertNotSame($cafXml, $withBreaks);

        $flattened = preg_replace_callback(
            $pattern,
            static function ( array $matches ): string {
                $compact = str_replace(["\r", "\n", "\t"], '', $matches[2]);
                return '<' . $matches[1] . '>' . $compact . '</' . $matches[1] . '>';
            },
            $withBreaks
        );

        $this->assertIsString($flattened);
        $this->assertNotSame($withBreaks, $flattened);

        $stored = FoliosDb::store_caf($rangeId, $flattened, 'caf39.xml');
        $this->assertTrue($stored);

        $storedRange = FoliosDb::get($rangeId);
        $this->assertIsArray($storedRange);
        $this->assertArrayHasKey('caf', $storedRange);
        $this->assertStringContainsString("\n", (string) $storedRange['caf']);
        $this->assertMatchesRegularExpression('/<RSASK>.*\n.*<\/RSASK>/s', (string) $storedRange['caf']);

        $data = [
            'Folio'    => 1,
            'FchEmis'  => '2024-01-01',
            'Receptor' => [
                'RUTRecep'    => '66666666-6',
                'RznSocRecep' => 'Cliente Prueba',
                'GiroRecep'   => 'Comercio',
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => 'Producto',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1000,
                    'MontoItem' => 1000,
                ],
            ],
        ];

        $xml = $engine->generate_dte_xml($data, 39, false);

        $this->assertIsString($xml);
        $this->assertNotSame('', $xml);
        $this->assertStringContainsString('<DTE', $xml);
    }

    public function test_generate_xml_uses_normalized_caf_from_memory_store(): void {
        FoliosDb::install();
        $rangeId = FoliosDb::insert(39, 1, 10, 'test');

        $settings = new Dummy_Settings([
            'environment'   => 'test',
            'rut_emisor'    => '76192083-9',
            'razon_social'  => 'Empresa de Prueba',
            'giro'          => 'Servicios',
            'direccion'     => 'Direccion 123',
            'comuna'        => 'Santiago',
        ]);

        $engine = new LibreDteEngine($settings);

        $reflection    = new \ReflectionClass(LibreDteEngine::class);
        $providerProp  = $reflection->getProperty('cafProvider');
        $providerProp->setAccessible(true);
        $cafProvider   = $providerProp->getValue($engine);

        $this->assertInstanceOf(CafProviderInterface::class, $cafProvider);

        $emisorEntity = new Emisor('76192083-9', 'Empresa de Prueba');
        $cafBag       = $cafProvider->resolve(39, 1, true, $emisorEntity, 'test');
        $cafXml       = $cafBag->getCaf()->getXml();

        $pattern = '/<(RSASK|RSAPUBK)>(.*?)<\/\1>/s';

        $withBreaks = preg_replace_callback(
            $pattern,
            static function ( array $matches ): string {
                $block = trim($matches[2]);
                if (!preg_match('/^(-----BEGIN [^-]+-----)(.*?)(-----END [^-]+-----)$/s', $block, $pemParts)) {
                    return $matches[0];
                }

                $body     = preg_replace('/[^A-Za-z0-9+\/=]/', '', $pemParts[2]);
                $chunked  = chunk_split($body, 64, "\n");
                $chunked  = rtrim($chunked, "\n");
                $formatted = $pemParts[1] . "\n" . $chunked . "\n" . $pemParts[3];

                return '<' . $matches[1] . '>' . "\n" . $formatted . "\n" . '</' . $matches[1] . '>';
            },
            $cafXml
        );

        $this->assertIsString($withBreaks);

        $flattened = preg_replace_callback(
            $pattern,
            static function ( array $matches ): string {
                $compact = str_replace(["\r", "\n", "\t"], '', $matches[2]);
                return '<' . $matches[1] . '>' . $compact . '</' . $matches[1] . '>';
            },
            $withBreaks
        );

        $this->assertIsString($flattened);
        $this->assertNotSame($withBreaks, $flattened);

        $foliosReflection = new \ReflectionClass(FoliosDb::class);
        $rowsProperty     = $foliosReflection->getProperty('rows');
        $rowsProperty->setAccessible(true);
        $rows = $rowsProperty->getValue();
        $this->assertIsArray($rows);
        $this->assertArrayHasKey($rangeId, $rows);
        $rows[$rangeId]['caf'] = $flattened;
        $rowsProperty->setValue(null, $rows);

        $range = FoliosDb::get($rangeId);
        $this->assertIsArray($range);
        $this->assertArrayHasKey('caf', $range);
        $this->assertStringContainsString("\n", (string) $range['caf']);

        $data = [
            'Folio'    => 1,
            'FchEmis'  => '2024-01-01',
            'Receptor' => [
                'RUTRecep'    => '66666666-6',
                'RznSocRecep' => 'Cliente Prueba',
                'GiroRecep'   => 'Comercio',
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => 'Producto',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1000,
                    'MontoItem' => 1000,
                ],
            ],
        ];

        $xml = $engine->generate_dte_xml($data, 39, false);

        $this->assertIsString($xml);
        $this->assertNotSame('', $xml);
        $this->assertStringContainsString('<DTE', $xml);
    }
}
