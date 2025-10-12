<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;

if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s ) { return $s; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field() {} }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce() { return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data ) { return json_encode( $data ); } }
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'http://example.com/uploads',
        );
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir = '' ) {
        if ( '' !== $dir && ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        return $dir;
    }
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content' );
}
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce() { return 'nonce'; } }
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.com/' . ltrim( $path, '/' );
    }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url ) {
        return $url . '?' . http_build_query( $args );
    }
}
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct( $code = '', $message = '', $data = null ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
        public function get_error_data( $code = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            return $this->data;
        }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ) { return $thing instanceof WP_Error; } }

class GenerateDtePageTest extends TestCase {
    public function test_process_post_generates_dte(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Principal',
                'giros'       => array( 'Principal', 'Alternativo' ),
            )
        );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( '123' );
        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->exactly( 2 ) )
            ->method( 'generate_dte_xml' )
            ->willReturnCallback( function ( $data, $document_type, $is_preview ) {
                static $call = 0;

                if ( 0 === $call ) {
                    $this->assertSame( 39, $document_type );
                    $this->assertTrue( $is_preview );
                    $this->assertSame( 0, $data['Folio'] );
                    $this->assertSame( 'Giro', $data['Receptor']['GiroRecep'] );
                    $call++;
                    return '<xml/>';
                }

                if ( 1 === $call ) {
                    $this->assertSame( 39, $document_type );
                    $this->assertFalse( $is_preview );
                    $this->assertSame( 1, $data['Folio'] );
                    $this->assertSame( 1, $data['Encabezado']['IdDoc']['Folio'] );
                    $this->assertSame( 'Mi Giro', $data['Encabezado']['Emisor']['GiroEmisor'] ?? '' );
                    $this->assertSame( 'Mi Giro', $data['Encabezado']['Emisor']['GiroEmis'] ?? '' );
                    $call++;
                    return '<xml/>';
                }

                $this->fail( 'generate_dte_xml called more than expected' );
            } );
        $tmpPdf = tempnam( sys_get_temp_dir(), 'pdf' );
        file_put_contents( $tmpPdf, '%PDF fake' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( $tmpPdf );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->once() )->method( 'get_next_folio' )->with( 39, false )->willReturn( 1 );
        $folio->expects( $this->once() )->method( 'mark_folio_used' )->with( 39, 1 )->willReturn( true );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $queue->expects( $this->never() )->method( 'enqueue_dte' );
        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array(
            'sii_boleta_generate_dte_nonce' => 'good',
            'rut' => '1-9',
            'razon' => 'Cliente',
            'giro' => 'Giro',
            'giro_emisor' => 'Mi Giro',
            'items' => array(
                array(
                    'desc' => 'Item',
                    'qty' => 1,
                    'price' => 1000,
                ),
            ),
            'tipo' => '39',
        ) );
        $this->assertSame( '123', $result['track_id'] );
        $this->assertSame( $tmpPdf, $result['pdf'] );
        $this->assertSame( 'success', $result['notice_type'] );
    }

    public function test_process_post_handles_stale_folio_error(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Principal',
                'giros'       => array( 'Principal', 'Alternativo' ),
            )
        );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->expects( $this->never() )->method( 'send_dte_to_sii' );
        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->exactly( 2 ) )
            ->method( 'generate_dte_xml' )
            ->willReturnOnConsecutiveCalls( '<xml/>', '<xml/>' );
        $tmpPdf = sys_get_temp_dir() . '/test.pdf';
        file_put_contents( $tmpPdf, '%PDF fake' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( $tmpPdf );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->once() )->method( 'get_next_folio' )->with( 39, false )->willReturn( 5 );
        $folio->expects( $this->once() )->method( 'mark_folio_used' )->with( 39, 5 )->willReturn( false );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $queue->expects( $this->never() )->method( 'enqueue_dte' );

        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array(
            'sii_boleta_generate_dte_nonce' => 'good',
            'rut' => '1-9',
            'razon' => 'Cliente',
            'giro' => 'Giro',
            'items' => array(
                array(
                    'desc' => 'Item',
                    'qty' => 1,
                    'price' => 1000,
                ),
            ),
            'tipo' => '39',
        ) );

        $this->assertArrayHasKey( 'error', $result );

        if ( file_exists( $tmpPdf ) ) {
            unlink( $tmpPdf );
        }
    }

    public function test_process_post_does_not_fetch_folio_when_xml_generation_fails(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Principal',
            )
        );

        $token = $this->createMock( TokenManager::class );
        $api   = $this->createMock( Api::class );
        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->once() )
            ->method( 'generate_dte_xml' )
            ->with(
                $this->callback( function ( $data ) {
                    $this->assertSame( 0, $data['Folio'] );
                    return true;
                } ),
                39,
                true
            )
            ->willReturn( new WP_Error( 'sii_boleta_invalid_caf', 'Invalid CAF' ) );

        $pdf   = $this->createMock( PdfGenerator::class );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->never() )->method( 'get_next_folio' );
        $folio->expects( $this->never() )->method( 'mark_folio_used' );

        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();

        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array(
            'sii_boleta_generate_dte_nonce' => 'good',
            'rut' => '1-9',
            'razon' => 'Cliente',
            'giro' => 'Giro',
            'items' => array(
                array(
                    'desc' => 'Item',
                    'qty' => 1,
                    'price' => 1000,
                ),
            ),
            'tipo' => '39',
        ) );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertSame( 'Invalid CAF', $result['error'] );
    }

    public function test_process_post_invalid_nonce(): void {
        $settings = $this->createMock( Settings::class );
        $token = $this->createMock( TokenManager::class );
        $api = $this->createMock( Api::class );
        $engine = $this->createMock( DteEngine::class );
        $pdf = $this->createMock( PdfGenerator::class );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->never() )->method( 'get_next_folio' );
        $folio->expects( $this->never() )->method( 'mark_folio_used' );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array() );
        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_preview_pdf_uses_descriptive_filename(): void {
        $tmpPdf = tempnam( sys_get_temp_dir(), 'pdf' );
        file_put_contents( $tmpPdf, '%PDF-1.4 fake content' );

        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Principal',
                'rut_emisor'  => '78.103.459-2',
            )
        );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->method( 'send_dte_to_sii' )->willReturn( '123' );
        $engine = $this->createMock( DteEngine::class );
        $engine->method( 'generate_dte_xml' )->willReturn( '<xml/>' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( $tmpPdf );
        $folio = $this->createMock( FolioManager::class );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();

        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post(
            array(
                'sii_boleta_generate_dte_nonce' => 'good',
                'preview' => '1',
                'rut' => '1-9',
                'razon' => 'Cliente',
                'giro' => 'Giro',
                'giro_emisor' => 'Mi Giro',
                'items' => array(
                    array(
                        'desc' => 'Item',
                        'qty' => 1,
                        'price' => 1000,
                    ),
                ),
                'tipo' => '39',
            )
        );

        $this->assertArrayHasKey( 'pdf_url', $result );
        $this->assertNotEmpty( $result['pdf_url'] );
        $query = array();
        parse_str( (string) parse_url( (string) $result['pdf_url'], PHP_URL_QUERY ), $query );
        $this->assertArrayHasKey( 'key', $query );
        $this->assertSame( 'boleta-n0-78103459-2.pdf', $query['key'] );
        $this->assertSame( '1', $query['preview'] ?? null );

        $uploads = wp_upload_dir();
        $stored = rtrim( (string) $uploads['basedir'], '/\\' ) . '/sii-boleta-dte/previews/' . $query['key'];
        $this->assertFalse( file_exists( $stored ) );
        $this->assertSame( $tmpPdf, GenerateDtePage::resolve_preview_path( $query['key'] ) );

        GenerateDtePage::clear_preview_path( $query['key'] );

        if ( file_exists( $tmpPdf ) ) {
            unlink( $tmpPdf );
        }
    }

    public function test_process_post_uses_default_emitter_giro(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Configurado',
                'giros'       => array( 'Configurado', 'Extra' ),
            )
        );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( '123' );
        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->exactly( 2 ) )
            ->method( 'generate_dte_xml' )
            ->willReturnCallback( function ( $data, $document_type, $is_preview ) {
                static $call = 0;

                if ( 0 === $call ) {
                    $this->assertSame( 39, $document_type );
                    $this->assertTrue( $is_preview );
                    $this->assertSame( 0, $data['Folio'] );
                    $call++;
                    return '<xml/>';
                }

                if ( 1 === $call ) {
                    $this->assertSame( 39, $document_type );
                    $this->assertFalse( $is_preview );
                    $this->assertSame( 1, $data['Folio'] );
                    $this->assertSame( 1, $data['Encabezado']['IdDoc']['Folio'] );
                    $this->assertSame( 'Configurado', $data['Encabezado']['Emisor']['GiroEmisor'] ?? '' );
                    $this->assertSame( 'Configurado', $data['Encabezado']['Emisor']['GiroEmis'] ?? '' );
                    $call++;
                    return '<xml/>';
                }

                $this->fail( 'generate_dte_xml called more than expected' );
            } );
        $tmpPdf = sys_get_temp_dir() . '/test.pdf';
        file_put_contents( $tmpPdf, '%PDF fake' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( $tmpPdf );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->once() )->method( 'get_next_folio' )->with( 39, false )->willReturn( 1 );
        $folio->expects( $this->once() )->method( 'mark_folio_used' )->with( 39, 1 )->willReturn( true );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array(
            'sii_boleta_generate_dte_nonce' => 'good',
            'rut' => '1-9',
            'razon' => 'Cliente',
            'giro' => 'Giro Cliente',
            'items' => array(
                array(
                    'desc' => 'Item',
                    'qty' => 1,
                    'price' => 1000,
                ),
            ),
            'tipo' => '39',
        ) );
        $this->assertSame( '123', $result['track_id'] );

        $this->assertArrayHasKey( 'pdf_url', $result );
        $this->assertNotSame( '', $result['pdf_url'] );
        $query = array();
        parse_str( (string) parse_url( (string) $result['pdf_url'], PHP_URL_QUERY ), $query );
        $this->assertSame( '1', $query['manual'] ?? null );
        $this->assertArrayHasKey( 'token', $query );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{16,}$/', (string) ( $query['key'] ?? '' ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{16,}$/', (string) ( $query['token'] ?? '' ) );

        $entry = GenerateDtePage::resolve_manual_pdf( (string) $query['key'] );
        $this->assertNotNull( $entry );
        if ( null !== $entry ) {
            $stored_path = (string) $entry['path'];
            GenerateDtePage::clear_manual_pdf( (string) $query['key'] );
            if ( file_exists( $stored_path ) ) {
                unlink( $stored_path );
            }
        } else {
            GenerateDtePage::clear_manual_pdf( (string) $query['key'] );
        }

        if ( file_exists( $tmpPdf ) ) {
            unlink( $tmpPdf );
        }
    }

    public function test_process_post_queues_when_sii_unavailable(): void {
        LogDb::purge();
        LogDb::install();
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Principal',
                'rut_emisor'  => '11.111.111-1',
            )
        );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( new WP_Error( 'sii_boleta_http_error', 'HTTP 500' ) );
        $engine = $this->createMock( DteEngine::class );
        $engine->method( 'generate_dte_xml' )->willReturn( '<xml/>' );
        $tmpPdf = tempnam( sys_get_temp_dir(), 'pdf' );
        file_put_contents( $tmpPdf, '%PDF fake' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( $tmpPdf );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->once() )->method( 'get_next_folio' )->with( 39, false )->willReturn( 10 );
        $folio->expects( $this->once() )->method( 'mark_folio_used' )->with( 39, 10 )->willReturn( true );

        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $queue->expects( $this->once() )->method( 'enqueue_dte' )->with(
            $this->callback( function ( $file ) {
                $this->assertIsString( $file );
                $this->assertNotSame( '', $file );
                $this->assertStringContainsString( 'sii-boleta-dte/private/xml/', $file );
                $this->assertFileExists( $file );
                return true;
            } ),
            'test',
            'tok',
            $this->callback( function ( $key ) {
                $this->assertIsString( $key );
                $this->assertNotSame( '', $key );
                $this->assertMatchesRegularExpression( '/^[a-f0-9]{16,}$/', $key );
                return true;
            } )
        );

        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array(
            'sii_boleta_generate_dte_nonce' => 'good',
            'rut' => '1-9',
            'razon' => 'Cliente',
            'giro' => 'Giro',
            'items' => array(
                array(
                    'desc' => 'Item',
                    'qty' => 1,
                    'price' => 1000,
                ),
            ),
            'tipo' => '39',
        ) );

        $this->assertArrayHasKey( 'queued', $result );
        $this->assertTrue( $result['queued'] );
        $this->assertSame( 'warning', $result['notice_type'] );
        $this->assertSame( 'El SII no respondió. El documento fue puesto en cola para un reintento automático.', $result['message'] );
        $this->assertSame( $tmpPdf, $result['pdf'] );
        $this->assertArrayHasKey( 'pdf_url', $result );
        $this->assertNotSame( '', $result['pdf_url'] );
        $query = array();
        parse_str( (string) parse_url( (string) $result['pdf_url'], PHP_URL_QUERY ), $query );
        $this->assertSame( '1', $query['manual'] ?? null );
        $entry = GenerateDtePage::resolve_manual_pdf( (string) ( $query['key'] ?? '' ) );
        $this->assertNotNull( $entry );
        if ( null !== $entry ) {
            $path = (string) $entry['path'];
            GenerateDtePage::clear_manual_pdf( (string) ( $query['key'] ?? '' ) );
            if ( file_exists( $path ) ) {
                unlink( $path );
            }
        }

        if ( file_exists( $tmpPdf ) ) {
            unlink( $tmpPdf );
        }

        $logs = LogDb::get_logs();
        $this->assertNotEmpty( $logs );
        $latest = $logs[0];
        $this->assertSame( 'queued', $latest['status'] );
    }

    public function test_process_post_dev_simulated_error_queues_document(): void {
        LogDb::purge();
        LogDb::install();
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'environment' => 'test',
                'giro'        => 'Principal',
                'rut_emisor'  => '11.111.111-1',
            )
        );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );

        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn(
            new WP_Error(
                'sii_boleta_dev_simulated_error',
                'Envío simulado con error desde ajustes de desarrollo.',
                array( 'trackId' => 'DTE-SIM-1234' )
            )
        );
        $engine = $this->createMock( DteEngine::class );
        $engine->method( 'generate_dte_xml' )->willReturn( '<xml/>' );
        $tmpPdf = tempnam( sys_get_temp_dir(), 'pdf' );
        file_put_contents( $tmpPdf, '%PDF fake' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( $tmpPdf );
        $folio = $this->createMock( FolioManager::class );
        $folio->expects( $this->once() )->method( 'get_next_folio' )->with( 39, false )->willReturn( 10 );
        $folio->expects( $this->once() )->method( 'mark_folio_used' )->with( 39, 10 )->willReturn( true );

        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $queue->expects( $this->once() )->method( 'enqueue_dte' );

        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $result = $page->process_post( array(
            'sii_boleta_generate_dte_nonce' => 'good',
            'rut' => '1-9',
            'razon' => 'Cliente',
            'giro' => 'Giro',
            'items' => array(
                array(
                    'desc' => 'Item',
                    'qty' => 1,
                    'price' => 1000,
                ),
            ),
            'tipo' => '39',
        ) );

        $this->assertArrayHasKey( 'queued', $result );
        $this->assertTrue( $result['queued'] );
        $this->assertSame( 'warning', $result['notice_type'] );
        $this->assertSame( 'Envío simulado con error. El documento fue puesto en cola para un reintento automático.', $result['message'] );

        if ( file_exists( $tmpPdf ) ) {
            unlink( $tmpPdf );
        }

        $logs = LogDb::get_logs();
        $this->assertNotEmpty( $logs );
        $first = $logs[0];
        $this->assertSame( 'queued', $first['status'] );
        $this->assertSame( 'DTE-SIM-1234', $first['track_id'] );
    }
}
