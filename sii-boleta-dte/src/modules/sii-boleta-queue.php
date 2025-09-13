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
     * Instancias para reutilizar motor y configuraciones.
     *
     * @var SII_DTE_Engine
     * @var SII_Boleta_Settings
     */
    private $engine;
    private $settings;

    /**
     * Constructor. Registra el callback del evento.
     *
     * @param SII_Boleta_API      $api       Instancia de la API del SII.
     * @param SII_Boleta_Settings $settings  Instancia de configuraciones.
     */
    public function __construct( SII_DTE_Engine $engine, SII_Boleta_Settings $settings ) {
        $this->engine   = $engine;
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
        $track_id = $this->engine->send_dte_file(
            $file_path,
            $settings['environment'] ?? 'test',
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );

        if ( is_wp_error( $track_id ) || ! $track_id ) {
            $message = is_wp_error( $track_id ) ? $track_id->get_error_message() : __( 'No se obtuvo TrackID del SII.', 'sii-boleta-dte' );
            sii_boleta_write_log( 'Fallo envío DTE: ' . $message, 'ERROR' );
            if ( class_exists( 'SII_Boleta_Log_DB' ) ) {
                SII_Boleta_Log_DB::add_entry( '', 'error', $message );
            }
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

        // Envío OK: consumir folio y mover XML a carpeta definitiva por RUT
        try {
            $xml = @simplexml_load_file( $file_path );
            if ( $xml && isset( $xml->Documento->Encabezado->IdDoc->TipoDTE ) ) {
                $tipo  = intval( (string) $xml->Documento->Encabezado->IdDoc->TipoDTE );
                $folio = intval( (string) $xml->Documento->Encabezado->IdDoc->Folio );
                $rut   = (string) $xml->Documento->Encabezado->Receptor->RUTRecep;
                // Consumir folio si coincide
                if ( class_exists( 'SII_Boleta_Folio_Manager' ) ) {
                    $fm = new SII_Boleta_Folio_Manager( $this->settings );
                    $fm->consume_folio( $tipo, $folio );
                }
                // Mover a uploads/dte/<RUT>/ y construir URLs
                $uploads   = wp_upload_dir();
                $rutFolder = strtoupper( preg_replace( '/[^0-9Kk-]/', '', $rut ?: 'SIN-RUT' ) );
                $destDir   = trailingslashit( $uploads['basedir'] ) . 'dte/' . $rutFolder . '/';
                if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $destDir ); } else { if ( ! is_dir( $destDir ) ) { @mkdir( $destDir, 0755, true ); } }
                $dest = trailingslashit( $destDir ) . basename( $file_path );
                if ( @rename( $file_path, $dest ) ) {
                    $file_path = $dest;
                }
                // Generar PDF y opcionalmente enviar por correo si hay pedido
                $pdf = $this->engine->render_pdf( file_get_contents( $file_path ), $settings );
                if ( $order_id ) {
                    $order = wc_get_order( $order_id );
                    if ( $order ) {
                        $order->update_meta_data( '_sii_boleta_track_id', (string) $track_id );
                        $order->update_meta_data( '_sii_boleta_folio', $folio );
                        $order->save();
                        // Nota con enlaces
                        $xml_url = str_replace( $uploads['basedir'], $uploads['baseurl'], $file_path );
                        $note = sprintf( __( 'DTE enviado. TrackID: %s', 'sii-boleta-dte' ), (string) $track_id );
                        if ( $xml_url ) {
                            $note .= ' | <a href="' . esc_url( $xml_url ) . '" target="_blank" rel="noopener">' . __( 'XML', 'sii-boleta-dte' ) . '</a>';
                        }
                        if ( $pdf && file_exists( $pdf ) ) {
                            $pdf_url = str_replace( $uploads['basedir'], $uploads['baseurl'], $pdf );
                            $note .= ' | <a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener">' . __( 'PDF', 'sii-boleta-dte' ) . '</a>';
                        }
                        $order->add_order_note( wp_kses_post( $note ) );
                        // Enviar correo con PDF si existe mail
                        if ( $pdf && $order->get_billing_email() ) {
                            $to       = $order->get_billing_email();
                            $subject  = sprintf( __( 'DTE de tu pedido #%s', 'sii-boleta-dte' ), $order->get_order_number() );
                            $message  = '<html><body><p>' . __( 'Adjuntamos la representación de tu DTE.', 'sii-boleta-dte' ) . '</p></body></html>';
                            $content_type = function(){ return 'text/html'; };
                            add_filter( 'wp_mail_content_type', $content_type );
                            $profile = $settings['smtp_profile'] ?? '';
                            $configure_mailer = function( $phpmailer ) use ( $profile ) { do_action( 'sii_boleta_setup_mailer', $phpmailer, $profile ); };
                            add_action( 'phpmailer_init', $configure_mailer );
                            wp_mail( $to, $subject, $message, [], [ $pdf ] );
                            remove_action( 'phpmailer_init', $configure_mailer );
                            remove_filter( 'wp_mail_content_type', $content_type );
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Ignorar fallos de post-proceso
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
