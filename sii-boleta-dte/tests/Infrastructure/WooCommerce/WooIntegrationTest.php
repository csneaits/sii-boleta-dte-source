<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;

// Stub WP functions
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb ) {} }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'update_post_meta' ) ) { function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; } }
if ( ! function_exists( 'delete_post_meta' ) ) { function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); } }
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'https://example.com/wp-content/uploads',
        );
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) {
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0777, true );
        }
        return true;
    }
}
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = array(), $attachments = array() ) {
        $GLOBALS['mail'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
        return true;
    }
}
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

        public function get_billing_email() {
            return 'customer@example.com';
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
        $GLOBALS['mail']  = array();

        $storage_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/sii-boleta-dte/private';
        if ( is_dir( $storage_dir ) ) {
            $this->removeDirectory( $storage_dir );
        }
    }

    private function removeDirectory( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = scandir( $dir );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $this->removeDirectory( $path );
            } else {
                @unlink( $path );
            }
        }

        @rmdir( $dir );
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
        $pdf_path = $this->createTemporaryPdf();
        $pdf      = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->expects( $this->once() )->method( 'generate' )->with( '<xml/>' )->willReturn( $pdf_path );

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

        $this->assertSame( 'T123', $GLOBALS['meta'][1]['_sii_boleta_track_id'] ?? null );
        $this->assertArrayHasKey( '_sii_boleta_pdf_key', $GLOBALS['meta'][1] );
        $this->assertArrayHasKey( '_sii_boleta_pdf_nonce', $GLOBALS['meta'][1] );
        $key = $GLOBALS['meta'][1]['_sii_boleta_pdf_key'];
        $this->assertNotEmpty( $key );
        $this->assertNotEmpty( $GLOBALS['meta'][1]['_sii_boleta_pdf_nonce'] );
        $stored_path = \Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage::resolve_path( $key );
        $this->assertFileExists( $stored_path );
        $this->assertArrayNotHasKey( '_sii_boleta_pdf', $GLOBALS['meta'][1] );
        $this->assertArrayNotHasKey( '_sii_boleta_pdf_path', $GLOBALS['meta'][1] );
        $this->assertArrayNotHasKey( '_sii_boleta_pdf_url', $GLOBALS['meta'][1] );

        $this->assertNotEmpty( $GLOBALS['mail'] );
        $email = $GLOBALS['mail'][0];
        $this->assertSame( 'customer@example.com', $email['to'] );
        $this->assertNotEmpty( $email['attachments'][0] ?? '' );
        $this->assertFileExists( $email['attachments'][0] );
        $this->assertStringContainsString( 'Documento tributario electrónico', $email['subject'] );
        $this->assertStringContainsString( 'admin-ajax.php', $email['message'] );
    }

    public function test_preview_mode_skips_sii_submission(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 39 ), 'woocommerce_preview_only' => 1 ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( true );

        $engine = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->expects( $this->once() )->method( 'generate_dte_xml' )->with( $this->callback( function ( $data ) { return is_array( $data ); } ), $this->equalTo( 39 ), $this->equalTo( true ) )->willReturn( '<xml/>' );

        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->expects( $this->never() )->method( 'send_dte_to_sii' );

        $pdf_path = $this->createTemporaryPdf();
        $pdf      = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->expects( $this->once() )->method( 'generate' )->with( '<xml/>' )->willReturn( $pdf_path );

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
        $this->assertArrayHasKey( '_sii_boleta_pdf_key', $GLOBALS['meta'][1] );
        $this->assertArrayHasKey( '_sii_boleta_pdf_nonce', $GLOBALS['meta'][1] );
        $key        = $GLOBALS['meta'][1]['_sii_boleta_pdf_key'];
        $storedPath = \Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage::resolve_path( $key );
        $this->assertFileExists( $storedPath );
        $this->assertArrayNotHasKey( '_sii_boleta_pdf', $GLOBALS['meta'][1] );
        $this->assertArrayNotHasKey( '_sii_boleta_pdf_path', $GLOBALS['meta'][1] );
        $this->assertArrayNotHasKey( '_sii_boleta_pdf_url', $GLOBALS['meta'][1] );
        $this->assertNotEmpty( $GLOBALS['notes'][1] ?? array() );
        $this->assertStringContainsString( 'previsualización', $GLOBALS['notes'][1][0] ?? '' );
        $this->assertStringContainsString( 'admin-ajax.php', $GLOBALS['notes'][1][0] ?? '' );
        $this->assertNotEmpty( $GLOBALS['mail'] );
        $email = $GLOBALS['mail'][0];
        $this->assertStringContainsString( 'Previsualización', $email['subject'] );
        $this->assertNotEmpty( $email['attachments'][0] ?? '' );
        $this->assertFileExists( $email['attachments'][0] );
        $this->assertStringContainsString( 'admin-ajax.php', $email['message'] );
    }

    public function test_defaults_to_boleta_when_meta_missing(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 39 ) ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( false );

        $engine = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->expects( $this->once() )->method( 'generate_dte_xml' )->with( $this->anything(), 39, false )->willReturn( '<xml/>' );

        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( 'T999' );
        $api->method( 'generate_token' )->willReturn( 'tok' );

        $pdf_path = $this->createTemporaryPdf();
        $pdf      = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->expects( $this->once() )->method( 'generate' )->with( '<xml/>' )->willReturn( $pdf_path );

        $plugin = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Plugin' )
            ->disableOriginalConstructor()
            ->onlyMethods( ['get_settings', 'get_engine', 'get_api', 'get_pdf_generator'] )
            ->getMock();

        $plugin->method( 'get_settings' )->willReturn( $settings );
        $plugin->method( 'get_engine' )->willReturn( $engine );
        $plugin->method( 'get_api' )->willReturn( $api );
        $plugin->method( 'get_pdf_generator' )->willReturn( $pdf );

        $woo = new Woo( $plugin );
        $woo->handle_order_completed( 1 );

        $this->assertSame( '39', $GLOBALS['meta'][1]['_sii_boleta_doc_type'] ?? '' );
        $this->assertSame( 'T999', $GLOBALS['meta'][1]['_sii_boleta_track_id'] ?? null );
    }

    public function test_defaults_to_first_enabled_type_when_boleta_disabled(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 33 ) ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( false );

        $engine = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->expects( $this->once() )->method( 'generate_dte_xml' )->with( $this->anything(), 33, false )->willReturn( '<xml/>' );

        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( 'T100' );
        $api->method( 'generate_token' )->willReturn( 'tok' );

        $pdf_path = $this->createTemporaryPdf();
        $pdf      = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->expects( $this->once() )->method( 'generate' )->with( '<xml/>' )->willReturn( $pdf_path );

        $plugin = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Plugin' )
            ->disableOriginalConstructor()
            ->onlyMethods( ['get_settings', 'get_engine', 'get_api', 'get_pdf_generator'] )
            ->getMock();

        $plugin->method( 'get_settings' )->willReturn( $settings );
        $plugin->method( 'get_engine' )->willReturn( $engine );
        $plugin->method( 'get_api' )->willReturn( $api );
        $plugin->method( 'get_pdf_generator' )->willReturn( $pdf );

        $woo = new Woo( $plugin );
        $woo->handle_order_completed( 2 );

        $this->assertSame( '33', $GLOBALS['meta'][2]['_sii_boleta_doc_type'] ?? '' );
        $this->assertSame( 'T100', $GLOBALS['meta'][2]['_sii_boleta_track_id'] ?? null );
    }

    public function test_renders_pdf_link_in_my_account(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 39 ) ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( false );

        $engine = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->method( 'generate_dte_xml' )->willReturn( '<xml/>' );

        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->method( 'send_dte_to_sii' )->willReturn( 'T555' );
        $api->method( 'generate_token' )->willReturn( 'tok' );

        $pdf_path = $this->createTemporaryPdf();
        $pdf      = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\PdfGenerator' );
        $pdf->method( 'generate' )->willReturn( $pdf_path );

        $plugin = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Plugin' )
            ->disableOriginalConstructor()
            ->onlyMethods( ['get_settings', 'get_engine', 'get_api', 'get_pdf_generator'] )
            ->getMock();

        $plugin->method( 'get_settings' )->willReturn( $settings );
        $plugin->method( 'get_engine' )->willReturn( $engine );
        $plugin->method( 'get_api' )->willReturn( $api );
        $plugin->method( 'get_pdf_generator' )->willReturn( $pdf );

        $woo = new Woo( $plugin );

        $GLOBALS['meta'][3]['_sii_boleta_doc_type'] = '39';
        $woo->handle_order_completed( 3 );

        ob_start();
        $woo->render_customer_pdf_download( new DummyOrder( 3 ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Descargar PDF del DTE', $output );
        $this->assertStringContainsString( 'https://example.com/wp-content/uploads/sii-boleta-dte/previews/', $output );
    }

    private function createTemporaryPdf(): string {
        $path = tempnam( sys_get_temp_dir(), 'woo_pdf_' );
        file_put_contents( $path, '%PDF-1.4 Fake content' );

        return $path;
    }
}
