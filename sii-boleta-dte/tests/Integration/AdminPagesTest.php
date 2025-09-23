<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Presentation\Admin\ControlPanelPage;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Application\RvdManager;
use Sii\BoletaDte\Application\LibroBoletas;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s ) { return $s; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field() {} }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $s ) { echo $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $text = '' ) { echo '<button>' . $text . '</button>'; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = 0 ) { return $d; } }

class AdminPagesTest extends TestCase {
    protected function setUp(): void {
        QueueDb::purge();
    }

    public function test_generate_page_renders_success_message(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'environment' => 'test' ) );
        $token = $this->createMock( TokenManager::class );
        $token->method( 'get_token' )->willReturn( 'tok' );
        $api = $this->createMock( Api::class );
        $api->method( 'send_dte_to_sii' )->willReturn( '123' );
        $engine = $this->createMock( DteEngine::class );
        $engine->method( 'generate_dte_xml' )->willReturn( '<xml/>' );
        $pdf = $this->createMock( PdfGenerator::class );
        $pdf->method( 'generate' )->willReturn( '/tmp/test.pdf' );
        $folio = $this->createMock( FolioManager::class );
        $folio->method( 'get_next_folio' )->willReturn( 1 );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $page = new GenerateDtePage( $settings, $token, $api, $engine, $pdf, $folio, $queue );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array(
            'sii_boleta_generate_dte_nonce' => 'x',
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
        );
        ob_start();
        $page->render_page();
        $html = ob_get_clean();
        $this->assertStringContainsString( 'Track ID', $html );
    }

    public function test_control_panel_process_invokes_processor(): void {
        $id = QueueDb::enqueue( 'dte', array( 'file' => 'x' ) );
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array() ) );
        $folio = $this->createMock( FolioManager::class );
        $processor = $this->getMockBuilder( QueueProcessor::class )->disableOriginalConstructor()->getMock();
        $processor->expects( $this->once() )->method( 'process' )->with( $id );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array(
            'queue_action' => 'process',
            'job_id' => (string) $id,
            'sii_boleta_queue_nonce' => 'x',
        );
        $rvd = $this->createMock( RvdManager::class );
        $rvd->method( 'generate_xml' )->willReturn( '<ConsumoFolios />' );
        $rvd->method( 'validate_rvd_xml' )->willReturn( true );
        $libro = $this->createMock( LibroBoletas::class );
        $libro->method( 'validate_libro_xml' )->willReturn( true );
        $api_control = $this->createMock( Api::class );
        $token_manager = $this->createMock( TokenManager::class );
        $token_manager->method( 'get_token' )->willReturn( 'token' );
        ob_start();
        $page = new ControlPanelPage( $settings, $folio, $processor, $rvd, $libro, $api_control, $token_manager );
        $page->render_page();
        ob_get_clean();
    }
}
