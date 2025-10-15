<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\Admin\Ajax;
use Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage;

if ( ! defined( 'SII_BOLETA_DTE_TESTING' ) ) {
    define( 'SII_BOLETA_DTE_TESTING', true );
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ) {
        return ! empty( $GLOBALS['current_user_can'] );
    }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        return ! empty( $GLOBALS['is_user_logged_in'] );
    }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return isset( $GLOBALS['current_user_id'] ) ? (int) $GLOBALS['current_user_id'] : 0;
    }
}
if ( ! function_exists( 'wc_get_order' ) ) {
    class AjaxTestOrder {
        public function get_user_id() {
            return isset( $GLOBALS['wc_order_user_id'] ) ? (int) $GLOBALS['wc_order_user_id'] : 0;
        }
    }
    function wc_get_order( $id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        return new AjaxTestOrder();
    }
}
if ( ! function_exists( 'status_header' ) ) {
    function status_header( $code ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        $GLOBALS['status_header'] = (int) $code;
    }
}
if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        return $GLOBALS['meta'][ $id ][ $key ] ?? '';
    }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $id, $key, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        $GLOBALS['meta'][ $id ][ $key ] = $value;
    }
}

class AjaxViewPdfTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['meta']              = array();
        $GLOBALS['current_user_can']  = false;
        $GLOBALS['is_user_logged_in'] = false;
        $GLOBALS['current_user_id']   = 0;
        $GLOBALS['wc_order_user_id']  = 0;
        $GLOBALS['status_header']     = null;
        $_GET                         = array();
        $this->cleanStorage();
    }

    protected function tearDown(): void {
        parent::tearDown();
        $_GET = array();
        $this->cleanStorage();
    }

    public function test_download_fails_with_invalid_nonce(): void {
        $stored = $this->prepareOrderPdf( 10 );

        $GLOBALS['current_user_can']  = true;
        $GLOBALS['is_user_logged_in'] = true;
        $GLOBALS['current_user_id']   = 5;
        $GLOBALS['wc_order_user_id']  = 5;

        $_GET = array(
            'order_id' => 10,
            'key'      => $stored['key'],
            'nonce'    => 'invalid',
            'type'     => '_sii_boleta',
        );

        $ajax = new Ajax( $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Plugin' ) );

        try {
            $ajax->view_pdf();
            $this->fail( 'Expected unauthorized access to terminate the request.' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'terminated:403', $e->getMessage() );
        }
    }

    public function test_authorized_user_can_download_pdf(): void {
        $stored = $this->prepareOrderPdf( 42 );

        $GLOBALS['current_user_can']  = true;
        $GLOBALS['wc_order_user_id']  = 7;
        $GLOBALS['is_user_logged_in'] = true;
        $GLOBALS['current_user_id']   = 7;

        $_GET = array(
            'order_id' => 42,
            'key'      => $stored['key'],
            'nonce'    => $stored['nonce'],
            'type'     => '_sii_boleta',
        );

        $ajax = new Ajax( $this->createMock( 'Sii\\BoletaDte\\Infrastructure\\Plugin' ) );

        $this->assertTrue( function_exists( 'is_user_logged_in' ) );
        $this->assertTrue( function_exists( 'get_current_user_id' ) );
        $this->assertTrue( function_exists( 'wc_get_order' ) );
        $this->assertTrue( \is_user_logged_in() );
        $this->assertSame( 7, \get_current_user_id() );
        $this->assertSame( 7, \wc_get_order( 42 )->get_user_id() );

        $ref = new \ReflectionMethod( Ajax::class, 'user_can_view_pdf' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->invoke( $ajax, 42 ) );

        $output = $this->captureOutput( function () use ( $ajax ) {
            $ajax->view_pdf();
        } );

        $this->assertNotEmpty( $output );
        $this->assertStringContainsString( '%PDF', $output );
        $this->assertNull( $GLOBALS['status_header'] );
    }

    /**
     * @return array{key:string,nonce:string}
     */
    private function prepareOrderPdf( int $order_id ): array {
        $pdf_path = tempnam( sys_get_temp_dir(), 'pdf' );
        file_put_contents( $pdf_path, "%PDF test\n" );

        $stored = PdfStorage::store( $pdf_path );
        $GLOBALS['meta'][ $order_id ]['_sii_boleta_pdf_key']   = $stored['key'];
        $GLOBALS['meta'][ $order_id ]['_sii_boleta_pdf_nonce'] = $stored['nonce'];

        return $stored;
    }

    private function cleanStorage(): void {
        $dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/sii-boleta-dte/private';
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = scandir( $dir );
        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $this->removeDir( $path );
            } else {
                @unlink( $path );
            }
        }
    }

    private function removeDir( string $dir ): void {
        $entries = scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return;
        }
        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if ( is_dir( $path ) ) {
                $this->removeDir( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }

    /**
     * @param callable $callback
     */
    private function captureOutput( callable $callback ): string {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
