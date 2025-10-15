<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\Ajax;

if ( ! defined( 'SII_BOLETA_DTE_TESTING' ) ) {
    define( 'SII_BOLETA_DTE_TESTING', true );
}

// Minimal shims for WP functions used in validation flow.
if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action, $query_arg = false, $die = true ) { return true; }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ) { return true; }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null ) { throw new RuntimeException( 'success:' . json_encode( $data ) ); }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null ) { throw new RuntimeException( 'error:' . json_encode( $data ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return is_string( $str ) ? trim( $str ) : ''; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $str ) { return $str; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( '__' ) ) { function __( $t,$d=null ){ return $t; } }

class AjaxValidateEnvioTest extends TestCase {
    public function test_validate_envio_success() {
        $ajax = new Ajax( $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Plugin' ) );
        $_POST['xml']  = '<DTE xmlns="http://www.sii.cl/SiiDte"><Documento><Encabezado><IdDoc><TipoDTE>39</TipoDTE></IdDoc></Encabezado></Documento></DTE>';
        $_POST['tipo'] = '39';
        try {
            $ajax->validate_envio();
            $this->fail( 'Expected RuntimeException to capture success.' );
        } catch ( RuntimeException $e ) {
            $this->assertStringStartsWith( 'success:', $e->getMessage() );
        }
    }

    public function test_validate_envio_error_with_invalid_xml() {
        $ajax = new Ajax( $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Plugin' ) );
        $_POST['xml']  = '<DTE><Broken></DTE>';
        $_POST['tipo'] = '39';
        try {
            $ajax->validate_envio();
            $this->fail( 'Expected RuntimeException to capture error.' );
        } catch ( RuntimeException $e ) {
            $this->assertStringStartsWith( 'error:', $e->getMessage() );
        }
    }
}
