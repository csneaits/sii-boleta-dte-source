<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase núcleo encargada de inicializar los diferentes componentes del plugin.
 *
 * Esta clase encapsula la lógica común de arranque y se encarga de crear
 * instancias de las clases de configuración, folios, firma, API del SII y RVD,
 * así como la integración con WooCommerce.
 */
class SII_Boleta_Core {

    /**
     * Instancias de clases utilizadas por el plugin. Se guardan como
     * propiedades para permitir que otros componentes accedan a ellas si es
     * necesario mediante métodos getter.
     *
     * @var SII_Boleta_Settings
     * @var SII_Boleta_Folio_Manager
     * @var SII_Boleta_Signer
     * @var SII_Boleta_API
     * @var SII_Boleta_RVD_Manager
     * @var SII_Boleta_Endpoints
     * @var SII_Boleta_Woo
     * @var SII_Boleta_Metrics
     * @var SII_Boleta_Consumo_Folios
     */
    private $settings;
    private $folio_manager;
    private $signer;
    private $api;
    private $rvd_manager;
    private $endpoints;
    private $woo;
    private $metrics;
    private $consumo_folios;
    private $queue;
    private $help;
    private $engine;
    private $libredte_missing = false;

    /**
     * Constructor. Inicializa todas las dependencias y registra las acciones
     * principales necesarias para el plugin.
     */
    public function __construct() {
        // Instanciar componentes
        $this->settings      = new SII_Boleta_Settings();
        $this->folio_manager = new SII_Boleta_Folio_Manager( $this->settings );
        $this->signer        = new SII_Boleta_Signer();
        $this->api           = new SII_Boleta_API();
        $this->rvd_manager   = new SII_Boleta_RVD_Manager( $this->settings );
        $this->endpoints     = new SII_Boleta_Endpoints();
        $this->metrics       = new SII_Boleta_Metrics();
        $this->consumo_folios = new SII_Boleta_Consumo_Folios( $this->settings, $this->folio_manager, $this->api );

        // Instanciar el motor LibreDTE
        try {
            $default_engine = new SII_LibreDTE_Engine( $this->settings );
        } catch ( \RuntimeException $e ) {
            $this->libredte_missing = true;
            $default_engine         = new SII_Null_Engine();
        }
        /**
         * Permite reemplazar el motor DTE por otro (p.ej. LibreDTE) desde un addon.
         *
         * @param SII_DTE_Engine $default_engine Motor por defecto.
         */
        $this->engine = apply_filters( 'sii_boleta_dte_engine', $default_engine );

        $this->queue         = new SII_Boleta_Queue( $this->engine, $this->settings );
        require_once SII_BOLETA_DTE_PATH . 'includes/admin/class-sii-boleta-help.php';
        $this->help = new SII_Boleta_Help();

        if ( class_exists( 'WooCommerce' ) ) {
            $this->woo = new SII_Boleta_Woo( $this );
        }

        // Registrar acciones para páginas del panel de administración
        add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );

        // Recursos necesarios para funcionalidades como la subida de imágenes
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Indicador visual del ambiente en la barra de administración
        add_action( 'admin_bar_menu', [ $this, 'add_environment_indicator' ], 100 );

        // Advertencias visuales en el panel de administración
        add_action( 'admin_notices', [ $this, 'maybe_show_admin_warnings' ] );

        // Acciones AJAX para operaciones como generación de boletas desde el panel
        add_action( 'wp_ajax_sii_boleta_dte_generate_dte', [ $this, 'ajax_generate_dte' ] );
        add_action( 'wp_ajax_sii_boleta_dte_preview_dte', [ $this, 'ajax_preview_dte' ] );
        add_action( 'wp_ajax_sii_boleta_dte_lookup_user_by_rut', [ $this, 'ajax_lookup_user_by_rut' ] );
        add_action( 'wp_ajax_sii_boleta_dte_list_dtes', [ $this, 'ajax_list_dtes' ] );
        add_action( 'wp_ajax_sii_boleta_dte_run_rvd', [ $this, 'ajax_run_rvd' ] );
        add_action( 'wp_ajax_sii_boleta_dte_job_log', [ $this, 'ajax_job_log' ] );
        add_action( 'wp_ajax_sii_boleta_dte_toggle_job', [ $this, 'ajax_toggle_job' ] );
        add_action( 'wp_ajax_sii_boleta_dte_run_cdf', [ $this, 'ajax_run_cdf' ] );
        add_action( 'wp_ajax_sii_boleta_dte_queue_status', [ $this, 'ajax_queue_status' ] );
        add_action( 'wp_ajax_sii_boleta_dte_check_status_now', [ $this, 'ajax_check_status_now' ] );
        add_action( 'wp_ajax_sii_boleta_dte_test_smtp', [ $this, 'ajax_test_smtp' ] );
        add_action( 'wp_ajax_sii_boleta_dte_search_customers', [ $this, 'ajax_search_customers' ] );
        add_action( 'wp_ajax_sii_boleta_dte_search_products', [ $this, 'ajax_search_products' ] );

