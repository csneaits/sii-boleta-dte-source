<?php
namespace Sii\BoletaDte\Presentation\WooCommerce;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Adds custom fields to the WooCommerce checkout process.
 */
class CheckoutFields {
    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Hooks into WooCommerce.
     */
    public function register(): void {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'woocommerce_after_order_notes', array( $this, 'render_rut_field' ) );
        }
    }

    /**
     * Renders the RUT field.
     */
    public function render_rut_field( $checkout ): void {
        $settings = $this->settings->get_settings();
        $label    = $settings['rut_label'] ?? __( 'RUT', 'sii-boleta-dte' );
        if ( function_exists( 'woocommerce_form_field' ) ) {
            woocommerce_form_field( 'billing_rut', array( 'type' => 'text', 'label' => $label ), '' );
        } else {
            echo '<p class="form-row"><label>' . esc_html( $label ) . '</label><input type="text" name="billing_rut" /></p>';
        }
    }
}

class_alias( CheckoutFields::class, 'SII_Boleta_Woo_Checkout_Fields' );
