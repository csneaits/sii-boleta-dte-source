<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Rest\Api;

// Stub WordPress functions and classes.
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return $response['response']['code'] ?? 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return $response['body'] ?? '';
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = array() ) {
        $GLOBALS['wp_remote_get_calls']++;
        if ( ! isset( $GLOBALS['wp_remote_get_queue'] ) ) {
            $GLOBALS['wp_remote_get_queue'] = array();
        }
        $queue = &$GLOBALS['wp_remote_get_queue'];
        if ( empty( $queue ) ) {
            return new WP_Error( 'empty_queue', '' );
        }
        return array_shift( $queue );
    }
}
if ( ! function_exists( 'sii_boleta_write_log' ) ) {
    function sii_boleta_write_log( $msg ) {}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['wp_remote_post_calls']++;
        if ( ! isset( $GLOBALS['wp_remote_post_queue'] ) ) {
            $GLOBALS['wp_remote_post_queue'] = [];
        }
        $queue = &$GLOBALS['wp_remote_post_queue'];
        if ( empty( $queue ) ) {
            return new WP_Error( 'empty_queue', '' );
        }
        return array_shift( $queue );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) { return date( 'Y-m-d H:i:s' ); }
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
    class DummyWPDB {
        public $prefix = '';
        public function insert() {}
        public function get_charset_collate() { return ''; }
        public function prepare( $query, $limit ) { return $query; }
        public function get_results( $sql, $output ) { return []; }
    }
    $GLOBALS['wpdb'] = new DummyWPDB();
}

class ApiFlowTest extends TestCase {
    private function create_temp_xml() {
        $file = tempnam( sys_get_temp_dir(), 'dte' );
        file_put_contents( $file, '<xml></xml>' );
        return $file;
    }

    public function test_send_dte_returns_track_id_on_success() {
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [
            [ 'response' => [ 'code' => 200 ], 'body' => '{"trackId":"123"}' ],
        ];
        $file = $this->create_temp_xml();
        $api  = new Api();
        $tid  = $api->send_dte_to_sii( $file, 'test', 'token' );
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
        $this->assertSame( '123', $tid );
        $this->assertSame( 1, $GLOBALS['wp_remote_post_calls'] );
    }

    public function test_send_dte_returns_error_on_rejection() {
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [
            [ 'response' => [ 'code' => 200 ], 'body' => '{"codigo":1,"mensaje":"Rechazo"}' ],
        ];
        $file = $this->create_temp_xml();
        $api  = new Api();
        $res  = $api->send_dte_to_sii( $file, 'test', 'token' );
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
        $this->assertTrue( is_wp_error( $res ) );
        $this->assertSame( 'sii_boleta_rechazo', $res->get_error_code() );
        $this->assertSame( 1, $GLOBALS['wp_remote_post_calls'] );
    }

    public function test_send_dte_retries_and_succeeds() {
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [
            new WP_Error( 'timeout', 'timeout' ),
            [ 'response' => [ 'code' => 200 ], 'body' => '{"trackId":"456"}' ],
        ];
        $file = $this->create_temp_xml();
        $api  = new Api();
        $tid  = $api->send_dte_to_sii( $file, 'test', 'token' );
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
        $this->assertSame( '456', $tid );
        $this->assertSame( 2, $GLOBALS['wp_remote_post_calls'] );
    }

    public function test_send_libro_returns_track_id() {
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [
            [ 'response' => [ 'code' => 200 ], 'body' => '<resp><trackId>789</trackId></resp>' ],
        ];
        $api  = new Api();
        $res  = $api->send_libro_to_sii( '<xml/>', 'test', 'token' );
        $this->assertIsArray( $res );
        $this->assertSame( '789', $res['trackId'] );
        $this->assertSame( 1, $GLOBALS['wp_remote_post_calls'] );
    }

    public function test_send_libro_returns_error_on_http_failure() {
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [
            [ 'response' => [ 'code' => 500 ], 'body' => 'err' ],
            [ 'response' => [ 'code' => 500 ], 'body' => 'err' ],
            [ 'response' => [ 'code' => 500 ], 'body' => 'err' ],
        ];
        $api  = new Api();
        $res  = $api->send_libro_to_sii( '<xml/>', 'test', 'token' );
        $this->assertTrue( is_wp_error( $res ) );
        $this->assertSame( 'sii_boleta_libro_http_error', $res->get_error_code() );
        $this->assertSame( 3, $GLOBALS['wp_remote_post_calls'] );
    }

    public function test_get_dte_status_returns_status() {
        $GLOBALS['wp_remote_get_calls'] = 0;
        $GLOBALS['wp_remote_get_queue'] = [ [ 'response' => [ 'code' => 200 ], 'body' => '{"status":"accepted"}' ] ];
        $api    = new Api();
        $status = $api->get_dte_status( '123', 'test', 'token' );
        $this->assertSame( 'accepted', $status );
        $this->assertSame( 1, $GLOBALS['wp_remote_get_calls'] );
    }

    public function test_custom_retry_limit() {
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [ new WP_Error( 'timeout', '' ), new WP_Error( 'timeout', '' ) ];
        $file = $this->create_temp_xml();
        $api  = new Api( null, 2 );
        $res  = $api->send_dte_to_sii( $file, 'test', 'token' );
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
        $this->assertTrue( is_wp_error( $res ) );
        $this->assertSame( 2, $GLOBALS['wp_remote_post_calls'] );
    }
}
