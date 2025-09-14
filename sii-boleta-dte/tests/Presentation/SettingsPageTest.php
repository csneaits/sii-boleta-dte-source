<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\SettingsPage;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'settings_fields' ) ) { function settings_fields() {} }
if ( ! function_exists( 'do_settings_sections' ) ) { function do_settings_sections() {} }
if ( ! function_exists( 'submit_button' ) ) { function submit_button() {} }
if ( ! function_exists( 'register_setting' ) ) { function register_setting() {} }
if ( ! function_exists( 'add_settings_section' ) ) { function add_settings_section() {} }
if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field() {} }

class SettingsPageTest extends TestCase {
    public function test_render_outputs_field(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'rut_emisor' => '1-9' ) );
        $page = new SettingsPage( $settings );
        $page->register();
        ob_start();
        $page->render_page();
        $page->field_rut_emisor();
        $html = ob_get_clean();
        $this->assertStringContainsString( '1-9', $html );
    }
}
