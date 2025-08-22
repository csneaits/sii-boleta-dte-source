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
            'api_token',
            __( 'Token de la API', 'sii-boleta-dte' ),
            [ $this, 'render_field_api_token' ],
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
    }

    /**
     * Sanitiza los ajustes recibidos antes de guardarlos en la base de datos.
     *
     * @param array $input Datos recibidos desde el formulario.
     * @return array Datos sanitizados.
     */
    public function sanitize_settings( $input ) {
        $output   = [];
        $existing = $this->get_settings();

        $output['rut_emisor']   = sanitize_text_field( $input['rut_emisor'] ?? '' );
        $output['razon_social'] = sanitize_text_field( $input['razon_social'] ?? '' );
        $output['giro']         = sanitize_text_field( $input['giro'] ?? '' );
        $output['direccion']    = sanitize_text_field( $input['direccion'] ?? '' );
        $output['comuna']       = sanitize_text_field( $input['comuna'] ?? '' );
        $output['cert_pass']    = sanitize_text_field( $input['cert_pass'] ?? '' );
        $output['api_token']    = sanitize_text_field( $input['api_token'] ?? '' );
        $output['environment']  = in_array( $input['environment'] ?? 'test', [ 'test', 'production' ], true ) ? $input['environment'] : 'test';
        $output['logo_id']      = isset( $input['logo_id'] ) ? intval( $input['logo_id'] ) : 0;
        $output['enable_logging'] = ! empty( $input['enable_logging'] );
        $output['api_token_expires'] = isset( $existing['api_token_expires'] ) ? intval( $existing['api_token_expires'] ) : 0;
        $valid_types            = [ '39', '33', '34', '52', '56', '61' ];
        $requested_types        = isset( $input['enabled_dte_types'] ) ? (array) $input['enabled_dte_types'] : [];
        $output['enabled_dte_types'] = array_values( array_intersect( $valid_types, array_map( 'sanitize_text_field', $requested_types ) ) );
        if ( empty( $output['enabled_dte_types'] ) ) {
            $output['enabled_dte_types'] = [ '39', '33', '34', '52', '56', '61' ];
        }

        // Guardar ruta de certificado existente o la proporcionada manualmente.
        if ( ! empty( $input['cert_path'] ) ) {
            $output['cert_path'] = sanitize_text_field( $input['cert_path'] );
        } else {
            $output['cert_path'] = $existing['cert_path'] ?? '';
        }
        $output['caf_path'] = is_array( $existing['caf_path'] ?? null ) ? $existing['caf_path'] : [];

        // Procesar subida del certificado.
        if ( ! empty( $_FILES['cert_file']['name'] ) ) {
            $file_type = wp_check_filetype( $_FILES['cert_file']['name'] );
            if ( in_array( $file_type['ext'], [ 'pfx', 'p12' ], true ) ) {
                $upload = wp_handle_upload( $_FILES['cert_file'], [ 'test_form' => false ] );
                if ( empty( $upload['error'] ) ) {
                    $output['cert_path'] = $upload['file'];
                }
            }
        }

        // Procesar campos de CAF por tipo de DTE.
        $caf_types = $input['caf_type'] ?? [];
        $caf_paths = $input['caf_path'] ?? [];
        $files     = $_FILES['caf_file'] ?? [];
        $output['caf_path'] = [];

        foreach ( $caf_types as $index => $tipo ) {
            $tipo = sanitize_text_field( $tipo );
            if ( empty( $tipo ) ) {
                continue;
            }

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
                $output['caf_path'][ $tipo ] = $path;
            }
        }

        return $output;
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
            'cert_path'     => '',
            'cert_pass'     => '',
            'caf_path'      => [],
            'api_token'    => '',
            'api_token_expires' => 0,
            'environment'   => 'test',
            'enabled_dte_types' => [ '39', '33', '34', '52', '56', '61' ],
            'logo_id'       => 0,
            'enable_logging' => 0,
        ];
        return wp_parse_args( get_option( self::OPTION_NAME, [] ), $defaults );
    }

    /**
     * Renderiza checkboxes para seleccionar los tipos de DTE disponibles en el checkout.
     */
    public function render_field_enabled_dte_types() {
        $options = $this->get_settings();
        $enabled = isset( $options['enabled_dte_types'] ) && is_array( $options['enabled_dte_types'] ) ? $options['enabled_dte_types'] : [];
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
                checked( in_array( $code, $enabled, true ), true, false ),
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
            '<input type="text" name="%s[razon_social]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['razon_social'] )
        );
    }

    /**
     * Renderiza el campo de texto para el giro comercial.
     */
    public function render_field_giro() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[giro]" value="%s" class="regular-text" />',
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
     * Renderiza el campo para la ruta del certificado digital.
     */
    public function render_field_cert_path() {
        $options = $this->get_settings();
        printf(
            '<input type="file" name="cert_file" accept=".pfx,.p12" />'
        );
        if ( ! empty( $options['cert_path'] ) ) {
            $current_cert = wp_basename( wp_normalize_path( $options['cert_path'] ) );
            printf(
                '<p class="description">%s <code>%s</code></p>',
                esc_html__( 'Certificado actual:', 'sii-boleta-dte' ),
                esc_html( $current_cert )
            );
        }
        echo '<p class="description">' . esc_html__( 'Cargue el certificado .pfx/.p12 o indique la ruta absoluta si ya existe en el servidor.', 'sii-boleta-dte' ) . '</p>';
        printf(
            '<input type="text" name="%s[cert_path]" value="%s" class="regular-text" placeholder="/ruta/certificado.pfx" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['cert_path'] )
        );
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

        echo '<div id="sii-dte-caf-container">';
        if ( empty( $caf_paths ) ) {
            $caf_paths = [ '' => '' ];
        }
        foreach ( $caf_paths as $tipo => $path ) {
            ?>
            <div class="sii-dte-caf-row">
                <input type="text" name="<?php echo $option_key; ?>[caf_type][]" value="<?php echo esc_attr( $tipo ); ?>" placeholder="<?php esc_attr_e( 'Tipo DTE', 'sii-boleta-dte' ); ?>" class="small-text" />
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
            $('#sii-dte-add-caf').on('click', function(){
                var row = '<div class="sii-dte-caf-row">'
                    + '<input type="text" name="'+ '<?php echo $option_key; ?>' +'[caf_type][]" placeholder="<?php esc_attr_e( 'Tipo DTE', 'sii-boleta-dte' ); ?>" class="small-text" />'
                    + '<input type="file" name="caf_file[]" accept=".xml" />'
                    + '<input type="text" name="'+ '<?php echo $option_key; ?>' +'[caf_path][]" class="regular-text" placeholder="/ruta/CAF.xml" />'
                    + '<button type="button" class="button sii-dte-remove-caf">&times;</button>'
                    + '</div>';
                $('#sii-dte-caf-container').append(row);
            });
            $(document).on('click', '.sii-dte-remove-caf', function(){
                $(this).closest('.sii-dte-caf-row').remove();
            });
        });
        </script>
        <?php
    }

    /**
     * Renderiza el campo para el token de la API.
     */
    public function render_field_api_token() {
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[api_token]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['api_token'] )
        );
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
     * Renderiza la página de configuración completa. Se engancha en el menú
     * principal desde la clase SII_Boleta_Core.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Configuración SII Boleta DTE', 'sii-boleta-dte' ); ?></h1>
            <form action="options.php" method="post" enctype="multipart/form-data">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'sii-boleta-dte' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}