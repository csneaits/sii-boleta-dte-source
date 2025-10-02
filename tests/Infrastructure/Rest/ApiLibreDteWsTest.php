<?php
declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Sii\BoletaDte\Infrastructure\Rest\Api;
    use Sii\BoletaDte\Infrastructure\Settings;

    // WP stubs (only if missing)
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error { private $code; private $message; public function __construct( $code = '', $message = '' ){ $this->code=$code; $this->message=$message; } public function get_error_message(){ return $this->message; } public function get_error_code(){ return $this->code; } }
    }
    if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ){ return $x instanceof WP_Error; } }
    if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ){ return $value; } }
    if ( ! function_exists( 'wp_remote_post' ) ) { function wp_remote_post( $url, $args = [] ){ $GLOBALS['wp_remote_post_calls']++; $q = &$GLOBALS['wp_remote_post_queue']; if ( empty( $q ) ) { return new WP_Error( 'empty_queue', '' ); } return array_shift( $q ); } }
    if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $url, $args = [] ){ $GLOBALS['wp_remote_get_calls']++; $q = &$GLOBALS['wp_remote_get_queue']; if ( empty( $q ) ) { return new WP_Error( 'empty_queue', '' ); } return array_shift( $q ); } }
    if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ){ return $r['response']['code'] ?? 0; } }
    if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ){ return $r['body'] ?? ''; } }
    if ( ! function_exists( 'sii_boleta_write_log' ) ) { function sii_boleta_write_log( $msg ) {} }
    if ( ! isset( $GLOBALS['wpdb'] ) ) { class DummyWPDB { public $prefix = ''; public function insert(){} public function get_charset_collate(){ return ''; } public function prepare( $q ){ return $q; } public function get_results(){ return []; } } $GLOBALS['wpdb'] = new DummyWPDB(); }

    // Dummy WS worker
    class DummySiiLazy { public $calls = []; public function consumeWebservice( $request, $service, $function, $args, $retries ){ $this->calls[] = func_get_args(); if ( $service === 'RecepcionRecibos' ) { return [ 'trackId' => 'WSTRACK' ]; } if ( $service === 'EstadoDTE' ) { return [ 'status' => 'accepted' ]; } return [ 'trackId' => 'WSDTE' ]; } }

    // Api subclass that injects the dummy WS worker
    class ApiWithWs extends Api { protected function resolve_sii_ws(){ return new DummySiiLazy(); } }

    class ApiLibreDteWsTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['wp_remote_post_calls'] = 0;
            $GLOBALS['wp_remote_get_calls'] = 0;
            $GLOBALS['wp_remote_post_queue'] = [];
            $GLOBALS['wp_remote_get_queue'] = [];
            $GLOBALS['wp_options'][ \Sii\BoletaDte\Infrastructure\Settings::OPTION_NAME ] = [ 'use_libredte_ws' => 1 ];
        }

        private function makeApiWithSettings(): ApiWithWs {
            $api = new ApiWithWs();
            $api->setSettings( new Settings() );
            return $api;
        }

        public function test_send_recibos_prefers_ws_and_maps_trackid() {
            $api = $this->makeApiWithSettings();
            $res = $api->send_recibos_to_sii( '<EnvioRecibos/>', '0', 'token' );
            $this->assertIsArray( $res );
            $this->assertSame( 'WSTRACK', $res['trackId'] );
            $this->assertSame( 0, $GLOBALS['wp_remote_post_calls'] );
        }

        public function test_get_status_prefers_ws_and_maps_status() {
            $api = $this->makeApiWithSettings();
            $status = $api->get_dte_status( '123', '0', 'token' );
            $this->assertSame( 'accepted', $status );
            $this->assertSame( 0, $GLOBALS['wp_remote_get_calls'] );
        }

        public function test_fallback_to_http_when_ws_disabled() {
            $GLOBALS['wp_options'][ \Sii\BoletaDte\Infrastructure\Settings::OPTION_NAME ] = [ 'use_libredte_ws' => 0 ];
            $api = $this->makeApiWithSettings();
            $GLOBALS['wp_remote_post_queue'] = [ [ 'response' => [ 'code' => 200 ], 'body' => '{"trackId":"HTTP123"}' ] ];
            $file = tempnam( sys_get_temp_dir(), 'dte' ); file_put_contents( $file, '<xml/>' );
            $tid = $api->send_dte_to_sii( $file, '0', 'token' );
            @unlink( $file );
            $this->assertSame( 'HTTP123', $tid );
            $this->assertSame( 1, $GLOBALS['wp_remote_post_calls'] );
        }
    }
}
