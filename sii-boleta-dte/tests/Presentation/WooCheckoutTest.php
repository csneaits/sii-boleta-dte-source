<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\WooCommerce\CheckoutFields;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb ) { $GLOBALS['added_actions'][ $hook ] = $cb; } }
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'woocommerce_form_field' ) ) {
    function woocommerce_form_field( $key, $args, $value ) {
        echo '<input name="' . $key . '" />';
    }
}

class WooCheckoutTest extends TestCase {
    public function test_field_renders(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $fields = new CheckoutFields( $settings );
        $fields->register();
        ob_start();
        $fields->render_rut_field( null );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'billing_rut', $html );
    }
}
