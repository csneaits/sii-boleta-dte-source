<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Presentation\WooCommerce\CheckoutFields;
use Sii\BoletaDte\Infrastructure\Settings;

if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb ) { $GLOBALS['added_actions'][ $hook ] = $cb; } }
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $value ) { return $value; } }
if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $message, $type ) {
        $GLOBALS['wc_notices'][] = array(
            'message' => $message,
            'type'    => $type,
        );
    }
}
if ( ! function_exists( 'woocommerce_form_field' ) ) {
    function woocommerce_form_field( $key, $args, $value ) {
        echo '<input name="' . $key . '" />';
    }
}

class WooCheckoutTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $_POST                   = array();
        $GLOBALS['wc_notices']   = array();
        $GLOBALS['added_actions'] = array();
    }

    public function test_field_renders(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'enabled_types' => array( 33 ) ) );
        $fields = new CheckoutFields( $settings );
        $fields->register();
        ob_start();
        $fields->render_fields( null );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'billing_rut', $html );
        $this->assertStringContainsString( 'sii_boleta_doc_type', $html );
    }

    public function test_register_adds_validation_hook(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $fields = new CheckoutFields( $settings );
        $fields->register();
        $this->assertArrayHasKey( 'woocommerce_checkout_process', $GLOBALS['added_actions'] );
    }

    public function test_validate_fields_rejects_invalid_rut(): void {
        $_POST['billing_rut'] = '76.192.083-1';
        $settings              = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $fields = new CheckoutFields( $settings );
        $fields->validate_fields();
        $this->assertNotEmpty( $GLOBALS['wc_notices'] );
        $this->assertSame( 'error', $GLOBALS['wc_notices'][0]['type'] );
    }

    public function test_validate_fields_formats_valid_rut(): void {
        $_POST['billing_rut'] = '761920839';
        $settings              = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array() );
        $fields = new CheckoutFields( $settings );
        $fields->validate_fields();
        $this->assertSame( array(), $GLOBALS['wc_notices'] );
        $this->assertSame( '76.192.083-9', $_POST['billing_rut'] );
    }
}
