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
        register_setting(
            Settings::OPTION_GROUP,
            Settings::OPTION_NAME,
            array(
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
            )
        );
        add_settings_section( 'sii_boleta_main', __( 'General', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
        add_settings_field(
            'rut_emisor',
            __( 'RUT Emisor', 'sii-boleta-dte' ),
            array( $this, 'field_rut_emisor' ),
            'sii-boleta-dte',
            'sii_boleta_main'
        );

        add_settings_field(
            'enabled_types',
            __( 'Document types', 'sii-boleta-dte' ),
            array( $this, 'field_enabled_types' ),
            'sii-boleta-dte',
            'sii_boleta_main'
        );
    }

    public function field_rut_emisor(): void {
        $settings = $this->settings->get_settings();
        $value    = esc_attr( $settings['rut_emisor'] ?? '' );
        echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[rut_emisor]" value="' . $value . '" />';
    }

    public function field_enabled_types(): void {
        $settings = $this->settings->get_settings();
        $enabled  = $settings['enabled_types'] ?? array();
        $name     = esc_attr( Settings::OPTION_NAME ) . '[enabled_types]';
        echo '<label><input type="checkbox" name="' . $name . '[33]" value="1"' . ( in_array( 33, $enabled, true ) ? ' checked' : '' ) . '/>' . esc_html__( 'Factura', 'sii-boleta-dte' ) . '</label><br />';
        echo '<label><input type="checkbox" name="' . $name . '[39]" value="1"' . ( in_array( 39, $enabled, true ) ? ' checked' : '' ) . '/>' . esc_html__( 'Boleta', 'sii-boleta-dte' ) . '</label>';
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

    /**
     * Sanitizes and validates settings before saving.
     *
     * @param array<string,mixed> $input Raw input data.
     * @return array<string,mixed>
     */
    public function sanitize_settings( array $input ): array {
        $output = array();

        if ( isset( $input['rut_emisor'] ) ) {
            $rut = sanitize_text_field( $input['rut_emisor'] );
            if ( ! preg_match( '/^[0-9kK-]+$/', $rut ) ) {
                add_settings_error( 'rut_emisor', 'invalid_rut', __( 'Invalid RUT format.', 'sii-boleta-dte' ) );
            } else {
                $output['rut_emisor'] = $rut;
            }
        }

        if ( isset( $input['razon_social'] ) ) {
            $output['razon_social'] = sanitize_text_field( $input['razon_social'] );
        }

        if ( isset( $input['giro'] ) ) {
            $output['giro'] = sanitize_text_field( $input['giro'] );
        }

        if ( isset( $input['cert_path'] ) ) {
            $output['cert_path'] = sanitize_file_name( $input['cert_path'] );
        }

        if ( isset( $input['caf_path'] ) ) {
            if ( is_array( $input['caf_path'] ) ) {
                $output['caf_path'] = array_map( 'sanitize_file_name', $input['caf_path'] );
            } else {
                $output['caf_path'] = sanitize_file_name( $input['caf_path'] );
            }
        }

        if ( isset( $input['environment'] ) ) {
            $output['environment'] = intval( $input['environment'] );
        }

        if ( isset( $input['enabled_types'] ) && is_array( $input['enabled_types'] ) ) {
            $output['enabled_types'] = array_map( 'intval', array_keys( $input['enabled_types'] ) );
        }

        if ( isset( $input['cert_pass'] ) ) {
            $pass = sanitize_text_field( $input['cert_pass'] );
            if ( '' !== $pass ) {
                $output['cert_pass'] = Settings::encrypt( $pass );
            }
        }

        return $output;
    }
}

class_alias( SettingsPage::class, 'SII_Boleta_Settings_Page' );
