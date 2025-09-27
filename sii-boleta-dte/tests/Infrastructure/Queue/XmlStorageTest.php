<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;

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

class XmlStorageTest extends TestCase {
    public function test_store_creates_private_directory_and_random_filename(): void {
        $source = tempnam( sys_get_temp_dir(), 'xml' );
        file_put_contents( $source, '<xml></xml>' );

        $stored = XmlStorage::store( $source );

        $this->assertNotSame( '', $stored['key'] );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{16,}$/', $stored['key'] );
        $this->assertFileExists( $stored['path'] );

        $expected_dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/sii-boleta-dte/private/xml';
        $this->assertStringStartsWith( $expected_dir, $stored['path'] );
        $this->assertFileExists( $expected_dir . '/.htaccess' );
        $this->assertFileExists( $expected_dir . '/index.php' );

        $htaccess = file_get_contents( $expected_dir . '/.htaccess' );
        $this->assertStringContainsString( 'Deny from all', $htaccess );

        $index = file_get_contents( $expected_dir . '/index.php' );
        $this->assertStringContainsString( 'exit', $index );

        @unlink( $stored['path'] );
    }
}
