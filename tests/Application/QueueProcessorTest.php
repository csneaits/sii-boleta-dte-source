<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

// WordPress stubs.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function __construct( public $code = '', public $message = '' ) {}
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $cb ) { $GLOBALS['added_actions'][ $hook ] = $cb; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['wp_remote_post_calls']++;
        $queue = &$GLOBALS['wp_remote_post_queue'];
        if ( empty( $queue ) ) {
            return new WP_Error( 'empty_queue', '' );
        }
        return array_shift( $queue );
    }
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
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir = '' ) {
        if ( '' !== $dir && ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        return $dir;
    }
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content' );
}
if ( ! function_exists( 'sii_boleta_write_log' ) ) {
    function sii_boleta_write_log( $msg ) {}
}

class QueueProcessorTest extends TestCase {
    protected function setUp(): void {
        QueueDb::purge();
        $GLOBALS['wpdb'] = null;
        $GLOBALS['wp_remote_post_calls'] = 0;
        $GLOBALS['wp_remote_post_queue'] = [];
    }

    private function create_temp_xml() {
        $file = tempnam( sys_get_temp_dir(), 'dte' );
        file_put_contents( $file, '<xml></xml>' );
        return $file;
    }

    public function test_process_executes_all_jobs() {
        $api       = new Api();
        $queue     = new Queue();
        $processor = new QueueProcessor( $api, static function(){} );
        $file     = $this->create_temp_xml();
        $queue->enqueue_dte( $file, 'test', 'token' );
        $queue->enqueue_libro( '<xml/>', 'test', 'token' );
        $queue->enqueue_rvd( '<ConsumoFolios/>', 'test', 'token' );

        $GLOBALS['wp_remote_post_queue'] = [
            [ 'response' => [ 'code' => 200 ], 'body' => '{"trackId":"1"}' ],
            [ 'response' => [ 'code' => 200 ], 'body' => '<resp><trackId>2</trackId></resp>' ],
            [ 'response' => [ 'code' => 200 ], 'body' => '{"trackId":"3"}' ],
        ];

        $processor->process();
        unlink( $file );

        $this->assertSame( 3, $GLOBALS['wp_remote_post_calls'] );
        $this->assertCount( 0, QueueDb::get_pending_jobs() );
    }

    public function test_process_resolves_secure_xml_paths() {
        $api = $this->getMockBuilder( Api::class )
            ->onlyMethods( array( 'send_dte_to_sii' ) )
            ->getMock();

        $processor = new QueueProcessor( $api, static function(){} );

        $temp = $this->create_temp_xml();
        $stored = XmlStorage::store( $temp );
        $this->assertNotSame( '', $stored['key'] );
        $this->assertFileExists( $stored['path'] );

        QueueDb::purge();
        QueueDb::enqueue( 'dte', array(
            'file'        => '/tmp/ignored.xml',
            'file_key'    => $stored['key'],
            'environment' => 'prod',
            'token'       => 'tok',
        ) );

        $api->expects( $this->once() )->method( 'send_dte_to_sii' )
            ->with( $stored['path'], 'prod', 'tok' )
            ->willReturn( 'track' );

        $processor->process();

        $this->assertCount( 0, QueueDb::get_pending_jobs() );
        @unlink( $stored['path'] );
    }
}
