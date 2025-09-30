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

		// Environment and document types.
		add_settings_section( 'sii_boleta_env', __( 'Environment', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'environment', __( 'Environment', 'sii-boleta-dte' ), array( $this, 'field_environment' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'enabled_types', __( 'Enabled DTE Types', 'sii-boleta-dte' ), array( $this, 'field_enabled_types' ), 'sii-boleta-dte', 'sii_boleta_env' );
                add_settings_field( 'woocommerce_preview_only', __( 'WooCommerce test preview', 'sii-boleta-dte' ), array( $this, 'field_woocommerce_preview_only' ), 'sii-boleta-dte', 'sii_boleta_env' );

		// PDF Options.
		add_settings_section( 'sii_boleta_pdf', __( 'PDF Options', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
		add_settings_field( 'pdf_format', __( 'Format', 'sii-boleta-dte' ), array( $this, 'field_pdf_format' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
                add_settings_field( 'pdf_logo', __( 'Company Logo', 'sii-boleta-dte' ), array( $this, 'field_pdf_logo' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
                add_settings_field( 'pdf_show_logo', __( 'Show Logo', 'sii-boleta-dte' ), array( $this, 'field_pdf_show_logo' ), 'sii-boleta-dte', 'sii_boleta_pdf' );
                add_settings_field( 'pdf_footer', __( 'Footer Note', 'sii-boleta-dte' ), array( $this, 'field_pdf_footer' ), 'sii-boleta-dte', 'sii_boleta_pdf' );

                // Automation.
                add_settings_section( 'sii_boleta_automation', __( 'Automation', 'sii-boleta-dte' ), '__return_false', 'sii-boleta-dte' );
                add_settings_field( 'rvd_auto', __( 'Daily RVD', 'sii-boleta-dte' ), array( $this, 'field_rvd_auto' ), 'sii-boleta-dte', 'sii_boleta_automation' );
                add_settings_field( 'libro_auto', __( 'Monthly Libro', 'sii-boleta-dte' ), array( $this, 'field_libro_auto' ), 'sii-boleta-dte', 'sii_boleta_automation' );

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
            echo '<p><button type="button" class="button" id="sii-dte-add-giro" data-remove-label="' . $remove_label . '">' . esc_html__( 'Add giro', 'sii-boleta-dte' ) . '</button></p>';
            echo '<p class="description">' . esc_html__( 'The first giro will be used as the default when issuing documents.', 'sii-boleta-dte' ) . '</p>';
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

        public function field_woocommerce_preview_only(): void {
                $settings    = $this->settings->get_settings();
                $checked     = ! empty( $settings['woocommerce_preview_only'] );
                $name        = esc_attr( Settings::OPTION_NAME ) . '[woocommerce_preview_only]';
                $environment = $this->settings->get_environment();
                $disabled    = '0' === $environment ? '' : ' disabled="disabled"';
                echo '<label><input type="checkbox" name="' . $name . '" value="1"' . ( $checked ? ' checked' : '' ) . $disabled . ' /> ' . esc_html__( 'Skip SII submission for WooCommerce orders while testing', 'sii-boleta-dte' ) . '</label>';
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
			$url = (string) wp_get_attachment_url( $value );
		}
		$name = esc_attr( Settings::OPTION_NAME ) . '[pdf_logo]';
                echo '<div class="sii-dte-logo-field">';
                echo '<img id="sii-dte-logo-preview" src="' . esc_url( $url ) . '" alt="" />';
                echo '<div class="sii-dte-logo-actions">';
                echo '<input type="hidden" id="sii_dte_logo_id" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
                echo '<button type="button" class="button" id="sii-dte-select-logo">' . esc_html__( 'Select Logo', 'sii-boleta-dte' ) . '</button>';
                echo '<button type="button" class="button" id="sii-dte-remove-logo">' . esc_html__( 'Remove Logo', 'sii-boleta-dte' ) . '</button>';
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

        public function field_rvd_auto(): void {
                $settings = $this->settings->get_settings();
                $enabled  = ! empty( $settings['rvd_auto_enabled'] );
                $time     = isset( $settings['rvd_auto_time'] ) ? (string) $settings['rvd_auto_time'] : '02:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
                        $time = '02:00';
                }
                $name = esc_attr( Settings::OPTION_NAME );
                echo '<label><input type="checkbox" name="' . $name . '[rvd_auto_enabled]" value="1"' . ( $enabled ? ' checked' : '' ) . ' /> ' . esc_html__( 'Enable automatic daily RVD sending', 'sii-boleta-dte' ) . '</label>';
                echo '<p><label for="sii-boleta-rvd-time">' . esc_html__( 'Send at', 'sii-boleta-dte' ) . '</label> ';
                echo '<input type="time" id="sii-boleta-rvd-time" name="' . $name . '[rvd_auto_time]" value="' . esc_attr( $time ) . '" step="60" /></p>';
        }

        public function field_libro_auto(): void {
                $settings = $this->settings->get_settings();
                $enabled  = ! empty( $settings['libro_auto_enabled'] );
                $day      = isset( $settings['libro_auto_day'] ) ? (int) $settings['libro_auto_day'] : 1;
                if ( $day < 1 || $day > 31 ) {
                        $day = 1;
                }
                $time = isset( $settings['libro_auto_time'] ) ? (string) $settings['libro_auto_time'] : '03:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
                        $time = '03:00';
                }
                $name = esc_attr( Settings::OPTION_NAME );
                echo '<label><input type="checkbox" name="' . $name . '[libro_auto_enabled]" value="1"' . ( $enabled ? ' checked' : '' ) . ' /> ' . esc_html__( 'Enable automatic monthly Libro sending', 'sii-boleta-dte' ) . '</label>';
                echo '<p><label for="sii-boleta-libro-day">' . esc_html__( 'Day of month', 'sii-boleta-dte' ) . '</label> ';
                echo '<input type="number" id="sii-boleta-libro-day" min="1" max="31" name="' . $name . '[libro_auto_day]" value="' . (int) $day . '" /></p>';
                echo '<p><label for="sii-boleta-libro-time">' . esc_html__( 'Send at', 'sii-boleta-dte' ) . '</label> ';
                echo '<input type="time" id="sii-boleta-libro-time" name="' . $name . '[libro_auto_time]" value="' . esc_attr( $time ) . '" step="60" /></p>';
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
        if ( ! function_exists( 'settings_fields' ) ) {
            return;
        }

        $steps = array(
            'emitter'      => array(
                'label'       => __( 'Emitter', 'sii-boleta-dte' ),
                'title'       => __( 'Business identity', 'sii-boleta-dte' ),
                'description' => __( 'Complete the legal information that will appear on every document sent to the SII.', 'sii-boleta-dte' ),
                'sections'    => array( 'sii_boleta_emitter' ),
            ),
            'credentials'  => array(
                'label'       => __( 'Credentials', 'sii-boleta-dte' ),
                'title'       => __( 'Certificate & environment', 'sii-boleta-dte' ),
                'description' => __( 'Upload the signing certificate, define passwords and choose the environment for testing or production.', 'sii-boleta-dte' ),
                'sections'    => array( 'sii_boleta_cert', 'sii_boleta_env' ),
            ),
            'pdf'          => array(
                'label'       => __( 'Branding', 'sii-boleta-dte' ),
                'title'       => __( 'PDF layout', 'sii-boleta-dte' ),
                'description' => __( 'Customize the PDF template, choose the format and add your logo or footer notes.', 'sii-boleta-dte' ),
                'sections'    => array( 'sii_boleta_pdf' ),
            ),
            'automation'   => array(
                'label'       => __( 'Automation', 'sii-boleta-dte' ),
                'title'       => __( 'Automation & email', 'sii-boleta-dte' ),
                'description' => __( 'Schedule automatic RVD and Libro processes and link SMTP profiles for transactional emails.', 'sii-boleta-dte' ),
                'sections'    => array( 'sii_boleta_automation', 'sii_boleta_smtp' ),
            ),
            'observability' => array(
                'label'       => __( 'Observability', 'sii-boleta-dte' ),
                'title'       => __( 'Diagnostics & logging', 'sii-boleta-dte' ),
                'description' => __( 'Keep an audit trail by enabling file logs for every interaction with the SII.', 'sii-boleta-dte' ),
                'sections'    => array( 'sii_boleta_logging' ),
            ),
        );

        $step_ids        = array_keys( $steps );
        $first_step_id   = (string) reset( $step_ids );
        $requirements    = $this->render_requirements_check();

        AdminStyles::open_container( 'sii-settings-page' );
        echo '<div class="sii-settings-wizard sii-dte-settings">';
        echo '<h1>' . esc_html__( 'SII Boleta DTE', 'sii-boleta-dte' ) . '</h1>';
        echo '<p class="sii-settings-subtitle">' . esc_html__( 'Configure every aspect of your electronic invoicing flow with a guided, step-by-step interface.', 'sii-boleta-dte' ) . '</p>';
        echo '<div class="sii-settings-layout">';
        echo '<div class="sii-settings-card">';
        if ( function_exists( 'settings_errors' ) ) {
            settings_errors();
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
        settings_fields( Settings::OPTION_GROUP );

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
                echo '<button type="button" class="button button-secondary" data-step-prev="' . esc_attr( $prev_step ) . '">' . esc_html__( 'Back', 'sii-boleta-dte' ) . '</button>';
            }
            if ( ! empty( $next_step ) ) {
                echo '<button type="button" class="button button-primary" data-step-next="' . esc_attr( $next_step ) . '">' . esc_html__( 'Continue', 'sii-boleta-dte' ) . '</button>';
            } else {
                if ( function_exists( 'get_submit_button' ) ) {
                    echo get_submit_button( __( 'Save changes', 'sii-boleta-dte' ), 'primary', 'submit', false );
                } else {
                    submit_button( __( 'Save changes', 'sii-boleta-dte' ) );
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
            do_settings_fields( $page, $section_id );
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

                $output['rvd_auto_enabled'] = empty( $input['rvd_auto_enabled'] ) ? 0 : 1;
                if ( isset( $input['rvd_auto_time'] ) ) {
                        $rvd_time = (string) $input['rvd_auto_time'];
                        if ( preg_match( '/^(\d{2}):(\d{2})$/', $rvd_time ) ) {
                                $output['rvd_auto_time'] = $rvd_time;
                        }
                }

                $output['libro_auto_enabled'] = empty( $input['libro_auto_enabled'] ) ? 0 : 1;
                if ( isset( $input['libro_auto_day'] ) ) {
                        $libro_day = (int) $input['libro_auto_day'];
                        if ( $libro_day < 1 ) {
                                $libro_day = 1;
                        } elseif ( $libro_day > 31 ) {
                                $libro_day = 31;
                        }
                        $output['libro_auto_day'] = $libro_day;
                }
                if ( isset( $input['libro_auto_time'] ) ) {
                        $libro_time = (string) $input['libro_auto_time'];
                        if ( preg_match( '/^(\d{2}):(\d{2})$/', $libro_time ) ) {
                                $output['libro_auto_time'] = $libro_time;
                        }
                }

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
                $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => WP_CONTENT_DIR . '/uploads' );
                $base    = rtrim( (string) ( $uploads['basedir'] ?? ( WP_CONTENT_DIR . '/uploads' ) ), '/\\' );
                $dir     = $base . '/sii-boleta-dte';
                if ( function_exists( 'wp_mkdir_p' ) ) {
                    wp_mkdir_p( $dir );
                } else {
                    if ( ! is_dir( $dir ) ) {
                        @mkdir( $dir, 0755, true );
                    }
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

			return $output;
	}
}

class_alias( SettingsPage::class, 'SII_Boleta_Settings_Page' );
