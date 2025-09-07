<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase encargada de gestionar la configuración del plugin. Define los
 * ajustes disponibles, renderiza el formulario de configuración y
 * proporciona un método para obtener los valores de configuración de
 * forma centralizada. Inspirada en la implementación de Settings del
 * plugin de ejemplo `csneaits-asistent-ia`.
 */
class SII_Boleta_Settings {

    /**
     * Nombre del grupo de opciones de WordPress.
     */
    const OPTION_GROUP = 'sii_boleta_dte_settings_group';

    /**
     * Nombre de la opción que almacena todos los ajustes.
     */
    const OPTION_NAME  = 'sii_boleta_dte_settings';

    /**
     * Constructor. Registra el menú y los campos de ajustes.
     */
    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_cert_expiry_notice' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_setup_notices' ] );
    }

    /**
     * Encola los scripts necesarios en el frontend.
     */
    public function enqueue_front_assets() {
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            wp_enqueue_script(
                'sii-boleta-checkout-rut',
                SII_BOLETA_DTE_URL . 'assets/js/checkout-rut.js',
                [],
                SII_BOLETA_DTE_VERSION,
                true
            );
        }
    }

    /**
     * Encola scripts y estilos para la página de ajustes en el admin.
     *
     * @param string $hook Hook de la página actual.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_sii-boleta-dte' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'sii-boleta-admin-settings',
            SII_BOLETA_DTE_URL . 'assets/css/admin-settings.css',
            [],
            SII_BOLETA_DTE_VERSION
        );

        wp_enqueue_script(
            'sii-boleta-admin-settings',
            SII_BOLETA_DTE_URL . 'assets/js/admin-settings.js',
            [],
            SII_BOLETA_DTE_VERSION,
            true
        );
    }

    /**
     * Registra los ajustes y campos a través de la API de Settings de WordPress.
     */
    public function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'sii_boleta_dte_settings_section',
            __( 'Configuración del Emisor y Certificados', 'sii-boleta-dte' ),
            function() {
                echo '<p>' . esc_html__( 'Complete la información del emisor, cargue su certificado digital y el archivo CAF. Estos datos son necesarios para generar y timbrar los DTE.', 'sii-boleta-dte' ) . '</p>';
            },
            'sii-boleta-dte'
        );

        // Campos de configuración del emisor
        add_settings_field(
            'rut_emisor',
            __( 'RUT Emisor', 'sii-boleta-dte' ),
            [ $this, 'render_field_rut_emisor' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'razon_social',
            __( 'Razón Social', 'sii-boleta-dte' ),
            [ $this, 'render_field_razon_social' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'giro',
            __( 'Giro Comercial', 'sii-boleta-dte' ),
            [ $this, 'render_field_giro' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'direccion',
            __( 'Dirección de origen', 'sii-boleta-dte' ),
            [ $this, 'render_field_direccion' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'comuna',
            __( 'Comuna de origen', 'sii-boleta-dte' ),
            [ $this, 'render_field_comuna' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'acteco',
            __( 'Código Acteco', 'sii-boleta-dte' ),
            [ $this, 'render_field_acteco' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'cdg_sii_sucur',
            __( 'Código Sucursal SII (opcional)', 'sii-boleta-dte' ),
            [ $this, 'render_field_cdg_sii_sucur' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );

        // Campos para certificado y CAF
        add_settings_field(
            'cert_path',
            __( 'Ruta del Certificado (.pfx)', 'sii-boleta-dte' ),
            [ $this, 'render_field_cert_path' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'cert_pass',
            __( 'Contraseña Certificado', 'sii-boleta-dte' ),
            [ $this, 'render_field_cert_pass' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'caf_path',
            __( 'Ruta del CAF (XML)', 'sii-boleta-dte' ),
            [ $this, 'render_field_caf_path' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'environment',
            __( 'Ambiente', 'sii-boleta-dte' ),
            [ $this, 'render_field_environment' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'enabled_dte_types',
            __( 'Tipos de Documentos en Checkout', 'sii-boleta-dte' ),
            [ $this, 'render_field_enabled_dte_types' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'logo_id',
            __( 'Logo de la Empresa', 'sii-boleta-dte' ),
            [ $this, 'render_field_logo' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );
        add_settings_field(
            'enable_logging',
            __( 'Habilitar logging', 'sii-boleta-dte' ),
            [ $this, 'render_field_enable_logging' ],
            'sii-boleta-dte',
            'sii_boleta_dte_settings_section'
        );

        // Opciones de PDF
        add_settings_section(
            'sii_boleta_dte_pdf_settings_section',
            __( 'Opciones de PDF', 'sii-boleta-dte' ),
            function() {
                echo '<p>' . esc_html__( 'Personaliza el formato de impresión del DTE.', 'sii-boleta-dte' ) . '</p>';
            },
            'sii-boleta-dte'
        );
        add_settings_field(
            'pdf_format',
            __( 'Formato', 'sii-boleta-dte' ),
            [ $this, 'render_field_pdf_format' ],
            'sii-boleta-dte',
            'sii_boleta_dte_pdf_settings_section'
        );
        add_settings_field(
            'pdf_show_logo',
            __( 'Mostrar logo en PDF', 'sii-boleta-dte' ),
            [ $this, 'render_field_pdf_show_logo' ],
            'sii-boleta-dte',
            'sii_boleta_dte_pdf_settings_section'
        );
        add_settings_field(
            'pdf_footer',
            __( 'Nota al pie', 'sii-boleta-dte' ),
            [ $this, 'render_field_pdf_footer' ],
            'sii-boleta-dte',
            'sii_boleta_dte_pdf_settings_section'
        );

        // Sección de correo: seleccionar perfil SMTP (definido en el servidor/otro plugin)
        add_settings_section(
            'sii_boleta_dte_mail_settings_section',
            __( 'Envío de Correos', 'sii-boleta-dte' ),
            function() {
                echo '<p>' . esc_html__( 'Seleccione el perfil SMTP previamente configurado en el servidor para enviar los correos del DTE. Si no selecciona ninguno, se usará la configuración por defecto de WordPress.', 'sii-boleta-dte' ) . '</p>';
            },
            'sii-boleta-dte'
        );
        add_settings_field(
            'smtp_profile',
            __( 'Perfil SMTP', 'sii-boleta-dte' ),
            [ $this, 'render_field_smtp_profile' ],
            'sii-boleta-dte',
            'sii_boleta_dte_mail_settings_section'
        );
    }

    /**
     * Sanitiza los ajustes recibidos antes de guardarlos en la base de datos.
     *
     * @param array $input Datos recibidos desde el formulario.
     * @return array Datos sanitizados.
     */
    public function sanitize_settings( $input ) {
        $output   = [];
        $existing = get_option( self::OPTION_NAME, [] );
        $existing_decrypted = $existing;
        if ( ! empty( $existing_decrypted['cert_pass'] ) ) {
            $decoded = $this->decrypt_value( $existing_decrypted['cert_pass'] );
            if ( false !== $decoded ) {
                $existing_decrypted['cert_pass'] = $decoded;
            }
        }

        $output['rut_emisor']   = sanitize_text_field( $input['rut_emisor'] ?? '' );
        $output['razon_social'] = sanitize_text_field( $input['razon_social'] ?? '' );
        $output['giro']         = sanitize_text_field( $input['giro'] ?? '' );
        $output['direccion']    = sanitize_text_field( $input['direccion'] ?? '' );
        $output['comuna']       = sanitize_text_field( $input['comuna'] ?? '' );
        $output['acteco']       = sanitize_text_field( $input['acteco'] ?? '' );
        $output['cdg_sii_sucur']= sanitize_text_field( $input['cdg_sii_sucur'] ?? '' );
        if ( ! empty( $input['cert_pass'] ) ) {
            $output['cert_pass'] = $this->encrypt_value( sanitize_text_field( $input['cert_pass'] ) );
        } else {
            $output['cert_pass'] = $existing['cert_pass'] ?? '';
        }
        $output['api_token']    = $existing_decrypted['api_token'] ?? '';
        $output['environment']  = in_array( $input['environment'] ?? 'test', [ 'test', 'production' ], true ) ? $input['environment'] : 'test';
        $output['logo_id']      = isset( $input['logo_id'] ) ? intval( $input['logo_id'] ) : 0;
        $output['enable_logging'] = ! empty( $input['enable_logging'] );
        $profiles = apply_filters( 'sii_boleta_available_smtp_profiles', [ '' => __( 'Predeterminado de WordPress', 'sii-boleta-dte' ) ] );
        $sel      = sanitize_text_field( $input['smtp_profile'] ?? '' );
        $output['smtp_profile'] = array_key_exists( $sel, (array) $profiles ) ? $sel : '';
        $output['api_token_expires'] = isset( $existing_decrypted['api_token_expires'] ) ? intval( $existing_decrypted['api_token_expires'] ) : 0;
        $valid_types            = [ '39', '33', '34', '52', '56', '61' ];
        $requested_types        = isset( $input['enabled_dte_types'] ) ? (array) $input['enabled_dte_types'] : [];
        $output['enabled_dte_types'] = array_values( array_intersect( $valid_types, array_map( 'sanitize_text_field', $requested_types ) ) );
        if ( empty( $output['enabled_dte_types'] ) ) {
            $output['enabled_dte_types'] = [ '39', '33', '34', '52', '56', '61' ];
        }

        // Guardar ruta de certificado existente o la proporcionada manualmente.
        if ( ! empty( $input['cert_path'] ) ) {
            $cert_path = trim( (string) $input['cert_path'] );
            // Si vino como URL, convertir a ruta del sistema de archivos.
            if ( preg_match( '#^https?://#i', $cert_path ) ) {
                $upload = wp_upload_dir();
                $cert_path = str_replace( $upload['baseurl'], $upload['basedir'], $cert_path );
            }
            $output['cert_path'] = wp_normalize_path( $cert_path );
        } else {
            $output['cert_path'] = isset( $existing_decrypted['cert_path'] ) ? wp_normalize_path( $existing_decrypted['cert_path'] ) : '';
        }
        $output['caf_path'] = is_array( $existing_decrypted['caf_path'] ?? null ) ? $existing_decrypted['caf_path'] : [];

        // Procesar subida del certificado.
        if ( ! empty( $_FILES['cert_file']['name'] ) ) {
            if ( ! function_exists( 'wp_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $file_type = wp_check_filetype( $_FILES['cert_file']['name'] );
            if ( in_array( $file_type['ext'], [ 'pfx', 'p12' ], true ) ) {
                $upload = wp_handle_upload( $_FILES['cert_file'], [ 'test_form' => false ] );
                if ( empty( $upload['error'] ) ) {
                    $output['cert_path'] = wp_normalize_path( $upload['file'] );
                }
            }
        }

        // Procesar campos de CAF por tipo de DTE.
        $caf_types  = $input['caf_type'] ?? [];
        $caf_paths  = $input['caf_path'] ?? [];
        $files      = $_FILES['caf_file'] ?? [];
        $output['caf_path'] = [];
        $valid_caf_types = [ '39', '41', '33', '34', '52', '56', '61' ];
        $seen_types      = [];

        foreach ( $caf_types as $index => $tipo ) {
            $tipo = sanitize_text_field( $tipo );
            if ( empty( $tipo ) || ! in_array( $tipo, $valid_caf_types, true ) || in_array( $tipo, $seen_types, true ) ) {
                continue;
            }
            $seen_types[] = $tipo;

            $path = sanitize_text_field( $caf_paths[ $index ] ?? '' );

            // Procesar subida del archivo correspondiente al índice.
            if ( ! empty( $files['name'][ $index ] ) ) {
                $file = [
                    'name'     => $files['name'][ $index ],
                    'type'     => $files['type'][ $index ],
                    'tmp_name' => $files['tmp_name'][ $index ],
                    'error'    => $files['error'][ $index ],
                    'size'     => $files['size'][ $index ],
                ];
                $file_type = wp_check_filetype( $file['name'] );
                if ( 'xml' === $file_type['ext'] ) {
                    $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
                    if ( empty( $upload['error'] ) ) {
                        $path = $upload['file'];
                    }
                }
            } elseif ( isset( $existing['caf_path'][ $tipo ] ) && empty( $path ) ) {
                // Mantener la ruta existente si no se proporcionó nueva ruta ni archivo
                $path = $existing['caf_path'][ $tipo ];
            }

            if ( ! empty( $path ) ) {
                // Convertir URL a ruta, normalizar separadores.
                if ( preg_match( '#^https?://#i', $path ) ) {
                    $upload = wp_upload_dir();
                    $path = str_replace( $upload['baseurl'], $upload['basedir'], $path );
                }
                $output['caf_path'][ $tipo ] = wp_normalize_path( $path );
            }
        }

        return $output;
    }

    private function get_encryption_key() {
        return hash( 'sha256', wp_salt( 'sii_boleta_dte_cert' ) );
    }

    private function get_encryption_iv() {
        return substr( hash( 'sha256', 'sii_boleta_dte_iv' ), 0, 16 );
    }

    private function encrypt_value( $value ) {
        $encrypted = openssl_encrypt( $value, 'AES-256-CBC', $this->get_encryption_key(), 0, $this->get_encryption_iv() );
        return $encrypted ? base64_encode( $encrypted ) : '';
    }

    private function decrypt_value( $value ) {
        $decoded = base64_decode( $value, true );
        if ( false === $decoded ) {
            return false;
        }
        return openssl_decrypt( $decoded, 'AES-256-CBC', $this->get_encryption_key(), 0, $this->get_encryption_iv() );
    }

    private function get_certificate_valid_to( $cert_path, $cert_pass ) {
        if ( empty( $cert_path ) || empty( $cert_pass ) || ! file_exists( $cert_path ) ) {
            return false;
        }
        $certs = [];
        if ( ! @openssl_pkcs12_read( file_get_contents( $cert_path ), $certs, $cert_pass ) ) {
            return false;
        }
        $info = openssl_x509_parse( $certs['cert'] );
        if ( ! $info || empty( $info['validTo_time_t'] ) ) {
            return false;
        }
        return (int) $info['validTo_time_t'];
    }

    /**
     * Devuelve los ajustes guardados o valores por defecto si aún no existen.
     *
     * @return array
     */
    public function get_settings() {
        $defaults = [
            'rut_emisor'    => '',
            'razon_social'  => '',
            'giro'          => '',
            'direccion'     => '',
            'comuna'        => '',
            'acteco'        => '',
            'cdg_sii_sucur' => '',
            'cert_path'     => '',
            'cert_pass'     => '',
            'caf_path'      => [],
            'api_token'    => '',
            'api_token_expires' => 0,
            'environment'   => 'test',
            'enabled_dte_types' => [ '39', '33', '34', '52', '56', '61' ],
            'logo_id'       => 0,
            'enable_logging' => 0,
            'smtp_profile'  => '',
            'pdf_format'    => 'A4',
            'pdf_show_logo' => true,
            'pdf_footer'    => '',
        ];
        $options = get_option( self::OPTION_NAME, [] );

        if ( ! empty( $options['cert_pass'] ) ) {
            $decrypted = $this->decrypt_value( $options['cert_pass'] );
            if ( false !== $decrypted ) {
                $options['cert_pass'] = $decrypted;
            }
        }

        return wp_parse_args( $options, $defaults );
    }

    /**
     * Renderiza checkboxes para seleccionar los tipos de DTE disponibles en el checkout.
     */
    public function render_field_enabled_dte_types() {
        $options = $this->get_settings();
        $enabled = isset( $options['enabled_dte_types'] ) && is_array( $options['enabled_dte_types'] )
            ? array_map( 'strval', $options['enabled_dte_types'] )
            : [];
        $types = [
            '39' => __( 'Boleta Electrónica', 'sii-boleta-dte' ),
            '33' => __( 'Factura Electrónica', 'sii-boleta-dte' ),
            '34' => __( 'Factura Exenta', 'sii-boleta-dte' ),
            '52' => __( 'Guía de Despacho', 'sii-boleta-dte' ),
            '56' => __( 'Nota de Débito Electrónica', 'sii-boleta-dte' ),
            '61' => __( 'Nota de Crédito Electrónica', 'sii-boleta-dte' ),
        ];
        foreach ( $types as $code => $label ) {
            printf(
                '<label><input type="checkbox" name="%s[enabled_dte_types][]" value="%s" %s /> %s</label><br />',
                esc_attr( self::OPTION_NAME ),
                esc_attr( $code ),
                checked( in_array( (string) $code, $enabled, true ), true, false ),
                esc_html( $label )
            );
        }
        echo '<p class="description">' . esc_html__( 'Seleccione los tipos de documentos que los clientes pueden elegir durante el checkout.', 'sii-boleta-dte' ) . '</p>';
    }

    /**
     * Renderiza el campo de texto para el RUT del emisor.
     */
    public function render_field_rut_emisor() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[rut_emisor]" value="%s" class="regular-text" placeholder="XXXXXXXX-X" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['rut_emisor'] )
        );
    }

    /**
     * Renderiza el campo de texto para la razón social.
     */
    public function render_field_razon_social() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[razon_social]" value="%s" class="regular-text sii-input-wide" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['razon_social'] )
        );
    }

    /**
     * Renderiza el selector de perfil SMTP.
     */
    public function render_field_smtp_profile() {
        $options  = $this->get_settings();
        $current  = $options['smtp_profile'] ?? '';
        $profiles = apply_filters( 'sii_boleta_available_smtp_profiles', [ '' => __( 'Predeterminado de WordPress', 'sii-boleta-dte' ) ] );
        echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[smtp_profile]">';
        foreach ( (array) $profiles as $key => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Perfiles provistos por el servidor/otros plugins (via filtro).', 'sii-boleta-dte' ) . '</p>';
    }

    /**
     * Renderiza el campo de texto para el giro comercial.
     */
    public function render_field_giro() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[giro]" value="%s" class="regular-text sii-input-wide" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['giro'] )
        );
    }

    /**
     * Renderiza el campo de texto para la dirección de origen.
     */
    public function render_field_direccion() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[direccion]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['direccion'] )
        );
    }

    /**
     * Renderiza el campo de texto para la comuna de origen.
     */
    public function render_field_comuna() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[comuna]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['comuna'] )
        );
    }

    /**
     * Renderiza el campo Acteco (código de actividad económica).
     */
    public function render_field_acteco() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[acteco]" value="%s" class="regular-text" placeholder="Ej: 726000" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['acteco'] )
        );
    }

    /**
     * Renderiza el campo CdgSIISucur (código de sucursal SII).
     */
    public function render_field_cdg_sii_sucur() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[cdg_sii_sucur]" value="%s" class="regular-text" placeholder="Opcional" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['cdg_sii_sucur'] )
        );
    }

    /**
     * Renderiza el campo para la ruta del certificado digital.
     */
    public function render_field_cert_path() {
        $options = $this->get_settings();

        echo '<div class="sii-dte-cert-row">';
        printf(
            '<input type="file" id="sii-dte-cert-file" name="cert_file" accept=".pfx,.p12" />'
        );
        printf(
            '<input type="text" id="sii-dte-cert-path" name="%s[cert_path]" value="%s" class="regular-text" placeholder="/ruta/certificado.pfx" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['cert_path'] )
        );
        echo '</div>';

        if ( ! empty( $options['cert_path'] ) ) {
            $current_cert = wp_basename( wp_normalize_path( $options['cert_path'] ) );
            $expiry_ts    = $this->get_certificate_valid_to( $options['cert_path'], $options['cert_pass'] );
            $expiry_str   = $expiry_ts ? date_i18n( get_option( 'date_format' ), $expiry_ts ) : '';
            $extra        = $expiry_str ? sprintf( ' (%s: %s)', esc_html__( 'Válido hasta', 'sii-boleta-dte' ), esc_html( $expiry_str ) ) : '';
            printf(
                '<p class="description">%s <code>%s</code>%s</p>',
                esc_html__( 'Certificado actual:', 'sii-boleta-dte' ),
                esc_html( $current_cert ),
                $extra
            );
        }

        echo '<p class="description">' . esc_html__( 'Cargue el certificado .pfx/.p12 o indique la ruta absoluta si ya existe en el servidor.', 'sii-boleta-dte' ) . '</p>';
    }

    /**
     * Renderiza el campo para la contraseña del certificado.
     */
    public function render_field_cert_pass() {
        $options = $this->get_settings();
        printf(
            '<input type="password" name="%s[cert_pass]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['cert_pass'] )
        );
    }

    /**
     * Renderiza el campo para la ruta del archivo CAF.
     */
    public function render_field_caf_path() {
        $options    = $this->get_settings();
        $caf_paths  = is_array( $options['caf_path'] ) ? $options['caf_path'] : [];
        $option_key = esc_attr( self::OPTION_NAME );

        $types      = [
            '39' => __( 'Boleta Electrónica', 'sii-boleta-dte' ),
            '41' => __( 'Boleta Exenta Electrónica', 'sii-boleta-dte' ),
            '33' => __( 'Factura Electrónica', 'sii-boleta-dte' ),
            '34' => __( 'Factura Exenta', 'sii-boleta-dte' ),
            '52' => __( 'Guía de Despacho', 'sii-boleta-dte' ),
            '56' => __( 'Nota de Débito Electrónica', 'sii-boleta-dte' ),
            '61' => __( 'Nota de Crédito Electrónica', 'sii-boleta-dte' ),
        ];

        echo '<div id="sii-dte-caf-container">';
        if ( empty( $caf_paths ) ) {
            $caf_paths = [ '' => '' ];
        }
        foreach ( $caf_paths as $tipo => $path ) {
            ?>
            <div class="sii-dte-caf-row">
                <select name="<?php echo $option_key; ?>[caf_type][]" class="sii-dte-caf-type">
                    <option value=""><?php esc_html_e( 'Seleccione documento', 'sii-boleta-dte' ); ?></option>
                    <?php foreach ( $types as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $tipo, $code ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="file" name="caf_file[]" accept=".xml" />
                <input type="text" name="<?php echo $option_key; ?>[caf_path][]" value="<?php echo esc_attr( $path ); ?>" class="regular-text" placeholder="/ruta/CAF.xml" />
                <button type="button" class="button sii-dte-remove-caf">&times;</button>
            </div>
            <?php
        }
        echo '</div>';
        ?>
        <button type="button" class="button" id="sii-dte-add-caf"><?php esc_html_e( 'Agregar CAF', 'sii-boleta-dte' ); ?></button>
        <p class="description"><?php esc_html_e( 'Cargue un archivo CAF para cada tipo de DTE necesario. Puede indicar la ruta manualmente o subir un archivo.', 'sii-boleta-dte' ); ?></p>
        <script type="text/javascript">
        jQuery(function($){
            var cafOptions = <?php echo wp_json_encode( $types ); ?>;
            var optionKey = '<?php echo $option_key; ?>';

            function updateCafOptions() {
                var selected = $('.sii-dte-caf-type').map(function(){ return $(this).val(); }).get();
                $('.sii-dte-caf-type').each(function(){
                    var current = $(this).val();
                    $(this).find('option').each(function(){
                        var val = $(this).val();
                        if ( val && val !== current && selected.indexOf( val ) !== -1 ) {
                            $(this).prop('disabled', true);
                        } else {
                            $(this).prop('disabled', false);
                        }
                    });
                });
            }

            $('#sii-dte-add-caf').on('click', function(){
                var select = '<select name="'+ optionKey +'[caf_type][]" class="sii-dte-caf-type">';
                select += '<option value="">' + '<?php echo esc_js( __( 'Seleccione documento', 'sii-boleta-dte' ) ); ?>' + '</option>';
                $.each(cafOptions, function(val, label){
                    select += '<option value="'+ val +'">'+ label +'</option>';
                });
                select += '</select>';
                var row = '<div class="sii-dte-caf-row">'
                    + select
                    + '<input type="file" name="caf_file[]" accept=".xml" />'
                    + '<input type="text" name="'+ optionKey +'[caf_path][]" class="regular-text" placeholder="/ruta/CAF.xml" />'
                    + '<button type="button" class="button sii-dte-remove-caf">&times;</button>'
                    + '</div>';
                $('#sii-dte-caf-container').append(row);
                updateCafOptions();
            });
            $(document).on('click', '.sii-dte-remove-caf', function(){
                $(this).closest('.sii-dte-caf-row').remove();
                updateCafOptions();
            });
            $(document).on('change', '.sii-dte-caf-type', updateCafOptions);
            updateCafOptions();
        });
        </script>
        <?php
    }

    /**
     * Renderiza el campo para seleccionar el ambiente (test o producción).
     */
    public function render_field_environment() {
        $options = $this->get_settings();
        $env = $options['environment'];
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[environment]">
            <option value="test" <?php selected( $env, 'test' ); ?>><?php esc_html_e( 'Ambiente de pruebas', 'sii-boleta-dte' ); ?></option>
            <option value="production" <?php selected( $env, 'production' ); ?>><?php esc_html_e( 'Ambiente de producción', 'sii-boleta-dte' ); ?></option>
        </select>
        <?php
    }

    /**
     * Renderiza el campo para seleccionar el logo de la empresa.
     * Utiliza la librería de medios de WordPress para elegir la imagen.
     */
    public function render_field_logo() {
        $options   = $this->get_settings();
        $logo_id   = $options['logo_id'];
        $image_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
        ?>
        <div>
            <img id="sii-dte-logo-preview" src="<?php echo esc_url( $image_url ); ?>" style="max-height:80px;">
            <input type="hidden" id="sii_dte_logo_id" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[logo_id]" value="<?php echo esc_attr( $logo_id ); ?>">
            <button type="button" class="button" id="sii-dte-select-logo"><?php esc_html_e( 'Seleccionar logo', 'sii-boleta-dte' ); ?></button>
            <button type="button" class="button" id="sii-dte-remove-logo"><?php esc_html_e( 'Quitar logo', 'sii-boleta-dte' ); ?></button>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($){
            var file_frame;
            $('#sii-dte-select-logo').on('click', function(e){
                e.preventDefault();
                if (file_frame) {
                    file_frame.open();
                    return;
                }
                file_frame = wp.media.frames.file_frame = wp.media({
                    title: '<?php echo esc_js( __( 'Seleccionar logo', 'sii-boleta-dte' ) ); ?>',
                    button: { text: '<?php echo esc_js( __( 'Usar este logo', 'sii-boleta-dte' ) ); ?>' },
                    multiple: false
                });
                file_frame.on('select', function(){
                    var attachment = file_frame.state().get('selection').first().toJSON();
                    $('#sii-dte-logo-preview').attr('src', attachment.url);
                    $('#sii_dte_logo_id').val(attachment.id);
                });
                file_frame.open();
            });
            $('#sii-dte-remove-logo').on('click', function(){
                $('#sii-dte-logo-preview').attr('src', '');
                $('#sii_dte_logo_id').val('');
            });
        });
        </script>
        <?php
    }

    public function render_field_ses_host() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[ses_host]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['ses_host'] )
        );
    }

    public function render_field_ses_port() {
        $options = $this->get_settings();
        printf(
            '<input type="number" name="%s[ses_port]" value="%s" class="small-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['ses_port'] )
        );
    }

    public function render_field_ses_username() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[ses_username]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['ses_username'] )
        );
    }

    public function render_field_ses_password() {
        $options = $this->get_settings();
        printf(
            '<input type="password" name="%s[ses_password]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['ses_password'] )
        );
    }

    /**
     * Renderiza el checkbox para habilitar el logging del plugin.
     */
    public function render_field_enable_logging() {
        $options = $this->get_settings();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_logging]" value="1" <?php checked( ! empty( $options['enable_logging'] ) ); ?> />
            <?php esc_html_e( 'Registrar eventos del plugin incluso si WP_DEBUG está desactivado.', 'sii-boleta-dte' ); ?>
        </label>
        <?php
    }

    /**
     * Muestra un aviso en el panel de administración cuando el certificado
     * esté próximo a vencer.
     */
    public function maybe_show_cert_expiry_notice() {
        $settings = $this->get_settings();
        $expiry   = $this->get_certificate_valid_to( $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        if ( ! $expiry ) {
            return;
        }

        $days_left = (int) floor( ( $expiry - time() ) / DAY_IN_SECONDS );
        if ( $days_left > 30 ) {
            return;
        }

        $class       = $days_left > 0 ? 'notice-warning' : 'notice-error';
        $plugin_name = __( 'SII Boleta DTE', 'sii-boleta-dte' );
        $expiry_date = date_i18n( get_option( 'date_format' ), $expiry );
        $message     = $days_left > 0
            ? sprintf(
                __( '%1$s: El certificado digital vence el %2$s (%3$d días restantes).', 'sii-boleta-dte' ),
                $plugin_name,
                $expiry_date,
                $days_left
            )
            : sprintf(
                __( '%1$s: El certificado digital venció el %2$s.', 'sii-boleta-dte' ),
                $plugin_name,
                $expiry_date
            );

        printf( '<div class="notice %s"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    public function render_field_pdf_format() {
        $options = $this->get_settings();
        $val = $options['pdf_format'];
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[pdf_format]">
            <option value="A4" <?php selected( $val, 'A4' ); ?>>A4</option>
            <option value="80mm" <?php selected( $val, '80mm' ); ?>>80mm (boleta térmica)</option>
        </select>
        <?php
    }

    public function render_field_pdf_show_logo() {
        $options = $this->get_settings();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[pdf_show_logo]" value="1" <?php checked( ! empty( $options['pdf_show_logo'] ) ); ?> />
            <?php esc_html_e( 'Incluir el logo configurado en el encabezado del PDF.', 'sii-boleta-dte' ); ?>
        </label>
        <?php
    }

    public function render_field_pdf_footer() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[pdf_footer]" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['pdf_footer'] ),
            esc_attr__( 'Gracias por su compra', 'sii-boleta-dte' )
        );
    }

    /**
     * Renderiza la página de configuración completa. Se engancha en el menú
     * principal desde la clase SII_Boleta_Core.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap sii-dte-settings">
            <h1><?php esc_html_e( 'Configuración SII Boleta DTE', 'sii-boleta-dte' ); ?></h1>
            <div class="sii-dte-card">
                <form action="options.php" method="post" class="sii-dte-form" enctype="multipart/form-data">
                    <?php
                    settings_fields( self::OPTION_GROUP );
                    do_settings_sections( 'sii-boleta-dte' );
                    submit_button();
                    ?>
                </form>
            </div>

            <?php $diag = $this->get_diagnostics(); ?>
            <div class="sii-dte-card">
                <h2><?php esc_html_e( 'Diagnóstico de Integración', 'sii-boleta-dte' ); ?></h2>
                <p><?php esc_html_e( 'Checklist rápido para validar que todo está listo para emitir.', 'sii-boleta-dte' ); ?></p>
                <ul>
                    <li><?php echo $this->render_check( ! empty( $this->get_settings()['rut_emisor'] ), __( 'RUT emisor configurado', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( ! empty( $this->get_settings()['razon_social'] ), __( 'Razón social configurada', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( ! empty( $this->get_settings()['giro'] ), __( 'Giro comercial configurado', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( ! empty( $this->get_settings()['direccion'] ) && ! empty( $this->get_settings()['comuna'] ), __( 'Dirección y Comuna de origen configuradas', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( $diag['acteco'], __( 'Código Acteco configurado', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( $diag['openssl'], __( 'Extensión OpenSSL disponible en PHP', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( $diag['cert_path'], __( 'Certificado digital (.p12/.pfx) accesible', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( $diag['cert_pass'], __( 'Contraseña del certificado configurada', 'sii-boleta-dte' ) ); ?></li>
                    <li><?php echo $this->render_check( $diag['libredte_available'], __( 'Librería LibreDTE disponible', 'sii-boleta-dte' ) ); ?></li>
                    <li>
                        <?php
                        $caf_ok = empty( $diag['caf_missing'] );
                        $msg = $caf_ok
                            ? __( 'CAF presentes para tipos habilitados', 'sii-boleta-dte' )
                            : sprintf( __( 'CAF faltantes para tipos: %s', 'sii-boleta-dte' ), esc_html( implode( ', ', $diag['caf_missing'] ) ) );
                        echo $this->render_check( $caf_ok, $msg );
                        ?>
                    </li>
                    <li>
                        <?php
                        $authMsg = $diag['sii_auth'] === true
                            ? __( 'Autenticación SII OK (token obtenido)', 'sii-boleta-dte' )
                            : ( $diag['sii_auth'] === false
                                ? sprintf( __( 'Autenticación SII falló: %s', 'sii-boleta-dte' ), esc_html( $diag['sii_auth_error'] ) )
                                : __( 'Autenticación SII no ejecutada (revise certificado/LibreDTE).', 'sii-boleta-dte' )
                            );
                        echo $this->render_check( (bool) $diag['sii_auth'], $authMsg );
                        ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Obtiene diagnóstico de configuración y conectividad.
     * @return array
     */
    private function get_diagnostics() {
        $o = $this->get_settings();
        $res = [
            'libredte_available' => class_exists( '\\libredte\\lib\\Core\\Application' ),
            'openssl'            => function_exists( 'openssl_pkcs12_read' ),
            'cert_path'          => ! empty( $o['cert_path'] ) && file_exists( $o['cert_path'] ),
            'cert_pass'          => ! empty( $o['cert_pass'] ),
            'acteco'             => ! empty( $o['acteco'] ),
            'caf_missing'        => [],
            'sii_auth'           => null,
            'sii_auth_error'     => '',
        ];

        $enabled = isset( $o['enabled_dte_types'] ) && is_array( $o['enabled_dte_types'] )
            ? array_map( 'strval', $o['enabled_dte_types'] )
            : [];
        $caf = isset( $o['caf_path'] ) && is_array( $o['caf_path'] ) ? $o['caf_path'] : [];
        foreach ( $enabled as $t ) {
            if ( empty( $caf[ $t ] ) || ! file_exists( $caf[ $t ] ) ) {
                $res['caf_missing'][] = $t;
            }
        }

        if ( $res['libredte_available'] && $res['openssl'] && $res['cert_path'] && $res['cert_pass'] ) {
            try {
                $app = \libredte\lib\Core\Application::getInstance( 'prod', false );
                /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
                $billing = $app->getPackageRegistry()->getPackage( 'billing' );
                $loader = new \Derafu\Certificate\Service\CertificateLoader();
                $cert   = $loader->loadFromFile( $o['cert_path'], $o['cert_pass'] );
                $ambiente = ( 'production' === strtolower( (string) ( $o['environment'] ?? 'test' ) ) )
                    ? \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::PRODUCCION
                    : \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::CERTIFICACION;
                $request = new \libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest( $cert, [ 'ambiente' => $ambiente ] );
                $token = $billing->getIntegrationComponent()->getSiiLazyWorker()->authenticate( $request );
                $res['sii_auth'] = ! empty( $token );
            } catch ( \Throwable $e ) {
                $res['sii_auth'] = false;
                $res['sii_auth_error'] = $e->getMessage();
            }
        }

        return $res;
    }

    /**
     * Render de un item de checklist con ✓ / ✗
     * @param bool $ok
     * @param string $label
     * @return string HTML
     */
    private function render_check( $ok, $label ) {
        $icon = $ok ? '✓' : '✗';
        $color = $ok ? 'green' : 'red';
        return sprintf( '<span style="color:%s;font-weight:bold;">%s</span> %s', esc_attr( $color ), esc_html( $icon ), esc_html( $label ) );
    }

    /**
     * Muestra avisos cuando faltan datos críticos de configuración.
     * Se muestra solo a administradores y preferentemente en la página del plugin.
     */
    public function maybe_show_setup_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Mostrar preferentemente en la página del plugin para no saturar.
        $screen_ok = true;
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && isset( $screen->id ) ) {
                $screen_ok = ( false !== strpos( $screen->id, 'sii-boleta-dte' ) );
            }
        }
        if ( ! $screen_ok ) {
            return;
        }

        $o = $this->get_settings();
        $errors   = [];
        $warnings = [];

        if ( empty( $o['rut_emisor'] ) ) {
            $errors[] = __( 'Falta el RUT del emisor.', 'sii-boleta-dte' );
        }
        if ( empty( $o['razon_social'] ) ) {
            $warnings[] = __( 'Falta la Razón Social del emisor.', 'sii-boleta-dte' );
        }
        if ( empty( $o['giro'] ) ) {
            $warnings[] = __( 'Falta el Giro comercial del emisor.', 'sii-boleta-dte' );
        }
        if ( empty( $o['direccion'] ) || empty( $o['comuna'] ) ) {
            $warnings[] = __( 'Faltan Dirección o Comuna de origen.', 'sii-boleta-dte' );
        }
        if ( empty( $o['acteco'] ) ) {
            $warnings[] = __( 'Falta el código Acteco. Recomendado para emisión con LibreDTE.', 'sii-boleta-dte' );
        }

        // Certificado (normalizado)
        $cert_path_chk = $o['cert_path'] ?? '';
        if ( $cert_path_chk ) {
            if ( preg_match( '#^https?://#i', $cert_path_chk ) ) {
                $u = wp_upload_dir();
                $cert_path_chk = str_replace( $u['baseurl'], $u['basedir'], $cert_path_chk );
            }
            $cert_path_chk = wp_normalize_path( $cert_path_chk );
        }
        if ( empty( $cert_path_chk ) || ! is_readable( $cert_path_chk ) ) {
            $errors[] = sprintf(
                /* translators: %s is the resolved cert path */
                __( 'No se encontró el certificado digital (.p12/.pfx) en la ruta configurada. Ruta resuelta: %s', 'sii-boleta-dte' ),
                esc_html( $cert_path_chk ?: '-' )
            );
        }
        if ( empty( $o['cert_pass'] ) ) {
            $errors[] = __( 'Falta la contraseña del certificado digital.', 'sii-boleta-dte' );
        }
        if ( ! function_exists( 'openssl_pkcs12_read' ) ) {
            $errors[] = __( 'La extensión OpenSSL de PHP no está disponible. Es requerida para firmar.', 'sii-boleta-dte' );
        }

        // CAF por tipo habilitado
        $enabled = isset( $o['enabled_dte_types'] ) && is_array( $o['enabled_dte_types'] ) ? array_map( 'strval', $o['enabled_dte_types'] ) : [];
        $caf     = isset( $o['caf_path'] ) && is_array( $o['caf_path'] ) ? $o['caf_path'] : [];
        foreach ( $enabled as $t ) {
            $caf_path_chk = $caf[ $t ] ?? '';
            if ( $caf_path_chk ) {
                if ( preg_match( '#^https?://#i', $caf_path_chk ) ) {
                    $u = wp_upload_dir();
                    $caf_path_chk = str_replace( $u['baseurl'], $u['basedir'], $caf_path_chk );
                }
                $caf_path_chk = wp_normalize_path( $caf_path_chk );
            }
            if ( empty( $caf_path_chk ) || ! is_readable( $caf_path_chk ) ) {
                $warnings[] = sprintf(
                    /* translators: 1: DTE type, 2: resolved CAF path */
                    __( 'Falta el CAF para el tipo de DTE %1$s en los ajustes. Ruta resuelta: %2$s', 'sii-boleta-dte' ),
                    esc_html( $t ),
                    esc_html( $caf_path_chk ?: '-' )
                );
            }
        }

        if ( $errors ) {
            printf( '<div class="notice notice-error"><p><strong>%s</strong></p><ul>', esc_html__( 'SII Boleta DTE: configuración incompleta.', 'sii-boleta-dte' ) );
            foreach ( $errors as $msg ) {
                printf( '<li>%s</li>', esc_html( $msg ) );
            }
            echo '</ul></div>';
        }

        if ( $warnings ) {
            printf( '<div class="notice notice-warning"><p><strong>%s</strong></p><ul>', esc_html__( 'SII Boleta DTE: recomendaciones de configuración.', 'sii-boleta-dte' ) );
            foreach ( $warnings as $msg ) {
                printf( '<li>%s</li>', esc_html( $msg ) );
            }
            echo '</ul></div>';
        }
    }
}
