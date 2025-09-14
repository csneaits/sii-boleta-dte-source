<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

class DteEngineGenerateTest extends TestCase {
    public function test_generate_dte_xml(): void {
        $settings = new class extends Settings { public function get_settings(): array { return array( 'rut_emisor' => '76086428-5', 'razon_social' => 'Test', 'giro' => 'GIRO', 'direccion' => 'Calle', 'comuna' => 'Santiago' ); } };
        $engine   = new LibreDteEngine( $settings );
        $xml = $engine->generate_dte_xml( array( 'Detalles' => array( array( 'NmbItem' => 'Item', 'QtyItem' => 1, 'PrcItem' => 1000 ) ) ), 39 );
        $this->assertIsString( $xml );
        $this->assertStringContainsString( '<DTE', $xml );
    }
}
