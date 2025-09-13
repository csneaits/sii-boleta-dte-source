<?php
namespace Sii\BoletaDte\Core;

use Sii\BoletaDte\Admin\Ajax;
use Sii\BoletaDte\Admin\Pages;

class Plugin {
    private \SII_Boleta_Settings $settings;
    private \SII_Boleta_Folio_Manager $folio_manager;
    private \SII_Boleta_Signer $signer;
    private \SII_Boleta_API $api;
    private \SII_Boleta_RVD_Manager $rvd_manager;
    private \SII_Boleta_Endpoints $endpoints;
    private \SII_Boleta_Woo $woo;
    private \SII_Boleta_Metrics $metrics;
    private \SII_Boleta_Consumo_Folios $consumo_folios;
    private \SII_Boleta_Queue $queue;
    private \SII_Boleta_Help $help;
    private \SII_DTE_Engine $engine;
    private bool $libredte_missing = false;
    private Ajax $ajax;
    private Pages $pages;

    public function __construct() {
        $this->settings      = new \SII_Boleta_Settings();
        $this->folio_manager = new \SII_Boleta_Folio_Manager( $this->settings );
        $this->signer        = new \SII_Boleta_Signer();
        $this->api           = new \SII_Boleta_API();
        $this->rvd_manager   = new \SII_Boleta_RVD_Manager( $this->settings );
        $this->endpoints     = new \SII_Boleta_Endpoints();
        $this->metrics       = new \SII_Boleta_Metrics();
        $this->consumo_folios = new \SII_Boleta_Consumo_Folios( $this->settings, $this->folio_manager, $this->api );

        try {
            $default_engine = new \SII_LibreDTE_Engine( $this->settings );
        } catch ( \RuntimeException $e ) {
            $this->libredte_missing = true;
            $default_engine         = new \SII_Null_Engine();
        }
        $this->engine = \apply_filters( 'sii_boleta_dte_engine', $default_engine );

        $this->queue = new \SII_Boleta_Queue( $this->engine, $this->settings );
        require_once SII_BOLETA_DTE_PATH . 'src/includes/admin/class-sii-boleta-help.php';
        $this->help = new \SII_Boleta_Help();

        if ( class_exists( 'WooCommerce' ) ) {
            $this->woo = new \SII_Boleta_Woo( $this );
        }

        $this->pages = new Pages( $this );
        $this->ajax  = new Ajax( $this );

        \add_action( 'admin_menu', [ $this->pages, 'register' ] );
        \add_action( 'admin_enqueue_scripts', [ $this->pages, 'enqueue_assets' ] );

        \add_action( 'admin_bar_menu', [ $this, 'add_environment_indicator' ], 100 );
        \add_action( 'admin_notices', [ $this, 'maybe_show_admin_warnings' ] );

        $this->ajax->register();

        \add_filter( 'sii_boleta_available_smtp_profiles', [ $this, 'fluent_smtp_profiles' ] );
        \add_action( 'sii_boleta_setup_mailer', [ $this, 'fluent_smtp_setup_mailer' ], 10, 2 );
    }

    public function get_settings() { return $this->settings; }
    public function get_folio_manager() { return $this->folio_manager; }
    public function get_signer() { return $this->signer; }
    public function get_api() { return $this->api; }
    public function get_rvd_manager() { return $this->rvd_manager; }
    public function get_consumo_folios() { return $this->consumo_folios; }
    public function get_queue() { return $this->queue; }
    public function get_engine() { return $this->engine; }

    public function fluent_smtp_profiles( $profiles ) {
        if ( class_exists( '\\FluentMail\\App\\Models\\Settings' ) ) {
            $settings  = new \FluentMail\App\Models\Settings();
            $config    = $settings->getConnections();
            foreach ( $config as $key => $data ) {
                $profiles[ $key ] = $data['title'] ?? $key;
            }
        }
        return $profiles;
    }

    public function fluent_smtp_setup_mailer( $phpmailer, $profile ) {
        \do_action( 'fluentmail_before_sending_email', $phpmailer, $profile );
    }

    public function add_environment_indicator( $wp_admin_bar ) {
        if ( $this->libredte_missing ) {
            $wp_admin_bar->add_node( [
                'id'    => 'sii-boleta-env',
                'title' => 'LibreDTE missing',
                'meta'  => [ 'class' => 'sii-boleta-env-warning' ],
            ] );
        }
    }

    public function maybe_show_admin_warnings() {
        if ( $this->libredte_missing ) {
            echo '<div class="notice notice-error"><p>';
            \esc_html_e( 'No se pudo cargar el motor LibreDTE.', 'sii-boleta-dte' );
            echo '</p></div>';
        }
    }
}
