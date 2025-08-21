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
            'environment',
            __( 'Ambiente', 'sii-boleta-dte' ),
            [ $this, 'render_field_environment' ],
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
    }

    /**
     * Sanitiza los ajustes recibidos antes de guardarlos en la base de datos.
     *
     * @param array $input Datos recibidos desde el formulario.
     * @return array Datos sanitizados.
     */
    public function sanitize_settings( $input ) {
        $output = [];
        $output['rut_emisor']    = sanitize_text_field( $input['rut_emisor'] ?? '' );
        $output['razon_social']  = sanitize_text_field( $input['razon_social'] ?? '' );
        $output['giro']          = sanitize_text_field( $input['giro'] ?? '' );
        $output['direccion']     = sanitize_text_field( $input['direccion'] ?? '' );
        $output['comuna']        = sanitize_text_field( $input['comuna'] ?? '' );
        $output['cert_path']     = sanitize_text_field( $input['cert_path'] ?? '' );
        $output['cert_pass']     = sanitize_text_field( $input['cert_pass'] ?? '' );
        $output['caf_path']      = sanitize_text_field( $input['caf_path'] ?? '' );
        $output['environment']   = in_array( $input['environment'] ?? 'test', [ 'test', 'production' ], true ) ? $input['environment'] : 'test';
        $output['logo_id']       = isset( $input['logo_id'] ) ? intval( $input['logo_id'] ) : 0;
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
            'caf_path'      => '',
            'environment'   => 'test',
            'logo_id'       => 0,
        ];
        return wp_parse_args( get_option( self::OPTION_NAME, [] ), $defaults );
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
            '<input type="text" name="%s[cert_path]" value="%s" class="regular-text" placeholder="/ruta/certificado.pfx" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['cert_path'] )
        );
        echo '<p class="description">' . esc_html__( 'Indique la ruta absoluta al archivo de certificado .pfx/.p12 en el servidor.', 'sii-boleta-dte' ) . '</p>';
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
        $options = $this->get_settings();
        printf(
            '<input type="text" name="%s[caf_path]" value="%s" class="regular-text" placeholder="/ruta/CAF.xml" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $options['caf_path'] )
        );
        echo '<p class="description">' . esc_html__( 'Ruta del archivo CAF emitido por el SII para folios de boletas. Este archivo se utiliza para timbrar el DTE.', 'sii-boleta-dte' ) . '</p>';
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
     * Renderiza la página de configuración completa. Se engancha en el menú
     * principal desde la clase SII_Boleta_Core.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Configuración SII Boleta DTE', 'sii-boleta-dte' ); ?></h1>
            <form action="options.php" method="post">
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