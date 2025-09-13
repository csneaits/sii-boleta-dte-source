<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Application\ConsumoFolios;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings(): array { return $this->data; }
    }
}

if ( ! function_exists( 'get_option' ) ) {
    $GLOBALS['test_options'] = [];
    function get_option( $key, $default = false ) {
        return $GLOBALS['test_options'][ $key ] ?? $default;
    }
}

class ConsumoFoliosTest extends TestCase {
    public function test_generates_ranges() {
        $GLOBALS['test_options']['sii_boleta_dte_last_folio_39'] = 10;
        $settings = new Dummy_Settings([
            'caf_path'   => [ 39 => __DIR__ . '/fixtures/caf39.xml' ],
            'rut_emisor' => '11111111-1',
        ]);
        $folio_mgr = $this->createMock( FolioManager::class );
        $api       = $this->createMock( Api::class );
        $cdf       = new ConsumoFolios( $settings, $folio_mgr, $api );
        $xml       = $cdf->generate_cdf_xml( '2024-05-01' );
        $this->assertNotFalse( $xml );
        $sx       = simplexml_load_string( $xml );
        $resumen  = $sx->Resumen;
        $this->assertEquals( '39', (string) $resumen['TipoDTE'] );
        $this->assertEquals( '11', (string) $resumen->FoliosEmitidos );
        $this->assertEquals( '0', (string) $resumen->RangoUtilizados->Inicial );
        $this->assertEquals( '10', (string) $resumen->RangoUtilizados->Final );
    }
}
