<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings(): array { return $this->data; }
    }
}

class InvalidCafTest extends TestCase {
    public function test_invalid_caf_returns_false_without_warnings() {
        $caf_path = tempnam( sys_get_temp_dir(), 'caf' );
        file_put_contents( $caf_path, 'no-xml' );
        $settings = new Dummy_Settings([
            'caf_path' => [ 33 => $caf_path ],
        ]);
        $engine = new LibreDteEngine( $settings );
        $data = [
            'Folio'     => 1,
            'FchEmis'   => '2024-01-01',
            'RutEmisor' => '11111111-1',
            'RznSoc'    => 'Emisor',
            'GiroEmisor'=> 'Giro',
            'DirOrigen' => 'Dir',
            'CmnaOrigen'=> 'Santiago',
            'Receptor'  => [
                'RUTRecep'    => '22222222-2',
                'RznSocRecep' => 'Cliente',
                'DirRecep'    => 'Dir',
                'CmnaRecep'   => 'Santiago',
            ],
            'Detalles'  => [
                [ 'NmbItem' => 'Item', 'QtyItem' => 1, 'PrcItem' => 1000 ],
            ],
        ];

        $result = $engine->generate_dte_xml( $data, 33, false );

        unlink( $caf_path );

        if ( class_exists( '\\WP_Error' ) ) {
            $this->assertInstanceOf( \WP_Error::class, $result );
        } else {
            $this->assertFalse( $result );
        }
    }
}
