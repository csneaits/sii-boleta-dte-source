<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Security\CertificateStorage;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

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
                add_settings_section( 'sii_boleta_emitter', __( 'Emisor', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'rut_emisor', __( 'RUT', 'sii-boleta-dte' ), array( $this, 'field_rut_emisor' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'razon_social', __( 'Razón Social', 'sii-boleta-dte' ), array( $this, 'field_razon_social' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'giro', __( 'Giro', 'sii-boleta-dte' ), array( $this, 'field_giro' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'direccion', __( 'Dirección', 'sii-boleta-dte' ), array( $this, 'field_direccion' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'comuna', __( 'Comuna', 'sii-boleta-dte' ), array( $this, 'field_comuna' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
		add_settings_field( 'acteco', __( 'Código Acteco', 'sii-boleta-dte' ), array( $this, 'field_acteco' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
                add_settings_field( 'cdg_sii_sucur', __( 'CdgSiiSucur (opcional)', 'sii-boleta-dte' ), array( $this, 'field_cdg_sii_sucur' ), 'sii-boleta-dte', 'sii_boleta_emitter' );
                add_settings_field( 'cdg_vendedor', __( 'CdgVendedor (opcional)', 'sii-boleta-dte' ), array( $this, 'field_cdg_vendedor' ), 'sii-boleta-dte', 'sii_boleta_emitter' );

                // Certificate and CAF.
                add_settings_section( 'sii_boleta_cert', __( 'Certificado y CAF', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'cert_path', __( 'Ruta del certificado', 'sii-boleta-dte' ), array( $this, 'field_cert_path' ), 'sii-boleta-dte', 'sii_boleta_cert' );
                        add_settings_field( 'cert_pass', __( 'Contraseña del certificado', 'sii-boleta-dte' ), array( $this, 'field_cert_pass' ), 'sii-boleta-dte', 'sii_boleta_cert' );
                add_settings_field( 'use_libredte_ws', __( 'Usar cliente WS de LibreDTE', 'sii-boleta-dte' ), array( $this, 'field_use_libredte_ws' ), 'sii-boleta-dte', 'sii_boleta_cert' );
                add_settings_field( 'prefer_libredte_recibos', __( 'Firmar EnvioRecibos con LibreDTE (si disponible)', 'sii-boleta-dte' ), array( $this, 'field_prefer_libredte_recibos' ), 'sii-boleta-dte', 'sii_boleta_cert' );

                // Environment and document types.
                add_settings_section( 'sii_boleta_env', __( 'Ambiente', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'environment', __( 'Ambiente', 'sii-boleta-dte' ), array( $this, 'field_environment' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'iva_rate', __( 'Tasa IVA (%)', 'sii-boleta-dte' ), array( $this, 'field_iva_rate' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'enabled_types', __( 'Tipos DTE habilitados', 'sii-boleta-dte' ), array( $this, 'field_enabled_types' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'woocommerce_preview_only', __( 'Previsualizar WooCommerce (pruebas)', 'sii-boleta-dte' ), array( $this, 'field_woocommerce_preview_only' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'auto_folio_libredte', __( 'Asignación automática de folios (LibreDTE)', 'sii-boleta-dte' ), array( $this, 'field_auto_folio_libredte' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'dev_sii_simulation_mode', __( 'Simulación de envíos al SII (desarrollo)', 'sii-boleta-dte' ), array( $this, 'field_dev_sii_simulation_mode' ), 'sii-boleta-dte', 'sii_boleta_env' );

                // Validation & Sanitization (LibreDTE)
                add_settings_section( 'sii_boleta_validation', __( 'Validación y sanitización (LibreDTE)', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'sanitize_with_libredte', __( 'Sanitizar documentos con LibreDTE', 'sii-boleta-dte' ), array( $this, 'field_sanitize_with_libredte' ), 'sii-boleta-dte', 'sii_boleta_validation' );
                add_settings_field( 'validate_schema_libredte', __( 'Validar esquema XML (XSD)', 'sii-boleta-dte' ), array( $this, 'field_validate_schema_libredte' ), 'sii-boleta-dte', 'sii_boleta_validation' );
                add_settings_field( 'validate_signature_libredte', __( 'Validar firma digital', 'sii-boleta-dte' ), array( $this, 'field_validate_signature_libredte' ), 'sii-boleta-dte', 'sii_boleta_validation' );

                // PDF Options.
                add_settings_section( 'sii_boleta_pdf', __( 'Opciones de PDF', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'pdf_format', __( 'Formato', 'sii-boleta-dte' ), array( $this, 'field_pdf_format' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
                add_settings_field( 'pdf_logo', __( 'Logo de la empresa', 'sii-boleta-dte' ), array( $this, 'field_pdf_logo' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
                add_settings_field( 'pdf_show_logo', __( 'Mostrar logo', 'sii-boleta-dte' ), array( $this, 'field_pdf_show_logo' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
                add_settings_field( 'pdf_footer', __( 'Nota de pie', 'sii-boleta-dte' ), array( $this, 'field_pdf_footer' ), 'sii-boleta-dte', 'sii_boleta_pdf' );

                // Per-DTE PDF/print settings
                add_settings_field( 'pdf_per_type', __( 'Configuración PDF por tipo', 'sii-boleta-dte' ), array( $this, 'field_pdf_per_type' ), 'sii-boleta-dte', 'sii_boleta_pdf' );

                // Automation.

                // SMTP profile.
                add_settings_section( 'sii_boleta_smtp', __( 'SMTP', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
        add_settings_field( 'smtp_profile', __( 'Perfil SMTP', 'sii-boleta-dte' ), array( $this, 'field_smtp_profile' ), 'sii-boleta-dte', 'sii_boleta_smtp' );

		// Logging.
                add_settings_section( 'sii_boleta_logging', __( 'Registro', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'enable_logging', __( 'Habilitar registro en archivo', 'sii-boleta-dte' ), array( $this, 'field_enable_logging' ), 'sii-boleta-dte', 'sii_boleta_logging' );

                // Depuración / limpieza
                add_settings_section( 'sii_boleta_debug', __( 'Depuración y limpieza', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'debug_retention_days', __( 'Retención de renders debug (días)', 'sii-boleta-dte' ), array( $this, 'field_debug_retention_days' ), 'sii-boleta-dte', 'sii_boleta_debug' );
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
            $settings = $this->settings->get_settings();
            $giros    = array();
            if ( isset( $settings['giros'] ) && is_array( $settings['giros'] ) ) {
                    $giros = array_values( array_filter( array_map( 'strval', $settings['giros'] ) ) );
            }
            if ( empty( $giros ) && ! empty( $settings['giro'] ) ) {
                    $giros = array( (string) $settings['giro'] );
            }
            if ( empty( $giros ) ) {
                    $giros = array( '' );
            }

            $option_key   = esc_attr( Settings::OPTION_NAME );
            $remove_label = esc_attr__( 'Remove giro', 'sii-boleta-dte' );
            echo '<div id="sii-dte-giros-container" class="sii-dte-giros">';
            foreach ( $giros as $giro ) {
                    $value = esc_attr( $giro );
                    echo '<div class="sii-dte-giro-row">';
                    echo '<input type="text" class="regular-text sii-input-wide" name="' . $option_key . '[giros][]" value="' . $value . '" />';
                    echo '<button type="button" class="button sii-dte-remove-giro" aria-label="' . $remove_label . '" title="' . $remove_label . '">&times;</button>';
                    echo '</div>';
            }
            echo '</div>';
            echo '<p><button type="button" class="button" id="sii-dte-add-giro" data-remove-label="' . $remove_label . '">' . esc_html__( 'Agregar giro', 'sii-boleta-dte' ) . '</button></p>';
            echo '<p class="description">' . esc_html__( 'El primer giro será usado como predeterminado al emitir documentos.', 'sii-boleta-dte' ) . '</p>';
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

	public function field_cdg_vendedor(): void {
		$settings = $this->settings->get_settings();
		$value    = esc_attr( $settings['cdg_vendedor'] ?? '' );
		echo '<input type="text" name="' . esc_attr( Settings::OPTION_NAME ) . '[cdg_vendedor]" value="' . $value . '" />';
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

                /**
                 * Toggle to use LibreDTE's SiiLazy client as transport to SII (SOAP), with fallback to HTTP.
                 */
                public function field_use_libredte_ws(): void {
                        $settings = $this->settings->get_settings();
                        $checked  = ! empty( $settings['use_libredte_ws'] );
                        $name     = esc_attr( Settings::OPTION_NAME ) . '[use_libredte_ws]';
                        echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Consumir servicios del SII con el cliente LibreDTE (SOAP/WSDL) cuando sea posible; si falla, se usa el transporte HTTP actual.', 'sii-boleta-dte' ) . '</label>';
                        echo '<p class="description">' . esc_html__( 'Recomendado para una integración nativa con LibreDTE. Mantiene el fallback a HTTP para no interrumpir flujos.', 'sii-boleta-dte' ) . '</p>';
                }

                /**
                 * Prefer delegating EnvioRecibos signing to LibreDTE when the library exposes it; fallback to xmlseclibs otherwise.
                 */
                public function field_prefer_libredte_recibos(): void {
                        $settings = $this->settings->get_settings();
                        $checked  = ! empty( $settings['prefer_libredte_recibos'] );
                        $name     = esc_attr( Settings::OPTION_NAME ) . '[prefer_libredte_recibos]';
                        echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Intentar usar LibreDTE para firmar EnvioRecibos; si no está disponible, se usa xmlseclibs.', 'sii-boleta-dte' ) . '</label>';
                }

	public function field_environment(): void {
		$settings = $this->settings->get_settings();
		$value    = $settings['environment'] ?? '0';
		$name     = esc_attr( Settings::OPTION_NAME ) . '[environment]';
		echo '<select name="' . $name . '">';
                        echo '<option value="2"' . selected( '2', (string) $value, false ) . '>' . esc_html__( 'Development', 'sii-boleta-dte' ) . '</option>';
		echo '<option value="0"' . selected( '0', (string) $value, false ) . '>' . esc_html__( 'Test', 'sii-boleta-dte' ) . '</option>';
		echo '<option value="1"' . selected( '1', (string) $value, false ) . '>' . esc_html__( 'Production', 'sii-boleta-dte' ) . '</option>';
		echo '</select>';
	}

                public function field_auto_folio_libredte(): void {
                        $settings = $this->settings->get_settings();
                        $checked  = ! empty( $settings['auto_folio_libredte'] );
                        $name     = esc_attr( Settings::OPTION_NAME ) . '[auto_folio_libredte]';
                        echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Usar CafProviderWorker para asignar el próximo folio y CAF automáticamente cuando no se indique Folio.', 'sii-boleta-dte' ) . '</label>';
                        echo '<p class="description">' . esc_html__( 'En desarrollo se usan CAF ficticios. En certificación y producción se usan los CAF cargados en Folios.', 'sii-boleta-dte' ) . '</p>';
                }

                public function field_dev_sii_simulation_mode(): void {
                        $settings    = $this->settings->get_settings();
                        $environment = $this->settings->get_environment();
                        $current     = isset( $settings['dev_sii_simulation_mode'] ) ? (string) $settings['dev_sii_simulation_mode'] : 'disabled';
                        $name        = esc_attr( Settings::OPTION_NAME ) . '[dev_sii_simulation_mode]';
                        $options     = array(
                                'disabled' => esc_html__( 'Desactivado (enviar al SII normalmente)', 'sii-boleta-dte' ),
                                'success'  => esc_html__( 'Simular envíos exitosos (cola marcada como "sent")', 'sii-boleta-dte' ),
                                'error'    => esc_html__( 'Simular errores al enviar (cola reintentará)', 'sii-boleta-dte' ),
                        );
                        $disabled_attr = '2' === $environment ? '' : ' disabled="disabled"';

                        echo '<fieldset class="sii-dte-dev-simulation">';
                        foreach ( $options as $value => $label ) {
                                echo '<label style="display:block; margin-bottom:4px;"><input type="radio" name="' . $name . '" value="' . esc_attr( $value ) . '"' . checked( $current, $value, false ) . $disabled_attr . ' /> ' . $label . '</label>';
                        }
                        echo '</fieldset>';

                        if ( '2' === $environment ) {
                                echo '<p class="description">' . esc_html__( 'Disponible solo en el ambiente de desarrollo para probar integraciones sin contactar al SII real.', 'sii-boleta-dte' ) . '</p>';
                        } else {
                                echo '<p class="description">' . esc_html__( 'Solo modificable en desarrollo. En certificación y producción los envíos se ejecutan normalmente.', 'sii-boleta-dte' ) . '</p>';
                        }
                }

        public function field_iva_rate(): void {
                $settings = $this->settings->get_settings();
                $value    = isset( $settings['iva_rate'] ) ? (float) $settings['iva_rate'] : 19.0;
                if ( $value < 0 ) { $value = 0.0; }
                if ( $value > 100 ) { $value = 100.0; }
                $name = esc_attr( Settings::OPTION_NAME ) . '[iva_rate]';
                echo '<input type="number" min="0" max="100" step="0.01" style="width:120px" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
                echo '<p class="description">' . esc_html__( 'Sobrescribe la tasa por defecto (19%) usada cuando no se especifica TasaIVA en un documento.', 'sii-boleta-dte' ) . '</p>';
        }

        /** Toggle: sanitize_with_libredte */
        public function field_sanitize_with_libredte(): void {
                $settings = $this->settings->get_settings();
                // Default ON; if absent in DB, present the checkbox as checked.
                $checked  = array_key_exists( 'sanitize_with_libredte', $settings ) ? ! empty( $settings['sanitize_with_libredte'] ) : true;
                $name     = esc_attr( Settings::OPTION_NAME ) . '[sanitize_with_libredte]';
                echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Aplicar limpieza/normalización usando los workers de LibreDTE cuando estén disponibles.', 'sii-boleta-dte' ) . '</label>';
                echo '<p class="description">' . esc_html__( 'Activado por defecto en todos los ambientes. Desmarca para desactivar.', 'sii-boleta-dte' ) . '</p>';
        }

        /** Toggle: validate_schema_libredte */
        public function field_validate_schema_libredte(): void {
                $settings = $this->settings->get_settings();
                $checked  = array_key_exists( 'validate_schema_libredte', $settings ) ? ! empty( $settings['validate_schema_libredte'] ) : true;
                $name     = esc_attr( Settings::OPTION_NAME ) . '[validate_schema_libredte]';
                echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Validar la estructura XML (XSD) del documento antes de enviarlo.', 'sii-boleta-dte' ) . '</label>';
                echo '<p class="description">' . esc_html__( 'Activado por defecto en todos los ambientes. Desmarca para desactivar.', 'sii-boleta-dte' ) . '</p>';
        }

        /** Toggle: validate_signature_libredte */
        public function field_validate_signature_libredte(): void {
                $settings = $this->settings->get_settings();
                $checked  = array_key_exists( 'validate_signature_libredte', $settings ) ? ! empty( $settings['validate_signature_libredte'] ) : true;
                $name     = esc_attr( Settings::OPTION_NAME ) . '[validate_signature_libredte]';
                echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Validar la firma digital cuando proceda.', 'sii-boleta-dte' ) . '</label>';
                echo '<p class="description">' . esc_html__( 'Activado por defecto en todos los ambientes. Desmarca para desactivar.', 'sii-boleta-dte' ) . '</p>';
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

        public function field_woocommerce_preview_only(): void {
                $settings    = $this->settings->get_settings();
                $checked     = ! empty( $settings['woocommerce_preview_only'] );
                $name        = esc_attr( Settings::OPTION_NAME ) . '[woocommerce_preview_only]';
                $environment = $this->settings->get_environment();
                $disabled    = '0' === $environment ? '' : ' disabled="disabled"';
                echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . $disabled . ' /> ' . esc_html__( 'Omitir envío al SII para pedidos de WooCommerce mientras se prueba', 'sii-boleta-dte' ) . '</label>';
                if ( '0' === $environment ) {
                        echo '<p class="description">' . esc_html__( 'Cuando está activo, los pedidos sólo generan un PDF de previsualización y no consumen folios ni se envían al SII.', 'sii-boleta-dte' ) . '</p>';
                } else {
                        echo '<p class="description">' . esc_html__( 'Disponible únicamente en el ambiente de certificación.', 'sii-boleta-dte' ) . '</p>';
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
                                        $url = (string) call_user_func( 'wp_get_attachment_url', $value );
		}
		$name = esc_attr( Settings::OPTION_NAME ) . '[pdf_logo]';
                echo '<div class="sii-dte-logo-field">';
                echo '<img id="sii-dte-logo-preview" src="' . esc_url( $url ) . '" alt="" />';
                echo '<div class="sii-dte-logo-actions">';
                echo '<input type="hidden" id="sii_dte_logo_id" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
                echo '<button type="button" class="button" id="sii-dte-select-logo">' . esc_html__( 'Seleccionar logo', 'sii-boleta-dte' ) . '</button>';
                echo '<button type="button" class="button" id="sii-dte-remove-logo">' . esc_html__( 'Eliminar logo', 'sii-boleta-dte' ) . '</button>';
                echo '</div>';
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

        /**
         * Renders a simple per-DTE settings UI allowing different template/format and paper size per type.
         */
        public function field_pdf_per_type(): void {
                $settings = $this->settings->get_settings();
                $per_type = isset( $settings['pdf_per_type'] ) && is_array( $settings['pdf_per_type'] ) ? $settings['pdf_per_type'] : array();
                $types = array(
                        33 => __( 'Factura (33)', 'sii-boleta-dte' ),
                        39 => __( 'Boleta (39)', 'sii-boleta-dte' ),
                );
                $option_key = esc_attr( Settings::OPTION_NAME );
                echo '<div class="sii-dte-per-type">';
                foreach ( $types as $code => $label ) {
                        $cfg = isset( $per_type[ $code ] ) && is_array( $per_type[ $code ] ) ? $per_type[ $code ] : array();
                        $template = esc_attr( $cfg['template'] ?? ( 39 === (int) $code ? 'boleta_ticket' : 'estandar' ) );
                        $paper_w = esc_attr( $cfg['paper_width'] ?? '' );
                        $paper_h = esc_attr( $cfg['paper_height'] ?? '' );

                        echo '<fieldset style="border:1px solid #eee; padding:8px; margin-bottom:8px;">';
                        echo '<legend style="font-weight:700;">' . esc_html( $label ) . '</legend>';
                        echo '<p><label>' . esc_html__( 'Template', 'sii-boleta-dte' ) . ': ';
                        echo '<select name="' . $option_key . '[pdf_per_type][' . esc_attr( $code ) . '][template]">';
                        echo '<option value="estandar"' . selected( 'estandar', $template, false ) . '>Estandar</option>';
                        echo '<option value="boleta_ticket"' . selected( 'boleta_ticket', $template, false ) . '>Boleta (ticket)</option>';
                        echo '</select></label></p>';
                        echo '<p>' . esc_html__( 'Tamaño de papel (ancho x alto en mm). Dejar vacío para usar los valores por defecto.', 'sii-boleta-dte' ) . '</p>';
                        echo '<p><input type="text" name="' . $option_key . '[pdf_per_type][' . esc_attr( $code ) . '][paper_width]" value="' . $paper_w . '" size="6" placeholder="Ancho mm" /> × ';
                        echo '<input type="text" name="' . $option_key . '[pdf_per_type][' . esc_attr( $code ) . '][paper_height]" value="' . $paper_h . '" size="6" placeholder="Alto mm" /></p>';
                        echo '</fieldset>';
                }
                echo '</div>';
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

        public function field_debug_retention_days(): void {
                $settings = $this->settings->get_settings();
                $value    = isset( $settings['debug_retention_days'] ) ? (int) $settings['debug_retention_days'] : ( defined( 'SII_BOLETA_DTE_DEBUG_RETENTION_DAYS' ) ? (int) SII_BOLETA_DTE_DEBUG_RETENTION_DAYS : 7 );
                if ( $value < 1 ) { $value = 7; }
                if ( $value > 30 ) { $value = 30; }
                $name = esc_attr( Settings::OPTION_NAME ) . '[debug_retention_days]';
                echo '<input type="number" min="1" max="30" step="1" style="width:90px" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
                echo '<p class="description">' . esc_html__( 'Días que se conservarán los PDFs de depuración antes de borrarlos automáticamente.', 'sii-boleta-dte' ) . '</p>';
        }

	/**
	 * Outputs the settings page markup.
	 */
    public function render_page(): void {
        if ( ! function_exists( 'settings_fields' ) ) {
            return;
        }

                $steps = array(
                        'emitter'      => array(
                                'label'       => __( 'Emisor', 'sii-boleta-dte' ),
                                'title'       => __( 'Identidad comercial', 'sii-boleta-dte' ),
                                'description' => __( 'Completa la información legal que aparecerá en cada documento enviado al SII.', 'sii-boleta-dte' ),
                                'sections'    => array( 'sii_boleta_emitter' ),
                        ),
                        'credentials'  => array(
                                'label'       => __( 'Credenciales', 'sii-boleta-dte' ),
                                'title'       => __( 'Certificado y ambiente', 'sii-boleta-dte' ),
                                'description' => __( 'Sube el certificado de firma, define contraseñas y elige el ambiente de pruebas o producción.', 'sii-boleta-dte' ),
                                'sections'    => array( 'sii_boleta_cert', 'sii_boleta_env' ),
                        ),
                        'pdf'          => array(
                                'label'       => __( 'Branding', 'sii-boleta-dte' ),
                                'title'       => __( 'Diseño PDF', 'sii-boleta-dte' ),
                                'description' => __( 'Personaliza la plantilla PDF, elige el formato y agrega tu logo o notas de pie de página.', 'sii-boleta-dte' ),
                                'sections'    => array( 'sii_boleta_pdf' ),
                        ),
                        'automation'   => array(
                                'label'       => __( 'Correo', 'sii-boleta-dte' ),
                                'title'       => __( 'Perfiles SMTP', 'sii-boleta-dte' ),
                                'description' => __( 'Enlaza perfiles SMTP para los correos transaccionales generados por el plugin.', 'sii-boleta-dte' ),
                                'sections'    => array( 'sii_boleta_smtp' ),
                        ),
                        'validation' => array(
                                'label'       => __( 'Validación', 'sii-boleta-dte' ),
                                'title'       => __( 'Sanitización y validación', 'sii-boleta-dte' ),
                                'description' => __( 'Controla la sanitización y validaciones de esquema y firma aplicadas por LibreDTE.', 'sii-boleta-dte' ),
                                'sections'    => array( 'sii_boleta_validation' ),
                        ),
                        'observability' => array(
                                'label'       => __( 'Observabilidad', 'sii-boleta-dte' ),
                                'title'       => __( 'Diagnósticos y registros', 'sii-boleta-dte' ),
                                'description' => __( 'Mantén un historial activando registros en archivos para cada interacción con el SII.', 'sii-boleta-dte' ),
                                'sections'    => array( 'sii_boleta_logging' ),
                        ),
                );

        $step_ids        = array_keys( $steps );
        $first_step_id   = (string) reset( $step_ids );
        $requirements    = $this->render_requirements_check();

        AdminStyles::open_container( 'sii-settings-page' );
        echo '<div class="sii-settings-wizard sii-dte-settings">';
        echo '<h1>' . esc_html__( 'SII Boleta DTE', 'sii-boleta-dte' ) . '</h1>';
        echo '<p class="sii-settings-subtitle">' . esc_html__( 'Configura todos los aspectos de tu flujo de facturación electrónica con una interfaz guiada paso a paso.', 'sii-boleta-dte' ) . '</p>';
        echo '<div class="sii-settings-layout">';
        echo '<div class="sii-settings-card">';
                        if ( function_exists( 'settings_errors' ) ) {
                                call_user_func( 'settings_errors' );
        }

        echo '<ul class="sii-settings-steps" role="tablist">';
        foreach ( $steps as $step_id => $step ) {
            $is_active = $first_step_id === $step_id;
            $classes   = $is_active ? 'is-active' : '';
            $class_attr = $classes ? ' class="' . esc_attr( $classes ) . '"' : '';
            echo '<li' . $class_attr . '>';
            echo '<button type="button" class="sii-settings-step-button" role="tab" id="sii-settings-tab-' . esc_attr( $step_id ) . '" data-step="' . esc_attr( $step_id ) . '" aria-controls="sii-settings-step-' . esc_attr( $step_id ) . '" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '" tabindex="' . ( $is_active ? '0' : '-1' ) . '"><span class="sii-settings-step-title">' . esc_html( $step['label'] ) . '</span></button>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<form method="post" action="options.php" enctype="multipart/form-data" class="sii-settings-form">';
                if ( function_exists( 'settings_fields' ) ) {
                        call_user_func( 'settings_fields', Settings::OPTION_GROUP );
                }

        foreach ( $step_ids as $position => $step_id ) {
            $step       = $steps[ $step_id ];
            $is_active  = $step_id === $first_step_id;
            $next_step  = $step_ids[ $position + 1 ] ?? '';
            $prev_step  = $step_ids[ $position - 1 ] ?? '';
            $active_cls = $is_active ? ' is-active' : '';
            $hidden     = $is_active ? '' : ' hidden';
            echo '<section id="sii-settings-step-' . esc_attr( $step_id ) . '" class="sii-settings-step' . esc_attr( $active_cls ) . '" data-step="' . esc_attr( $step_id ) . '" role="tabpanel" aria-labelledby="sii-settings-tab-' . esc_attr( $step_id ) . '"' . $hidden . ' aria-hidden="' . ( $is_active ? 'false' : 'true' ) . '">';
            echo '<header class="sii-settings-step-header">';
            echo '<h2 class="sii-settings-step-heading">' . esc_html( $step['title'] ) . '</h2>';
            echo '<p class="sii-settings-step-description">' . esc_html( $step['description'] ) . '</p>';
            echo '</header>';

            foreach ( $step['sections'] as $section_id ) {
                $this->render_settings_section( 'sii-boleta-dte', $section_id );
            }

            echo '<nav class="sii-step-navigation">';
                        if ( ! empty( $prev_step ) ) {
                                echo '<button type="button" class="button button-secondary" data-step-prev="' . esc_attr( $prev_step ) . '">' . esc_html__( 'Atrás', 'sii-boleta-dte' ) . '</button>';
                        }
                        if ( ! empty( $next_step ) ) {
                                echo '<button type="button" class="button button-primary" data-step-next="' . esc_attr( $next_step ) . '">' . esc_html__( 'Continuar', 'sii-boleta-dte' ) . '</button>';
            } else {
                                if ( function_exists( 'get_submit_button' ) ) {
                                        echo (string) call_user_func( 'get_submit_button', __( 'Guardar cambios', 'sii-boleta-dte' ), 'primary', 'submit', false );
                                } elseif ( function_exists( 'submit_button' ) ) {
                                        call_user_func( 'submit_button', __( 'Guardar cambios', 'sii-boleta-dte' ) );
                                } else {
                                        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar cambios', 'sii-boleta-dte' ) . '</button>';
                                }
            }
            echo '</nav>';

            echo '</section>';
        }

        echo '</form>';
        echo '</div>';

        if ( '' !== trim( $requirements ) ) {
            echo '<aside class="sii-settings-summary" aria-label="' . esc_attr__( 'Certification checklist', 'sii-boleta-dte' ) . '">';
            echo $requirements;
            echo '</aside>';
        }

        echo '</div>';
        echo '</div>';
        AdminStyles::close_container();
    }

    /**
     * Displays a quick checklist to verify certification readiness.
     */
    private function render_requirements_check(): string {
        $cfg    = $this->settings->get_settings();
        $checks = array(
            'rut_emisor'   => __( 'RUT configured', 'sii-boleta-dte' ),
            'razon_social' => __( 'Razón Social configured', 'sii-boleta-dte' ),
            'cert_path'    => __( 'Certificate file present', 'sii-boleta-dte' ),
        );

        ob_start();
        echo '<div class="sii-settings-summary-card">';
        echo '<h2 class="sii-settings-summary-title">' . esc_html__( 'Certification readiness', 'sii-boleta-dte' ) . '</h2>';
        echo '<ul class="sii-settings-checklist">';
        foreach ( $checks as $key => $label ) {
            $ok = false;
            if ( 'cert_path' === $key ) {
                $ok = ! empty( $cfg['cert_path'] ) && file_exists( (string) $cfg['cert_path'] );
            } else {
                $ok = ! empty( $cfg[ $key ] );
            }
            $icon_class = $ok ? '' : ' is-bad';
            $icon       = $ok ? '&#10003;' : '&#10007;';
            echo '<li><span class="sii-settings-status-icon' . esc_attr( $icon_class ) . '">' . $icon . '</span>' . esc_html( $label ) . '</li>';
        }
        echo '</ul>';
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Outputs registered WordPress settings sections inside our custom layout.
     */
    private function render_settings_section( string $page, string $section_id ): void {
        global $wp_settings_sections;

        if ( ! isset( $wp_settings_sections[ $page ][ $section_id ] ) ) {
            return;
        }

        $section = $wp_settings_sections[ $page ][ $section_id ];

        echo '<section class="sii-settings-section">';
        if ( ! empty( $section['title'] ) ) {
            echo '<h3 class="sii-settings-section-title">' . esc_html( $section['title'] ) . '</h3>';
        }

        if ( ! empty( $section['callback'] ) ) {
            call_user_func( $section['callback'], $section );
        }

                        if ( function_exists( 'do_settings_fields' ) ) {
            ob_start();
                                call_user_func( 'do_settings_fields', $page, $section_id );
            $fields_markup = (string) ob_get_clean();
            if ( '' !== trim( $fields_markup ) ) {
                echo '<table class="form-table">' . $fields_markup . '</table>';
            }
        }

        echo '</section>';
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

                if ( isset( $output['environment'] ) ) {
                        $saved_environment = Settings::normalize_environment( (string) $output['environment'] );
                } else {
                        $saved_environment = $this->settings->get_environment();
                }

                if ( isset( $input['environment'] ) ) {
                        $requested_environment = Settings::normalize_environment( (string) $input['environment'] );
                } else {
                        $requested_environment = $saved_environment;
                }

                $output['woocommerce_preview_only'] = '0' === $requested_environment && ! empty( $input['woocommerce_preview_only'] ) ? 1 : 0;

                $allowed_simulation_modes = array( 'disabled', 'success', 'error' );
                if ( '2' === $requested_environment ) {
                        if ( isset( $input['dev_sii_simulation_mode'] ) && in_array( (string) $input['dev_sii_simulation_mode'], $allowed_simulation_modes, true ) ) {
                                $output['dev_sii_simulation_mode'] = (string) $input['dev_sii_simulation_mode'];
                        } elseif ( ! isset( $output['dev_sii_simulation_mode'] ) || ! in_array( (string) $output['dev_sii_simulation_mode'], $allowed_simulation_modes, true ) ) {
                                $output['dev_sii_simulation_mode'] = 'disabled';
                        }
                } else {
                        $output['dev_sii_simulation_mode'] = 'disabled';
                }

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
                        $giros_raw = $input['giros'];
                        $giros     = array();
                        if ( is_array( $giros_raw ) ) {
                                foreach ( $giros_raw as $giro_value ) {
                                        $giro = sanitize_text_field( (string) $giro_value );
                                        if ( '' !== $giro ) {
                                                $giros[] = $giro;
                                        }
                                }
                        } elseif ( is_string( $giros_raw ) ) {
                                $giro = sanitize_text_field( $giros_raw );
                                if ( '' !== $giro ) {
                                        $giros[] = $giro;
                                }
                        }
                        $output['giros'] = $giros;
                        if ( ! empty( $giros ) ) {
                                $output['giro'] = $giros[0];
                        } elseif ( isset( $output['giro'] ) ) {
                                unset( $output['giro'] );
                        }
                }

                if ( isset( $input['giro'] ) && ! isset( $input['giros'] ) ) {
                        $giro = sanitize_text_field( $input['giro'] );
                        $output['giro'] = $giro;
                        if ( ! isset( $output['giros'] ) || ! is_array( $output['giros'] ) || empty( $output['giros'] ) ) {
                                $output['giros'] = '' === $giro ? array() : array( $giro );
                        }
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

                if ( isset( $input['cdg_vendedor'] ) ) {
                        $output['cdg_vendedor'] = sanitize_text_field( $input['cdg_vendedor'] );
                }

                // LibreDTE validation/sanitization toggles
                $output['sanitize_with_libredte']     = empty( $input['sanitize_with_libredte'] ) ? 0 : 1;
                $output['validate_schema_libredte']   = empty( $input['validate_schema_libredte'] ) ? 0 : 1;
                $output['validate_signature_libredte'] = empty( $input['validate_signature_libredte'] ) ? 0 : 1;

		$previous_cert_path = isset( $output['cert_path'] ) ? (string) $output['cert_path'] : '';

		// Handle certificate upload if present.
		if ( isset( $_FILES['cert_file'] ) && is_array( $_FILES['cert_file'] ) && (int) ( $_FILES['cert_file']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
			$tmp  = (string) $_FILES['cert_file']['tmp_name'];
			$name = sanitize_file_name( (string) ( $_FILES['cert_file']['name'] ?? 'cert.p12' ) );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'p12', 'pfx' ), true ) ) {
				add_settings_error( 'cert_file', 'invalid_ext', __( 'Certificate must be a .p12 or .pfx file.', 'sii-boleta-dte' ) );
			} elseif ( ! file_exists( $tmp ) ) {
				add_settings_error( 'cert_file', 'missing_tmp', __( 'Upload failed: temporary file not found.', 'sii-boleta-dte' ) );
			} else {
				$stored = CertificateStorage::store_uploaded( $tmp, $name );
				if ( null === $stored ) {
					add_settings_error( 'cert_file', 'move_failed', __( 'Could not save the uploaded certificate.', 'sii-boleta-dte' ) );
				} else {
					if ( '' !== $previous_cert_path && $previous_cert_path !== $stored ) {
						CertificateStorage::delete_if_managed( $previous_cert_path );
					}
					$output['cert_path'] = $stored;
				}
			}
		} elseif ( isset( $input['cert_path'] ) ) {
            $path = trim( (string) $input['cert_path'] );
            if ( '' === $path ) {
                $output['cert_path'] = '';
            } else {
                $normalizer = static function ( string $value ): string {
                    if ( function_exists( 'wp_normalize_path' ) ) {
                        return wp_normalize_path( $value );
                    }
                    return str_replace( '\\', '/', $value );
                };

                $normalized = $normalizer( $path );

                // Reject directory traversal attempts.
                if ( preg_match( '#(^|/)\.\.(?:/|$)#', $normalized ) ) {
                    if ( function_exists( 'add_settings_error' ) ) {
                        add_settings_error( 'cert_path', 'invalid_path', __( 'Invalid certificate path.', 'sii-boleta-dte' ) );
                    }
                    $output['cert_path'] = '';
                } else {
                    $resolved = $normalized;
                    $candidates = array();

                    // If the path is not absolute, try resolving it relative to common WordPress roots.
                    if ( ! preg_match( '#^[a-zA-Z]:[\\/]|^/|^\\\\#', $normalized ) ) {
                        $trimmed = ltrim( $normalized, '/\\' );
                        if ( defined( 'ABSPATH' ) ) {
                            $candidates[] = rtrim( $normalizer( ABSPATH ), '/\\' ) . '/' . $trimmed;
                        }
                        if ( defined( 'WP_CONTENT_DIR' ) ) {
                            $candidates[] = rtrim( $normalizer( WP_CONTENT_DIR ), '/\\' ) . '/' . $trimmed;
                        }
                    } else {
                        $candidates[] = $normalized;
                    }

                    foreach ( $candidates as $candidate ) {
                        $real = realpath( $candidate );
                        if ( false !== $real ) {
                            $resolved = $normalizer( $real );
                            break;
                        }
                    }

                    $output['cert_path'] = sanitize_text_field( $resolved );
                }
            }
        }

		if ( isset( $input['environment'] ) ) {
				$output['environment'] = intval( $input['environment'] );
		}

                // Tasa IVA configurable (0 - 100). No afecta documentos que ya definan Encabezado.Totales.TasaIVA.
                if ( isset( $input['iva_rate'] ) ) {
                        $iva = (float) $input['iva_rate'];
                        if ( $iva < 0 ) { $iva = 0.0; }
                        if ( $iva > 100 ) { $iva = 100.0; }
                        // Opcional: normalizar a 2 decimales.
                        $output['iva_rate'] = round( $iva, 2 );
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

        // Prefer LibreDTE for EnvioRecibos signing when available
        $output['prefer_libredte_recibos'] = empty( $input['prefer_libredte_recibos'] ) ? 0 : 1;

        // Use LibreDTE WS client for SII transport when available
        $output['use_libredte_ws'] = empty( $input['use_libredte_ws'] ) ? 0 : 1;

        // Auto folio assignment via LibreDTE CafProviderWorker
        $output['auto_folio_libredte'] = empty( $input['auto_folio_libredte'] ) ? 0 : 1;

		if ( isset( $input['pdf_format'] ) ) {
			$output['pdf_format'] = sanitize_text_field( $input['pdf_format'] );
		}

		if ( isset( $input['pdf_logo'] ) ) {
			$output['pdf_logo'] = (int) $input['pdf_logo'];
		}

			$output['pdf_show_logo'] = empty( $input['pdf_show_logo'] ) ? 0 : 1;

                        if ( isset( $input['pdf_footer'] ) ) {
                                // Preserve line breaks in footer notes
                                if ( function_exists( 'sanitize_textarea_field' ) ) {
                                        $output['pdf_footer'] = sanitize_textarea_field( $input['pdf_footer'] );
                                } else {
                                        $output['pdf_footer'] = sanitize_text_field( $input['pdf_footer'] );
                                }
                        }

                // Sanitize per-type PDF settings
                if ( isset( $input['pdf_per_type'] ) && is_array( $input['pdf_per_type'] ) ) {
                        $allowed_templates = array( 'estandar', 'boleta_ticket' );
                        $per_type_clean = array();
                        foreach ( $input['pdf_per_type'] as $type_code => $cfg ) {
                                $type_int = (int) $type_code;
                                if ( $type_int <= 0 ) {
                                        continue;
                                }
                                if ( ! is_array( $cfg ) ) {
                                        continue;
                                }
                                $template = isset( $cfg['template'] ) ? sanitize_text_field( $cfg['template'] ) : '';
                                if ( ! in_array( $template, $allowed_templates, true ) ) {
                                        $template = 'estandar';
                                }
                                $w = isset( $cfg['paper_width'] ) ? preg_replace( '/[^0-9\.]/', '', (string) $cfg['paper_width'] ) : '';
                                $h = isset( $cfg['paper_height'] ) ? preg_replace( '/[^0-9\.]/', '', (string) $cfg['paper_height'] ) : '';
                                $entry = array( 'template' => $template );
                                if ( '' !== $w ) { $entry['paper_width'] = $w; }
                                if ( '' !== $h ) { $entry['paper_height'] = $h; }
                                $per_type_clean[ $type_int ] = $entry;
                        }
                        if ( ! empty( $per_type_clean ) ) {
                                $output['pdf_per_type'] = $per_type_clean;
                        }
                }

		if ( isset( $input['smtp_profile'] ) ) {
			$output['smtp_profile'] = sanitize_text_field( $input['smtp_profile'] );
		}

			$output['enable_logging'] = empty( $input['enable_logging'] ) ? 0 : 1;

                // Días de retención de renders debug (permitir 1-30)
                if ( isset( $input['debug_retention_days'] ) ) {
                        $days = (int) $input['debug_retention_days'];
                        if ( $days < 1 ) { $days = 1; }
                        if ( $days > 30 ) { $days = 30; }
                        $output['debug_retention_days'] = $days;
                }

			return $output;
	}
}

class_alias( SettingsPage::class, 'SII_Boleta_Settings_Page' );
