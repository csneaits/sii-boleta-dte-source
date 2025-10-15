<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\WordPress\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

class TokenManagerTest extends TestCase {
    public function test_caches_token_per_environment(): void {
        $api = $this->createMock( Api::class );
        $api->expects( $this->once() )->method( 'generate_token' )->willReturn( 'tok' );
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $tm = new TokenManager( $api, $settings );
        $this->assertSame( 'tok', $tm->get_token( 'test' ) );
        $this->assertSame( 'tok', $tm->get_token( 'test' ) ); // same token, no extra call
    }
}
