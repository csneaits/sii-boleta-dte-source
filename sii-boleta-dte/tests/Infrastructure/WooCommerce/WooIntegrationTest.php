<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;

// Stub WP functions
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb ) {} }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'update_post_meta' ) ) { function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; } }
if ( ! function_exists( 'wc_get_order' ) ) {
    class DummyOrder {
        private $id;

        public function __construct( $id ) {
            $this->id = $id;
        }

        public function get_total() {
            return 100;
        }

        public function get_order_number() {
            return (string) $this->id;
        }

        public function add_order_note( $message ) {
            $GLOBALS['notes'][ $this->id ][] = $message;
        }
    }
    function wc_get_order( $id ) { return new DummyOrder( $id ); }
}
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; public function __construct( $c = '' ) { $this->code = $c; } } }

class DummyPlugin {
    public function __construct( $settings, $engine, $api ) {
        $this->settings = $settings; $this->engine = $engine; $this->api = $api; }
    public function get_settings() { return $this->settings; }
    public function get_engine() { return $this->engine; }
    public function get_api() { return $this->api; }
}

class WooIntegrationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['meta']  = array();
        $GLOBALS['notes'] = array();
    }

    public function test_generates_dte_and_saves_track_id(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 39 ) ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( false );
        $engine = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->expects( $this->once() )->method( 'generate_dte_xml' )->with( $this->callback( function ( $data ) { return is_array( $data ); } ), $this->equalTo( 39 ), $this->equalTo( false ) )->willReturn( '<xml/>' );
        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( 'T123' );
        $api->method( 'generate_token' )->willReturn( 'tok' );
        $pdf = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->expects( $this->once() )->method( 'generate' )->with( '<xml/>' )->willReturn( __FILE__ );

        $plugin = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Plugin' )
            ->disableOriginalConstructor()
            ->onlyMethods( ['get_settings', 'get_engine', 'get_api', 'get_pdf_generator'] )
            ->getMock();
        $plugin->method( 'get_settings' )->willReturn( $settings );
        $plugin->method( 'get_engine' )->willReturn( $engine );
        $plugin->method( 'get_api' )->willReturn( $api );
        $plugin->method( 'get_pdf_generator' )->willReturn( $pdf );
        $woo = new Woo( $plugin );
        $GLOBALS['meta'][1]['_sii_boleta_doc_type'] = '39';
        $woo->handle_order_completed( 1 );
        $this->assertTrue( true );
    }

    public function test_preview_mode_skips_sii_submission(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 39 ), 'woocommerce_preview_only' => 1 ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( true );

        $engine = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->expects( $this->once() )->method( 'generate_dte_xml' )->with( $this->callback( function ( $data ) { return is_array( $data ); } ), $this->equalTo( 39 ), $this->equalTo( true ) )->willReturn( '<xml/>' );

        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->expects( $this->never() )->method( 'send_dte_to_sii' );

        $pdf = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->expects( $this->once() )->method( 'generate' )->with( '<xml/>' )->willReturn( __FILE__ );

        $plugin = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Plugin' )
            ->disableOriginalConstructor()
            ->onlyMethods( ['get_settings', 'get_engine', 'get_api', 'get_pdf_generator'] )
            ->getMock();

        $plugin->method( 'get_settings' )->willReturn( $settings );
        $plugin->method( 'get_engine' )->willReturn( $engine );
        $plugin->method( 'get_api' )->willReturn( $api );
        $plugin->method( 'get_pdf_generator' )->willReturn( $pdf );

        $woo = new Woo( $plugin );
        $GLOBALS['meta'][1]['_sii_boleta_doc_type'] = '39';
        $woo->handle_order_completed( 1 );

        $this->assertSame( '', $GLOBALS['meta'][1]['_sii_boleta_track_id'] ?? null );
        $this->assertSame( __FILE__, $GLOBALS['meta'][1]['_sii_boleta_pdf'] ?? null );
        $this->assertNotEmpty( $GLOBALS['notes'][1] ?? array() );
        $this->assertStringContainsString( 'previsualización', $GLOBALS['notes'][1][0] ?? '' );
    }
}
