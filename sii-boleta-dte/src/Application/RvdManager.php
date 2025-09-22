<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Cron;

/**
 * Generates and sends the daily sales summary (RVD).
 */
class RvdManager {
    private Settings $settings;
    private Api $api;
    private Queue $queue;

    public function __construct( Settings $settings, Api $api = null, Queue $queue = null ) {
        $this->settings = $settings;
        $this->api      = $api ?? new Api();
        $this->queue    = $queue ?? new Queue();
        if ( function_exists( 'add_action' ) ) {
            add_action( Cron::HOOK, array( $this, 'maybe_run' ) );
        }
    }

    /** Triggered by cron to generate and send the RVD. */
    public function maybe_run(): void {
        $xml = $this->generate_xml();
        if ( '' === $xml || ! $this->validate_rvd_xml( $xml ) ) {
            return;
        }
        $environment = $this->settings->get_environment();
        $token       = $this->api->generate_token( $environment, '', '' );
        if ( '' === $token ) {
            return;
        }
        $this->queue->enqueue_rvd( $xml, $environment, $token );
    }

    /**
     * Builds the RVD XML from WooCommerce orders of the current day.
     */
    public function generate_xml(): string {
        $orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'limit' => -1 ) ) : array();
        $total  = 0;
        foreach ( $orders as $order ) {
            if ( method_exists( $order, 'get_total' ) ) {
                $total += (float) $order->get_total();
            }
        }
        $xml = '<ConsumoFolios><Resumen><Totales><MntTotal>' . $total . '</MntTotal></Totales></Resumen></ConsumoFolios>';
        return $xml;
    }

    /**
     * Validates RVD XML against the official schema.
     */
    public function validate_rvd_xml( string $xml ): bool {
        $doc = new \DOMDocument();
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        libxml_use_internal_errors( true );
        $xsd   = SII_BOLETA_DTE_PATH . 'resources/xml/schemas/consumo_folios.xsd';
        $valid = $doc->schemaValidate( $xsd );
        libxml_clear_errors();
        return $valid;
    }
}

class_alias( RvdManager::class, 'SII_Boleta_RVD_Manager' );
