<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;

class SettingsTest extends TestCase {
    protected function setUp(): void {
        unset( $GLOBALS['test_options'] );
        $GLOBALS['wp_options'] = array();
    }

    public function test_normalize_environment_and_option_keys(): void {
        $this->assertSame( '1', Settings::normalize_environment( 'production' ) );
        $this->assertSame( '0', Settings::normalize_environment( 'certification' ) );
        $this->assertSame( '0', Settings::normalize_environment( 'unknown' ) );

        $this->assertSame( 'sii_boleta_dte_last_folio_0_39', Settings::last_folio_option_key( 39, 'test' ) );
    }

    public function test_get_and_update_last_folio_value_uses_wordpress_options(): void {
        Settings::update_last_folio_value( 39, '0', 123 );

        $this->assertSame( 123, Settings::get_last_folio_value( 39, '0' ) );
        $this->assertSame( 123, $GLOBALS['wp_options']['sii_boleta_dte_last_folio_0_39'] );
    }

    public function test_compare_and_update_last_folio_value(): void {
        Settings::update_last_folio_value( 39, '0', 10 );

        $this->assertFalse( Settings::compare_and_update_last_folio_value( 39, '0', 8, 11 ) );
        $this->assertTrue( Settings::compare_and_update_last_folio_value( 39, '0', 10, 11 ) );
        $this->assertSame( 11, Settings::get_last_folio_value( 39, '0' ) );
    }

    public function test_schedule_option_key_and_preview_only_flag(): void {
        $this->assertSame(
            'sii_boleta_dte_last_my_task_run_0',
            Settings::schedule_option_key( 'My Task', 'certificacion' )
        );

        $settings = new class extends Settings {
            public function get_settings(): array {
                return array(
                    'environment'               => '0',
                    'woocommerce_preview_only' => true,
                );
            }
        };

        $this->assertTrue( $settings->is_woocommerce_preview_only_enabled() );

        $prod = new class extends Settings {
            public function get_settings(): array {
                return array(
                    'environment'               => '1',
                    'woocommerce_preview_only' => true,
                );
            }
        };

        $this->assertFalse( $prod->is_woocommerce_preview_only_enabled() );
    }
}
