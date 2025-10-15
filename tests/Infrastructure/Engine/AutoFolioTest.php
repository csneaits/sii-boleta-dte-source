<?php
declare(strict_types=1);

namespace Tests\Infrastructure\Engine;

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

final class AutoFolioTest extends TestCase
{
    private function makeSettings(array $overrides = []): Settings
    {
        $settings = new Settings();
        $opts = array_merge([
            'environment' => '2',
            'auto_folio_libredte' => 1,
            'rut_emisor' => '11111111-1',
            'razon_social' => 'Empresa Test',
            'giro' => 'Comercio',
            'direccion' => 'Dir 123',
            'comuna' => 'Santiago',
        ], $overrides);
        $GLOBALS['wp_options'][Settings::OPTION_NAME] = $opts;
        return $settings;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // reset last folio counters
        foreach ([33,34,39,41,46,52,56,61] as $t) {
            \Sii\BoletaDte\Infrastructure\WordPress\Settings::update_last_folio_value($t, '2', 0);
        }
    }

    public function testEngineAssignsFolioWhenMissingInDev(): void
    {
        $settings = $this->makeSettings();
        $engine = new LibreDteEngine($settings);

        $data = [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => 39,
                    // Folio omitted on purpose
                    'FchEmis' => gmdate('Y-m-d'),
                ],
                'Receptor' => [
                    'RUTRecep'   => '66666666-6',
                    'RznSocRecep'=> 'Cliente',
                    'GiroRecep'  => 'Servicios',
                    'DirRecep'   => 'Calle 1',
                    'CmnaRecep'  => 'Comuna',
                ],
            ],
            'Detalle' => [
                [ 'NmbItem' => 'Item', 'QtyItem' => 1, 'PrcItem' => 1190, 'MntBruto' => 1 ],
            ],
        ];

        $xml = $engine->generate_dte_xml($data, 39, false);
        $this->assertIsString($xml);
        $this->assertNotSame('', $xml);

        $this->assertStringContainsString('<Folio>', $xml);

        // Verify last folio advanced
        $last = Settings::get_last_folio_value(39, '2');
        $this->assertGreaterThan(0, $last);
    }
}
