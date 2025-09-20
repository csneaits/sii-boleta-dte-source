<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

class DummySettings extends Settings {
    public function __construct( private array $data ) {
    }

    public function get_settings(): array {
        return $this->data;
    }
}

class LibreDteEngineInvalidRutTest extends TestCase {
    public function test_generate_dte_xml_returns_wp_error_when_rut_is_invalid(): void {
        $settings = new DummySettings(
            array(
                'rut_emisor'   => '78103459-2',
                'razon_social' => 'CSNEAITS SpA',
                'giro'         => 'Venta al por menor de productos en comercio por internet.',
                'direccion'    => 'Belisario Prats 1850',
                'comuna'       => 'Independencia',
                'caf_path'     => array( 39 => __DIR__ . '/../../fixtures/caf39.xml' ),
            )
        );

        $engine = new LibreDteEngine( $settings );
        $result = $engine->generate_dte_xml(
            array(
                'Folio'   => 0,
                'FchEmis' => '2024-12-09',
                'Receptor' => array(
                    'RUTRecep'    => '25915006-6',
                    'RznSocRecep' => 'Carlos Rodriguez',
                ),
                'Detalles' => array(
                    array(
                        'NroLinDet' => 1,
                        'NmbItem'   => 'TEST (993)',
                        'QtyItem'   => 1,
                        'PrcItem'   => 1200,
                        'MontoItem' => 1200,
                    ),
                ),
            ),
            39,
            true
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'sii_boleta_invalid_rut', $result->get_error_code() );
        $this->assertSame( 'El RUT del receptor no es válido. Verifica el dígito verificador.', $result->get_error_message() );
    }
}
