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
     * Nombre del evento cron que se ejecutará diariamente para el RVD.
     */
    const CRON_HOOK = 'sii_boleta_dte_daily_rvd';

    /**
     * Evento cron mensual para el envío del libro.
     */
    const LIBRO_CRON_HOOK = 'sii_boleta_dte_monthly_libro';

    /**
     * Evento cron diario para el envío del CDF.
     */
    const CDF_CRON_HOOK = 'sii_boleta_dte_daily_cdf';

    /**
     * Gancho legado usado en versiones anteriores del plugin.
     * Se mantiene para eliminar eventos huérfanos.
     */
    const LEGACY_CRON_HOOK = 'cron_sii_cl_boleta_dte';

    /**
     * Constructor. Engancha las funciones a los eventos programados.
     */
    public function __construct( SII_Boleta_Settings $settings ) {
        $this->settings = $settings;
        add_action( self::CRON_HOOK, [ $this, 'generate_and_send_rvd' ] );
        add_action( self::LIBRO_CRON_HOOK, [ $this, 'generate_and_send_libro' ] );
        add_action( self::CDF_CRON_HOOK, [ $this, 'generate_and_send_cdf' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
    }

    /**
     * Agrega intervalos personalizados al cron.
     *
     * @param array $schedules Listado de intervalos existentes.
     *
     * @return array
     */
    public static function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => MONTH_IN_SECONDS,
                'display'  => __( 'Una vez al mes', 'sii-boleta-dte' ),
            ];
        }

        return $schedules;
    }

    /**
     * Programa los eventos cron al activar el plugin.
     *
     * Se ejecutan diariamente para el RVD y CDF, y mensualmente para el libro.
     * Si ya existe un evento programado, no vuelve a programarlo.
     */
    public static function activate() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );

        // Limpiar eventos antiguos que podían causar errores.
        wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Programar para la medianoche de la zona horaria configurada
            $timestamp = strtotime( 'tomorrow midnight' );
            wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
        }

        if ( ! wp_next_scheduled( self::CDF_CRON_HOOK ) ) {
            $timestamp = strtotime( 'tomorrow midnight' );
            wp_schedule_event( $timestamp, 'daily', self::CDF_CRON_HOOK );
        }

        if ( ! wp_next_scheduled( self::LIBRO_CRON_HOOK ) ) {
            $timestamp = strtotime( 'first day of next month midnight' );
            wp_schedule_event( $timestamp, 'monthly', self::LIBRO_CRON_HOOK );
        }
    }

    /**
     * Elimina el evento programado al desactivar el plugin.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::CDF_CRON_HOOK );
        wp_clear_scheduled_hook( self::LIBRO_CRON_HOOK );
        wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
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
        $env     = $settings['environment'];
        $enviado = $rvd_manager->send_rvd_to_sii( $xml, $env, $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
        $admin_email = get_option( 'admin_email' );
        if ( $enviado ) {
            sii_boleta_write_log( 'RVD enviado correctamente para la fecha ' . $date );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'RVD enviado', 'RVD enviado correctamente para la fecha ' . $date );
            }
        } else {
            sii_boleta_write_log( 'Error al enviar el RVD para la fecha ' . $date );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'Error al enviar RVD', 'Error al enviar el RVD para la fecha ' . $date );
            }
        }
    }

    /**
     * Callback que genera el Libro del mes anterior y lo envía al SII.
     */
    public function generate_and_send_libro() {
        $month         = date( 'Y-m', strtotime( 'first day of previous month' ) );
        $libro_manager = new SII_Boleta_Libro_Manager( $this->settings );
        $xml           = $libro_manager->generate_libro_xml( $month );

        if ( ! $xml ) {
            sii_boleta_write_log( 'Fallo al generar el Libro para el mes ' . $month );
            return;
        }

        $settings = $this->settings->get_settings();
        $env      = $settings['environment'];
        $enviado  = $libro_manager->send_libro_to_sii(
            $xml,
            $env,
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );

        $admin_email = get_option( 'admin_email' );
        if ( $enviado ) {
            sii_boleta_write_log( 'Libro enviado correctamente para el mes ' . $month );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'Libro enviado', 'Libro enviado correctamente para el mes ' . $month );
            }
        } else {
            sii_boleta_write_log( 'Error al enviar el Libro para el mes ' . $month );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'Error al enviar Libro', 'Error al enviar el Libro para el mes ' . $month );
            }
        }
    }

    /**
     * Callback que genera el CDF para el día anterior y lo envía al SII.
     */
    public function generate_and_send_cdf() {
        $date         = date( 'Y-m-d', strtotime( '-1 day' ) );
        $cdf_manager  = new SII_Boleta_CDF_Manager( $this->settings );
        $xml          = $cdf_manager->generate_cdf_xml( $date );

        if ( ! $xml ) {
            sii_boleta_write_log( 'Fallo al generar el CDF para la fecha ' . $date );
            return;
        }

        $settings = $this->settings->get_settings();
        $env      = $settings['environment'];
        $enviado  = $cdf_manager->send_cdf_to_sii(
            $xml,
            $env,
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );

        $admin_email = get_option( 'admin_email' );
        if ( $enviado ) {
            sii_boleta_write_log( 'CDF enviado correctamente para la fecha ' . $date );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'CDF enviado', 'CDF enviado correctamente para la fecha ' . $date );
            }
        } else {
            sii_boleta_write_log( 'Error al enviar el CDF para la fecha ' . $date );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'Error al enviar CDF', 'Error al enviar el CDF para la fecha ' . $date );
            }
        }
    }
}