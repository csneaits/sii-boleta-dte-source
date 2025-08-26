<?php
use PHPUnit\Framework\TestCase;

// Stub WordPress functions needed by endpoints.
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $func ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $func ) { return $func; }
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
    function add_rewrite_rule() {}
}
if ( ! function_exists( 'get_query_var' ) ) {
    function get_query_var( $var ) {
        return $GLOBALS['query_var'][ $var ] ?? null;
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return [ 'basedir' => $GLOBALS['upload_dir'], 'baseurl' => 'http://example.com/uploads' ];
    }
}
if ( ! function_exists( 'status_header' ) ) {
    function status_header( $code ) { $GLOBALS['status_header_code'] = $code; }
}
if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $msg ) { throw new Exception( $msg ); }
}
if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {}
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text ) { return $text; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text ) { echo $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return $text; }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return $url; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
}

class EndpointsTest extends TestCase {
    public function test_get_boleta_html() {
        $temp = sys_get_temp_dir() . '/boleta-' . uniqid();
        mkdir( $temp );
        $GLOBALS['upload_dir'] = $temp;
        $xml = '<EnvioDTE><Documento><Encabezado><Emisor><RznSoc>Emit</RznSoc><RUTEmisor>111</RUTEmisor></Emisor><Receptor><RznSocRecep>Client</RznSocRecep><RUTRecep>222</RUTRecep></Receptor><IdDoc><FchEmis>2024-05-01</FchEmis></IdDoc><Totales><MntTotal>1000</MntTotal></Totales></Encabezado><Detalle><NmbItem>Item</NmbItem><QtyItem>1</QtyItem><PrcItem>1000</PrcItem><MontoItem>1000</MontoItem></Detalle></Documento></EnvioDTE>';
        file_put_contents( $temp . '/DTE_1_1_1.xml', $xml );
        $ep   = new SII_Boleta_Endpoints();
        $html = $ep->get_boleta_html( 1 );
        $this->assertIsString( $html );
        $this->assertStringContainsString( 'Emit', $html );
        $this->assertStringContainsString( 'Client', $html );
    }

    public function test_render_boleta_404_when_missing() {
        $temp = sys_get_temp_dir() . '/boleta-' . uniqid();
        mkdir( $temp );
        $GLOBALS['upload_dir'] = $temp;
        $GLOBALS['query_var']['sii_boleta_folio'] = 999;
        $ep = new SII_Boleta_Endpoints();
        try {
            $ep->render_boleta();
        } catch ( Exception $e ) {
            $this->assertEquals( 404, $GLOBALS['status_header_code'] );
            $this->assertStringContainsString( 'Boleta no encontrada', $e->getMessage() );
            return;
        }
        $this->fail( 'Se esperaba excepción 404.' );
    }
}
