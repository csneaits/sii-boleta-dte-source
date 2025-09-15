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

		// Emitter data.
		add_settings_section( 'sii_boleta_emitter', __( 'Emitter', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'rut_emisor', __( 'RUT', 'sii-boleta-dte' ), array( $this, 'field_rut_emisor' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'razon_social', __( 'Razón Social', 'sii-boleta-dte' ), array( $this, 'field_razon_social' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'giro', __( 'Giro', 'sii-boleta-dte' ), array( $this, 'field_giro' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'direccion', __( 'Dirección', 'sii-boleta-dte' ), array( $this, 'field_direccion' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'comuna', __( 'Comuna', 'sii-boleta-dte' ), array( $this, 'field_comuna' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'acteco', __( 'Código Acteco', 'sii-boleta-dte' ), array( $this, 'field_acteco' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'cdg_sii_sucur', __( 'CdgSiiSucur (opcional)', 'sii-boleta-dte' ), array( $this, 'field_cdg_sii_sucur' ), 'sii-boleta-dte', 'sii_boleta_emitter' );

		// Certificate and CAF.
		add_settings_section( 'sii_boleta_cert', __( 'Certificate and CAF', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'cert_path', __( 'Certificate Path', 'sii-boleta-dte' ), array( $this, 'field_cert_path' ), 'sii-boleta-dte', 'sii_boleta_cert' );
		add_settings_field( 'cert_pass', __( 'Certificate Password', 'sii-boleta-dte' ), array( $this, 'field_cert_pass' ), 'sii-boleta-dte', 'sii_boleta_cert' );
		add_settings_field( 'caf_paths', __( 'CAF Paths', 'sii-boleta-dte' ), array( $this, 'field_caf_paths' ), 'sii-boleta-dte', 'sii_boleta_cert' );

		// Environment and document types.
		add_settings_section( 'sii_boleta_env', __( 'Environment', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'environment', __( 'Environment', 'sii-boleta-dte' ), array( $this, 'field_environment' ), 'sii-boleta-dte', 'sii_boleta_env' );
		add_settings_field( 'enabled_types', __( 'Enabled DTE Types', 'sii-boleta-dte' ), array( $this, 'field_enabled_types' ), 'sii-boleta-dte', 'sii_boleta_env' );

		// PDF Options.
		add_settings_section( 'sii_boleta_pdf', __( 'PDF Options', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'pdf_format', __( 'Format', 'sii-boleta-dte' ), array( $this, 'field_pdf_format' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
		add_settings_field( 'pdf_logo', __( 'Company Logo', 'sii-boleta-dte' ), array( $this, 'field_pdf_logo' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
		add_settings_field( 'pdf_show_logo', __( 'Show Logo', 'sii-boleta-dte' ), array( $this, 'field_pdf_show_logo' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
		add_settings_field( 'pdf_footer', __( 'Footer Note', 'sii-boleta-dte' ), array( $this, 'field_pdf_footer' ), 'sii-boleta-dte', 'sii_boleta_pdf' );

		// SMTP profile.
		add_settings_section( 'sii_boleta_smtp', __( 'SMTP', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'smtp_profile', __( 'SMTP Profile', 'sii-boleta-dte' ), array( $this, 'field_smtp_profile' ), 'sii-boleta-dte', 'sii_boleta_smtp' );

		// Logging.
		add_settings_section( 'sii_boleta_logging', __( 'Logging', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'enable_logging', __( 'Enable Logging', 'sii-boleta-dte' ), array( $this, 'field_enable_logging' ), 'sii-boleta-dte', 'sii_boleta_logging' );
	}

	public function field_rut_emisor(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['rut_emisor'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[rut_emisor]" value="' . $value . '" />';
	}

	public function field_razon_social(): void {
				$settings = $this->settings->get_settings();
				$value    = esc_attr( $settings['razon_social'] ?? '' );
				echo '<input type="text" class="regular-text" style="width:25em" name="' . esc_attr( Settings::OPTION_NAME ) . '[razon_social]" value="' . $value . '" />';
	}

	public function field_giro(): void {
				$settings = $this->settings->get_settings();
				$value    = esc_attr( $settings['giro'] ?? '' );
				echo '<input type="text" class="regular-text sii-input-wide" name="' . esc_attr( Settings::OPTION_NAME ) . '[giro]" value="' . $value . '" />';
	}

	public function field_direccion(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['direccion'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[direccion]" value="' . $value . '" />';
	}

	public function field_comuna(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['comuna'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[comuna]" value="' . $value . '" />';
	}

	public function field_acteco(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['acteco'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[acteco]" value="' . $value . '" />';
	}

	public function field_cdg_sii_sucur(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['cdg_sii_sucur'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[cdg_sii_sucur]" value="' . $value . '" />';
	}

	public function field_cert_path(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['cert_path'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[cert_path]" value="' . $value . '" />';
	}

	public function field_cert_pass(): void {
		echo '<input type="password" name="' . esc_attr( Settings::OPTION_NAME ) . '[cert_pass]" value="" autocomplete="off" />';
	}

	public function field_caf_paths(): void {
		$settings = $this->settings->get_settings();
		$paths    = $settings['caf_paths'] ?? array();
		$name     = esc_attr( Settings::OPTION_NAME ) . '[caf_paths]';
		$types    = array(
			33 => __( 'Factura', 'sii-boleta-dte' ),
			39 => __( 'Boleta', 'sii-boleta-dte' ),
		);
		foreach ( $types as $code => $label ) {
			$value = isset( $paths[ $code ] ) ? esc_textarea( implode( "\n", (array) $paths[ $code ] ) ) : '';
			echo '<p><label>' . esc_html( $label ) . '</label><br /><textarea name="' . $name . '[' . esc_attr( (string) $code ) . ']" rows="3" cols="40">' . $value . '</textarea></p>';
		}
	}

	public function field_environment(): void {
		$settings = $this->settings->get_settings();
		$value    = $settings['environment'] ?? '0';
		$name     = esc_attr( Settings::OPTION_NAME ) . '[environment]';
		echo '<select name="' . $name . '">';
		echo '<option value="0"' . selected( '0', (string) $value, false ) . '>' . esc_html__( 'Test', 'sii-boleta-dte' ) . '</option>';
		echo '<option value="1"' . selected( '1', (string) $value, false ) . '>' . esc_html__( 'Production', 'sii-boleta-dte' ) . '</option>';
		echo '</select>';
	}

	public function field_enabled_types(): void {
		$settings = $this->settings->get_settings();
		$enabled  = $settings['enabled_types'] ?? array();
		$name     = esc_attr( Settings::OPTION_NAME ) . '[enabled_types]';
		$types    = array(
			33 => __( 'Factura', 'sii-boleta-dte' ),
			39 => __( 'Boleta', 'sii-boleta-dte' ),
		);
		foreach ( $types as $code => $label ) {
			echo '<label><input type="checkbox" name="' . $name . '[' . esc_attr( (string) $code ) . ']" value="1"' . ( in_array( $code, $enabled, true ) ? ' checked' : '' ) . '/>' . esc_html( $label ) . '</label><br />';
		}
	}

	public function field_pdf_format(): void {
		$settings = $this->settings->get_settings();
		$value    = $settings['pdf_format'] ?? 'carta';
		$name     = esc_attr( Settings::OPTION_NAME ) . '[pdf_format]';
		echo '<select name="' . $name . '">';
		echo '<option value="carta"' . selected( 'carta', $value, false ) . '>' . esc_html__( 'Carta', 'sii-boleta-dte' ) . '</option>';
		echo '<option value="boleta"' . selected( 'boleta', $value, false ) . '>' . esc_html__( 'Boleta', 'sii-boleta-dte' ) . '</option>';
		echo '</select>';
	}

	public function field_pdf_logo(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['pdf_logo'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[pdf_logo]" value="' . $value . '" />';
	}

	public function field_pdf_show_logo(): void {
		$settings = $this->settings->get_settings();
		$checked  = ! empty( $settings['pdf_show_logo'] );
		$name     = esc_attr( Settings::OPTION_NAME ) . '[pdf_show_logo]';
		echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Show logo on PDF', 'sii-boleta-dte' ) . '</label>';
	}

	public function field_pdf_footer(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_textarea( $settings['pdf_footer'] ?? '' );
		echo '<textarea name="' . esc_attr( Settings::OPTION_NAME ) . '[pdf_footer]" rows="3" cols="40">' . $value . '</textarea>';
	}

	public function field_smtp_profile(): void {
				$settings = $this->settings->get_settings();
				$current  = $settings['smtp_profile'] ?? '';
				$options  = apply_filters( 'sii_boleta_available_smtp_profiles', array() );
				$name     = esc_attr( Settings::OPTION_NAME ) . '[smtp_profile]';
				echo '<select id="sii-smtp-profile" name="' . $name . '"><option value="">' . esc_html__( 'Default', 'sii-boleta-dte' ) . '</option>';
		foreach ( $options as $key => $label ) {
			echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';
	}

	public function field_enable_logging(): void {
		$settings = $this->settings->get_settings();
		$checked  = ! empty( $settings['enable_logging'] );
		$name     = esc_attr( Settings::OPTION_NAME ) . '[enable_logging]';
		echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Enable file logging', 'sii-boleta-dte' ) . '</label>';
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
			echo '</form>';
			$this->render_requirements_check();
			echo '</div>';
	}

		/** Displays a quick checklist to verify certification readiness. */
	private function render_requirements_check(): void {
			$cfg    = $this->settings->get_settings();
			$checks = array(
				'rut_emisor'   => __( 'RUT configured', 'sii-boleta-dte' ),
				'razon_social' => __( 'Razón Social configured', 'sii-boleta-dte' ),
				'cert_path'    => __( 'Certificate file present', 'sii-boleta-dte' ),
				'caf_paths'    => __( 'CAF paths configured', 'sii-boleta-dte' ),
			);
			echo '<h2>' . esc_html__( 'Certification readiness', 'sii-boleta-dte' ) . '</h2><ul>';
			foreach ( $checks as $key => $label ) {
					$ok = false;
				if ( 'cert_path' === $key ) {
						$ok = ! empty( $cfg['cert_path'] ) && file_exists( $cfg['cert_path'] );
				} elseif ( 'caf_paths' === $key ) {
						$ok = ! empty( $cfg['caf_paths'] );
				} else {
						$ok = ! empty( $cfg[ $key ] );
				}
					echo '<li>' . ( $ok ? '&#10003;' : '&#10007;' ) . ' ' . esc_html( $label ) . '</li>';
			}
			echo '</ul>';
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

		if ( isset( $input['direccion'] ) ) {
			$output['direccion'] = sanitize_text_field( $input['direccion'] );
		}

		if ( isset( $input['comuna'] ) ) {
			$output['comuna'] = sanitize_text_field( $input['comuna'] );
		}

		if ( isset( $input['acteco'] ) ) {
			$output['acteco'] = sanitize_text_field( $input['acteco'] );
		}

		if ( isset( $input['cdg_sii_sucur'] ) ) {
			$output['cdg_sii_sucur'] = sanitize_text_field( $input['cdg_sii_sucur'] );
		}

		if ( isset( $input['cert_path'] ) ) {
			$output['cert_path'] = sanitize_file_name( $input['cert_path'] );
		}

		if ( isset( $input['caf_paths'] ) && is_array( $input['caf_paths'] ) ) {
				$output['caf_paths'] = array();
				$loaded              = false;
			foreach ( $input['caf_paths'] as $type => $paths ) {
						$lines                              = array_filter( array_map( 'trim', explode( "\n", (string) $paths ) ) );
						$files                              = array_map( 'sanitize_file_name', $lines );
						$output['caf_paths'][ (int) $type ] = $files;
				foreach ( $files as $f ) {
					if ( file_exists( $f ) ) {
								$loaded = true;
								break 2;
					}
				}
			}
			if ( $loaded ) {
							add_settings_error( 'caf_paths', 'caf_loaded', __( 'CAF files loaded.', 'sii-boleta-dte' ), 'updated' );
			} else {
				add_settings_error( 'caf_paths', 'caf_missing', __( 'CAF files missing.', 'sii-boleta-dte' ) );
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

		if ( isset( $input['pdf_format'] ) ) {
			$output['pdf_format'] = sanitize_text_field( $input['pdf_format'] );
		}

		if ( isset( $input['pdf_logo'] ) ) {
			$output['pdf_logo'] = sanitize_file_name( $input['pdf_logo'] );
		}

		$output['pdf_show_logo'] = empty( $input['pdf_show_logo'] ) ? 0 : 1;

		if ( isset( $input['pdf_footer'] ) ) {
			$output['pdf_footer'] = sanitize_text_field( $input['pdf_footer'] );
		}

		if ( isset( $input['smtp_profile'] ) ) {
			$output['smtp_profile'] = sanitize_text_field( $input['smtp_profile'] );
		}

		$output['enable_logging'] = empty( $input['enable_logging'] ) ? 0 : 1;

		return $output;
	}
}

class_alias( SettingsPage::class, 'SII_Boleta_Settings_Page' );
