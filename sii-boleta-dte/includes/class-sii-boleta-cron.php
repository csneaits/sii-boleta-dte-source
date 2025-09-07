<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Maneja las tareas programadas del plugin (WP-Cron). Esta clase
 * registra un evento cada 12 horas para generar y enviar el Resumen de Ventas
 * Diarias (RVD) automáticamente, además de un evento diario para el Consumo de
 * Folios (CDF). También ofrece funciones de activación y desactivación para
 * registrar o eliminar dichos eventos.
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
     * Evento cron diario para el envío del CDF.
     */
    const CDF_CRON_HOOK = 'sii_boleta_dte_run_cdf';

    /**
     * Evento cron para verificar el estado de envíos pendientes (Track IDs).
     */
    const CHECK_STATUS_CRON_HOOK = 'sii_boleta_dte_check_status';

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
        add_action( self::CDF_CRON_HOOK, [ $this, 'generate_and_send_cdf' ] );
        add_action( self::CHECK_STATUS_CRON_HOOK, [ $this, 'check_pending_statuses' ] );
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
     * Programa los eventos cron al activar el plugin.
     *
     * Se ejecutan diariamente para el RVD y CDF.
     * Si ya existe un evento programado, no vuelve a programarlo.
     */
    public static function activate() {
        // Limpiar eventos antiguos que podían causar errores.
        wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::CHECK_STATUS_CRON_HOOK );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $tz       = new DateTimeZone( 'America/Santiago' );
            $next_run = new DateTime( 'today 00:10', $tz );
            while ( $next_run->getTimestamp() <= time() ) {
                $next_run->modify( '+12 hours' );
            }
            wp_schedule_event( $next_run->getTimestamp(), 'twicedaily', self::CRON_HOOK );
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

        // Programar verificación de estados (cada hora)
        if ( ! wp_next_scheduled( self::CHECK_STATUS_CRON_HOOK ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CHECK_STATUS_CRON_HOOK );
        }

    }

    /**
     * Elimina el evento programado al desactivar el plugin.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::CDF_CRON_HOOK );
        wp_clear_scheduled_hook( 'sii_boleta_dte_daily_cdf' );
        wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
        wp_clear_scheduled_hook( self::CHECK_STATUS_CRON_HOOK );
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
     * Callback que genera el CDF para el día en curso y lo envía al SII.
     */
    public function generate_and_send_cdf() {
        $tz   = new DateTimeZone( 'America/Santiago' );
        $date = ( new DateTime( 'today', $tz ) )->format( 'Y-m-d' );
        $this->run_cdf_for_date( $date );
    }

    /**
     * Ejecuta el proceso de generación y envío del CDF para una fecha dada.
     *
     * @param string $date Fecha en formato Y-m-d.
     * @return bool True si se envió correctamente.
     */
    public function run_cdf_for_date( $date ) {
        $folio_mgr   = new SII_Boleta_Folio_Manager( $this->settings );
        $api         = new SII_Boleta_API();
        $cdf_manager = new SII_Boleta_Consumo_Folios( $this->settings, $folio_mgr, $api );
        $xml         = $cdf_manager->generate_cdf_xml( $date );

        if ( ! $xml ) {
            sii_boleta_write_log( 'Fallo al generar el CDF para la fecha ' . $date );
            return false;
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
            return true;
        }

        sii_boleta_write_log( 'Error al enviar el CDF para la fecha ' . $date );
        if ( $admin_email ) {
            wp_mail( $admin_email, 'Error al enviar CDF', 'Error al enviar el CDF para la fecha ' . $date );
        }
        return false;
    }

    /**
     * Verifica el estado de Track IDs pendientes usando LibreDTE y guarda el resultado en la tabla de logs.
     */
    public function check_pending_statuses() {
        if ( ! class_exists( '\\libredte\\lib\\Core\\Application' ) || ! class_exists( 'SII_Boleta_Log_DB' ) ) {
            return;
        }
        $pending = SII_Boleta_Log_DB::get_pending_track_ids( 50 );
        if ( empty( $pending ) ) {
            return;
        }

        try {
            $app = \libredte\lib\Core\Application::getInstance( 'prod', false );
            /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
            $billing = $app->getPackageRegistry()->getPackage( 'billing' );
            $integration = $billing->getIntegrationComponent();
            $loader = new \Derafu\Certificate\Service\CertificateLoader();
            $opts   = $this->settings->get_settings();
            $certificate = $loader->loadFromFile( $opts['cert_path'] ?? '', $opts['cert_pass'] ?? '' );
            $ambiente = ( 'production' === strtolower( (string) ( $opts['environment'] ?? 'test' ) ) )
                ? \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::PRODUCCION
                : \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::CERTIFICACION;
            $request = new \libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest( $certificate, [ 'ambiente' => $ambiente ] );
            $company = (string) ( $opts['rut_emisor'] ?? '' );

            foreach ( $pending as $track_id ) {
                try {
                    $resp = $integration->getSiiLazyWorker()->checkXmlDocumentSentStatus( $request, intval( $track_id ), $company );
                    $statusText = method_exists( $resp, 'getReviewStatus' ) ? $resp->getReviewStatus() : 'checked';
                    $payload = json_encode( $resp, JSON_UNESCAPED_UNICODE );
                    SII_Boleta_Log_DB::add_entry( (string) $track_id, $statusText, $payload ?: '' );
                } catch ( \Throwable $e ) {
                    SII_Boleta_Log_DB::add_entry( (string) $track_id, 'check_error', $e->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            // Silencioso para no romper cron
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

    /**
     * Comando WP-CLI: wp sii:cdf [--date=YYYY-MM-DD].
     *
     * @param array $args       Argumentos posicionados.
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function cli_cdf_command( $args, $assoc_args ) {
        $date = $assoc_args['date'] ?? date( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            WP_CLI::error( 'Debe indicar la fecha en formato YYYY-MM-DD mediante --date.' );
        }
        $settings = new SII_Boleta_Settings();
        $cron     = new self( $settings );
        if ( $cron->run_cdf_for_date( $date ) ) {
            WP_CLI::success( 'CDF procesado para la fecha ' . $date );
        } else {
            WP_CLI::error( 'Error al procesar el CDF para la fecha ' . $date );
        }
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'sii rvd', [ 'SII_Boleta_Cron', 'cli_rvd_command' ] );
    WP_CLI::add_command( 'sii cdf', [ 'SII_Boleta_Cron', 'cli_cdf_command' ] );
}
