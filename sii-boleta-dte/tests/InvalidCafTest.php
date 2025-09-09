<?php
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends SII_Boleta_Settings {
        private $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings() { return $this->data; }
    }
}

class Exposed_XML_Generator_InvalidCAF extends SII_Boleta_XML_Generator {
    public function exposed_generate_ted( array $data, $caf_path ) {
        return $this->generate_ted( $data, $caf_path );
    }
}

class InvalidCafTest extends TestCase {
    public function test_invalid_caf_returns_false_without_warnings() {
        $cert_file = tempnam( sys_get_temp_dir(), 'pfx' );
        file_put_contents( $cert_file, 'fake' );
        $settings = new Dummy_Settings([
            'cert_path' => $cert_file,
            'cert_pass' => 'none',
        ]);
        $generator = new Exposed_XML_Generator_InvalidCAF( $settings );
        $data = [
            'RutEmisor' => '11111111-1',
            'TipoDTE'   => 33,
            'Folio'     => 1,
            'FchEmis'   => '2024-01-01',
            'Receptor'  => [
                'RUTRecep'     => '22222222-2',
                'RznSocRecep'  => 'Cliente',
            ],
            'Detalles'  => [
                [ 'NmbItem' => 'Item', 'MontoItem' => 1000 ],
            ],
        ];
        $caf_path = tempnam( sys_get_temp_dir(), 'caf' );
        file_put_contents( $caf_path, 'no-xml' );

        $hadWarning = false;
        set_error_handler( function( $errno ) use ( & $hadWarning ) {
            if ( $errno === E_WARNING ) { $hadWarning = true; }
        } );
        $result = $generator->exposed_generate_ted( $data, $caf_path );
        restore_error_handler();

        unlink( $caf_path );
        unlink( $cert_file );

        $this->assertFalse( $hadWarning );
        $this->assertFalse( $result );
    }
}
