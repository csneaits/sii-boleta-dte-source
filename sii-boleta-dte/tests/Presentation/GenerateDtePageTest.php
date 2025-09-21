<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Application\FolioManager;

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

class GenerateDtePageTest extends TestCase {
    public function test_process_post_generates_dte(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array(
            'environment' => 'test',
            'giro'        => 'Principal',
            'giros'       => array( 'Principal', 'Secundario' ),
        ) );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( '123' );
        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->once() )
            ->method( 'generate_dte_xml' )
            ->with(
                $this->callback( function ( $data ) {
                    $this->assertSame( 'Secundario', $data['Encabezado']['Emisor']['GiroEmisor'] ?? '' );
                    return true;
                } ),
                39,
                false
            )
            ->willReturn( '<xml/>' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( '/tmp/test.pdf' );
        $folio = $this->createMock( FolioManager::class );
        $folio->method( 'get_next_folio' )->willReturn( 1 );
        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio );
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
            'emisor_giro' => 'Secundario',
        ) );
        $this->assertSame( '123', $result['track_id'] );
        $this->assertSame( '/tmp/test.pdf', $result['pdf'] );
    }

    public function test_process_post_invalid_nonce(): void {
        $settings = $this->createMock( Settings::class );
        $token = $this->createMock( TokenManager::class );
        $api = $this->createMock( Api::class );
        $engine = $this->createMock( DteEngine::class );
        $pdf = $this->createMock( PdfGenerator::class );
        $folio = $this->createMock( FolioManager::class );
        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio );
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
                'rut_emisor'  => '78.103.459-2',
                'giro'        => 'Principal',
                'giros'       => array( 'Principal' ),
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

        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio );
        $result = $page->process_post(
            array(
                'sii_boleta_generate_dte_nonce' => 'good',
                'preview' => '1',
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
            )
        );

        $this->assertArrayHasKey( 'pdf_url', $result );
        $this->assertNotEmpty( $result['pdf_url'] );
        $query = array();
        parse_str( (string) parse_url( (string) $result['pdf_url'], PHP_URL_QUERY ), $query );
        $this->assertArrayHasKey( 'key', $query );
        $this->assertSame( 'boleta-n0-78103459-2.pdf', $query['key'] );

        $uploads = wp_upload_dir();
        $stored = rtrim( (string) $uploads['basedir'], '/\\' ) . '/sii-boleta-dte/previews/' . $query['key'];
        if ( file_exists( $stored ) ) {
            unlink( $stored );
        }
        if ( file_exists( $tmpPdf ) ) {
            unlink( $tmpPdf );
        }
    }
}
