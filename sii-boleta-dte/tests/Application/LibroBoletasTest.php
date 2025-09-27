<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Application\LibroBoletas;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! class_exists( 'DummyLibroSettings' ) ) {
    class DummyLibroSettings extends Settings {
        private array $data;

        public function __construct( array $data ) {
            $this->data = $data;
        }

        public function get_settings(): array {
            return $this->data;
        }

        public function get_environment(): string {
            return $this->data['environment'] ?? '0';
        }
    }
}

if ( ! class_exists( 'DummyLibroFolioManager' ) ) {
    class DummyLibroFolioManager extends FolioManager {
        public function __construct() {}

        public function get_caf_info( int $type = 39 ): array {
            return array(
                'FchResol' => '2024-01-01',
                'NroResol' => '1',
                'D'        => 1,
                'H'        => 100,
            );
        }
    }
}

if ( ! class_exists( 'DummyLibroOrder' ) ) {
    class DummyLibroOrder {
        public function get_total(): float {
            return 1190.0;
        }
    }
}

$GLOBALS['wc_get_orders_args'] = null;

if ( ! function_exists( 'wc_get_orders' ) ) {
    function wc_get_orders( $args = array() ) {
        $GLOBALS['wc_get_orders_args'] = $args;
        return array( new DummyLibroOrder() );
    }
}

class LibroBoletasTest extends TestCase {
    public function test_collect_orders_uses_inclusive_range(): void {
        $settings = new DummyLibroSettings(
            array(
                'rut_emisor'  => '11111111-1',
                'environment' => '0',
            )
        );

        $libro = new LibroBoletas( $settings, null, null, new DummyLibroFolioManager() );
        $xml   = $libro->generate_monthly_xml( '2024-05' );

        $this->assertNotSame( '', $xml );
        $this->assertIsArray( $GLOBALS['wc_get_orders_args'] );
        $this->assertArrayHasKey( 'date_created', $GLOBALS['wc_get_orders_args'] );
        $this->assertSame( '2024-05-01...2024-05-31', $GLOBALS['wc_get_orders_args']['date_created'] );
    }
}
