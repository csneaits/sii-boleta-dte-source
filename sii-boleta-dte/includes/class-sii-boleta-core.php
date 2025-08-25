<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase núcleo encargada de inicializar los diferentes componentes del plugin.
 *
 * Esta clase encapsula la lógica común de arranque y se encarga de crear
 * instancias de las clases de configuración, folios, generador de XML, firma,
 * API del SII, generación de PDF y RVD, así como la integración con WooCommerce.
 */
class SII_Boleta_Core {

    /**
     * Instancias de clases utilizadas por el plugin. Se guardan como
     * propiedades para permitir que otros componentes accedan a ellas si es
     * necesario mediante métodos getter.
     *
     * @var SII_Boleta_Settings
     * @var SII_Boleta_Folio_Manager
     * @var SII_Boleta_XML_Generator
     * @var SII_Boleta_Signer
     * @var SII_Boleta_API
     * @var SII_Boleta_PDF
     * @var SII_Boleta_RVD_Manager
     * @var SII_Boleta_Public
     * @var SII_Boleta_Woo
     * @var SII_Boleta_Metrics
     * @var SII_Boleta_Consumo_Folios
     */
    private $settings;
    private $folio_manager;
    private $xml_generator;
    private $signer;
    private $api;
    private $pdf;
    private $rvd_manager;
    private $public;
    private $woo;
    private $metrics;
    private $libro_boletas;
    private $consumo_folios;

    /**
     * Constructor. Inicializa todas las dependencias y registra las acciones
     * principales necesarias para el plugin.
     */
    public function __construct() {
        // Instanciar componentes
        $this->settings      = new SII_Boleta_Settings();
        $this->folio_manager = new SII_Boleta_Folio_Manager( $this->settings );
        $this->xml_generator = new SII_Boleta_XML_Generator( $this->settings );
        $this->signer        = new SII_Boleta_Signer();
        $this->api           = new SII_Boleta_API();
        $this->pdf           = new SII_Boleta_PDF();
        $this->rvd_manager   = new SII_Boleta_RVD_Manager( $this->settings );
        $this->libro_boletas = new SII_Boleta_Libro_Boletas( $this->settings );
        $this->public        = new SII_Boleta_Public();
        $this->metrics       = new SII_Boleta_Metrics();
        $this->consumo_folios = new SII_Boleta_Consumo_Folios( $this->settings, $this->folio_manager, $this->api );

        if ( class_exists( 'WooCommerce' ) ) {
            $this->woo = new SII_Boleta_Woo( $this );
        }

        // Registrar acciones para páginas del panel de administración
        add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );

        // Recursos necesarios para funcionalidades como la subida de imágenes
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Indicador visual del ambiente en la barra de administración
        add_action( 'admin_bar_menu', [ $this, 'add_environment_indicator' ], 100 );

