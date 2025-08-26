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
    const CDF_CRON_HOOK = 'sii_boleta_dte_run_cdf';

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
     * Escribe un mensaje en el log de WooCommerce.
     *
     * @param string $level   Nivel del log (info, error, etc).
     * @param string $message Mensaje a registrar.
     */
    private function log( $level, $message ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $message, [ 'source' => 'sii-boleta' ] );
        } else {
            sii_boleta_write_log( $message );
        }
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
        wp_clear_scheduled_hook( self::CRON_HOOK );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $tz        = new DateTimeZone( 'America/Santiago' );
            $next_run  = new DateTime( 'tomorrow 00:10', $tz );
            $timestamp = $next_run->getTimestamp();
            wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
        }

        wp_clear_scheduled_hook( 'sii_boleta_dte_daily_cdf' );
        if ( ! wp_next_scheduled( self::CDF_CRON_HOOK ) ) {
            $tz       = new DateTimeZone( 'America/Santiago' );
            $next_run = new DateTime( 'today 23:55', $tz );
            if ( $next_run->getTimestamp() <= time() ) {
                $next_run->modify( '+1 day' );
            }
            $timestamp = $next_run->getTimestamp();
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
        wp_clear_scheduled_hook( 'sii_boleta_dte_daily_cdf' );
        wp_clear_scheduled_hook( self::LIBRO_CRON_HOOK );
        wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
    }

    /**
     * Ejecuta el proceso de generación y envío del RVD para una fecha dada.
     * Aplica idempotencia evitando reenviar el RVD de una fecha ya procesada.
     *
     * @param string $date Fecha en formato Y-m-d.
     *
     * @return bool True si se procesó/envió correctamente.
     */
    public function run_rvd_for_date( $date ) {
        $sent_dates = get_option( 'sii_boleta_dte_rvd_sent_dates', [] );
        if ( ! is_array( $sent_dates ) ) {
            $sent_dates = [];
        }
        if ( in_array( $date, $sent_dates, true ) ) {
            $this->log( 'info', 'RVD ya enviado previamente para la fecha ' . $date );
            return true;
        }

        $rvd_manager = new SII_Boleta_RVD_Manager( $this->settings );
        $xml         = $rvd_manager->generate_rvd_xml( $date );
        if ( ! $xml ) {
            $this->log( 'error', 'Fallo al generar el RVD para la fecha ' . $date );
            return false;
        }
        $settings  = $this->settings->get_settings();
        $env       = $settings['environment'];
        $token     = $settings['api_token'] ?? '';
        $cert_path = $settings['cert_path'] ?? '';
        $cert_pass = $settings['cert_pass'] ?? '';

        $attempts = 0;
        $max_attempts = 3;
        $delay = 1;
        $enviado = false;
        while ( ! $enviado && $attempts < $max_attempts ) {
            $attempts++;
            $enviado = $rvd_manager->send_rvd_to_sii( $xml, $env, $token, $cert_path, $cert_pass );
            if ( ! $enviado && $attempts < $max_attempts ) {
                $token = '';
                sleep( $delay );
                $delay *= 2;
            }
        }
        $admin_email = get_option( 'admin_email' );
        if ( $enviado ) {
            $sent_dates[] = $date;
            $sent_dates   = array_values( array_unique( $sent_dates ) );
            update_option( 'sii_boleta_dte_rvd_sent_dates', $sent_dates );
            $this->log( 'info', 'RVD enviado correctamente para la fecha ' . $date );
            if ( $admin_email ) {
                wp_mail( $admin_email, 'RVD enviado', 'RVD enviado correctamente para la fecha ' . $date );
            }
            return true;
        }

        $this->log( 'error', 'Error al enviar el RVD para la fecha ' . $date );
        if ( $admin_email ) {
            wp_mail( $admin_email, 'Error al enviar RVD', 'Error al enviar el RVD para la fecha ' . $date );
        }
        return false;
    }

    /**
     * Callback que genera el RVD para el día anterior y lo envía al SII.
     */
    public function generate_and_send_rvd() {
        $tz   = new DateTimeZone( 'America/Santiago' );
        $date = ( new DateTime( 'yesterday', $tz ) )->format( 'Y-m-d' );
        $this->run_rvd_for_date( $date );
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
     * Callback que genera el CDF para el día en curso y lo envía al SII.
     */
    public function generate_and_send_cdf() {
        $tz          = new DateTimeZone( 'America/Santiago' );
        $date        = ( new DateTime( 'today', $tz ) )->format( 'Y-m-d' );
        $folio_mgr   = new SII_Boleta_Folio_Manager( $this->settings );
        $api         = new SII_Boleta_API();
        $cdf_manager = new SII_Boleta_Consumo_Folios( $this->settings, $folio_mgr, $api );
        $xml         = $cdf_manager->generate_cdf_xml( $date );

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
    /**
     * Comando WP-CLI: wp sii:rvd --date=YYYY-MM-DD.
     *
     * @param array $args       Argumentos posicionados.
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function cli_rvd_command( $args, $assoc_args ) {
        $date = $assoc_args['date'] ?? '';
        if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            WP_CLI::error( 'Debe indicar la fecha en formato YYYY-MM-DD mediante --date.' );
        }
        $settings = new SII_Boleta_Settings();
        $cron     = new self( $settings );
        if ( $cron->run_rvd_for_date( $date ) ) {
            WP_CLI::success( 'RVD procesado para la fecha ' . $date );
        } else {
            WP_CLI::error( 'Error al procesar el RVD para la fecha ' . $date );
        }
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'sii rvd', [ 'SII_Boleta_Cron', 'cli_rvd_command' ] );
}
