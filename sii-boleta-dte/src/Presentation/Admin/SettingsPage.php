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
				add_settings_field( 'giro', __( 'Giros económicos', 'sii-boleta-dte' ), array( $this, 'field_giro' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'direccion', __( 'Dirección', 'sii-boleta-dte' ), array( $this, 'field_direccion' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'comuna', __( 'Comuna', 'sii-boleta-dte' ), array( $this, 'field_comuna' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'acteco', __( 'Código Acteco', 'sii-boleta-dte' ), array( $this, 'field_acteco' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'cdg_sii_sucur', __( 'CdgSiiSucur (opcional)', 'sii-boleta-dte' ), array( $this, 'field_cdg_sii_sucur' ), 'sii-boleta-dte', 'sii_boleta_emitter' );

		// Certificate and CAF.
		add_settings_section( 'sii_boleta_cert', __( 'Certificate and CAF', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'cert_path', __( 'Certificate Path', 'sii-boleta-dte' ), array( $this, 'field_cert_path' ), 'sii-boleta-dte', 'sii_boleta_cert' );
				add_settings_field( 'cert_pass', __( 'Certificate Password', 'sii-boleta-dte' ), array( $this, 'field_cert_pass' ), 'sii-boleta-dte', 'sii_boleta_cert' );

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
				echo '<input type="text" class="regular-text sii-input-wide" name="' . esc_attr( Settings::OPTION_NAME ) . '[razon_social]" value="' . $value . '" />';
	}

	public function field_giro(): void {
			// phpcs:disable WordPress.WhiteSpace.ControlStructureSpacing,WordPress.WhiteSpace.ScopeIndent,WordPress.WhiteSpace.TabIndentation,Generic.Arrays.ArrayIndentation,PEAR.Functions.FunctionCallSignature
			$settings = $this->settings->get_settings();
			$values   = array();
		if ( isset( $settings['giros'] ) && is_array( $settings['giros'] ) ) {
			foreach ( $settings['giros'] as $giro ) {
				$giro = trim( (string) $giro );
				if ( '' !== $giro ) {
						$values[] = $giro;
				}
			}
		}

		if ( empty( $values ) && ! empty( $settings['giro'] ) ) {
				$values[] = (string) $settings['giro'];
		}

		if ( empty( $values ) ) {
				$values[] = '';
		}

			$field_name = esc_attr( Settings::OPTION_NAME ) . '[giros][]';
			$rows       = '';
		foreach ( $values as $value ) {
				$value_attr = esc_attr( $value );
				$rows      .= '<div class="sii-giros-row" style="margin-bottom:6px;display:flex;gap:6px;align-items:center">';
				$rows      .= '<input type="text" class="regular-text sii-input-wide" name="' . $field_name . '" value="' . $value_attr . '" />';
				$rows      .= '<button type="button" class="button sii-remove-giro" aria-label="' . esc_attr__( 'Eliminar giro', 'sii-boleta-dte' ) . '">&times;</button>';
				$rows      .= '</div>';
		}

			$add_label    = esc_html__( 'Agregar giro', 'sii-boleta-dte' );
			$description  = esc_html__( 'Agrega los giros económicos disponibles para tu empresa. Podrás elegir uno al generar el DTE.', 'sii-boleta-dte' );
			$option_name  = esc_attr( Settings::OPTION_NAME );
			$remove_label = esc_attr__( 'Eliminar giro', 'sii-boleta-dte' );

			$template = <<<HTML
<div id="sii-giros-container">{$rows}</div>
<p><button type="button" class="button" id="sii-add-giro">{$add_label}</button></p>
<p class="description">{$description}</p>
<template id="sii-giro-template">
        <div class="sii-giros-row" style="margin-bottom:6px;display:flex;gap:6px;align-items:center">
                <input type="text" class="regular-text sii-input-wide" name="{$option_name}[giros][]" value="" />
                <button type="button" class="button sii-remove-giro" aria-label="{$remove_label}">&times;</button>
        </div>
</template>
<script>
( function () {
        function ready( fn ) {
                if ( document.readyState === 'loading' ) {
                        document.addEventListener( 'DOMContentLoaded', fn );
                } else {
                        fn();
                }
        }
        ready( function () {
                var container = document.getElementById( 'sii-giros-container' );
                var addBtn = document.getElementById( 'sii-add-giro' );
                if ( ! container || ! addBtn ) {
                        return;
                }
                var template = document.getElementById( 'sii-giro-template' );
                function bindRemove( button ) {
                        if ( ! button ) {
                                return;
                        }
                        button.addEventListener( 'click', function () {
                                var row = button.closest( '.sii-giros-row' );
                                if ( row && container.children.length > 1 ) {
                                        row.remove();
                                } else if ( row ) {
                                        var input = row.querySelector( 'input' );
                                        if ( input ) {
                                                input.value = '';
                                        }
                                }
                        } );
                }
                Array.prototype.forEach.call( container.querySelectorAll( '.sii-remove-giro' ), bindRemove );
                addBtn.addEventListener( 'click', function () {
                        if ( template && template.content ) {
                                var clone = document.importNode( template.content, true );
                                if ( clone ) {
                                        container.appendChild( clone );
                                        bindRemove( container.lastElementChild.querySelector( '.sii-remove-giro' ) );
                                }
                                return;
                        }
                        var fallback = template && template.firstElementChild ? template.firstElementChild.cloneNode( true ) : null;
                        if ( fallback ) {
                                container.appendChild( fallback );
                                bindRemove( container.lastElementChild.querySelector( '.sii-remove-giro' ) );
                                return;
                        }
                        var row = document.createElement( 'div' );
                        row.className = 'sii-giros-row';
                        row.style.marginBottom = '6px';
                        row.style.display = 'flex';
                        row.style.gap = '6px';
                        row.style.alignItems = 'center';
                        row.innerHTML = '<input type="text" class="regular-text sii-input-wide" name="{$option_name}[giros][]" value="" />' +
                                '<button type="button" class="button sii-remove-giro" aria-label="{$remove_label}">&times;</button>';
                        container.appendChild( row );
                        bindRemove( row.querySelector( '.sii-remove-giro' ) );
                } );
        } );
} )();
</script>
HTML;

			echo $template; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// phpcs:enable
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
		echo '<div class="sii-dte-cert-row">';
		echo '<input type="file" id="sii-dte-cert-file" name="cert_file" accept=".p12,.pfx" />';
		echo '<input type="text" id="sii-dte-cert-path" name="' . esc_attr( Settings::OPTION_NAME ) . '[cert_path]" value="' . $value . '" placeholder="/ruta/al/certificado.p12" class="regular-text" />';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Puedes subir un archivo .p12/.pfx o ingresar una ruta absoluta en el servidor.', 'sii-boleta-dte' ) . '</p>';
	}

	public function field_cert_pass(): void {
		echo '<input type="password" name="' . esc_attr( Settings::OPTION_NAME ) . '[cert_pass]" value="" autocomplete="off" />';
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
		$value    = (int) ( $settings['pdf_logo'] ?? 0 );
		$url      = '';
		if ( $value && function_exists( 'wp_get_attachment_url' ) ) {
			$url = (string) wp_get_attachment_url( $value );
		}
		$name = esc_attr( Settings::OPTION_NAME ) . '[pdf_logo]';
		echo '<div class="sii-dte-logo-field">';
		echo '<img id="sii-dte-logo-preview" src="' . esc_url( $url ) . '" alt="" />';
		echo '<input type="hidden" id="sii_dte_logo_id" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
		echo '<button type="button" class="button" id="sii-dte-select-logo">' . esc_html__( 'Select Logo', 'sii-boleta-dte' ) . '</button> ';
		echo '<button type="button" class="button" id="sii-dte-remove-logo">' . esc_html__( 'Remove Logo', 'sii-boleta-dte' ) . '</button>';
		echo '</div>';
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
			// Enable file uploads in settings form.
			echo '<form method="post" action="options.php" enctype="multipart/form-data">';
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
					);
					echo '<h2>' . esc_html__( 'Certification readiness', 'sii-boleta-dte' ) . '</h2><ul>';
					foreach ( $checks as $key => $label ) {
									$ok = false;
						if ( 'cert_path' === $key ) {
										$ok = ! empty( $cfg['cert_path'] ) && file_exists( $cfg['cert_path'] );
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
			$current = function_exists( 'get_option' ) ? get_option( Settings::OPTION_NAME, array() ) : array();
			$output  = is_array( $current ) ? $current : array();

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

		if ( isset( $input['giros'] ) ) {
				$giros     = is_array( $input['giros'] ) ? $input['giros'] : explode( "\n", (string) $input['giros'] );
				$sanitized = array();
			foreach ( $giros as $giro ) {
						$value = sanitize_text_field( (string) $giro );
				if ( '' !== $value ) {
					$sanitized[] = $value;
				}
			}
			if ( ! empty( $sanitized ) ) {
							$output['giros'] = array_values( array_unique( $sanitized ) );
							$output['giro']  = $output['giros'][0];
			} else {
				$output['giros'] = array();
				$output['giro']  = '';
			}
		} elseif ( isset( $input['giro'] ) ) {
						$value           = sanitize_text_field( $input['giro'] );
						$output['giro']  = $value;
						$output['giros'] = '' === $value ? array() : array( $value );
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

		// Handle certificate upload if present.
		if ( isset( $_FILES['cert_file'] ) && is_array( $_FILES['cert_file'] ) && (int) ( $_FILES['cert_file']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$tmp  = (string) $_FILES['cert_file']['tmp_name']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$name = sanitize_file_name( (string) ( $_FILES['cert_file']['name'] ?? 'cert.p12' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'p12', 'pfx' ), true ) ) {
				add_settings_error( 'cert_file', 'invalid_ext', __( 'Certificate must be a .p12 or .pfx file.', 'sii-boleta-dte' ) );
			} elseif ( ! file_exists( $tmp ) ) {
				add_settings_error( 'cert_file', 'missing_tmp', __( 'Upload failed: temporary file not found.', 'sii-boleta-dte' ) );
			} else {
				$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => WP_CONTENT_DIR . '/uploads' );
				$base    = rtrim( (string) ( $uploads['basedir'] ?? ( WP_CONTENT_DIR . '/uploads' ) ), '/\\' );
				$dir     = $base . '/sii-boleta-dte';
				if ( function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $dir );
				} elseif ( ! is_dir( $dir ) ) {
												@mkdir( $dir, 0755, true );
				}
								$dest = $dir . '/' . $name;
				if ( file_exists( $dest ) ) {
					$filename = pathinfo( $name, PATHINFO_FILENAME );
					$dest     = $dir . '/' . $filename . '-' . time() . '.' . $ext;
				}
				if ( @move_uploaded_file( $tmp, $dest ) ) {
					$output['cert_path'] = $dest;
				} else {
					add_settings_error( 'cert_file', 'move_failed', __( 'Could not save the uploaded certificate.', 'sii-boleta-dte' ) );
				}
			}
		} elseif ( isset( $input['cert_path'] ) ) {
			// Manual path entered by user; keep only file name if a path was provided for safety.
			$path = trim( (string) $input['cert_path'] );
			if ( '' !== $path ) {
				// Allow absolute paths; otherwise, sanitize to filename.
				if ( preg_match( '#^[a-zA-Z]:\\\\|^/|^\\\\#', $path ) ) {
					$output['cert_path'] = sanitize_text_field( $path );
				} else {
					$output['cert_path'] = sanitize_file_name( $path );
				}
			} else {
				$output['cert_path'] = '';
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
			$output['pdf_logo'] = (int) $input['pdf_logo'];
		}

			$output['pdf_show_logo'] = empty( $input['pdf_show_logo'] ) ? 0 : 1;

		if ( isset( $input['pdf_footer'] ) ) {
			$output['pdf_footer'] = sanitize_text_field( $input['pdf_footer'] );
		}

		if ( isset( $input['smtp_profile'] ) ) {
			$output['smtp_profile'] = sanitize_text_field( $input['smtp_profile'] );
		}

						$output['enable_logging'] = empty( $input['enable_logging'] ) ? 0 : 1;

						Settings::clear_cache();

						return $output;
	}
}

class_alias( SettingsPage::class, 'SII_Boleta_Settings_Page' );
