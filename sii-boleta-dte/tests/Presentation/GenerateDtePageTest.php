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
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }

class GenerateDtePageTest extends TestCase {
    public function test_process_post_generates_dte(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'environment' => 'test' ) );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'send_dte_to_sii' )->willReturn( '123' );
        $engine = $this->createMock( DteEngine::class );
        $engine->method( 'generate_dte_xml' )->willReturn( '<xml/>' );
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
}
