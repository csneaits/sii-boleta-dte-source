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

if ( ! function_exists( 'get_option' ) ) {
        function get_option( $name, $default = false ) {
                return $GLOBALS['wp_options'][ $name ] ?? $default;
        }
}

if ( ! function_exists( 'update_option' ) ) {
        function update_option( $name, $value ) {
                $GLOBALS['wp_options'][ $name ] = $value;
        }
}

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Presentation\Admin\CafPage;

class CafPageTest extends TestCase {
        protected function setUp(): void {
                $GLOBALS['wp_options'] = array();
        }

        public function test_handle_delete_removes_legacy_caf_entries(): void {
                $file = tempnam( sys_get_temp_dir(), 'caf' );
                $this->assertNotFalse( $file );
                file_put_contents(
                        $file,
                        '<AUTORIZACION><CAF><DA><TD>39</TD><RNG><D>1</D><H>1</H></RNG><FA>2024-01-01</FA></DA></CAF></AUTORIZACION>'
                );

                $GLOBALS['wp_options'][ Settings::OPTION_NAME ] = array(
                        'environment' => Settings::ENV_TEST,
                        'caf_path'    => array(
                                39 => $file,
                        ),
                );

                $settings = new Settings();
                $page     = new CafPage( $settings );

                $param  = rawurlencode( base64_encode( $file ) );
                $method = new \ReflectionMethod( CafPage::class, 'handle_delete' );
                $method->setAccessible( true );
                $method->invoke( $page, $param );

                $stored = $GLOBALS['wp_options'][ Settings::OPTION_NAME ] ?? array();
                $this->assertSame( array(), $stored['cafs'] ?? array() );
                $this->assertSame( array(), $stored['caf_path'] ?? array() );
                $this->assertFileDoesNotExist( $file );
        }
}

}
