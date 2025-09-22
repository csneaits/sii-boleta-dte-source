<?php
namespace Sii\BoletaDte\Infrastructure {
        if ( ! isset( $GLOBALS['wp_options'] ) ) {
                $GLOBALS['wp_options'] = array();
        }
        if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
                function get_option( $name, $default = false ) {
                        return $GLOBALS['wp_options'][ $name ] ?? $default;
                }
        }
        if ( ! function_exists( __NAMESPACE__ . '\\update_option' ) ) {
                function update_option( $name, $value ) {
                        $GLOBALS['wp_options'][ $name ] = $value;
                }
        }
}

namespace {

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;

class SettingsTest extends TestCase {
        protected function setUp(): void {
                $GLOBALS['wp_options'] = array();
                Settings::clear_cache();
        }

        public function test_get_settings_filters_by_environment(): void {
$GLOBALS['wp_options'][ Settings::OPTION_NAME ] = array(
'environment' => '0',
'cert_pass'   => Settings::encrypt( 'secret' ),
'cafs'        => array(
array(
'tipo'        => 39,
'desde'       => 1,
'hasta'       => 100,
'environment' => Settings::ENV_TEST,
),
array(
'tipo'        => 41,
'desde'       => 1,
'hasta'       => 200,
'environment' => Settings::ENV_PROD,
),
array(
'tipo'  => 33,
'desde' => 50,
'hasta' => 150,
),
),
);

                $settings = new Settings();
                $result   = $settings->get_settings();

                $this->assertArrayHasKey( 'cert_pass', $result );
                $this->assertSame( Settings::ENV_TEST, $result['environment_slug'] );
                $this->assertSame( 1, $result['cafs_hidden'] );
                $this->assertCount( 2, $result['cafs'] );
$this->assertSame( array(), $result['caf_path'] );
        }

        public function test_get_settings_uses_cache_until_cleared(): void {
$GLOBALS['wp_options'][ Settings::OPTION_NAME ] = array(
'giro'  => 'Retail',
'giros' => array( 'Retail' ),
);

                $settings = new Settings();
                $first    = $settings->get_settings();

$GLOBALS['wp_options'][ Settings::OPTION_NAME ] = array();

                $second = $settings->get_settings();
                $this->assertSame( $first, $second );

                Settings::clear_cache();
                $third = $settings->get_settings();
                $this->assertNotEquals( $first, $third );
        }

        public function test_normalize_environment_slug(): void {
                $this->assertSame( Settings::ENV_PROD, Settings::normalize_environment_slug( '1' ) );
                $this->assertSame( Settings::ENV_PROD, Settings::normalize_environment_slug( 'production' ) );
                $this->assertSame( Settings::ENV_TEST, Settings::normalize_environment_slug( '' ) );
        }
}

}
