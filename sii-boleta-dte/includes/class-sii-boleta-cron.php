<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Maneja las tareas programadas del plugin (WP-Cron). Esta clase
 * registra un evento diario para generar y enviar el Resumen de Ventas
 * Diarias (RVD) automáticamente. También ofrece funciones de activación
 * y desactivación para registrar o eliminar dicho evento.
 */
class SII_Boleta_Cron {

    /**
     * Instancia de configuraciones del plugin.
     *
     * @var SII_Boleta_Settings
     */
    private $settings;

    /**
     * Nombre del evento cron que se ejecutará diariamente.
     */
    const CRON_HOOK = 'sii_boleta_dte_daily_rvd';

    /**
     * Constructor. Engancha las funciones al evento programado.
     */
    public function __construct( SII_Boleta_Settings $settings ) {
        $this->settings = $settings;
        add_action( self::CRON_HOOK, [ $this, 'generate_and_send_rvd' ] );
    }

    /**
     * Programa el cron al activar el plugin. Se ejecuta una vez al día. Si ya
     * existe un evento programado, no vuelve a programarlo.
     */
    public static function activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Programar para la medianoche de la zona horaria configurada
            $timestamp = strtotime( 'tomorrow midnight' );
            wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Elimina el evento programado al desactivar el plugin.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Callback que genera el RVD para el día anterior y lo envía al SII.
     */
    public function generate_and_send_rvd() {
        // Se genera el RVD del día anterior (fecha de hoy menos un día)
        $date = date( 'Y-m-d', strtotime( '-1 day' ) );
        $rvd_manager = new SII_Boleta_RVD_Manager( $this->settings );
        $xml = $rvd_manager->generate_rvd_xml( $date );
        if ( ! $xml ) {
            sii_boleta_write_log( 'Fallo al generar el RVD para la fecha ' . $date );
            return;
        }
        $settings = $this->settings->get_settings();
        $env = $settings['environment'];
        $enviado = $rvd_manager->send_rvd_to_sii( $xml, $env );
        if ( $enviado ) {
            sii_boleta_write_log( 'RVD enviado correctamente para la fecha ' . $date );
        } else {
            sii_boleta_write_log( 'Error al enviar el RVD para la fecha ' . $date );
        }
    }
}