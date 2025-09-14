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
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( $s ); } }
if ( ! function_exists( 'sanitize_file_name' ) ) { function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9_\.\-]/', '', basename( $s ) ); } }
if ( ! function_exists( 'add_settings_error' ) ) { function add_settings_error() {} }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return $s; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b, $c ) { return $a == $b ? 'selected' : ''; } }

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

    public function test_sanitize_settings(): void {
        $page = new SettingsPage( new Settings() );
        $input = array(
            'rut_emisor'    => ' 11-1 ',
            'cert_pass'     => 'secret',
            'cert_path'     => '../cert.pfx',
            'caf_paths'     => array( '33' => "../caf.xml\n../caf2.xml" ),
            'environment'   => '2',
            'enabled_types' => array( '33' => 1, '39' => 1 ),
            'pdf_logo'      => '../logo.png',
            'pdf_show_logo' => '1',
            'enable_logging'=> '1',
        );
        $clean = $page->sanitize_settings( $input );
        $this->assertSame( '11-1', $clean['rut_emisor'] );
        $this->assertSame( 'cert.pfx', $clean['cert_path'] );
        $this->assertSame( array( 33 => array( 'caf.xml', 'caf2.xml' ) ), $clean['caf_paths'] );
        $this->assertSame( 2, $clean['environment'] );
        $this->assertSame( array( 33, 39 ), $clean['enabled_types'] );
        $this->assertSame( 'secret', Settings::decrypt( $clean['cert_pass'] ) );
        $this->assertSame( 'logo.png', $clean['pdf_logo'] );
        $this->assertSame( 1, $clean['pdf_show_logo'] );
        $this->assertSame( 1, $clean['enable_logging'] );
    }
}
