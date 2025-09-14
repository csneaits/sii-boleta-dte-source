<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\ControlPanelPage;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field() {} }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }

class ControlPanelPageTest extends TestCase {
    protected function setUp(): void {
        QueueDb::purge();
    }

    public function test_render_shows_queue_items(): void {
        $id = QueueDb::enqueue( 'dte', array( 'file' => 'x' ) );
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array() ) );
        $folio = $this->createMock( FolioManager::class );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $page = new ControlPanelPage( $settings, $folio, $queue );
        ob_start();
        $page->render_page();
        $html = ob_get_clean();
        $this->assertStringContainsString( (string) $id, $html );
    }

    public function test_cancel_removes_job(): void {
        $id = QueueDb::enqueue( 'dte', array( 'file' => 'x' ) );
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $folio = $this->createMock( FolioManager::class );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $page = new ControlPanelPage( $settings, $folio, $queue );
        $_POST['sii_boleta_queue_nonce'] = 'x';
        $page->handle_queue_action( 'cancel', $id );
        $this->assertCount( 0, QueueDb::get_pending_jobs() );
    }

    public function test_requeue_resets_attempts(): void {
        $id = QueueDb::enqueue( 'dte', array( 'file' => 'x' ) );
        QueueDb::increment_attempts( $id );
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $folio = $this->createMock( FolioManager::class );
        $queue = $this->getMockBuilder( Queue::class )->disableOriginalConstructor()->getMock();
        $page = new ControlPanelPage( $settings, $folio, $queue );
        $_POST['sii_boleta_queue_nonce'] = 'x';
        $page->handle_queue_action( 'requeue', $id );
        $jobs = QueueDb::get_pending_jobs();
        $this->assertSame( 0, $jobs[0]['attempts'] );
    }
}
