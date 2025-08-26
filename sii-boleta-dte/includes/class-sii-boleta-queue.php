<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cola asíncrona para envío de DTE.
 *
 * Registra un evento inmediato para despachar el XML al SII fuera
 * del ciclo de vida del pedido. En caso de error se reintenta con
 * retardos crecientes hasta un máximo de tres intentos.
 */
class SII_Boleta_Queue {

    /**
     * Gancho usado por la cola asíncrona.
     */
    const CRON_HOOK = 'sii_boleta_dte_async_send';

    /**
     * Instancias para reutilizar API y configuraciones.
     *
     * @var SII_Boleta_API
     * @var SII_Boleta_Settings
     */
    private $api;
    private $settings;

    /**
     * Constructor. Registra el callback del evento.
     *
     * @param SII_Boleta_API      $api       Instancia de la API del SII.
     * @param SII_Boleta_Settings $settings  Instancia de configuraciones.
     */
    public function __construct( SII_Boleta_API $api, SII_Boleta_Settings $settings ) {
        $this->api      = $api;
        $this->settings = $settings;
        add_action( self::CRON_HOOK, [ $this, 'process' ], 10, 3 );
    }

    /**
     * Encola el envío asíncrono.
     *
     * @param string $file_path Ruta del XML firmado.
     * @param int    $order_id  ID del pedido asociado.
     * @param int    $attempt   Número de intento actual.
     */
    public function enqueue( $file_path, $order_id, $attempt = 1 ) {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::CRON_HOOK, [ $file_path, $order_id, $attempt ] );
        } else {
            wp_schedule_single_event( time(), self::CRON_HOOK, [ $file_path, $order_id, $attempt ] );
        }
        $this->log_queue_status( sprintf( 'Encolado envío DTE para pedido %d (intento %d)', $order_id, $attempt ) );
    }

    /**
     * Procesa el envío de un DTE. Registra fallos y reintenta con
     * un retardo incremental hasta un máximo de tres intentos.
     *
     * @param string $file_path Ruta del XML firmado.
     * @param int    $order_id  ID del pedido.
     * @param int    $attempt   Número de intento.
     */
    public function process( $file_path, $order_id, $attempt ) {
        $settings = $this->settings->get_settings();
        $track_id = $this->api->send_dte_to_sii(
            $file_path,
            $settings['environment'],
            $settings['api_token'],
            $settings['cert_path'],
            $settings['cert_pass']
        );

        if ( is_wp_error( $track_id ) ) {
            sii_boleta_write_log( 'Fallo envío DTE: ' . $track_id->get_error_message(), 'ERROR' );
            if ( $attempt < 3 ) {
                $delay = min( $attempt * 600, HOUR_IN_SECONDS );
                if ( function_exists( 'as_enqueue_async_action' ) ) {
                    as_enqueue_async_action( self::CRON_HOOK, [ $file_path, $order_id, $attempt + 1 ], $delay );
                } else {
                    wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $file_path, $order_id, $attempt + 1 ] );
                }
                $this->log_queue_status( sprintf( 'Reintento programado para pedido %d (intento %d)', $order_id, $attempt + 1 ) );
            }
            return;
        }

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_sii_boleta_track_id', $track_id );
                $order->save();
            }
        }
        $this->log_queue_status( sprintf( 'Envío DTE exitoso para pedido %d', $order_id ) );
    }

    /**
     * Registra en el log el número de eventos pendientes en la cola.
     *
     * @param string $context Mensaje que describe el punto de registro.
     */
    private function log_queue_status( $context ) {
        $crons = _get_cron_array();
        $count = 0;
        foreach ( $crons as $timestamp => $hooks ) {
            if ( isset( $hooks[ self::CRON_HOOK ] ) ) {
                $count += count( $hooks[ self::CRON_HOOK ] );
            }
        }
        sii_boleta_write_log( sprintf( '%s. Eventos pendientes en cola: %d', $context, $count ), 'INFO' );
    }
}
