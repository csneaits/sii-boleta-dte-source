<?php
namespace Sii\BoletaDte\Infrastructure\Queue;

/**
 * Stores queued XML payloads in a private directory with random filenames.
 */
class XmlStorage {
    private const DIRECTORY = 'sii-boleta-dte/private/xml';

    /**
     * Moves the XML file into the protected directory.
     *
     * @return array{path:string,key:string}
     */
    public static function store( string $source ): array {
        $result = array(
            'path' => '',
            'key'  => '',
        );

        if ( '' === $source || ! file_exists( $source ) ) {
            return $result;
        }

        $directory = self::ensure_directory();
        if ( '' === $directory ) {
            return $result;
        }

        $key  = self::generate_token();
        $dest = $directory . '/' . $key . '.xml';

        if ( ! self::move_file( $source, $dest ) ) {
            return $result;
        }

        self::protect_directory( $directory );

        return array(
            'path' => $dest,
            'key'  => $key,
        );
    }

    /** Resolves the absolute path of a stored XML file using its key. */
    public static function resolve_path( string $key ): string {
        $key = preg_replace( '/[^a-f0-9]/', '', strtolower( $key ) );
        if ( '' === $key ) {
            return '';
        }

        $directory = self::ensure_directory();
        if ( '' === $directory ) {
            return '';
        }

        return $directory . '/' . $key . '.xml';
    }

    private static function ensure_directory(): string {
        $base = self::resolve_base_directory();
        if ( '' === $base ) {
            return '';
        }

        if ( ! is_dir( $base ) ) {
            self::create_directory( $base );
        }

        if ( is_dir( $base ) && is_writable( $base ) ) {
            @chmod( $base, 0750 );
            return $base;
        }

        return '';
    }

    private static function resolve_base_directory(): string {
        if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
            $dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DIRECTORY;
        } else {
            $dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/' . self::DIRECTORY;
        }

        return $dir;
    }

    private static function create_directory( string $path ): void {
        if ( function_exists( 'wp_mkdir_p' ) ) {
            wp_mkdir_p( $path );
            return;
        }

        if ( ! is_dir( $path ) ) {
            @mkdir( $path, 0750, true );
        }
    }

    private static function move_file( string $source, string $destination ): bool {
        if ( @rename( $source, $destination ) ) {
            @chmod( $destination, 0640 );
            return true;
        }

        if ( @copy( $source, $destination ) ) {
            @chmod( $destination, 0640 );
            @unlink( $source );
            return true;
        }

        return false;
    }

    private static function protect_directory( string $directory ): void {
        $htaccess = $directory . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }

        $index = $directory . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\nexit;\n" );
        }
    }

    private static function generate_token(): string {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $e ) {
            if ( function_exists( 'wp_generate_password' ) ) {
                return strtolower( preg_replace( '/[^a-z0-9]/', '', wp_generate_password( 32, false, false ) ) );
            }

            return strtolower( preg_replace( '/[^a-z0-9]/', '', (string) uniqid( '', true ) ) );
        }
    }
}
