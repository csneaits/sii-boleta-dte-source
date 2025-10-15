<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Rest\SignedUrlService;

if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string {
        return 'https://example.com' . $path;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $key, $value, string $url ): string {
        $args = is_array( $key ) ? $key : array( $key => $value );
        $query = http_build_query( $args );
        $separator = str_contains( $url, '?' ) ? '&' : '?';
        return $url . $separator . $query;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, $value, int $ttl ): bool {
        $GLOBALS['transients'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ) {
        return $GLOBALS['transients'][ $key ] ?? false;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $key ): bool {
        unset( $GLOBALS['transients'][ $key ] );
        return true;
    }
}

class SignedUrlServiceTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['transients'] = array();
    }

    public function test_generate_and_validate_signed_url(): void {
        $service = new SignedUrlService( static fn() => 1000 );

        $url = $service->generate_signed_url( 123, 120 );

        $parts = parse_url( $url );
        $this->assertContains( $parts['scheme'], array( 'http', 'https' ) );
        $this->assertSame( 'example.com', $parts['host'] );
        $this->assertSame( '/boleta/123/', $parts['path'] );

        parse_str( $parts['query'] ?? '', $query );
        $token = $query['sii_boleta_token'] ?? '';
        $this->assertNotSame( '', $token );

        $stored = get_transient( 'sii_boleta_dte_signed_' . $token );
        $this->assertIsArray( $stored );
        $this->assertSame( 123, $stored['folio'] );
        $this->assertSame( 1120, $stored['expires'] );

        $this->assertTrue( $service->validate_token( $token, 123 ) );
        $this->assertFalse( get_transient( 'sii_boleta_dte_signed_' . $token ) );
    }

    public function test_validate_token_handles_invalid_and_expired_entries(): void {
        $service = new SignedUrlService( static fn() => 2000 );

        $this->assertFalse( $service->validate_token( 'missing', 10 ) );

        set_transient(
            'sii_boleta_dte_signed_expired',
            array( 'folio' => 10, 'expires' => 1500 ),
            60
        );

        $this->assertFalse( $service->validate_token( 'expired', 10 ) );
        $this->assertFalse( get_transient( 'sii_boleta_dte_signed_expired' ) );

        set_transient(
            'sii_boleta_dte_signed_wrong',
            array( 'folio' => 5, 'expires' => 5000 ),
            60
        );

        $this->assertFalse( $service->validate_token( 'wrong', 10 ) );
    }
}
