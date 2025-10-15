<?php
namespace Sii\BoletaDte\Infrastructure\Security;

/**
 * Handles secure storage of uploaded signing certificates.
 */
class CertificateStorage {
    private const DIRECTORY = 'sii-boleta-dte/private/certs';

    /**
     * Moves an uploaded certificate into the protected storage directory.
     */
    public static function store_uploaded( string $temporary_path, string $original_name ): ?string {
        if ( '' === $temporary_path || ! file_exists( $temporary_path ) ) {
            return null;
        }

        $directory = self::ensure_directory();
        if ( '' === $directory ) {
            return null;
        }

        $extension   = self::resolve_extension( $original_name );
        $destination = $directory . '/' . self::generate_token() . '.' . $extension;

        if ( ! self::relocate_file( $temporary_path, $destination ) ) {
            return null;
        }

        self::protect_directory( $directory );

        return $destination;
    }

    /**
     * Deletes a previously stored certificate if it is within the managed directory.
     */
    public static function delete_if_managed( string $path ): void {
        if ( '' === $path || ! self::is_managed_path( $path ) ) {
            return;
        }

        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    /**
     * Ensures that the base directory exists and is writable.
     */
    private static function ensure_directory(): string {
        $base = self::resolve_base_directory();
        if ( '' === $base ) {
            return '';
        }

        if ( ! is_dir( $base ) ) {
            self::create_directory( $base );
        }

        if ( is_dir( $base ) && is_writable( $base ) ) {
            return $base;
        }

        return '';
    }

    private static function resolve_base_directory(): string {
        if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
            $base = rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DIRECTORY;
        } else {
            $base = rtrim( sys_get_temp_dir(), '/\\' ) . '/' . self::DIRECTORY;
        }

        return $base;
    }

    private static function create_directory( string $path ): void {
        if ( function_exists( 'wp_mkdir_p' ) ) {
            wp_mkdir_p( $path );
            return;
        }

        if ( ! is_dir( $path ) ) {
            @mkdir( $path, 0755, true );
        }
    }

    private static function relocate_file( string $source, string $destination ): bool {
        if ( function_exists( 'is_uploaded_file' ) && @is_uploaded_file( $source ) ) {
            if ( @move_uploaded_file( $source, $destination ) ) {
                @chmod( $destination, 0600 );
                return true;
            }
        }

        if ( @rename( $source, $destination ) ) {
            @chmod( $destination, 0600 );
            return true;
        }

        if ( @copy( $source, $destination ) ) {
            @chmod( $destination, 0600 );
            @unlink( $source );
            return true;
        }

        return false;
    }

    private static function protect_directory( string $directory ): void {
        $htaccess = $directory . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $rules = "Deny from all\n";
            if ( version_compare( PHP_VERSION, '5.4.0', '>=' ) ) {
                $rules .= "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
            }
            @file_put_contents( $htaccess, $rules );
        }

        $web_config = $directory . '/web.config';
        if ( ! file_exists( $web_config ) ) {
            $config = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <authorization>
            <deny users="*" />
        </authorization>
    </system.webServer>
</configuration>
XML;
            @file_put_contents( $web_config, $config );
        }

        $index = $directory . '/index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' );
        }
    }

    private static function resolve_extension( string $original_name ): string {
        $extension = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );
        if ( in_array( $extension, array( 'p12', 'pfx' ), true ) ) {
            return $extension;
        }

        return 'p12';
    }

    private static function generate_token(): string {
        try {
            return bin2hex( random_bytes( 18 ) );
        } catch ( \Throwable $e ) {
            if ( function_exists( 'wp_generate_password' ) ) {
                return strtolower( preg_replace( '/[^a-z0-9]/', '', wp_generate_password( 36, false, false ) ) );
            }

            return strtolower( preg_replace( '/[^a-z0-9]/', '', (string) uniqid( '', true ) ) );
        }
    }

    private static function normalize_path( string $path ): string {
        $normalized = str_replace( '\\', '/', $path );
        $normalized = preg_replace( '#/+#', '/', $normalized );

        return (string) $normalized;
    }

    private static function is_managed_path( string $path ): bool {
        $normalized = self::normalize_path( $path );
        $base       = self::normalize_path( self::resolve_base_directory() );
        if ( '' === $normalized || '' === $base ) {
            return false;
        }

        return str_starts_with( $normalized, rtrim( $base, '/' ) . '/' );
    }
}
