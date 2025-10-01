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
        private $total = 100.0;
        private $total_tax = 0.0;
        private $items = array();
        private $refunds = array();
        private $status = 'cancelled';
        private $billing_first_name = 'John';
        private $billing_last_name = 'Doe';
        private $billing_phone = '+56900000000';
        private $billing_email = 'customer@example.com';
        private $billing_address = 'Demo 123';
        private $billing_city = 'Santiago';

        public function __construct( $id ) {
            $this->id = $id;
        }

        public function get_id() {
            return $this->id;
        }

        public function set_total( float $total ): void {
            $this->total = $total;
        }

        public function get_total() {
            return $this->total;
        }

        public function set_total_tax( float $tax ): void {
            $this->total_tax = $tax;
        }

        public function get_total_tax() {
            return $this->total_tax;
        }

        public function set_items( array $items ): void {
            $this->items = $items;
        }

        public function get_items() {
            return $this->items;
        }

        public function set_refunds( array $refunds ): void {
            $this->refunds = $refunds;
        }

        public function get_refunds() {
            return $this->refunds;
        }

        public function set_status( string $status ): void {
            $this->status = $status;
        }

        public function has_status( string $status ): bool {
            return $this->status === $status;
        }

        public function get_order_number() {
            return (string) $this->id;
        }

        public function get_user_id() {
            return isset( $GLOBALS['wc_order_user_id'] ) ? (int) $GLOBALS['wc_order_user_id'] : 0;
        }

        public function get_billing_email() {
            return $this->billing_email;
        }

        public function get_billing_phone() {
            return $this->billing_phone;
        }

        public function get_billing_address_1() {
            return $this->billing_address;
        }

        public function get_billing_city() {
            return $this->billing_city;
        }

        public function get_formatted_billing_full_name() {
            return trim( $this->billing_first_name . ' ' . $this->billing_last_name );
        }

        public function get_billing_first_name() {
            return $this->billing_first_name;
        }

        public function get_billing_last_name() {
            return $this->billing_last_name;
        }

        public function add_order_note( $message ) {
            $GLOBALS['notes'][ $this->id ][] = $message;
        }
    }

    class DummyRefundItem {
        private $name;
        private $total;
        private $tax;
        private $quantity;

        public function __construct( string $name, float $total, float $tax = 0.0, float $quantity = 1.0 ) {
            $this->name     = $name;
            $this->total    = $total;
            $this->tax      = $tax;
            $this->quantity = $quantity;
        }

        public function get_name() {
            return $this->name;
        }

        public function get_total() {
            return $this->total;
        }

        public function get_total_tax() {
            return $this->tax;
        }

        public function get_quantity() {
            return $this->quantity;
        }
    }

    class DummyRefund {
        private $id;
        private $items;
        private $amount;
        private $tax;
        private $reason;

        public function __construct( int $id, array $items, float $amount, float $tax = 0.0, string $reason = '' ) {
            $this->id     = $id;
            $this->items  = $items;
            $this->amount = $amount;
            $this->tax    = $tax;
            $this->reason = $reason;
        }

        public function get_id() {
            return $this->id;
        }

        public function get_items() {
            return $this->items;
        }

        public function get_amount() {
            return $this->amount;
        }

        public function get_total() {
            return -1 * $this->amount;
        }

        public function get_total_tax() {
            return -1 * $this->tax;
        }

        public function get_reason() {
            return $this->reason;
        }
    }

    function wc_get_order( $id ) {
        return $GLOBALS['wc_orders'][ $id ] ?? new DummyOrder( $id );
    }
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
        $GLOBALS['wc_orders'] = array();
        $_POST = array();

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
        $this->assertStringContainsString( 'admin-ajax.php?action=sii_boleta_dte_view_pdf', $output );
    }

    public function test_manual_credit_note_uses_partial_refund_data(): void {
        $settings = $this->getMockBuilder( 'Sii\\BoletaDte\\Infrastructure\\Settings' )->onlyMethods( ['get_settings', 'is_woocommerce_preview_only_enabled'] )->getMock();
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 39 ) ) );
        $settings->method( 'is_woocommerce_preview_only_enabled' )->willReturn( false );

        $captured = null;
        $engine   = $this->createMock( 'Sii\\BoletaDte\\Domain\\DteEngine' );
        $engine->expects( $this->once() )->method( 'generate_dte_xml' )->willReturnCallback(
            function ( $data, $document_type, $preview ) use ( &$captured ) {
                $captured = $data;
                return '<xml/>';
            }
        );

        $api = $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Rest\\Api' );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( 'TCREDIT' );
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

        $order_id = 55;
        $order    = new DummyOrder( $order_id );
        $order->set_status( 'cancelled' );
        $order->set_total( 100.0 );
        $order->set_total_tax( 19.0 );
        $order->set_items( array( new DummyRefundItem( 'Producto inicial', 81.0, 19.0, 1.0 ) ) );

        $refund_item = new DummyRefundItem( 'Producto inicial', 25.0, 5.0, 1.0 );
        $refund      = new DummyRefund( 77, array( $refund_item ), 30.0, 5.0, 'Cambio parcial' );
        $order->set_refunds( array( $refund ) );

        $GLOBALS['wc_orders'][ $order_id ]                   = $order;
        $GLOBALS['meta'][ $order_id ]['_sii_boleta_doc_type'] = '39';

        $_POST['sii_boleta_refund_id']     = '77';
        $_POST['sii_boleta_refund_reason'] = 'Producto defectuoso';
        $_POST['sii_boleta_refund_codref'] = '6';

        $woo->handle_manual_credit_note( $order );

        $this->assertIsArray( $captured );
        $this->assertSame( 61, $captured['Encabezado']['IdDoc']['TipoDTE'] ?? 0 );
        $this->assertCount( 1, $captured['Detalles'] ?? array() );
        $detail = $captured['Detalles'][0];
        $this->assertSame( 'Producto inicial', $detail['NmbItem'] );
        $this->assertSame( 30.0, $detail['MontoItem'] );
        $this->assertSame( 1.0, $detail['QtyItem'] );

        $totals = $captured['Encabezado']['Totales'] ?? array();
        $this->assertSame( 25.0, $totals['MntNeto'] ?? 0.0 );
        $this->assertSame( 5.0, $totals['IVA'] ?? 0.0 );
        $this->assertSame( 30.0, $totals['MntTotal'] ?? 0.0 );

        $reference = $captured['Referencia'][0] ?? array();
        $this->assertSame( 6, $reference['CodRef'] ?? 0 );
        $this->assertSame( 'Producto defectuoso', $reference['RazonRef'] ?? '' );

        $this->assertSame( 'Producto defectuoso', $GLOBALS['meta'][ $order_id ]['_sii_boleta_credit_note_reason'] ?? '' );
        $this->assertSame( '77', $GLOBALS['meta'][ $order_id ]['_sii_boleta_credit_note_refund_id'] ?? '' );
    }

    private function createTemporaryPdf(): string {
        $path = tempnam( sys_get_temp_dir(), 'woo_pdf_' );
        file_put_contents( $path, '%PDF-1.4 Fake content' );

        return $path;
    }
}