        // Acciones AJAX para operaciones como generación de boletas desde el panel
        add_action( 'wp_ajax_sii_boleta_dte_generate_dte', [ $this, 'ajax_generate_dte' ] );
        add_action( 'wp_ajax_sii_boleta_dte_preview_dte', [ $this, 'ajax_preview_dte' ] );
        add_action( 'wp_ajax_sii_boleta_dte_list_dtes', [ $this, 'ajax_list_dtes' ] );
        add_action( 'wp_ajax_sii_boleta_dte_run_rvd', [ $this, 'ajax_run_rvd' ] );
        add_action( 'wp_ajax_sii_boleta_dte_job_log', [ $this, 'ajax_job_log' ] );
        add_action( 'wp_ajax_sii_boleta_dte_toggle_job', [ $this, 'ajax_toggle_job' ] );
        add_action( 'wp_ajax_sii_boleta_dte_generate_libro', [ $this, 'ajax_generate_libro' ] );
        add_action( 'wp_ajax_sii_boleta_dte_send_libro', [ $this, 'ajax_send_libro' ] );
        add_action( 'wp_ajax_sii_boleta_dte_run_cdf', [ $this, 'ajax_run_cdf' ] );
    }

    /**
     * Devuelve la instancia de configuraciones. Útil para otras clases.
     *
     * @return SII_Boleta_Settings
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Devuelve la instancia del manejador de folios.
     *
     * @return SII_Boleta_Folio_Manager
     */
    public function get_folio_manager() {
        return $this->folio_manager;
    }

    /**
     * Devuelve la instancia del generador de XML.
     *
     * @return SII_Boleta_XML_Generator
     */
    public function get_xml_generator() {
        return $this->xml_generator;
    }

    /**
     * Devuelve la instancia del firmador de XML.
     *
     * @return SII_Boleta_Signer
     */
    public function get_signer() {
        return $this->signer;
    }

    /**
     * Devuelve la instancia de la API del SII.
     *
     * @return SII_Boleta_API
     */
    public function get_api() {
        return $this->api;
    }

    /**
     * Devuelve la instancia del generador de PDF.
     *
     * @return SII_Boleta_PDF
     */
    public function get_pdf() {
        return $this->pdf;
    }

    /**
     * Devuelve la instancia del manejador de RVD.
     *
     * @return SII_Boleta_RVD_Manager
     */
    public function get_rvd_manager() {
        return $this->rvd_manager;
    }

    /**
     * Devuelve la instancia del manejador de Consumo de Folios.
     *
     * @return SII_Boleta_Consumo_Folios
     */
    public function get_consumo_folios() {
        return $this->consumo_folios;
    }

    /**
     * Agrega un indicador en la barra de administración para mostrar si el
     * plugin está operando en ambiente de pruebas o producción.
     *
     * @param WP_Admin_Bar $wp_admin_bar Barra de administración de WordPress.
     */
    public function add_environment_indicator( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->settings->get_settings();
        $env      = isset( $settings['environment'] ) ? $settings['environment'] : 'test';

        $label = ( 'production' === $env )
            ? __( 'Producción', 'sii-boleta-dte' )
            : __( 'Pruebas', 'sii-boleta-dte' );

        $color = ( 'production' === $env ) ? '#46b450' : '#ffb900';

        $title = sprintf(
            '<span class="ab-label" style="background:%s;color:#fff;padding:0 5px;border-radius:3px;">%s: %s</span>',
            esc_attr( $color ),
            esc_html__( 'SII DTE', 'sii-boleta-dte' ),
            esc_html( $label )
        );

        $wp_admin_bar->add_node([
            'id'    => 'sii-boleta-dte-env',
            'title' => $title,
            'href'  => admin_url( 'admin.php?page=sii-boleta-dte' ),
        ]);
    }

    /**
     * Agrega las páginas al menú de administración. Aquí se declaran las
     * distintas pantallas: configuración y emisión manual de boletas.
     */
    public function add_admin_pages() {
        add_menu_page(
            __( 'SII Boletas', 'sii-boleta-dte' ),
            __( 'SII Boletas', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte',
            [ $this->settings, 'render_settings_page' ],
            'dashicons-media-document'
        );

        add_submenu_page(
            'sii-boleta-dte',
            __( 'Generar DTE', 'sii-boleta-dte' ),
            __( 'Generar DTE', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte-generate',
            [ $this, 'render_generate_dte_page' ]
        );

        add_submenu_page(
            'sii-boleta-dte',
            __( 'Panel de Control', 'sii-boleta-dte' ),
            __( 'Panel de Control', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte-panel',
            [ $this, 'render_control_panel_page' ]
        );

        add_submenu_page(
            'sii-boleta-dte',
            __( 'Libro de Boletas', 'sii-boleta-dte' ),
            __( 'Libro de Boletas', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte-libro',
            [ $this, 'render_libro_boletas_page' ]
        );

        add_submenu_page(
            'sii-boleta-dte',
            __( 'Actividad del Job', 'sii-boleta-dte' ),
            __( 'Actividad del Job', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte-job-log',
            [ $this, 'render_job_log_page' ]
        );
    }

    /**
     * Encola scripts y estilos necesarios en el área de administración.
     *
     * @param string $hook Nombre del hook de la pantalla actual.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_sii-boleta-dte' === $hook ) {
            wp_enqueue_media();
        }
    }

    /**
     * Renderiza un panel de control con pestañas para distintos contenidos.
     */
    public function render_control_panel_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'boletas';
        ?>
        <div class='wrap'>
            <h1><?php esc_html_e( 'Panel de Control', 'sii-boleta-dte' ); ?></h1>
            <h2 class='nav-tab-wrapper'>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=boletas' ) ); ?>' class='nav-tab <?php echo ( 'boletas' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Boletas', 'sii-boleta-dte' ); ?></a>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=jobs' ) ); ?>' class='nav-tab <?php echo ( 'jobs' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Jobs', 'sii-boleta-dte' ); ?></a>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=metrics' ) ); ?>' class='nav-tab <?php echo ( 'metrics' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Métricas', 'sii-boleta-dte' ); ?></a>
            </h2>
            <?php if ( 'jobs' === $active_tab ) : ?>
                <p>
                    <?php esc_html_e( 'Estado del job:', 'sii-boleta-dte' ); ?>
                    <span id="sii-job-status"></span>
                </p>
                <p>
                    <?php esc_html_e( 'Próxima ejecución del job:', 'sii-boleta-dte' ); ?>
                    <span id="sii-job-next"></span>
                </p>
                <p>
                    <button type="button" class="button" id="sii-toggle-job"><?php esc_html_e( 'Programar Job', 'sii-boleta-dte' ); ?></button>
                    <button type="button" class="button" id="sii-run-rvd"><?php esc_html_e( 'Generar RVD del día', 'sii-boleta-dte' ); ?></button>
                    <button type="button" class="button" id="sii-run-cdf"><?php esc_html_e( 'Generar CDF del día', 'sii-boleta-dte' ); ?></button>
                </p>
                <div id="sii-rvd-result"></div>
                <div id="sii-cdf-result"></div>
                <pre id="sii-job-log" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:300px;overflow:auto;"></pre>
            <?php elseif ( 'metrics' === $active_tab ) : ?>
                <?php $metrics = $this->metrics->gather_metrics(); ?>
                <h2><?php esc_html_e( 'Resumen de Documentos', 'sii-boleta-dte' ); ?></h2>
                <p><?php printf( esc_html__( 'Total de DTE generados: %d', 'sii-boleta-dte' ), intval( $metrics['total'] ) ); ?></p>
                <p><?php printf( esc_html__( 'DTE enviados al SII: %d', 'sii-boleta-dte' ), intval( $metrics['sent'] ) ); ?></p>
                <?php if ( ! empty( $metrics['by_type'] ) ) : ?>
                    <h3><?php esc_html_e( 'Cantidad por tipo', 'sii-boleta-dte' ); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Tipo', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $metrics['by_type'] as $type => $count ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $type ); ?></td>
                                    <td><?php echo esc_html( $count ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <h3><?php esc_html_e( 'Errores detectados', 'sii-boleta-dte' ); ?></h3>
                <?php if ( $metrics['errors'] ) : ?>
                    <p><?php printf( esc_html__( 'Total de errores: %d', 'sii-boleta-dte' ), intval( $metrics['errors'] ) ); ?></p>
                    <?php if ( ! empty( $metrics['error_reasons'] ) ) : ?>
                        <ul>
                            <?php foreach ( $metrics['error_reasons'] as $reason => $count ) : ?>
                                <li><?php echo esc_html( sprintf( '%s: %d', $reason, $count ) ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else : ?>
                    <p><?php esc_html_e( 'No se encontraron errores en el log.', 'sii-boleta-dte' ); ?></p>
                <?php endif; ?>
            <?php else : ?>
                <p>
                    <button type="button" class="button" id="sii-refresh-dtes"><?php esc_html_e( 'Actualizar', 'sii-boleta-dte' ); ?></button>
                </p>
                <table class="widefat striped" id="sii-dte-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Tipo', 'sii-boleta-dte' ); ?></th>
                            <th><?php esc_html_e( 'Folio', 'sii-boleta-dte' ); ?></th>
                            <th><?php esc_html_e( 'Fecha', 'sii-boleta-dte' ); ?></th>
                            <th><?php esc_html_e( 'XML', 'sii-boleta-dte' ); ?></th>
                            <th><?php esc_html_e( 'PDF/HTML', 'sii-boleta-dte' ); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            <?php endif; ?>
        </div>
        <script type="text/javascript">
        jQuery(function($){
            var activeTab = '<?php echo esc_js( $active_tab ); ?>';
            if (activeTab === 'boletas') {
                function loadDtes(){
                    $('#sii-dte-table tbody').html('<tr><td colspan="5"><?php echo esc_js( __( 'Cargando...', 'sii-boleta-dte' ) ); ?></td></tr>');
                    $.post(ajaxurl, {action:'sii_boleta_dte_list_dtes'}, function(resp){
                        if(resp.success){
                            var rows='';
                            $.each(resp.data.dtes, function(i,d){
                                var pdf = d.pdf ? '<a href="'+d.pdf+'" target="_blank"><?php echo esc_js( __( 'Ver', 'sii-boleta-dte' ) ); ?></a>' : '-';
                                rows += '<tr><td>'+d.tipo+'</td><td>'+d.folio+'</td><td>'+d.fecha+'</td><td><a href="'+d.xml+'" target="_blank">XML</a></td><td>'+pdf+'</td></tr>';
                            });
                            if(!rows){ rows = '<tr><td colspan="5"><?php echo esc_js( __( 'No hay DTE disponibles.', 'sii-boleta-dte' ) ); ?></td></tr>'; }
                            $('#sii-dte-table tbody').html(rows);
                        } else {
                            $('#sii-dte-table tbody').html('<tr><td colspan="5">'+resp.data.message+'</td></tr>');
                        }
                    });
                }
                $('#sii-refresh-dtes').on('click', loadDtes);
                loadDtes();
            } else if (activeTab === 'jobs') {
                function loadLog(){
                    $.post(ajaxurl, {action:'sii_boleta_dte_job_log'}, function(resp){
                        if(resp.success){
                            $('#sii-job-log').text(resp.data.log);
                            $('#sii-job-next').text(resp.data.next_run);
                            var active = resp.data.status === 'active';
                            $('#sii-job-status').text(active ? '<?php echo esc_js( __( 'Activo', 'sii-boleta-dte' ) ); ?>' : '<?php echo esc_js( __( 'Inactivo', 'sii-boleta-dte' ) ); ?>');
                            $('#sii-toggle-job').text(active ? '<?php echo esc_js( __( 'Desprogramar Job', 'sii-boleta-dte' ) ); ?>' : '<?php echo esc_js( __( 'Programar Job', 'sii-boleta-dte' ) ); ?>');
                        }
                    });
                }
                $('#sii-toggle-job').on('click', function(){
                    $('#sii-rvd-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                    $.post(ajaxurl, {action:'sii_boleta_dte_toggle_job'}, function(resp){
                        if(resp.success){
                            $('#sii-rvd-result').html('<div class="notice notice-success"><p>'+resp.data.message+'</p></div>');
                        }else{
                            $('#sii-rvd-result').html('<div class="notice notice-error"><p>'+resp.data.message+'</p></div>');
                        }
                        loadLog();
                    });
                });
                $('#sii-run-rvd').on('click', function(){
                    $('#sii-rvd-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                    $.post(ajaxurl, {action:'sii_boleta_dte_run_rvd'}, function(resp){
                        if(resp.success){
                            $('#sii-rvd-result').html('<div class="notice notice-success"><p>'+resp.data.message+'</p></div>');
                        }else{
                            $('#sii-rvd-result').html('<div class="notice notice-error"><p>'+resp.data.message+'</p></div>');
                        }
                        loadLog();
                    });
                });
                $('#sii-run-cdf').on('click', function(){
                    $('#sii-cdf-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                    $.post(ajaxurl, {action:'sii_boleta_dte_run_cdf'}, function(resp){
                        if(resp.success){
                            $('#sii-cdf-result').html('<div class="notice notice-success"><p>'+resp.data.message+'</p></div>');
                        }else{
                            $('#sii-cdf-result').html('<div class="notice notice-error"><p>'+resp.data.message+'</p></div>');
                        }
                    });
                });
                loadLog();
            }
        });
        </script>
        <?php
    }

    /**
     * Renderiza la página de generación manual de DTE (boletas, facturas,
     * guías de despacho y notas de crédito o débito).
     */
    public function render_generate_dte_page() {
        // Cargar folio disponible por AJAX y manejar generación desde el cliente
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Generar DTE', 'sii-boleta-dte' ); ?></h1>
            <form id="sii-boleta-generate-form" method="post">
                <?php wp_nonce_field( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="dte_type"><?php esc_html_e( 'Tipo de DTE', 'sii-boleta-dte' ); ?></label></th>
                        <td>
                    <select name="dte_type" id="dte_type">
                                <option value="39">Boleta Electrónica (39)</option>
                                <option value="33">Factura Electrónica (33)</option>
                                <option value="34">Factura Exenta (34)</option>
                                <option value="52">Guía de Despacho Electrónica (52)</option>
                                <option value="61">Nota de Crédito Electrónica (61)</option>
                                <option value="56">Nota de Débito Electrónica (56)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="receptor_rut"><?php esc_html_e( 'RUT Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="receptor_rut" id="receptor_rut" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="receptor_nombre"><?php esc_html_e( 'Nombre Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="receptor_nombre" id="receptor_nombre" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="direccion_recep"><?php esc_html_e( 'Dirección Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="direccion_recep" id="direccion_recep" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="comuna_recep"><?php esc_html_e( 'Comuna Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="comuna_recep" id="comuna_recep" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="descripcion"><?php esc_html_e( 'Descripción Ítem', 'sii-boleta-dte' ); ?></label></th>
                        <td><textarea name="descripcion" id="descripcion" class="large-text" rows="3" required></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cantidad"><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="number" name="cantidad" id="cantidad" class="small-text" step="1" min="1" value="1" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="precio_unitario"><?php esc_html_e( 'Precio Unitario', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="number" name="precio_unitario" id="precio_unitario" class="small-text" step="0.01" min="0" value="0" required></td>
                    </tr>
                    <tr id="referencia_fields" style="display:none;">
                        <th scope="row"><label for="folio_ref"><?php esc_html_e( 'Folio Documento Referencia', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="folio_ref" id="folio_ref" class="regular-text"><br/>
                            <label for="tipo_doc_ref"><?php esc_html_e( 'Tipo Doc Referencia', 'sii-boleta-dte' ); ?></label>
                            <select name="tipo_doc_ref" id="tipo_doc_ref">
                                <option value="39">Boleta (39)</option>
                                <option value="33">Factura (33)</option>
                                <option value="34">Factura Exenta (34)</option>
                                <option value="52">Guía de Despacho (52)</option>
                                <option value="61">Nota de Crédito (61)</option>
                                <option value="56">Nota de Débito (56)</option>
                            </select><br/>
                            <label for="razon_ref"><?php esc_html_e( 'Razón Referencia', 'sii-boleta-dte' ); ?></label>
                            <input type="text" name="razon_ref" id="razon_ref" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="enviar_sii"><?php esc_html_e( '¿Enviar al SII?', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="checkbox" name="enviar_sii" id="enviar_sii" value="1"></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" id="sii-generate-dte">
                        <?php esc_html_e( 'Generar DTE', 'sii-boleta-dte' ); ?>
                    </button>
                </p>
                <div id="sii-boleta-result"></div>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            function toggleReferenceFields() {
                var type = $('#dte_type').val();
                if (type === '56' || type === '61') {
                    $('#referencia_fields').show();
                } else {
                    $('#referencia_fields').hide();
                }
            }
            function toggleAddressFields() {
                var type = $('#dte_type').val();
                if (type === '33' || type === '34' || type === '52') {
                    $('#direccion_recep').prop('required', true);
                    $('#comuna_recep').prop('required', true);
                } else {
                    $('#direccion_recep').prop('required', false);
                    $('#comuna_recep').prop('required', false);
                }
            }
            toggleReferenceFields();
            toggleAddressFields();
            $('#dte_type').on('change', function(){
                toggleReferenceFields();
                toggleAddressFields();
            });
            $('#sii-boleta-generate-form').on('submit', function(e){
                e.preventDefault();
                var data = $(this).serialize();
                $('#sii-generate-dte').prop('disabled', true);
                $('#sii-boleta-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                $.post(ajaxurl, data + '&action=sii_boleta_dte_generate_dte', function(response){
                    $('#sii-generate-dte').prop('disabled', false);
                    if (response.success) {
                        $('#sii-boleta-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    } else {
                        $('#sii-boleta-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Renderiza la página para generar y enviar el Libro de Boletas manualmente.
     */
    public function render_libro_boletas_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Libro de Boletas', 'sii-boleta-dte' ); ?></h1>
            <form id="sii-libro-form" method="post">
                <?php wp_nonce_field( 'sii_boleta_generate_libro', 'sii_boleta_generate_libro_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="fecha_inicio"><?php esc_html_e( 'Fecha inicio', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="date" name="fecha_inicio" id="fecha_inicio" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fecha_fin"><?php esc_html_e( 'Fecha fin', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="date" name="fecha_fin" id="fecha_fin" required></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" id="sii-generate-libro"><?php esc_html_e( 'Generar Libro', 'sii-boleta-dte' ); ?></button>
                    <button type="button" class="button" id="sii-send-libro"><?php esc_html_e( 'Enviar al SII', 'sii-boleta-dte' ); ?></button>
                </p>
                <div id="sii-libro-result"></div>
            </form>
        </div>
        <script>
        jQuery(function($){
            $('#sii-libro-form').on('submit', function(e){
                e.preventDefault();
                var data = $(this).serialize();
                $('#sii-generate-libro').prop('disabled', true);
                $('#sii-libro-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                $.post(ajaxurl, data + '&action=sii_boleta_dte_generate_libro', function(response){
                    $('#sii-generate-libro').prop('disabled', false);
                    if (response.success) {
                        $('#sii-libro-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    } else {
                        $('#sii-libro-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                });
            });
            $('#sii-send-libro').on('click', function(){
                var data = $('#sii-libro-form').serialize();
                $('#sii-send-libro').prop('disabled', true);
                $('#sii-libro-result').html('<p><?php echo esc_js( __( 'Enviando...', 'sii-boleta-dte' ) ); ?></p>');
                $.post(ajaxurl, data + '&action=sii_boleta_dte_send_libro', function(response){
                    $('#sii-send-libro').prop('disabled', false);
                    if (response.success) {
                        $('#sii-libro-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    } else {
                        $('#sii-libro-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Muestra un registro simple de la actividad del job diario.
     */
    public function render_job_log_page() {
        $upload_dir = wp_upload_dir();
        $log_file   = trailingslashit( $upload_dir['basedir'] ) . 'sii-boleta-logs/sii-boleta.log';
        $log        = file_exists( $log_file ) ? file_get_contents( $log_file ) : __( 'No hay actividad registrada.', 'sii-boleta-dte' );
        $next_run   = wp_next_scheduled( SII_Boleta_Cron::CRON_HOOK );
        $next_run_human = $next_run ? date_i18n( 'Y-m-d H:i:s', $next_run ) : __( 'No programado', 'sii-boleta-dte' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Actividad del Job', 'sii-boleta-dte' ); ?></h1>
            <p><?php printf( esc_html__( 'Hora de activación del job: %s', 'sii-boleta-dte' ), esc_html( $next_run_human ) ); ?></p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:400px;overflow:auto;">
<?php echo esc_html( $log ); ?>
            </pre>
        </div>
        <?php
    }

    /**
     * Manejador AJAX para generar un DTE desde la interfaz de administración.
     */
    public function ajax_generate_dte() {
        check_admin_referer( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }

        $type            = isset( $_POST['dte_type'] ) ? intval( $_POST['dte_type'] ) : 39;
        $rut_receptor    = sanitize_text_field( $_POST['receptor_rut'] );
        $nombre_receptor = sanitize_text_field( $_POST['receptor_nombre'] );
        $dir_recep       = sanitize_text_field( $_POST['direccion_recep'] ?? '' );
        $cmna_recep      = sanitize_text_field( $_POST['comuna_recep'] ?? '' );
        $descripcion     = sanitize_textarea_field( $_POST['descripcion'] );
        $cantidad        = max( 1, intval( $_POST['cantidad'] ) );
        $precio_unitario = max( 0, floatval( $_POST['precio_unitario'] ) );
        $enviar_sii      = isset( $_POST['enviar_sii'] );
        // Datos de referencia para notas
        $folio_ref       = isset( $_POST['folio_ref'] ) ? sanitize_text_field( $_POST['folio_ref'] ) : '';
        $tipo_doc_ref    = isset( $_POST['tipo_doc_ref'] ) ? sanitize_text_field( $_POST['tipo_doc_ref'] ) : '';
        $razon_ref       = isset( $_POST['razon_ref'] ) ? sanitize_text_field( $_POST['razon_ref'] ) : '';

        // Obtener un nuevo folio
        $folio = $this->folio_manager->get_next_folio( $type );
        if ( is_wp_error( $folio ) ) {
            wp_send_json_error( [ 'message' => $folio->get_error_message() ] );
        }
        if ( ! $folio ) {
            wp_send_json_error( [ 'message' => __( 'No hay folios disponibles. Cargue un CAF válido.', 'sii-boleta-dte' ) ] );
        }

        // Preparar datos comunes para el DTE
        $settings = $this->settings->get_settings();
        $monto_total = round( $cantidad * $precio_unitario );
        $dte_data = [
            'TipoDTE'    => $type,
            'Folio'      => $folio,
            'FchEmis'    => date( 'Y-m-d' ),
            'RutEmisor'  => $settings['rut_emisor'],
            'RznSoc'     => $settings['razon_social'],
            'GiroEmisor' => $settings['giro'],
            'DirOrigen'  => $settings['direccion'],
            'CmnaOrigen' => $settings['comuna'],
            'Receptor'   => [
                'RUTRecep'    => $rut_receptor,
                'RznSocRecep' => $nombre_receptor,
                'DirRecep'    => $dir_recep,
                'CmnaRecep'   => $cmna_recep,
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => $descripcion,
                    'QtyItem'   => $cantidad,
                    'PrcItem'   => $precio_unitario,
                    'MontoItem' => $monto_total,
                ],
            ],
        ];
        // Añadir referencia si corresponde (notas de crédito o débito)
        if ( in_array( $type, [56,61], true ) && $folio_ref && $tipo_doc_ref ) {
            $dte_data['Referencias'][] = [
                'TpoDocRef' => $tipo_doc_ref,
                'FolioRef'  => $folio_ref,
                'FchRef'    => date( 'Y-m-d' ),
                'RazonRef'  => $razon_ref ?: 'Corrección',
            ];
        }

        // Generar XML base para el DTE
        $xml = $this->xml_generator->generate_dte_xml( $dte_data, $type );
        if ( is_wp_error( $xml ) ) {
            wp_send_json_error( [ 'message' => $xml->get_error_message() ] );
        }
        if ( ! $xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al generar el XML del DTE.', 'sii-boleta-dte' ) ] );
        }

        // Firmar el XML
        $signed_xml = $this->signer->sign_dte_xml( $xml, $settings['cert_path'], $settings['cert_pass'] );
        if ( ! $signed_xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al firmar el XML. Verifique su certificado.', 'sii-boleta-dte' ) ] );
        }

        // Guardar el archivo XML en la carpeta uploads
        $upload_dir = wp_upload_dir();
        $file_name  = 'DTE_' . $type . '_' . $folio . '_' . time() . '.xml';
        $file_path  = trailingslashit( $upload_dir['basedir'] ) . $file_name;
        file_put_contents( $file_path, $signed_xml );

        // Lógica para enviar al SII si el usuario lo solicita
        $track_id = false;
        if ( $enviar_sii ) {
            $track_id = $this->api->send_dte_to_sii(
                $file_path,
                $settings['environment'],
                $settings['api_token'],
                $settings['cert_path'],
                $settings['cert_pass']
            );
            if ( is_wp_error( $track_id ) ) {
                wp_send_json_error( [ 'message' => $track_id->get_error_message() ] );
            }
        }

        // Generar PDF de representación de la boleta con TED y PDF417
        $pdf_path = $this->pdf->generate_pdf_representation( $signed_xml, $settings );

        // Agregar el resultado del envío al mensaje de éxito
        $message = sprintf( __( 'DTE generado correctamente. Archivo XML: %s', 'sii-boleta-dte' ), esc_html( $file_name ) );
        if ( $track_id ) {
            $message .= ' | ' . sprintf( __( 'Enviado al SII. Track ID: %s', 'sii-boleta-dte' ), esc_html( $track_id ) );
        }

        if ( $pdf_path ) {
            $message .= ' | ' . sprintf( __( 'PDF generado: %s', 'sii-boleta-dte' ), esc_html( basename( $pdf_path ) ) );
        }

        wp_send_json_success( [ 'message' => $message ] );
    }

    /**
     * Genera una previsualización del DTE sin consumir folios ni enviarlo al SII.
     */
    public function ajax_preview_dte() {
        check_admin_referer( 'sii_boleta_preview_dte', 'sii_boleta_preview_dte_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }

        $type            = isset( $_POST['dte_type'] ) ? intval( $_POST['dte_type'] ) : 39;
        $rut_receptor    = sanitize_text_field( $_POST['receptor_rut'] );
        $nombre_receptor = sanitize_text_field( $_POST['receptor_nombre'] );
        $dir_recep       = sanitize_text_field( $_POST['direccion_recep'] ?? '' );
        $cmna_recep      = sanitize_text_field( $_POST['comuna_recep'] ?? '' );
        $descripcion     = sanitize_textarea_field( $_POST['descripcion'] );
        $cantidad        = max( 1, intval( $_POST['cantidad'] ) );
        $precio_unitario = max( 0, floatval( $_POST['precio_unitario'] ) );

        $settings   = $this->settings->get_settings();
        $monto_total = round( $cantidad * $precio_unitario );
        $dte_data = [
            'TipoDTE'    => $type,
            'Folio'      => 0,
            'FchEmis'    => date( 'Y-m-d' ),
            'RutEmisor'  => $settings['rut_emisor'],
            'RznSoc'     => $settings['razon_social'],
            'GiroEmisor' => $settings['giro'],
            'DirOrigen'  => $settings['direccion'],
            'CmnaOrigen' => $settings['comuna'],
            'Receptor'   => [
                'RUTRecep'    => $rut_receptor,
                'RznSocRecep' => $nombre_receptor,
                'DirRecep'    => $dir_recep,
                'CmnaRecep'   => $cmna_recep,
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => $descripcion,
                    'QtyItem'   => $cantidad,
                    'PrcItem'   => $precio_unitario,
                    'MontoItem' => $monto_total,
                ],
            ],
        ];

        $xml = $this->xml_generator->generate_dte_xml( $dte_data, $type, true );
        if ( is_wp_error( $xml ) || ! $xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al generar la previsualización del DTE.', 'sii-boleta-dte' ) ] );
        }

        $pdf_path = $this->pdf->generate_pdf_representation( $xml, $settings );
        if ( ! $pdf_path ) {
            wp_send_json_error( [ 'message' => __( 'No se pudo generar el archivo de previsualización.', 'sii-boleta-dte' ) ] );
        }

        $upload_dir = wp_upload_dir();
        $preview_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path );

        wp_send_json_success( [
            'message'     => __( 'Previsualización generada correctamente.', 'sii-boleta-dte' ),
            'preview_url' => esc_url_raw( $preview_url ),
        ] );
    }

    /**
     * Devuelve la lista de DTE generados buscando los archivos en la carpeta de uploads.
     */
    public function ajax_list_dtes() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
        $files      = glob( $base_dir . 'DTE_*.xml' );
        $dtes       = [];
        if ( $files ) {
            foreach ( $files as $file ) {
                $name = basename( $file );
                if ( preg_match( '/DTE_(\d+)_(\d+)_(\d+)\.xml$/', $name, $m ) ) {
                    $tipo = $m[1];
                    $folio = $m[2];
                    $ts   = (int) $m[3];
                    $fecha = date_i18n( 'Y-m-d H:i', $ts );
                    $pdf   = '';
                    $pdf_file  = $base_dir . 'DTE_' . $tipo . '_' . $folio . '_' . $m[3] . '.pdf';
                    $html_file = $base_dir . 'DTE_' . $tipo . '_' . $folio . '_' . $m[3] . '.html';
                    if ( file_exists( $pdf_file ) ) {
                        $pdf = $base_url . basename( $pdf_file );
                    } elseif ( file_exists( $html_file ) ) {
                        $pdf = $base_url . basename( $html_file );
                    }
                    $dtes[] = [
                        'tipo'  => $tipo,
                        'folio' => $folio,
                        'fecha' => $fecha,
                        'xml'   => $base_url . $name,
                        'pdf'   => $pdf,
                    ];
                }
            }
        }
        wp_send_json_success( [ 'dtes' => $dtes ] );
    }

    /**
     * Genera el Libro de Boletas para un rango de fechas y guarda el XML.
     */
    public function ajax_generate_libro() {
        check_admin_referer( 'sii_boleta_generate_libro', 'sii_boleta_generate_libro_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $inicio = isset( $_POST['fecha_inicio'] ) ? sanitize_text_field( $_POST['fecha_inicio'] ) : '';
        $fin    = isset( $_POST['fecha_fin'] ) ? sanitize_text_field( $_POST['fecha_fin'] ) : '';
        if ( ! $inicio || ! $fin ) {
            wp_send_json_error( [ 'message' => __( 'Fechas inválidas.', 'sii-boleta-dte' ) ] );
        }
        $xml = $this->libro_boletas->generate_libro_xml( $inicio, $fin );
        if ( ! $xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al generar el libro.', 'sii-boleta-dte' ) ] );
        }
        $upload_dir = wp_upload_dir();
        $file_name  = 'LibroBoletas_' . $inicio . '_' . $fin . '.xml';
        file_put_contents( trailingslashit( $upload_dir['basedir'] ) . $file_name, $xml );
        wp_send_json_success( [ 'message' => sprintf( __( 'Libro generado: %s', 'sii-boleta-dte' ), esc_html( $file_name ) ) ] );
    }

    /**
     * Genera y envía el Libro de Boletas al SII.
     */
    public function ajax_send_libro() {
        check_admin_referer( 'sii_boleta_generate_libro', 'sii_boleta_generate_libro_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $inicio = isset( $_POST['fecha_inicio'] ) ? sanitize_text_field( $_POST['fecha_inicio'] ) : '';
        $fin    = isset( $_POST['fecha_fin'] ) ? sanitize_text_field( $_POST['fecha_fin'] ) : '';
        if ( ! $inicio || ! $fin ) {
            wp_send_json_error( [ 'message' => __( 'Fechas inválidas.', 'sii-boleta-dte' ) ] );
        }
        $xml = $this->libro_boletas->generate_libro_xml( $inicio, $fin );
        if ( ! $xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al generar el libro.', 'sii-boleta-dte' ) ] );
        }
        $settings = $this->settings->get_settings();
        $sent = $this->libro_boletas->send_libro_to_sii( $xml, $settings['environment'], $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        if ( $sent ) {
            wp_send_json_success( [ 'message' => __( 'Libro enviado correctamente.', 'sii-boleta-dte' ) ] );
        }
        wp_send_json_error( [ 'message' => __( 'Error al enviar el libro.', 'sii-boleta-dte' ) ] );
    }

    /**
     * Devuelve el registro de actividad del job y la próxima ejecución programada.
     */
    public function ajax_job_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $upload_dir = wp_upload_dir();
        $log_file   = trailingslashit( $upload_dir['basedir'] ) . 'sii-boleta-logs/sii-boleta.log';
        $log        = file_exists( $log_file ) ? file_get_contents( $log_file ) : __( 'No hay actividad registrada.', 'sii-boleta-dte' );
        $next_run   = wp_next_scheduled( SII_Boleta_Cron::CRON_HOOK );
        $status     = $next_run ? 'active' : 'inactive';
        $next_run_human = $next_run ? date_i18n( 'Y-m-d H:i:s', $next_run ) : __( 'No programado', 'sii-boleta-dte' );
        wp_send_json_success( [ 'log' => $log, 'next_run' => $next_run_human, 'status' => $status ] );
    }

    /**
     * Programa o desprograma el job diario via AJAX.
     */
    public function ajax_toggle_job() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $next_run = wp_next_scheduled( SII_Boleta_Cron::CRON_HOOK );
        if ( $next_run ) {
            SII_Boleta_Cron::deactivate();
            wp_send_json_success( [ 'message' => __( 'Job desprogramado.', 'sii-boleta-dte' ) ] );
        } else {
            SII_Boleta_Cron::activate();
            wp_send_json_success( [ 'message' => __( 'Job programado.', 'sii-boleta-dte' ) ] );
        }
    }

    /**
     * Genera y envía el RVD del día actual mediante AJAX.
     */
    public function ajax_run_rvd() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $settings = $this->settings->get_settings();
        $rvd_xml  = $this->rvd_manager->generate_rvd_xml();
        if ( ! $rvd_xml ) {
            wp_send_json_error( [ 'message' => __( 'No fue posible generar el RVD.', 'sii-boleta-dte' ) ] );
        }
        $sent     = $this->rvd_manager->send_rvd_to_sii( $rvd_xml, $settings['environment'], $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        $today    = date( 'Y-m-d' );
        if ( $sent ) {
            sii_boleta_write_log( 'RVD enviado manualmente para la fecha ' . $today );
            wp_send_json_success( [ 'message' => __( 'RVD enviado correctamente.', 'sii-boleta-dte' ) ] );
        } else {
            sii_boleta_write_log( 'Error al enviar el RVD manual para la fecha ' . $today );
            wp_send_json_error( [ 'message' => __( 'Error al enviar el RVD.', 'sii-boleta-dte' ) ] );
        }
    }

    /**
     * Genera y envía manualmente el Consumo de Folios.
     */
    public function ajax_run_cdf() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $settings = $this->settings->get_settings();
        $today    = date( 'Y-m-d' );
        $cdf_xml  = $this->consumo_folios->generate_cdf_xml( $today );
        if ( ! $cdf_xml ) {
            wp_send_json_error( [ 'message' => __( 'No fue posible generar el CDF.', 'sii-boleta-dte' ) ] );
        }
        $sent = $this->consumo_folios->send_cdf_to_sii( $cdf_xml, $settings['environment'], $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        if ( $sent ) {
            sii_boleta_write_log( 'CDF enviado manualmente para la fecha ' . $today );
            wp_send_json_success( [ 'message' => __( 'CDF enviado correctamente.', 'sii-boleta-dte' ) ] );
        } else {
            sii_boleta_write_log( 'Error al enviar el CDF manual para la fecha ' . $today );
            wp_send_json_error( [ 'message' => __( 'Error al enviar el CDF.', 'sii-boleta-dte' ) ] );
        }
    }
}
