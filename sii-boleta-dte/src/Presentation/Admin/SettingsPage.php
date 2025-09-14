<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Renders the plugin settings page using WordPress settings API.
 */
class SettingsPage {
    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Registers settings sections and fields.
     */
    public function register(): void {
        if ( ! function_exists( 'register_setting' ) ) {
            return;
        }
        register_setting( Settings::OPTION_GROUP, Settings::OPTION_NAME );
        add_settings_section( 'sii_boleta_main', __( 'General', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
        add_settings_field(
            'rut_emisor',
            __( 'RUT Emisor', 'sii-boleta-dte' ),
            array( $this, 'field_rut_emisor' ),
            'sii-boleta-dte',
            'sii_boleta_main'
        );
    }

    public function field_rut_emisor(): void {
        $settings = $this->settings->get_settings();
        $value    = esc_attr( $settings['rut_emisor'] ?? '' );
        echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[rut_emisor]" value="' . $value . '" />';
    }

    /**
     * Outputs the settings page markup.
     */
    public function render_page(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'SII Boleta DTE', 'sii-boleta-dte' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( Settings::OPTION_GROUP );
        do_settings_sections( 'sii-boleta-dte' );
        submit_button();
        echo '</form></div>';
    }
}

class_alias( SettingsPage::class, 'SII_Boleta_Settings_Page' );
