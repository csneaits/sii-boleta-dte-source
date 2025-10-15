<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;

class XmlStorageTest extends TestCase {
    protected function setUp(): void {
        $this->cleanup();
    }

    protected function tearDown(): void {
        $this->cleanup();
    }

    public function test_store_moves_file_and_protects_directory(): void {
        $source = tempnam( sys_get_temp_dir(), 'xml' );
        file_put_contents( $source, '<xml />' );

        $result = XmlStorage::store( $source );

        $this->assertNotSame( '', $result['path'] );
        $this->assertNotSame( '', $result['key'] );
        $this->assertFileExists( $result['path'] );
        $this->assertFileDoesNotExist( $source );

        $base = dirname( $result['path'] );
        $this->assertFileExists( $base . '/.htaccess' );
        $this->assertFileExists( $base . '/index.php' );
    }

    public function test_resolve_path_sanitizes_key(): void {
        $base = dirname( XmlStorage::resolve_path( 'seed' ) );

        $sanitized = XmlStorage::resolve_path( '../etc/passwd' );
        $this->assertSame( $base . '/ecad.xml', $sanitized );

        $source = tempnam( sys_get_temp_dir(), 'xml' );
        file_put_contents( $source, '<xml />' );
        $result = XmlStorage::store( $source );

        $this->assertSame( $result['path'], XmlStorage::resolve_path( strtoupper( $result['key'] ) ) );
    }

    private function cleanup(): void {
        $roots = array();
        if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
            $roots[] = rtrim( WP_CONTENT_DIR, '/\\' );
        }
        $roots[] = rtrim( sys_get_temp_dir(), '/\\' );

        foreach ( $roots as $root ) {
            $path = $root . '/sii-boleta-dte';
            if ( ! is_dir( $path ) ) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iterator as $item ) {
                if ( $item->isDir() ) {
                    @rmdir( $item->getPathname() );
                } else {
                    @unlink( $item->getPathname() );
                }
            }
            @rmdir( $path );
        }
    }
}