        // Integración automática con FluentSMTP (perfiles + selección de remitente)
        add_filter( 'sii_boleta_available_smtp_profiles', [ $this, 'fluent_smtp_profiles' ] );
        add_action( 'sii_boleta_setup_mailer', [ $this, 'fluent_smtp_setup_mailer' ], 10, 2 );
    }

    /**
     * Normaliza un RUT a formato XXXXXXXX-DV (sin puntos) y en mayúsculas.
     *
     * @param string $rut
     * @return string RUT normalizado o cadena vacía si no hay dígitos suficientes.
     */
    private function normalize_rut( $rut ) {
        $c = strtoupper( preg_replace( '/[^0-9Kk]/', '', (string) $rut ) );
        if ( strlen( $c ) < 2 ) {
            return '';
        }
        $body = substr( $c, 0, -1 );
        $dv   = substr( $c, -1 );
        return ltrim( $body, '0' ) . '-' . $dv;
    }

    /**
     * Valida el RUT chileno por dígito verificador.
     *
     * @param string $rut
     * @return bool
     */
    private function is_valid_rut( $rut ) {
        $c = strtoupper( preg_replace( '/[^0-9Kk]/', '', (string) $rut ) );
        if ( strlen( $c ) < 2 ) {
            return false;
        }
        $body = substr( $c, 0, -1 );
        $dv   = substr( $c, -1 );
        $sum = 0; $mul = 2;
        for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
            $sum += intval( $body[$i] ) * $mul;
            $mul = ( $mul === 7 ) ? 2 : $mul + 1;
        }
        $rem = 11 - ( $sum % 11 );
        $exp = ( $rem === 11 ) ? '0' : ( $rem === 10 ? 'K' : (string) $rem );
        return $dv === $exp;
    }

    /**
     * Devuelve datos de usuario por RUT si existe (nombre, email, dirección, comuna).
     */
    private function find_user_by_rut_data( $rut ) {
        $rut_norm = $this->normalize_rut( $rut );
        $rut_clean = strtoupper( preg_replace( '/[^0-9Kk]/', '', $rut_norm ) );
        $meta_keys = [ 'billing_rut', '_billing_rut', 'rut', 'user_rut', 'customer_rut' ];
        foreach ( $meta_keys as $mk ) {
            // Búsqueda exacta
            $users = get_users( [
                'meta_key'   => $mk,
                'meta_value' => $rut_clean,
                'number'     => 1,
                'count_total'=> false,
                'fields'     => [ 'ID', 'display_name', 'user_email' ],
            ] );
            if ( ! empty( $users ) ) { $u = $users[0]; break; }
            $users = get_users( [
                'meta_key'   => $mk,
                'meta_value' => $rut_norm,
                'number'     => 1,
                'count_total'=> false,
                'fields'     => [ 'ID', 'display_name', 'user_email' ],
            ] );
            if ( ! empty( $users ) ) { $u = $users[0]; break; }
            // Búsqueda laxa (LIKE) para valores con puntos/guiones variados
            $q = new \WP_User_Query( [
                'number'      => 1,
                'count_total' => false,
                'fields'      => [ 'ID', 'display_name', 'user_email' ],
                'meta_query'  => [
                    'relation' => 'OR',
                    [ 'key' => $mk, 'value' => $rut_clean, 'compare' => 'LIKE' ],
                    [ 'key' => $mk, 'value' => $rut_norm,  'compare' => 'LIKE' ],
                ],
            ] );
            $found = $q->get_results();
            if ( ! empty( $found ) ) { $u = $found[0]; break; }
        }
        if ( ! empty( $u ) ) {
            $name  = $u->display_name ?: ( get_user_meta( $u->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $u->ID, 'billing_last_name', true ) );
            $email = $u->user_email ?: get_user_meta( $u->ID, 'billing_email', true );
            $addr  = get_user_meta( $u->ID, 'billing_address_1', true );
            $cmna  = get_user_meta( $u->ID, 'billing_city', true );
            return [ 'name' => trim( $name ), 'email' => $email, 'address' => $addr, 'comuna' => $cmna ];
        }
        // Fallback: buscar última orden de WooCommerce con ese RUT en metadatos
        if ( class_exists( '\\WC_Order_Query' ) ) {
            $rut_candidates = [ $rut_clean, $rut_norm ];
            $order_meta_keys = [ 'billing_rut', '_billing_rut', 'rut', 'billing_rut_number', 'customer_rut' ];
            foreach ( $order_meta_keys as $okey ) {
                foreach ( $rut_candidates as $rv ) {
                    $q = new \WC_Order_Query( [
                        'limit'      => 1,
                        'orderby'    => 'date',
                        'order'      => 'DESC',
                        'return'     => 'ids',
                        'meta_query' => [ [ 'key' => $okey, 'value' => $rv ] ],
                    ] );
                    $ids = $q->get_orders();
                    if ( ! empty( $ids ) ) {
                        $order = wc_get_order( $ids[0] );
                        if ( $order ) {
                            $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                            $email = $order->get_billing_email();
                            $addr  = $order->get_billing_address_1();
                            $cmna  = $order->get_billing_city();
                            return [ 'name' => $name, 'email' => $email, 'address' => $addr, 'comuna' => $cmna ];
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Detecta perfiles de FluentSMTP y los expone para el selector de perfiles SMTP.
     *
     * @param array $profiles Perfiles actuales.
     * @return array
     */
    public function fluent_smtp_profiles( $profiles ) {
        // Delegar en Settings::get_fluent_smtp_profiles si está disponible
        if ( class_exists( 'SII_Boleta_Settings' ) ) {
            $settings = new SII_Boleta_Settings();
            if ( method_exists( $settings, 'get_fluent_smtp_profiles' ) ) {
                $fluent = $settings->get_fluent_smtp_profiles();
                // Mantener los existentes y agregar los nuevos sin provocar recursión
                return $profiles + $fluent;
            }
        }
        return $profiles;
    }

    /**
     * Configura PHPMailer para que FluentSMTP seleccione la conexión indicada.
     * Establece el From/Return-Path al del perfil. FluentSMTP enruta por remitente.
     *
     * @param PHPMailer $phpmailer
     * @param string    $profile   Clave del perfil seleccionado.
     */
    public function fluent_smtp_setup_mailer( $phpmailer, $profile ) {
        if ( empty( $profile ) ) {
            return;
        }
        $settings = get_option( 'fluent_smtp_settings' );
        $conn     = isset( $settings['connections'][ $profile ] ) ? $settings['connections'][ $profile ] : null;
        if ( ! $conn ) {
            return;
        }
        $from = isset( $conn['sender_email'] ) ? (string) $conn['sender_email'] : '';
        $name = isset( $conn['sender_name'] ) ? (string) $conn['sender_name'] : '';
        if ( $from && method_exists( $phpmailer, 'setFrom' ) ) {
            $phpmailer->setFrom( $from, $name, false );
            $phpmailer->Sender = $from; // Return-Path
        }
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
     * Envía un correo de prueba usando perfil SMTP (FluentSMTP enruta por From).
     */
    public function ajax_test_smtp() {
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $to = isset($_POST['to']) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : get_option('admin_email');
        if ( ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => __( 'Dirección de destino inválida.', 'sii-boleta-dte' ) ] );
        }
        $profile = isset($_POST['profile']) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';

        $settings = new SII_Boleta_Settings();
        $conn = method_exists( $settings, 'get_fluent_smtp_connection' ) ? $settings->get_fluent_smtp_connection( $profile ) : null;
        $from_email = is_array($conn) && ! empty( $conn['sender_email'] ) ? $conn['sender_email'] : get_option('admin_email');
        $from_name  = is_array($conn) && ! empty( $conn['sender_name'] )  ? $conn['sender_name']  : get_bloginfo('name');

        $headers = [ 'From: ' . sprintf( '%s <%s>', $from_name, $from_email ) ];
        $ok = wp_mail( $to, 'Prueba SMTP – SII Boleta DTE', "Este es un correo de prueba enviado desde el perfil seleccionado.\nSitio: " . home_url() . "\nPerfil: " . $profile, $headers );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => __( 'No se pudo enviar el correo de prueba. Revise la configuración del proveedor SMTP.', 'sii-boleta-dte' ) ] );
        }
        wp_send_json_success( [ 'message' => __( 'Correo de prueba enviado. Revise su bandeja.', 'sii-boleta-dte' ) ] );
    }

    /**
     * Busca clientes por RUT (usuarios y últimas órdenes) devolviendo coincidencias.
     */
    public function ajax_search_customers() {
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        if ( strlen( preg_replace( '/[^0-9Kk]/', '', $term ) ) < 3 ) {
            wp_send_json_success( [ 'items' => [] ] );
        }
        $norm     = $this->normalize_rut( $term );
        $compact  = strtoupper( str_replace( '-', '', $norm ) );
        $clean    = strtoupper( preg_replace( '/[^0-9Kk]/', '', $norm ) );
        $meta_keys = [ 'billing_rut', '_billing_rut', 'rut', 'user_rut', 'customer_rut', 'billing_rut_number' ];

        $results = [];
        $seen = [];

        // Buscar usuarios
        foreach ( $meta_keys as $mk ) {
            $q = new \WP_User_Query( [
                'number'      => 10,
                'count_total' => false,
                'fields'      => [ 'ID', 'display_name', 'user_email' ],
                'meta_query'  => [ 'relation' => 'OR',
                    [ 'key' => $mk, 'value' => $clean,   'compare' => 'LIKE' ],
                    [ 'key' => $mk, 'value' => $norm,    'compare' => 'LIKE' ],
                    [ 'key' => $mk, 'value' => $compact, 'compare' => 'LIKE' ],
                ],
            ] );
            foreach ( (array) $q->get_results() as $u ) {
                $rut_meta = '';
                foreach ( $meta_keys as $mk2 ) {
                    $v = get_user_meta( $u->ID, $mk2, true );
                    if ( $v ) { $rut_meta = $v; break; }
                }
                $rut_show = $rut_meta ? $this->normalize_rut( $rut_meta ) : '';
                $key = md5( 'u-' . $u->ID );
                if ( isset( $seen[ $key ] ) ) { continue; }
                $seen[ $key ] = 1;
                $results[] = [
                    'source'  => 'user',
                    'rut'     => $rut_show,
                    'name'    => $u->display_name,
                    'email'   => $u->user_email,
                    'address' => get_user_meta( $u->ID, 'billing_address_1', true ),
                    'comuna'  => get_user_meta( $u->ID, 'billing_city', true ),
                ];
                if ( count( $results ) >= 10 ) { break 2; }
            }
        }

        // Buscar órdenes si faltan resultados
        if ( count( $results ) < 10 && class_exists( '\\WC_Order_Query' ) ) {
            foreach ( $meta_keys as $okey ) {
                foreach ( [ $clean, $norm, $compact ] as $rv ) {
                    $oq = new \WC_Order_Query( [
                        'limit'      => 10,
                        'orderby'    => 'date',
                        'order'      => 'DESC',
                        'return'     => 'ids',
                        'meta_query' => [ [ 'key' => $okey, 'value' => $rv, 'compare' => 'LIKE' ] ],
                    ] );
                    foreach ( (array) $oq->get_orders() as $oid ) {
                        $o = wc_get_order( $oid ); if ( ! $o ) { continue; }
                        $rut_meta=''; foreach ( $meta_keys as $mk3 ) { $mv=$o->get_meta($mk3); if($mv){ $rut_meta=$mv; break; } }
                        $rut_show = $rut_meta ? $this->normalize_rut( $rut_meta ) : '';
                        $key = md5( 'o-' . $oid ); if ( isset( $seen[ $key ] ) ) { continue; }
                        $seen[ $key ] = 1;
                        $results[] = [
                            'source'  => 'order',
                            'rut'     => $rut_show,
                            'name'    => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
                            'email'   => $o->get_billing_email(),
                            'address' => $o->get_billing_address_1(),
                            'comuna'  => $o->get_billing_city(),
                        ];
                        if ( count( $results ) >= 10 ) { break 3; }
                    }
                }
            }
        }

        wp_send_json_success( [ 'items' => $results ] );
    }

    /**
     * Busca productos de WooCommerce por término y devuelve id, nombre, precio, sku.
     */
    public function ajax_search_products() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $q = isset($_POST['q']) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
        if ( ! class_exists( 'WC_Product' ) ) {
            wp_send_json_error( [ 'message' => __( 'WooCommerce no está activo.', 'sii-boleta-dte' ) ] );
        }
        $args = [
            'post_type'      => [ 'product', 'product_variation' ],
            's'              => $q,
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ];
        $ids = get_posts( $args );
        $out = [];
        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) { continue; }
            $out[] = [
                'id'    => $product->get_id(),
                'name'  => html_entity_decode( wp_strip_all_tags( $product->get_formatted_name() ), ENT_QUOTES, 'UTF-8' ),
                'price' => (float) $product->get_price(),
                'sku'   => (string) $product->get_sku(),
            ];
        }
        wp_send_json_success( [ 'items' => $out ] );
    }

    /**
     * Busca un usuario por RUT en metadatos comunes y retorna nombre/email.
     */
    public function ajax_lookup_user_by_rut() {
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $rut_raw = isset( $_POST['rut'] ) ? sanitize_text_field( wp_unslash( $_POST['rut'] ) ) : '';
        if ( ! $rut_raw ) { wp_send_json_error( [ 'message' => __( 'RUT vacío.', 'sii-boleta-dte' ) ] ); }
        $rut_norm  = $this->normalize_rut( $rut_raw );
        $rut_clean = strtoupper( preg_replace( '/[^0-9Kk]/', '', $rut_norm ) );
        $rut_compact = strtoupper( str_replace( '-', '', $rut_norm ) );

        // Claves meta frecuentes para RUT
        $meta_keys = [ 'billing_rut', '_billing_rut', 'rut', 'user_rut', 'customer_rut', 'billing_rut_number' ];
        $user = null;
        foreach ( $meta_keys as $mk ) {
            // Exactos
            $users = get_users( [ 'meta_key'=>$mk, 'meta_value'=>$rut_clean, 'number'=>1, 'count_total'=>false, 'fields'=>['ID','display_name','user_email'] ] );
            if ( ! empty( $users ) ) { $user = $users[0]; break; }
            $users = get_users( [ 'meta_key'=>$mk, 'meta_value'=>$rut_norm,  'number'=>1, 'count_total'=>false, 'fields'=>['ID','display_name','user_email'] ] );
            if ( ! empty( $users ) ) { $user = $users[0]; break; }
            $users = get_users( [ 'meta_key'=>$mk, 'meta_value'=>$rut_compact, 'number'=>1, 'count_total'=>false, 'fields'=>['ID','display_name','user_email'] ] );
            if ( ! empty( $users ) ) { $user = $users[0]; break; }
            // LIKE
            $q = new \WP_User_Query( [
                'number'      => 1,
                'count_total' => false,
                'fields'      => [ 'ID', 'display_name', 'user_email' ],
                'meta_query'  => [ 'relation' => 'OR',
                    [ 'key'=>$mk, 'value'=>$rut_clean,   'compare'=>'LIKE' ],
                    [ 'key'=>$mk, 'value'=>$rut_norm,    'compare'=>'LIKE' ],
                    [ 'key'=>$mk, 'value'=>$rut_compact, 'compare'=>'LIKE' ],
                ],
            ] );
            $found = $q->get_results();
            if ( ! empty( $found ) ) { $user = $found[0]; break; }
        }

        if ( ! $user && class_exists( '\\WC_Order_Query' ) ) {
            // Fallback a última orden con ese RUT en metadatos
            $order_keys = [ 'billing_rut', '_billing_rut', 'rut', 'billing_rut_number', 'customer_rut' ];
            foreach ( $order_keys as $okey ) {
                foreach ( [ $rut_clean, $rut_norm, $rut_compact ] as $rv ) {
                    $q = new \WC_Order_Query( [
                        'limit'      => 1,
                        'orderby'    => 'date',
                        'order'      => 'DESC',
                        'return'     => 'ids',
                        'meta_query' => [ [ 'key' => $okey, 'value' => $rv, 'compare'=>'LIKE' ] ],
                    ] );
                    $ids = $q->get_orders();
                    if ( ! empty( $ids ) ) {
                        $order = wc_get_order( $ids[0] );
                        if ( $order ) {
                            $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                            $email = $order->get_billing_email();
                            $addr  = $order->get_billing_address_1();
                            $cmna  = $order->get_billing_city();
                            wp_send_json_success( [ 'name'=>$name, 'email'=>$email, 'address'=>$addr, 'comuna'=>$cmna ] );
                        }
                    }
                }
            }
        }

        if ( ! $user ) {
            wp_send_json_error( [ 'message' => __( 'Usuario no encontrado para el RUT dado.', 'sii-boleta-dte' ) ] );
        }

        $name  = $user->display_name ?: ( get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $user->ID, 'billing_last_name', true ) );
        $email = $user->user_email ?: get_user_meta( $user->ID, 'billing_email', true );
        $addr  = get_user_meta( $user->ID, 'billing_address_1', true );
        $cmna  = get_user_meta( $user->ID, 'billing_city', true );
        wp_send_json_success( [ 'name' => trim( $name ), 'email' => $email, 'address' => $addr, 'comuna' => $cmna ] );
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
     * Devuelve la instancia de la cola asíncrona.
     *
     * @return SII_Boleta_Queue
     */
    public function get_queue() {
        return $this->queue;
    }

    /**
     * Devuelve el motor DTE activo (nativo o inyectado por addon).
     *
     * @return SII_DTE_Engine
     */
    public function get_engine() {
        return $this->engine;
    }

    /**
     * AJAX: Forzar verificación de estados ahora.
     */
    public function ajax_check_status_now() {
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        try {
            $cron = new SII_Boleta_Cron( $this->settings );
            $cron->check_pending_statuses();
            wp_send_json_success( [ 'message' => __( 'Verificación ejecutada.', 'sii-boleta-dte' ) ] );
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
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
     * Muestra advertencias visuales en la administración sobre el ambiente,
     * folios disponibles y errores recientes en los envíos.
     */
    public function maybe_show_admin_warnings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( $this->libredte_missing ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'LibreDTE no está disponible. Instala la librería requerida para generar DTE.', 'sii-boleta-dte' ) . '</p></div>';
            return;
        }

        $settings = $this->settings->get_settings();
        $env      = isset( $settings['environment'] ) ? $settings['environment'] : 'test';
        if ( 'test' === $env ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'SII Boleta DTE está en modo de pruebas.', 'sii-boleta-dte' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'SII Boleta DTE está en modo de producción.', 'sii-boleta-dte' ) . '</p></div>';
        }

        $enabled = $settings['enabled_dte_types'] ?? [];
        foreach ( $enabled as $type ) {
            $info = $this->folio_manager->get_caf_info( intval( $type ) );
            if ( ! $info ) {
                continue;
            }
            $option_key = SII_Boleta_Folio_Manager::OPTION_LAST_FOLIO_PREFIX . intval( $type );
            $last       = intval( get_option( $option_key, $info['D'] - 1 ) );
            $remaining  = intval( $info['H'] ) - $last;
            if ( $remaining <= 0 ) {
                echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'No quedan folios disponibles para el tipo %s.', 'sii-boleta-dte' ), esc_html( $type ) ) . '</p></div>';
            } elseif ( $remaining <= 10 ) {
                echo '<div class="notice notice-warning"><p>' . sprintf( esc_html__( 'Quedan %1$d folios para el tipo %2$s.', 'sii-boleta-dte' ), $remaining, esc_html( $type ) ) . '</p></div>';
            }
        }

        $upload_dir = wp_upload_dir();
        $log_file   = trailingslashit( $upload_dir['basedir'] ) . 'sii-boleta-logs/sii-boleta-' . date( 'Y-m-d' ) . '.log';
        if ( file_exists( $log_file ) ) {
            $lines  = file( $log_file );
            $errors = 0;
            if ( $lines ) {
                foreach ( $lines as $line ) {
                    if ( false !== strpos( $line, 'ERROR' ) ) {
                        $errors++;
                    }
                }
            }
            if ( $errors >= 5 ) {
                echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Se han detectado %d errores recientes en los envíos.', 'sii-boleta-dte' ), $errors ) . '</p></div>';
            }
        }
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
            __( 'Actividad del Job', 'sii-boleta-dte' ),
            __( 'Actividad del Job', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte-job-log',
            [ $this, 'render_job_log_page' ]
        );

        // Página de ayuda del plugin.
        $this->help->register_page();
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

        if ( 'sii-boleta-dte_page_sii-boleta-dte-panel' === $hook ) {
            wp_enqueue_style(
                'sii-boleta-control-panel',
                SII_BOLETA_DTE_URL . 'assets/css/control-panel.css',
                [],
                SII_BOLETA_DTE_VERSION
            );
        }
    }

    /**
     * Renderiza un panel de control con pestañas para distintos contenidos.
     */
    public function render_control_panel_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'boletas';
        ?>
        <div class='wrap sii-dte-panel'>
            <h1><?php esc_html_e( 'Panel de Control', 'sii-boleta-dte' ); ?></h1>
            <h2 class='nav-tab-wrapper'>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=boletas' ) ); ?>' class='nav-tab <?php echo ( 'boletas' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Boletas', 'sii-boleta-dte' ); ?></a>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=folios' ) ); ?>' class='nav-tab <?php echo ( 'folios' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Folios', 'sii-boleta-dte' ); ?></a>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=jobs' ) ); ?>' class='nav-tab <?php echo ( 'jobs' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Jobs', 'sii-boleta-dte' ); ?></a>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=metrics' ) ); ?>' class='nav-tab <?php echo ( 'metrics' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Métricas', 'sii-boleta-dte' ); ?></a>
                <a href='<?php echo esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel&tab=logs' ) ); ?>' class='nav-tab <?php echo ( 'logs' === $active_tab ) ? 'nav-tab-active' : ''; ?>'><?php esc_html_e( 'Log de Envíos', 'sii-boleta-dte' ); ?></a>
            </h2>
            <div class='sii-dte-card'>
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
                    <?php esc_html_e( 'Cola de envíos pendientes:', 'sii-boleta-dte' ); ?>
                    <span id="sii-queue-count"></span>
                </p>
                <p>
                    <button type="button" class="button" id="sii-toggle-job"><?php esc_html_e( 'Programar Job', 'sii-boleta-dte' ); ?></button>
                    <button type="button" class="button" id="sii-run-rvd"><?php esc_html_e( 'Generar RVD del día', 'sii-boleta-dte' ); ?></button>
                    <button type="button" class="button" id="sii-run-cdf"><?php esc_html_e( 'Generar CDF del día', 'sii-boleta-dte' ); ?></button>
                </p>
                <div id="sii-rvd-result"></div>
                <div id="sii-cdf-result"></div>
                <pre id="sii-job-log" class="sii-job-log"></pre>
            <?php elseif ( 'folios' === $active_tab ) : ?>
                <?php
                $settings  = $this->settings->get_settings();
                $caf_paths = $settings['caf_path'] ?? [];
                ?>
                <h2><?php esc_html_e( 'CAF Cargados', 'sii-boleta-dte' ); ?></h2>
                <?php if ( ! empty( $caf_paths ) ) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Tipo DTE', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Desde', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Hasta', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Último Usado', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Disponibles', 'sii-boleta-dte' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $caf_paths as $tipo => $path ) : ?>
                                <?php
                                $info = $this->folio_manager->get_caf_info( intval( $tipo ) );
                                if ( ! $info ) {
                                    continue;
                                }
                                $option_key = SII_Boleta_Folio_Manager::OPTION_LAST_FOLIO_PREFIX . intval( $tipo );
                                $last_folio = intval( get_option( $option_key, $info['D'] - 1 ) );
                                if ( $last_folio < $info['D'] - 1 ) {
                                    $last_folio = $info['D'] - 1;
                                }
                                $remaining = $info['H'] - $last_folio;
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $tipo ); ?></td>
                                    <td><?php echo esc_html( $info['D'] ); ?></td>
                                    <td><?php echo esc_html( $info['H'] ); ?></td>
                                    <td><?php echo esc_html( $last_folio ); ?></td>
                                    <td><?php echo esc_html( $remaining ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e( 'No se encontraron CAF configurados.', 'sii-boleta-dte' ); ?></p>
                <?php endif; ?>
            <?php elseif ( 'metrics' === $active_tab ) : ?>
                <?php $metrics = $this->metrics->gather_metrics(); ?>
                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <canvas id="sii-chart-by-type" height="120"></canvas>
                <canvas id="sii-chart-errors" height="120"></canvas>
            <?php elseif ( 'logs' === $active_tab ) : ?>
                <?php
                $filter_track   = isset( $_GET['track'] ) ? sanitize_text_field( wp_unslash( $_GET['track'] ) ) : '';
                $filter_status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
                $filter_from    = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
                $filter_to      = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
                $per_page       = 20;
                $paged          = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
                $offset         = ( $paged - 1 ) * $per_page;
                $total          = class_exists( 'SII_Boleta_Log_DB' ) ? SII_Boleta_Log_DB::count_entries_filtered( $filter_track, $filter_status, $filter_from, $filter_to ) : 0;
                $entries        = class_exists( 'SII_Boleta_Log_DB' ) ? SII_Boleta_Log_DB::get_entries_filtered( $filter_track, $filter_status, $filter_from, $filter_to, $per_page, $offset ) : [];
                ?>
                <h2><?php esc_html_e( 'Log de Envíos al SII', 'sii-boleta-dte' ); ?></h2>
                <form method="get" style="margin-bottom:10px;">
                    <input type="hidden" name="page" value="sii-boleta-dte-panel" />
                    <input type="hidden" name="tab" value="logs" />
                    <input type="text" name="track" placeholder="Track ID" value="<?php echo esc_attr( $filter_track ); ?>" />
                    <input type="text" name="status" placeholder="Estado" value="<?php echo esc_attr( $filter_status ); ?>" />
                    <input type="date" name="date_from" value="<?php echo esc_attr( $filter_from ); ?>" />
                    <input type="date" name="date_to" value="<?php echo esc_attr( $filter_to ); ?>" />
                    <button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'sii-boleta-dte' ); ?></button>
                    <button type="button" id="sii-check-status-now" class="button button-primary" style="float:right;"><?php esc_html_e( 'Revisar estados ahora', 'sii-boleta-dte' ); ?></button>
                    <span id="sii-check-status-result" style="float:right;margin-right:10px;"></span>
                </form>
                <?php if ( empty( $entries ) ) : ?>
                    <p><?php esc_html_e( 'No hay envíos registrados aún.', 'sii-boleta-dte' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Fecha', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Track ID', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Estado', 'sii-boleta-dte' ); ?></th>
                                <th><?php esc_html_e( 'Detalle', 'sii-boleta-dte' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $entries as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['created_at'] ); ?></td>
                                    <td><?php echo esc_html( $row['track_id'] ); ?></td>
                                    <td><?php echo esc_html( $row['status'] ); ?></td>
                                    <td>
                                        <?php
                                        $resp = trim( (string) ( $row['response'] ?? '' ) );
                                        $short = mb_strimwidth( $resp, 0, 120, '…', 'UTF-8' );
                                        echo esc_html( $short );
                                        ?>
                                        <?php if ( $resp !== '' ) : ?>
                                            <button type="button" class="button-link sii-log-view" data-response="<?php echo esc_attr( $resp ); ?>"><?php esc_html_e( 'Ver', 'sii-boleta-dte' ); ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    $total_pages = max( 1, ceil( $total / $per_page ) );
                    if ( $total_pages > 1 ) :
                        echo '<div class="tablenav"><div class="tablenav-pages">';
                        $base_url = remove_query_arg( [ 'paged' ] );
                        for ( $p = 1; $p <= $total_pages; $p++ ) {
                            $url = esc_url( add_query_arg( 'paged', $p, $base_url ) );
                            $class = ( $p === $paged ) ? ' class="page-numbers current"' : ' class="page-numbers"';
                            echo '<a' . $class . ' href="' . $url . '">' . $p . '</a> ';
                        }
                        echo '</div></div>';
                    endif;
                    ?>
                    <div id="sii-log-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;">
                        <div style="background:#fff;max-width:800px;margin:60px auto;padding:15px;border-radius:4px;position:relative;">
                            <button type="button" id="sii-log-close" class="button" style="position:absolute;top:10px;right:10px;">&times;</button>
                            <h3><?php esc_html_e( 'Detalle de respuesta', 'sii-boleta-dte' ); ?></h3>
                            <pre id="sii-log-content" style="white-space:pre-wrap;max-height:60vh;overflow:auto;"></pre>
                        </div>
                    </div>
                <?php endif; ?>
                <script>
                jQuery(function($){
                    $('#sii-check-status-now').on('click', function(){
                        var $btn = $(this);
                        $btn.prop('disabled', true);
                        $('#sii-check-status-result').text('<?php echo esc_js( __( 'Consultando...', 'sii-boleta-dte' ) ); ?>');
                        $.post(ajaxurl, { action: 'sii_boleta_dte_check_status_now', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>' }, function(resp){
                            $btn.prop('disabled', false);
                            if (resp && resp.success) {
                                $('#sii-check-status-result').text(resp.data.message || 'OK');
                            } else {
                                $('#sii-check-status-result').text('Error');
                            }
                        });
                    });
                    $(document).on('click', '.sii-log-view', function(){
                        var resp = $(this).data('response') || '';
                        $('#sii-log-content').text(resp);
                        $('#sii-log-modal').show();
                    });
                    $('#sii-log-close').on('click', function(){
                        $('#sii-log-modal').hide();
                        $('#sii-log-content').text('');
                    });
                });
                </script>
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
                            <th><?php esc_html_e( 'Estado', 'sii-boleta-dte' ); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>
        <script type="text/javascript">
        jQuery(function($){
            var activeTab = '<?php echo esc_js( $active_tab ); ?>';
            if (activeTab === 'boletas') {
                function loadDtes(){
                    $('#sii-dte-table tbody').html('<tr><td colspan="6"><?php echo esc_js( __( 'Cargando...', 'sii-boleta-dte' ) ); ?></td></tr>');
                    $.post(ajaxurl, {action:'sii_boleta_dte_list_dtes', _wpnonce:'<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>'}, function(resp){
                        if(resp.success){
                            var rows='';
                            $.each(resp.data.dtes, function(i,d){
                                var pdf = d.pdf ? '<a href="'+d.pdf+'" target="_blank"><?php echo esc_js( __( 'Ver', 'sii-boleta-dte' ) ); ?></a>' : '-';
                                var status = d.status === 'pending' ? '<?php echo esc_js( __( 'Pendiente', 'sii-boleta-dte' ) ); ?>' : '<?php echo esc_js( __( 'Enviado', 'sii-boleta-dte' ) ); ?>';
                                rows += '<tr><td>'+d.tipo+'</td><td>'+d.folio+'</td><td>'+d.fecha+'</td><td><a href="'+d.xml+'" target="_blank">XML</a></td><td>'+pdf+'</td><td>'+status+'</td></tr>';
                            });
                            if(!rows){ rows = '<tr><td colspan="6"><?php echo esc_js( __( 'No hay DTE disponibles.', 'sii-boleta-dte' ) ); ?></td></tr>'; }
                            $('#sii-dte-table tbody').html(rows);
                        } else {
                            $('#sii-dte-table tbody').html('<tr><td colspan="6">'+resp.data.message+'</td></tr>');
                        }
                    });
                }
                $('#sii-refresh-dtes').on('click', loadDtes);
                loadDtes();
            } else if (activeTab === 'jobs') {
                function loadLog(){
                    $.post(ajaxurl, {action:'sii_boleta_dte_job_log', _wpnonce:'<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>'}, function(resp){
                        if(resp.success){
                            $('#sii-job-log').text(resp.data.log);
                            $('#sii-job-next').text(resp.data.next_run);
                            var active = resp.data.status === 'active';
                            $('#sii-job-status').text(active ? '<?php echo esc_js( __( 'Activo', 'sii-boleta-dte' ) ); ?>' : '<?php echo esc_js( __( 'Inactivo', 'sii-boleta-dte' ) ); ?>');
                            $('#sii-toggle-job').text(active ? '<?php echo esc_js( __( 'Desprogramar Job', 'sii-boleta-dte' ) ); ?>' : '<?php echo esc_js( __( 'Programar Job', 'sii-boleta-dte' ) ); ?>');
                        }
                    });
                }
                function loadQueue(){
                    $.post(ajaxurl, {action:'sii_boleta_dte_queue_status', _wpnonce:'<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>'}, function(resp){
                        if(resp.success){
                            $('#sii-queue-count').text(resp.data.count);
                        }
                    });
                }
                $('#sii-toggle-job').on('click', function(){
                    $('#sii-rvd-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                    $.post(ajaxurl, {action:'sii_boleta_dte_toggle_job', _wpnonce:'<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>'}, function(resp){
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
                    $.post(ajaxurl, {action:'sii_boleta_dte_run_rvd', _wpnonce:'<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>'}, function(resp){
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
                    $.post(ajaxurl, {action:'sii_boleta_dte_run_cdf', _wpnonce:'<?php echo esc_js( wp_create_nonce( 'sii_boleta_nonce' ) ); ?>'}, function(resp){
                        if(resp.success){
                            $('#sii-cdf-result').html('<div class="notice notice-success"><p>'+resp.data.message+'</p></div>');
                        }else{
                            $('#sii-cdf-result').html('<div class="notice notice-error"><p>'+resp.data.message+'</p></div>');
                        }
                    });
                });
                loadLog();
                loadQueue();
            } else if (activeTab === 'metrics') {
                var byType = <?php echo ( 'metrics' === $active_tab ) ? wp_json_encode( $metrics['by_type'] ) : '[]'; ?>;
                var ctx1 = document.getElementById('sii-chart-by-type').getContext('2d');
                new Chart(ctx1, {type:'bar', data:{labels:Object.keys(byType), datasets:[{label:'DTE', data:Object.values(byType)}]}});
                var errors = <?php echo ( 'metrics' === $active_tab ) ? wp_json_encode( $metrics['error_reasons'] ) : '[]'; ?>;
                if(Object.keys(errors).length){
                    var ctx2 = document.getElementById('sii-chart-errors').getContext('2d');
                    new Chart(ctx2, {type:'pie', data:{labels:Object.keys(errors), datasets:[{data:Object.values(errors)}]}});
                }
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
                </table>

                <h2><?php esc_html_e( 'Datos del Receptor', 'sii-boleta-dte' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="receptor_rut"><?php esc_html_e( 'RUT Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td>
                            <input type="text" name="receptor_rut" id="receptor_rut" class="regular-text" required>
                            <button type="button" class="button" id="sii-rut-completar"><?php esc_html_e( 'Completar', 'sii-boleta-dte' ); ?></button>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="receptor_nombre"><?php esc_html_e( 'Nombre Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="receptor_nombre" id="receptor_nombre" class="regular-text" required></td>
                    </tr>
                    <tr class="recipient-details-fields" style="display:none;">
                        <th scope="row"><label for="direccion_recep"><?php esc_html_e( 'Dirección Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="direccion_recep" id="direccion_recep" class="regular-text"></td>
                    </tr>
                    <tr class="recipient-details-fields" style="display:none;">
                        <th scope="row"><label for="comuna_recep"><?php esc_html_e( 'Comuna Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="text" name="comuna_recep" id="comuna_recep" class="regular-text"></td>
                    </tr>
                    <tr class="recipient-details-fields" style="display:none;">
                        <th scope="row"><label for="correo_recep"><?php esc_html_e( 'Correo Receptor', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="email" name="correo_recep" id="correo_recep" class="regular-text"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Ítems', 'sii-boleta-dte' ); ?></h2>
                <table class="widefat striped" id="tabla-items">
                    <thead>
                        <tr>
                            <th style="width:55%"><?php esc_html_e( 'Producto (tienda)', 'sii-boleta-dte' ); ?></th>
                            <th style="width:15%"><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></th>
                            <th style="width:20%"><?php esc_html_e( 'Precio Unitario', 'sii-boleta-dte' ); ?></th>
                            <th style="width:10%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <input type="hidden" name="product_id[]" class="sii-product-id" value="">
                                <input type="text" class="regular-text sii-prod-input" placeholder="<?php esc_attr_e('Buscar producto…','sii-boleta-dte'); ?>">
                                <div class="sii-suggest" style="position:relative;"></div>
                                <input type="hidden" name="descripcion[]" class="sii-desc" value="">
                            </td>
                            <td><input type="number" name="cantidad[]" class="small-text" step="1" min="1" value="1" required></td>
                            <td><input type="number" name="precio_unitario[]" class="small-text" step="0.01" min="0" value="0" required></td>
                            <td><button type="button" class="button remove-item" aria-label="Eliminar">&times;</button></td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button" id="agregar-item"><?php esc_html_e( 'Agregar ítem', 'sii-boleta-dte' ); ?></button>
                </p>

                <div id="medio_pago_fields" style="display:none;">
                    <h2><?php esc_html_e( 'Condiciones de Venta', 'sii-boleta-dte' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="medio_pago"><?php esc_html_e( 'Medio de Pago', 'sii-boleta-dte' ); ?></label></th>
                            <td>
                                <select name="medio_pago" id="medio_pago">
                                    <option value=""><?php esc_html_e( 'Seleccione…', 'sii-boleta-dte' ); ?></option>
                                    <option value="1"><?php esc_html_e( 'Contado', 'sii-boleta-dte' ); ?></option>
                                    <option value="2"><?php esc_html_e( 'Crédito', 'sii-boleta-dte' ); ?></option>
                                    <option value="3"><?php esc_html_e( 'Gratuito', 'sii-boleta-dte' ); ?></option>
                                    <option value="4"><?php esc_html_e( 'Cheque', 'sii-boleta-dte' ); ?></option>
                                    <option value="5"><?php esc_html_e( 'Transferencia', 'sii-boleta-dte' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="referencia_fields" style="display:none;">
                    <h2><?php esc_html_e( 'Referencia', 'sii-boleta-dte' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="folio_ref"><?php esc_html_e( 'Folio Documento Referencia', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="folio_ref" id="folio_ref" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tipo_doc_ref"><?php esc_html_e( 'Tipo Doc Referencia', 'sii-boleta-dte' ); ?></label></th>
                            <td>
                                <select name="tipo_doc_ref" id="tipo_doc_ref">
                                    <option value="39">Boleta (39)</option>
                                    <option value="33">Factura (33)</option>
                                    <option value="34">Factura Exenta (34)</option>
                                    <option value="52">Guía de Despacho (52)</option>
                                    <option value="61">Nota de Crédito (61)</option>
                                    <option value="56">Nota de Débito (56)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="razon_ref"><?php esc_html_e( 'Razón Referencia', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="razon_ref" id="razon_ref" class="regular-text" /></td>
                        </tr>
                    </table>
                </div>

                <div id="guia_fields" style="display:none;">
                    <h2><?php esc_html_e( 'Datos de Transporte (Guía 52)', 'sii-boleta-dte' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="tpo_traslado"><?php esc_html_e( 'Tipo de Traslado', 'sii-boleta-dte' ); ?></label></th>
                            <td>
                                <select name="tpo_traslado" id="tpo_traslado">
                                    <option value=""><?php esc_html_e( 'Seleccione…', 'sii-boleta-dte' ); ?></option>
                                    <option value="1"><?php esc_html_e( 'Venta', 'sii-boleta-dte' ); ?></option>
                                    <option value="2"><?php esc_html_e( 'Consignación', 'sii-boleta-dte' ); ?></option>
                                    <option value="3"><?php esc_html_e( 'Entrega Gratuita', 'sii-boleta-dte' ); ?></option>
                                    <option value="5"><?php esc_html_e( 'Traslado Interno', 'sii-boleta-dte' ); ?></option>
                                    <option value="6"><?php esc_html_e( 'Devolución', 'sii-boleta-dte' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="patente"><?php esc_html_e( 'Patente Vehículo', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="patente" id="patente" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rut_trans"><?php esc_html_e( 'RUT Transportista', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="rut_trans" id="rut_trans" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="rut_chofer"><?php esc_html_e( 'RUT Chofer', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="rut_chofer" id="rut_chofer" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nombre_chofer"><?php esc_html_e( 'Nombre Chofer', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="nombre_chofer" id="nombre_chofer" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dir_dest"><?php esc_html_e( 'Dirección Destino', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="dir_dest" id="dir_dest" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cmna_dest"><?php esc_html_e( 'Comuna Destino', 'sii-boleta-dte' ); ?></label></th>
                            <td><input type="text" name="cmna_dest" id="cmna_dest" class="regular-text"></td>
                        </tr>
                        
                    </table>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="enviar_sii"><?php esc_html_e( '¿Enviar al SII?', 'sii-boleta-dte' ); ?></label></th>
                        <td><input type="checkbox" name="enviar_sii" id="enviar_sii" value="1"></td>
                    </tr>
                </table>

                <?php wp_nonce_field( 'sii_boleta_preview_dte', 'sii_boleta_preview_dte_nonce' ); ?>
                <p class="submit">
                    <button type="button" class="button" id="sii-preview-dte"><?php esc_html_e( 'Previsualizar', 'sii-boleta-dte' ); ?></button>
                    <button type="submit" class="button button-primary" id="sii-generate-dte">
                        <?php esc_html_e( 'Generar DTE', 'sii-boleta-dte' ); ?>
                    </button>
                </p>
                <div id="sii-boleta-result"></div>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            function updateFormByType(){
                var type = $('#dte_type').val();
                // Mostrar/ocultar referencias (notas de crédito/débito)
                if (type === '56' || type === '61') {
                    $('#referencia_fields').show();
                    $('#folio_ref, #tipo_doc_ref, #razon_ref').prop('required', true);
                } else {
                    $('#referencia_fields').hide();
                    $('#folio_ref, #tipo_doc_ref, #razon_ref').prop('required', false);
                }
                // Campos de receptor: para 33/34/52 obligatorios dirección/comuna.
                // Para boleta (39/41) los mostramos como opcionales (útil para email/teléfono).
                if (type === '33' || type === '34' || type === '52') {
                    $('.recipient-details-fields').show();
                    $('#direccion_recep, #comuna_recep').prop('required', true);
                } else {
                    $('.recipient-details-fields').show();
                    $('#direccion_recep, #comuna_recep').prop('required', false);
                }
                // Medio de pago solo para facturas (33/34)
                if (type === '33' || type === '34') {
                    $('#medio_pago_fields').show();
                } else {
                    $('#medio_pago_fields').hide();
                    $('#medio_pago').val('');
                }
                // Datos de Guía (52)
                if (type === '52') {
                    $('#guia_fields').show();
                    $('#tpo_traslado, #dir_dest, #cmna_dest').prop('required', true);
                } else {
                    $('#guia_fields').hide();
                    $('#tpo_traslado, #dir_dest, #cmna_dest').prop('required', false);
                    $('#tpo_traslado').val('');
                    $('#patente, #rut_trans, #rut_chofer, #nombre_chofer, #dir_dest, #cmna_dest').val('');
                }
            }
            updateFormByType();
            $('#dte_type').on('change', updateFormByType);

            // Manejo dinámico de ítems
            $('#agregar-item').on('click', function(){
                var row = '<tr>'+
                    '<td>'+
                    '<input type="hidden" name="product_id[]" class="sii-product-id" value="">'+
                    '<input type="text" class="regular-text sii-prod-input" placeholder="<?php echo esc_js(__('Buscar producto…','sii-boleta-dte')); ?>">'+
                    '<div class="sii-suggest" style="position:relative;"></div>'+
                    '<input type="hidden" name="descripcion[]" class="sii-desc" value="">'+
                    '</td>'+
                    '<td><input type="number" name="cantidad[]" class="small-text" step="1" min="1" value="1" required></td>'+
                    '<td><input type="number" name="precio_unitario[]" class="small-text" step="0.01" min="0" value="0" required></td>'+
                    '<td><button type="button" class="button remove-item" aria-label="Eliminar">&times;</button></td>'+
                '</tr>';
                $('#tabla-items tbody').append(row);
                attachSearch($('#tabla-items tbody tr:last'));
            });
            $(document).on('click', '.remove-item', function(){
                var $rows = $('#tabla-items tbody tr');
                if ($rows.length > 1) {
                    $(this).closest('tr').remove();
                }
            });

            function attachSearch($row){
                var $input = $row.find('.sii-prod-input');
                var $list  = $row.find('.sii-suggest');
                var $pid   = $row.find('.sii-product-id');
                var $desc  = $row.find('.sii-desc');
                var $price = $row.find('input[name="precio_unitario[]"]');
                var timer; var cache=[];
                function render(items){
                    var html='<ul class="sii-suggest-list" style="position:absolute;z-index:10;background:#fff;border:1px solid #ccd0d4;margin:0;padding:0;max-height:180px;overflow:auto;width:100%">';
                    items.forEach(function(it){
                        var label = (it.sku?('['+it.sku+'] '):'') + it.name + ' — $' + (it.price||0);
                        html+='<li data-id="'+it.id+'" data-name="'+$('<div>').text(it.name).html()+'" data-price="'+it.price+'" style="list-style:none;padding:4px 8px;cursor:pointer;">'+label+'</li>';
                    });
                    html+='</ul>'; $list.html(html);
                }
                $list.on('click','li',function(){
                    var id=$(this).data('id'); var name=$(this).data('name'); var price=$(this).data('price');
                    $pid.val(id); $desc.val(name); $input.val(name); $price.val(price);
                    $list.empty();
                });
                $input.on('input focus', function(){
                    clearTimeout(timer); var q=$.trim($input.val()); if(!q){ $list.empty(); return; }
                    timer=setTimeout(function(){
                        $.post(ajaxurl,{action:'sii_boleta_dte_search_products', q:q},function(resp){
                            if(resp && resp.success){ cache=resp.data.items||[]; render(cache); }
                        });
                    }, 250);
                });
                // Si se edita manualmente borrar selección
                $input.on('change', function(){ if($input.val()!==$desc.val()){ $pid.val(''); } });
            }
            attachSearch($('#tabla-items tbody tr:first'));

            // === Validador de RUT (admin Generar DTE) ===
            function rutClean(v){ return (v||'').replace(/[^0-9kK]/g,'').toUpperCase(); }
            function rutFormat(v){
                var c = rutClean(v); if(c.length<2) return c;
                var body=c.slice(0,-1), dv=c.slice(-1), out='';
                while(body.length>3){ out='.'+body.slice(-3)+out; body=body.slice(0,-3); }
                return body+out+'-'+dv;
            }
            function rutDV(body){ var s=0,m=2; for(var i=body.length-1;i>=0;i--){ s+=parseInt(body.charAt(i),10)*m; m=(m===7)?2:m+1; } var r=11-(s%11); return r===11?'0':(r===10?'K':String(r)); }
            function rutValid(v){ var c=rutClean(v); if(c.length<2) return false; var body=c.slice(0,-1), dv=c.slice(-1); return rutDV(body)===dv; }
            function attachRutValidation(sel){
                var $i=$(sel); if(!$i.length) return;
                function check(){ var raw=$i.val(); var c=rutClean(raw); if(!c){ $i.val(''); $i.get(0).setCustomValidity(''); return true; }
                    $i.val(rutFormat(c)); if(!rutValid(c)){ $i.get(0).setCustomValidity('RUT inválido'); $i.get(0).reportValidity(); return false; }
                    $i.get(0).setCustomValidity(''); return true; }
                $i.on('input', check);
                return check;
            }
            var checkRutRecep = attachRutValidation('#receptor_rut');
            var checkRutTrans = attachRutValidation('#rut_trans');
            var checkRutChofer= attachRutValidation('#rut_chofer');

            // Auto-completar datos del usuario por RUT (nombre/email/dirección)
            var lookupTimer=null;
            // Sugerencias de clientes por RUT
            var $rut = $('#receptor_rut');
            var $rutSuggest = $('<div id="sii-rut-suggest" style="position:relative;"></div>').insertAfter($rut);
            function renderRutList(items){
                if(!items || !items.length){ $rutSuggest.empty(); return; }
                var html='<ul style="position:absolute;z-index:10;background:#fff;border:1px solid #ccd0d4;margin:4px 0 0;padding:0;max-height:220px;overflow:auto;width:360px">';
                items.forEach(function(it){
                    var label = (it.rut?it.rut+' — ':'') + (it.name||'') + (it.email?(' — '+it.email):'');
                    html+='<li class="sii-rut-item" data-rut="'+(it.rut||'')+'" data-name="'+$('<div>').text(it.name||'').html()+'" data-email="'+(it.email||'')+'" data-addr="'+$('<div>').text(it.address||'').html()+'" data-cmna="'+$('<div>').text(it.comuna||'').html()+'" style="list-style:none;padding:6px 8px;cursor:pointer;">'+label+'</li>';
                });
                html+='</ul>'; $rutSuggest.html(html);
            }
            $(document).on('click','.sii-rut-item',function(){
                var r=$(this).data('rut'); var n=$(this).data('name'); var e=$(this).data('email'); var a=$(this).data('addr'); var c=$(this).data('cmna');
                if(r){ $('#receptor_rut').val(r); }
                if(n){ $('#receptor_nombre').val(n); }
                if(e){ if($('#correo_recep').length){ $('#correo_recep').val(e); } else { $('<input>').attr({type:'hidden',id:'correo_recep',name:'correo_recep'}).val(e).appendTo('#sii-boleta-generate-form'); } }
                if(a && $('#direccion_recep').length){ $('#direccion_recep').val(a); }
                if(c && $('#comuna_recep').length){ $('#comuna_recep').val(c); }
                $rutSuggest.empty();
            });
            $(document).on('click', function(ev){ if(!$(ev.target).closest('#receptor_rut, #sii-rut-suggest').length){ $rutSuggest.empty(); } });

            function doLookup(){
                clearTimeout(lookupTimer);
                var v = $('#receptor_rut').val();
                if(!v){ return; }
                lookupTimer = setTimeout(function(){
                    // Autocompletar directo si calza exacto
                    $.post(ajaxurl, {action:'sii_boleta_dte_lookup_user_by_rut', rut:v, _wpnonce:'<?php echo esc_js( wp_create_nonce('sii_boleta_nonce') ); ?>'}, function(resp){
                        if(resp && resp.success && resp.data){
                            if(resp.data.name){ $('#receptor_nombre').val(resp.data.name); }
                            if(resp.data.email){
                                if($('#correo_recep').length){ $('#correo_recep').val(resp.data.email); }
                                else { $('<input>').attr({type:'hidden', id:'correo_recep', name:'correo_recep'}).val(resp.data.email).appendTo('#sii-boleta-generate-form'); }
                            }
                            if(resp.data.address && $('#direccion_recep').length){ $('#direccion_recep').val(resp.data.address); }
                            if(resp.data.comuna  && $('#comuna_recep').length){ $('#comuna_recep').val(resp.data.comuna); }
                        }
                    });
                    // Además, mostrar listado de coincidencias
                    $.post(ajaxurl, {action:'sii_boleta_dte_search_customers', term:v, _wpnonce:'<?php echo esc_js( wp_create_nonce('sii_boleta_nonce') ); ?>'}, function(resp){
                        if(resp && resp.success && resp.data){ renderRutList(resp.data.items||[]); }
                    });
                }, 250);
            }
            $('#receptor_rut').on('blur keyup change', doLookup);
            $('#sii-rut-completar').on('click', function(e){ e.preventDefault(); doLookup(); });
            if($('#receptor_rut').val()){ doLookup(); }
            

            $('#sii-boleta-generate-form').on('submit', function(e){
                if (checkRutRecep && !checkRutRecep()) { e.preventDefault(); return; }
                if ($('#rut_trans').is(':visible') && checkRutTrans && !checkRutTrans()) { e.preventDefault(); return; }
                if ($('#rut_chofer').is(':visible') && checkRutChofer && !checkRutChofer()) { e.preventDefault(); return; }
                e.preventDefault();
                // Validar que todos los ítems tengan producto seleccionado
                var ok=true; $('#tabla-items tbody tr').each(function(){ if(!$(this).find('.sii-product-id').val()){ ok=false; } });
                if(!ok){
                    alert('<?php echo esc_js(__('Seleccione productos de la tienda para cada ítem.','sii-boleta-dte')); ?>');
                    return;
                }
                var data = $(this).serialize();
                $('#sii-generate-dte').prop('disabled', true);
                $('#sii-boleta-result').html('<p><?php echo esc_js( __( 'Procesando...', 'sii-boleta-dte' ) ); ?></p>');
                $.post(ajaxurl, data + '&action=sii_boleta_dte_generate_dte', function(response){
                    $('#sii-generate-dte').prop('disabled', false);
                    if (response.success) {
                        $('#sii-boleta-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    } else {
                        $('#sii-boleta-result').html('<div class="notice notice-error"><p>' + (response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error inesperado.', 'sii-boleta-dte' ) ); ?>') + '</p></div>');
                    }
                });
            });

            $('#sii-preview-dte').on('click', function(){
                if (checkRutRecep && !checkRutRecep()) { return; }
                var data = $('#sii-boleta-generate-form').serialize();
                $('#sii-preview-dte').prop('disabled', true);
                $('#sii-boleta-result').html('<p><?php echo esc_js( __( 'Generando previsualización...', 'sii-boleta-dte' ) ); ?></p>');
                $.post(ajaxurl, data + '&action=sii_boleta_dte_preview_dte', function(response){
                    $('#sii-preview-dte').prop('disabled', false);
                    if (response.success) {
                        var url = response.data.preview_url;
                        var html = '<div class="notice notice-success"><p><?php echo esc_js( __( 'Previsualización lista.', 'sii-boleta-dte' ) ); ?> ';
                        if (url) { html += '<a href="'+url+'" target="_blank"><?php echo esc_js( __( 'Abrir en una nueva pestaña', 'sii-boleta-dte' ) ); ?></a>'; }
                        html += '</p></div>';
                        $('#sii-boleta-result').html(html);
                        if (url) { try { window.open(url, '_blank'); } catch(e) {} }
                    } else {
                        $('#sii-boleta-result').html('<div class="notice notice-error"><p>' + (response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error al previsualizar.', 'sii-boleta-dte' ) ); ?>') + '</p></div>');
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
        if ( ! $this->is_valid_rut( $rut_receptor ) ) {
            wp_send_json_error( [ 'message' => __( 'RUT del receptor inválido. Verifique el dígito verificador.', 'sii-boleta-dte' ) ] );
        }
        $rut_receptor = $this->normalize_rut( $rut_receptor );
        if ( ! $this->is_valid_rut( $rut_receptor ) ) {
            wp_send_json_error( [ 'message' => __( 'RUT del receptor inválido. Verifique el dígito verificador.', 'sii-boleta-dte' ) ] );
        }
        $rut_receptor = $this->normalize_rut( $rut_receptor );
        $nombre_receptor = sanitize_text_field( $_POST['receptor_nombre'] );
        $dir_recep       = sanitize_text_field( $_POST['direccion_recep'] ?? '' );
        $cmna_recep      = sanitize_text_field( $_POST['comuna_recep'] ?? '' );
        $correo_recep    = sanitize_text_field( $_POST['correo_recep'] ?? '' );
        // Ítems múltiples
        $descripciones   = isset( $_POST['descripcion'] ) ? (array) $_POST['descripcion'] : [];
        $cantidades      = isset( $_POST['cantidad'] ) ? (array) $_POST['cantidad'] : [];
        $precios         = isset( $_POST['precio_unitario'] ) ? (array) $_POST['precio_unitario'] : [];
        // Datos Guía (52)
        $tpo_traslado    = isset( $_POST['tpo_traslado'] ) ? sanitize_text_field( $_POST['tpo_traslado'] ) : '';
        $patente         = isset( $_POST['patente'] ) ? sanitize_text_field( $_POST['patente'] ) : '';
        $rut_trans       = isset( $_POST['rut_trans'] ) ? sanitize_text_field( $_POST['rut_trans'] ) : '';
        $rut_chofer      = isset( $_POST['rut_chofer'] ) ? sanitize_text_field( $_POST['rut_chofer'] ) : '';
        if ( ! empty( $rut_trans ) && ! $this->is_valid_rut( $rut_trans ) ) {
            wp_send_json_error( [ 'message' => __( 'RUT del transportista inválido.', 'sii-boleta-dte' ) ] );
        }
        if ( ! empty( $rut_chofer ) && ! $this->is_valid_rut( $rut_chofer ) ) {
            wp_send_json_error( [ 'message' => __( 'RUT del chofer inválido.', 'sii-boleta-dte' ) ] );
        }
        if ( ! empty( $rut_trans ) ) { $rut_trans = $this->normalize_rut( $rut_trans ); }
        if ( ! empty( $rut_chofer ) ) { $rut_chofer = $this->normalize_rut( $rut_chofer ); }
        $nombre_chofer   = isset( $_POST['nombre_chofer'] ) ? sanitize_text_field( $_POST['nombre_chofer'] ) : '';
        $dir_dest        = isset( $_POST['dir_dest'] ) ? sanitize_text_field( $_POST['dir_dest'] ) : '';
        $cmna_dest       = isset( $_POST['cmna_dest'] ) ? sanitize_text_field( $_POST['cmna_dest'] ) : '';
        // Datos de referencia para notas
        $folio_ref       = isset( $_POST['folio_ref'] ) ? sanitize_text_field( $_POST['folio_ref'] ) : '';
        $tipo_doc_ref    = isset( $_POST['tipo_doc_ref'] ) ? sanitize_text_field( $_POST['tipo_doc_ref'] ) : '';
        $razon_ref       = isset( $_POST['razon_ref'] ) ? sanitize_text_field( $_POST['razon_ref'] ) : '';
        $enviar_sii      = isset( $_POST['enviar_sii'] );
        $medio_pago      = isset( $_POST['medio_pago'] ) ? sanitize_text_field( $_POST['medio_pago'] ) : '';

        // Política: solo consumir folio si se envía al SII con éxito.
        $folio = 0;
        if ( $enviar_sii ) {
            $folio = $this->folio_manager->peek_next_folio( $type );
            if ( is_wp_error( $folio ) ) {
                wp_send_json_error( [ 'message' => $folio->get_error_message() ] );
            }
            if ( ! $folio ) {
                wp_send_json_error( [ 'message' => __( 'No hay folios disponibles. Cargue un CAF válido.', 'sii-boleta-dte' ) ] );
            }
        }

        // Preparar datos comunes para el DTE
        $settings = $this->settings->get_settings();
        // Autocompletar email/nombre desde usuario si no vienen y existe registro por RUT
        if ( empty( $correo_recep ) || empty( $nombre_receptor ) || empty( $dir_recep ) || empty( $cmna_recep ) ) {
            $user_data = $this->find_user_by_rut_data( $rut_receptor );
            if ( $user_data ) {
                if ( empty( $nombre_receptor ) && ! empty( $user_data['name'] ) ) { $nombre_receptor = $user_data['name']; }
                if ( empty( $correo_recep ) && ! empty( $user_data['email'] ) ) { $correo_recep = $user_data['email']; }
                if ( empty( $dir_recep ) && ! empty( $user_data['address'] ) ) { $dir_recep = $user_data['address']; }
                if ( empty( $cmna_recep ) && ! empty( $user_data['comuna'] ) ) { $cmna_recep = $user_data['comuna']; }
            }
        }
        // Construir detalles desde arrays (con fallback a un solo ítem)
        $detalles = [];
        $line     = 1;
        $monto_total = 0;
        if ( ! empty( $descripciones ) ) {
            foreach ( $descripciones as $i => $desc ) {
                $desc  = sanitize_text_field( $desc );
                $qty   = isset( $cantidades[ $i ] ) ? max( 1, intval( $cantidades[ $i ] ) ) : 1;
                $price = isset( $precios[ $i ] ) ? max( 0, floatval( $precios[ $i ] ) ) : 0;
                $monto = round( $qty * $price );
                $monto_total += $monto;
                $detalles[] = [
                    'NroLinDet' => $line++,
                    'NmbItem'   => $desc,
                    'QtyItem'   => $qty,
                    'PrcItem'   => $price,
                    'MontoItem' => $monto,
                ];
            }
        } else {
            $descripcion     = sanitize_textarea_field( $_POST['descripcion'] );
            $cantidad        = max( 1, intval( $_POST['cantidad'] ) );
            $precio_unitario = max( 0, floatval( $_POST['precio_unitario'] ) );
            $monto_total = round( $cantidad * $precio_unitario );
            $detalles[] = [
                'NroLinDet' => 1,
                'NmbItem'   => $descripcion,
                'QtyItem'   => $cantidad,
                'PrcItem'   => $precio_unitario,
                'MontoItem' => $monto_total,
            ];
        }

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
                'RUTRecep'       => $rut_receptor,
                'RznSocRecep'    => $nombre_receptor,
                'DirRecep'       => $dir_recep,
                'CmnaRecep'      => $cmna_recep,
                'CorreoRecep'    => $correo_recep,
            ],
            'Detalles' => $detalles,
        ];
        // Validaciones por tipo
        if ( in_array( $type, [33,34,52], true ) ) {
            if ( empty( $dir_recep ) || empty( $cmna_recep ) ) {
                wp_send_json_error( [ 'message' => __( 'Dirección y comuna del receptor son obligatorias para facturas y guías.', 'sii-boleta-dte' ) ] );
            }
        }
        if ( 52 === $type ) {
            if ( empty( $tpo_traslado ) || empty( $dir_dest ) || empty( $cmna_dest ) ) {
                wp_send_json_error( [ 'message' => __( 'Para la Guía 52 debe indicar tipo de traslado, dirección y comuna de destino.', 'sii-boleta-dte' ) ] );
            }
        }
        // Medio de pago para facturas (33/34)
        if ( in_array( $type, [33,34], true ) && $medio_pago !== '' ) {
            $dte_data['MedioPago'] = $medio_pago;
        }
        // Marcar ítem exento cuando es factura exenta (34)
        if ( 34 === $type ) {
            foreach ( $dte_data['Detalles'] as &$d ) {
                $d['IndExe'] = 1;
            }
            unset( $d );
        }
        // Añadir referencia si corresponde (notas de crédito o débito)
        if ( in_array( $type, [56,61], true ) ) {
            if ( ! $folio_ref || ! $tipo_doc_ref ) {
                wp_send_json_error( [ 'message' => __( 'Debe indicar documento y folio de referencia para notas.', 'sii-boleta-dte' ) ] );
            }
            $dte_data['Referencias'][] = [
                'TpoDocRef' => $tipo_doc_ref,
                'FolioRef'  => $folio_ref,
                'FchRef'    => date( 'Y-m-d' ),
                'RazonRef'  => $razon_ref ?: 'Corrección',
            ];
        }

        // Datos de guía (52)
        if ( 52 === $type ) {
            $dte_data['TpoTraslado'] = $tpo_traslado;
            $dte_data['Transporte'] = array_filter([
                'Patente'      => $patente,
                'RUTTrans'     => $rut_trans,
                'RUTChofer'    => $rut_chofer,
                'NombreChofer' => $nombre_chofer,
                'DirDest'      => $dir_dest,
                'CmnaDest'     => $cmna_dest,
                'CiudadDest'   => $ciudad_dest,
                'TipoVehiculo' => $tipo_vehiculo,
            ]);
        }

        // Generar XML base para el DTE usando el motor activo (en preview no exige CAF/TED)
        $xml = $this->engine->generate_dte_xml( $dte_data, $type, ! $enviar_sii );
        if ( is_wp_error( $xml ) ) {
            wp_send_json_error( [ 'message' => $xml->get_error_message() ] );
        }
        if ( ! $xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al generar el XML del DTE.', 'sii-boleta-dte' ) ] );
        }

        // Firmar el XML con el motor
        $signed_xml = $this->engine->sign_dte_xml( $xml );
        if ( $enviar_sii && ! $signed_xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al firmar el XML. Verifique su certificado.', 'sii-boleta-dte' ) ] );
        }

        // Guardar el archivo XML en la carpeta uploads/dte/<RUTRecep>
        $upload_dir = wp_upload_dir();
        $rut_folder = strtoupper( preg_replace( '/[^0-9Kk-]/', '', $rut_receptor ?: 'SIN-RUT' ) );
        $target_dir = trailingslashit( $upload_dir['basedir'] ) . 'dte/' . $rut_folder . '/';
        if ( function_exists( 'wp_mkdir_p' ) ) {
            wp_mkdir_p( $target_dir );
        } else {
            if ( ! is_dir( $target_dir ) ) {
                @mkdir( $target_dir, 0755, true );
            }
        }
        $timestamp  = time();
        $file_name  = 'DTE_' . $type . '_' . ( $folio ?: 0 ) . '_' . $timestamp . '.xml';
        $file_path  = $target_dir . $file_name;
        if ( $enviar_sii ) {
            // Guardar temporalmente para envío; confirmar y mover tras éxito
            $tmp_dir = trailingslashit( $upload_dir['basedir'] ) . 'dte/tmp/';
            if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $tmp_dir ); } else { if ( ! is_dir( $tmp_dir ) ) { @mkdir( $tmp_dir, 0755, true ); } }
            $tmp_path = $tmp_dir . $file_name;
            file_put_contents( $tmp_path, $signed_xml );
        }

        // Lógica para enviar al SII si el usuario lo solicita
        $track_id = false;
        if ( $enviar_sii ) {
            $send_path = isset( $tmp_path ) ? $tmp_path : $file_path;
            $track_id = $this->engine->send_dte_file( $send_path, $settings['environment'] ?? 'test', $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
            if ( is_wp_error( $track_id ) ) {
                wp_send_json_error( [ 'message' => $track_id->get_error_message() ] );
            }
            if ( $track_id ) {
                // Consumir folio y mover XML definitivo
                $this->folio_manager->consume_folio( $type, $folio );
                if ( isset( $tmp_path ) ) { @rename( $tmp_path, $file_path ); }
            }
        }

        // Generar PDF/HTML de representación vía motor
        $pdf_path = $this->engine->render_pdf( $signed_xml ?: $xml, $settings );
        $upload_url = $upload_dir['baseurl'];
        $xml_url    = '';
        $pdf_url    = '';
        if ( $enviar_sii && ! empty( $file_path ) && file_exists( $file_path ) ) {
            $xml_url = str_replace( $upload_dir['basedir'], $upload_url, $file_path );
        }
        if ( $pdf_path && file_exists( $pdf_path ) ) {
            $pdf_url = str_replace( $upload_dir['basedir'], $upload_url, $pdf_path );
        }

        // Agregar el resultado del envío al mensaje de éxito
        $message = $enviar_sii
            ? sprintf( __( 'DTE emitido. Archivo XML: %s', 'sii-boleta-dte' ), esc_html( $file_name ) )
            : __( 'Representación generada sin consumir folio.', 'sii-boleta-dte' );
        if ( $xml_url ) {
            $message .= ' | <a href="' . esc_url( $xml_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Descargar XML', 'sii-boleta-dte' ) . '</a>';
        }
        if ( $pdf_url ) {
            $message .= ' | <a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Abrir PDF', 'sii-boleta-dte' ) . '</a>';
        }
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
        $correo_recep    = sanitize_text_field( $_POST['correo_recep'] ?? '' );
        $medio_pago      = isset( $_POST['medio_pago'] ) ? sanitize_text_field( $_POST['medio_pago'] ) : '';
        // Ítems múltiples
        $descripciones   = isset( $_POST['descripcion'] ) ? (array) $_POST['descripcion'] : [];
        $cantidades      = isset( $_POST['cantidad'] ) ? (array) $_POST['cantidad'] : [];
        $precios         = isset( $_POST['precio_unitario'] ) ? (array) $_POST['precio_unitario'] : [];

        $settings   = $this->settings->get_settings();
        if ( empty( $correo_recep ) || empty( $nombre_receptor ) || empty( $dir_recep ) || empty( $cmna_recep ) ) {
            $user_data = $this->find_user_by_rut_data( $rut_receptor );
            if ( $user_data ) {
                if ( empty( $nombre_receptor ) && ! empty( $user_data['name'] ) ) { $nombre_receptor = $user_data['name']; }
                if ( empty( $correo_recep ) && ! empty( $user_data['email'] ) ) { $correo_recep = $user_data['email']; }
                if ( empty( $dir_recep ) && ! empty( $user_data['address'] ) ) { $dir_recep = $user_data['address']; }
                if ( empty( $cmna_recep ) && ! empty( $user_data['comuna'] ) ) { $cmna_recep = $user_data['comuna']; }
            }
        }
        // Construir detalles desde arrays (con fallback a un solo ítem)
        $detalles = [];
        $line     = 1;
        $monto_total = 0;
        if ( ! empty( $descripciones ) ) {
            foreach ( $descripciones as $i => $desc ) {
                $desc  = sanitize_text_field( $desc );
                $qty   = isset( $cantidades[ $i ] ) ? max( 1, intval( $cantidades[ $i ] ) ) : 1;
                $price = isset( $precios[ $i ] ) ? max( 0, floatval( $precios[ $i ] ) ) : 0;
                $monto = round( $qty * $price );
                $monto_total += $monto;
                $detalles[] = [
                    'NroLinDet' => $line++,
                    'NmbItem'   => $desc,
                    'QtyItem'   => $qty,
                    'PrcItem'   => $price,
                    'MontoItem' => $monto,
                ];
            }
        } else {
            $descripcion     = sanitize_textarea_field( $_POST['descripcion'] );
            $cantidad        = max( 1, intval( $_POST['cantidad'] ) );
            $precio_unitario = max( 0, floatval( $_POST['precio_unitario'] ) );
            $monto_total = round( $cantidad * $precio_unitario );
            $detalles[] = [
                'NroLinDet' => 1,
                'NmbItem'   => $descripcion,
                'QtyItem'   => $cantidad,
                'PrcItem'   => $precio_unitario,
                'MontoItem' => $monto_total,
            ];
        }

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
                'RUTRecep'       => $rut_receptor,
                'RznSocRecep'    => $nombre_receptor,
                'DirRecep'       => $dir_recep,
                'CmnaRecep'      => $cmna_recep,
                'CorreoRecep'    => $correo_recep,
            ],
            'Detalles' => $detalles,
        ];
        // Validaciones por tipo (previsualización)
        if ( in_array( $type, [33,34,52], true ) ) {
            if ( empty( $dir_recep ) || empty( $cmna_recep ) ) {
                wp_send_json_error( [ 'message' => __( 'Dirección y comuna del receptor son obligatorias para facturas y guías.', 'sii-boleta-dte' ) ] );
            }
        }
        if ( 52 === $type ) {
            if ( empty( $tpo_traslado ) || empty( $dir_dest ) || empty( $cmna_dest ) ) {
                wp_send_json_error( [ 'message' => __( 'Para la Guía 52 debe indicar tipo de traslado, dirección y comuna de destino.', 'sii-boleta-dte' ) ] );
            }
        }
        // Medio de pago para facturas (33/34)
        if ( in_array( $type, [33,34], true ) && $medio_pago !== '' ) {
            $dte_data['MedioPago'] = $medio_pago;
        }
        // Marcar ítem exento cuando es factura exenta (34)
        if ( 34 === $type ) {
            foreach ( $dte_data['Detalles'] as &$d ) {
                $d['IndExe'] = 1;
            }
            unset( $d );
        }
        // Añadir referencia si corresponde (notas de crédito o débito)
        if ( in_array( $type, [56,61], true ) && $folio_ref && $tipo_doc_ref ) {
            $dte_data['Referencias'][] = [
                'TpoDocRef' => $tipo_doc_ref,
                'FolioRef'  => $folio_ref,
                'FchRef'    => date( 'Y-m-d' ),
                'RazonRef'  => $razon_ref ?: 'Corrección',
            ];
        }
        // Datos de guía (52)
        if ( 52 === $type ) {
            $dte_data['TpoTraslado'] = $tpo_traslado;
            $dte_data['Transporte'] = array_filter([
                'Patente'      => $patente,
                'RUTTrans'     => $rut_trans,
                'RUTChofer'    => $rut_chofer,
                'NombreChofer' => $nombre_chofer,
                'DirDest'      => $dir_dest,
                'CmnaDest'     => $cmna_dest,
            ]);
        }

        $xml = $this->engine->generate_dte_xml( $dte_data, $type, true );
        if ( is_wp_error( $xml ) || ! $xml ) {
            wp_send_json_error( [ 'message' => __( 'Error al generar la previsualización del DTE.', 'sii-boleta-dte' ) ] );
        }

        // Log de diagnóstico: totales y cantidad de líneas
        try {
            $raw = (string) $xml; if ( substr($raw,0,3)==="\xEF\xBB\xBF" ) { $raw=substr($raw,3); }
            $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$raw);
            $sx = @simplexml_load_string( $raw );
            if ( $sx ) {
                $doc_nodes = $sx->xpath('//*[local-name()="Documento"]');
                if ( $doc_nodes && ! empty($doc_nodes[0]) ) {
                    $d = $doc_nodes[0];
                    $tot = $d->Encabezado->Totales ?? null;
                    $lines = $d->xpath('./*[local-name()="Detalle"]');
                    if ( class_exists('SII_Logger') ) {
                        \SII_Logger::info('[Preview] Detalles=' . ( $lines ? count($lines) : 0 ) . ' MntNeto=' . (string)($tot->MntNeto ?? '') . ' IVA=' . (string)($tot->IVA ?? '') . ' MntExe=' . (string)($tot->MntExe ?? '') . ' MntTotal=' . (string)($tot->MntTotal ?? '') );
                        $head = substr( trim($raw), 0, 80 );
                        \SII_Logger::info('[Preview] XML head: ' . $head );
                    }
                }
            }
        } catch ( \Throwable $e ) {}

        $pdf_path = $this->engine->render_pdf( $xml, $settings );
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
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
                // Recorrer recursivamente para encontrar archivos DTE/Woo_DTE
                $files = [];
                $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) );
                foreach ( $it as $file ) {
                    if ( $file->isFile() && preg_match( '/^(?:Woo_)?DTE_\d+_\d+_\d+\.xml$/', $file->getFilename() ) ) {
                        $files[] = $file->getPathname();
                    }
                }
        $crons      = _get_cron_array();
        $pending    = [];
        foreach ( $crons as $timestamp => $hooks ) {
            if ( isset( $hooks[ SII_Boleta_Queue::CRON_HOOK ] ) ) {
                foreach ( $hooks[ SII_Boleta_Queue::CRON_HOOK ] as $event ) {
                    $args = $event['args'] ?? [];
                    if ( ! empty( $args[0] ) ) {
                        $pending[] = basename( $args[0] );
                    }
                }
            }
        }
        $dtes = [];
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
                    $status = in_array( $name, $pending, true ) ? 'pending' : 'sent';
                    $dtes[] = [
                        'tipo'   => $tipo,
                        'folio'  => $folio,
                        'fecha'  => $fecha,
                        'xml'    => $base_url . $name,
                        'pdf'    => $pdf,
                        'status' => $status,
                    ];
                }
            }
        }
        wp_send_json_success( [ 'dtes' => $dtes ] );
    }


    /**
     * Devuelve el registro de actividad del job y la próxima ejecución programada.
     */
    public function ajax_job_log() {
        check_ajax_referer( 'sii_boleta_nonce' );
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
        check_ajax_referer( 'sii_boleta_nonce' );
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
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $settings = $this->settings->get_settings();
        $rvd_xml  = $this->engine->build_rvd_xml( date( 'Y-m-d' ) );
        if ( ! $rvd_xml ) {
            wp_send_json_error( [ 'message' => __( 'No fue posible generar el RVD.', 'sii-boleta-dte' ) ] );
        }
        // Firmado y validación ocurren dentro de los componentes del engine.
        $signed = ( new SII_Boleta_Signer() )->sign_rvd_xml( $rvd_xml, $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        if ( ! $signed ) {
            wp_send_json_error( [ 'message' => __( 'No fue posible firmar el RVD.', 'sii-boleta-dte' ) ] );
        }
        $sent     = $this->engine->send_rvd( $signed, $settings['environment'], $settings['api_token'] ?? '' );
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
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $settings = $this->settings->get_settings();
        $today    = date( 'Y-m-d' );
        $cdf_xml  = $this->engine->generate_cdf_xml( $today );
        if ( ! $cdf_xml ) {
            wp_send_json_error( [ 'message' => __( 'No fue posible generar el CDF.', 'sii-boleta-dte' ) ] );
        }
        $sent = $this->engine->send_cdf( $cdf_xml, $settings['environment'], $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        if ( $sent ) {
            sii_boleta_write_log( 'CDF enviado manualmente para la fecha ' . $today );
            wp_send_json_success( [ 'message' => __( 'CDF enviado correctamente.', 'sii-boleta-dte' ) ] );
        } else {
            sii_boleta_write_log( 'Error al enviar el CDF manual para la fecha ' . $today );
            wp_send_json_error( [ 'message' => __( 'Error al enviar el CDF.', 'sii-boleta-dte' ) ] );
        }
    }

    /**
     * Devuelve el número de eventos pendientes en la cola de envíos.
     */
    public function ajax_queue_status() {
        check_ajax_referer( 'sii_boleta_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ] );
        }
        $crons = _get_cron_array();
        $count = 0;
        foreach ( $crons as $timestamp => $hooks ) {
            if ( isset( $hooks[ SII_Boleta_Queue::CRON_HOOK ] ) ) {
                $count += count( $hooks[ SII_Boleta_Queue::CRON_HOOK ] );
            }
        }
        if ( $count > 0 ) {
            sii_boleta_write_log( sprintf( 'Cola de envíos pendiente: %d evento(s)', $count ) );
        }
        wp_send_json_success( [ 'count' => $count ] );
    }
}
