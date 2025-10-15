<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\Ajax;

if ( ! defined( 'SII_BOLETA_DTE_TESTING' ) ) {
    define( 'SII_BOLETA_DTE_TESTING', true );
}

// Reuse shims from previous test file if not loaded.
if ( ! function_exists( 'check_ajax_referer' ) ) { function check_ajax_referer() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_send_json_success' ) ) { function wp_send_json_success( $d=null ){ throw new RuntimeException('success:'.json_encode($d)); } }
if ( ! function_exists( 'wp_send_json_error' ) ) { function wp_send_json_error( $d=null ){ throw new RuntimeException('error:'.json_encode($d)); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field($s){return is_string($s)?trim($s):'';} }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash($s){return $s;} }
if ( ! function_exists( '__' ) ) { function __($t,$d=null){return $t;} }
if ( ! function_exists( 'esc_html' ) ) { function esc_html($t){ return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); } }

class AjaxValidateEnvioBoletaTest extends TestCase {
    public function test_envio_boleta_uses_envio_boleta_schema() {
        $ajax = new Ajax( $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Plugin' ) );
        // Minimal boleta DTE snippet (TipoDTE 39)
        $_POST['tipo'] = '39';
        $_POST['xml'] = '<DTE xmlns="http://www.sii.cl/SiiDte"><Documento><Encabezado><IdDoc><TipoDTE>39</TipoDTE><Folio>1</Folio><FchEmis>2025-10-07</FchEmis></IdDoc></Encabezado></Documento></DTE>';
        try {
            $ajax->validate_envio();
            $this->fail('Should throw success capture');
        } catch ( RuntimeException $e ) {
            $this->assertStringStartsWith('success:', $e->getMessage());
        }
    }
}
