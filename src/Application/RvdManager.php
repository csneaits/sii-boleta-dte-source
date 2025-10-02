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
        $config = $this->settings->get_settings();
        if ( empty( $config['rvd_auto_enabled'] ) ) {
            return;
        }

        $environment = $this->settings->get_environment();
        $time_string = isset( $config['rvd_auto_time'] ) ? (string) $config['rvd_auto_time'] : '02:00';
        if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time_string ) ) {
            $time_string = '02:00';
        }

        $now        = $this->current_timestamp();
        $today_key  = $this->format_date( $now, 'Y-m-d' );
        $targetTime = $this->timestamp_for_time( $time_string, $now );

        if ( $now < $targetTime ) {
            return;
        }

        $last_run = Settings::get_schedule_last_run( 'rvd', $environment );
        if ( $last_run === $today_key ) {
            return;
        }

        $xml = $this->generate_xml();
        if ( '' === $xml || ! $this->validate_rvd_xml( $xml ) ) {
            return;
        }
        $token       = $this->api->generate_token( $environment, '', '' );
        if ( '' === $token ) {
            return;
        }
        $this->queue->enqueue_rvd( $xml, $environment, $token );
        Settings::update_schedule_last_run( 'rvd', $environment, $today_key );
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

    private function current_timestamp(): int {
        if ( function_exists( 'current_time' ) ) {
            return (int) current_time( 'timestamp' );
        }
        return time();
    }

    private function timestamp_for_time( string $time, int $reference ): int {
        try {
            $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        } catch ( \Throwable $e ) {
            $timezone = new \DateTimeZone( 'UTC' );
        }

        $date = new \DateTimeImmutable( '@' . $reference );
        $date = $date->setTimezone( $timezone );

        list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
        $date = $date->setTime( $hour, $minute, 0 );

        return $date->getTimestamp();
    }

    private function format_date( int $timestamp, string $format ): string {
        if ( function_exists( 'wp_date' ) ) {
            return wp_date( $format, $timestamp );
        }
        return gmdate( $format, $timestamp );
    }
}

class_alias( RvdManager::class, 'SII_Boleta_RVD_Manager' );
