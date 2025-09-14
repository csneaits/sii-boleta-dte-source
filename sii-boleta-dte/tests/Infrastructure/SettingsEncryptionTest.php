<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;

class SettingsEncryptionTest extends TestCase {
    public function test_encrypt_and_decrypt_roundtrip(): void {
        $secret = 'my-password';
        $encrypted = Settings::encrypt( $secret );
        $this->assertNotSame( $secret, $encrypted );
        $this->assertSame( $secret, Settings::decrypt( $encrypted ) );
    }
}
