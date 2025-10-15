<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\LogsPage;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

if ( ! function_exists( 'add_submenu_page' ) ) { function add_submenu_page() {} }
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return $s; } }

class LogsPageTest extends TestCase {
    public function test_renders_table_with_entries(): void {
        LogDb::install();
        LogDb::add_entry( '1', 'sent', 'ok', '0' );
        $settings = new Settings();
        $page     = new LogsPage( $settings );
        ob_start();
        $page->render_page();
        $html = ob_get_clean();
        $this->assertStringContainsString( 'Track ID', $html );
        $this->assertStringContainsString( '1', $html );
    }
}
