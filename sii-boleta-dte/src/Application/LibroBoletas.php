<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Signer;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Application\FolioManager;

/**
 * Generates and validates Libro de Boletas XML.
 */
class LibroBoletas {
        private Settings $settings;
        private Api $api;
        private Signer $signer;
        private Queue $queue;
        private FolioManager $folio_manager;

        public function __construct( Settings $settings, Api $api = null, Queue $queue = null, FolioManager $folio_manager = null ) {
                $this->settings      = $settings;
                $this->api           = $api ?? new Api();
                $this->queue         = $queue ?? new Queue();
                $this->signer        = new Signer();
                $this->folio_manager = $folio_manager ?? new FolioManager( $settings );
                if ( function_exists( 'add_action' ) ) {
                        add_action( \Sii\BoletaDte\Infrastructure\Cron::HOOK, array( $this, 'maybe_run' ) );
                }
        }

        /**
         * Validates Libro XML against schema.
         */
	public function validate_libro_xml( string $xml ): bool {
		$doc = new \DOMDocument();
		if ( ! $doc->loadXML( $xml ) ) {
			return false;
		}
		libxml_use_internal_errors( true );
		$xsd   = SII_BOLETA_DTE_PATH . 'resources/xml/schemas/libro_boletas.xsd';
        $valid = $doc->schemaValidate( $xsd );
        libxml_clear_errors();
        return $valid;
    }

    /** Triggers the monthly Libro sending when the schedule is due. */
    public function maybe_run(): void {
        $config = $this->settings->get_settings();
        if ( empty( $config['libro_auto_enabled'] ) ) {
            return;
        }

        $environment = $this->settings->get_environment();
        $now         = $this->current_timestamp();
        $month_key   = $this->format_date( $now, 'Y-m' );

        $day = isset( $config['libro_auto_day'] ) ? (int) $config['libro_auto_day'] : 1;
        if ( $day < 1 ) {
            $day = 1;
        }
        if ( $day > 31 ) {
            $day = 31;
        }

        $time_string = isset( $config['libro_auto_time'] ) ? (string) $config['libro_auto_time'] : '03:00';
        if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time_string ) ) {
            $time_string = '03:00';
        }

        $target = $this->timestamp_for_month_day_time( $day, $time_string, $now );
        if ( $now < $target ) {
            return;
        }

        $last_run = Settings::get_schedule_last_run( 'libro', $environment );
        if ( $last_run === $month_key ) {
            return;
        }

        $period = $this->previous_month_period( $now );
        $xml    = $this->generate_monthly_xml( $period );
        if ( '' === $xml || ! $this->validate_libro_xml( $xml ) ) {
            return;
        }

        $token = $this->api->generate_token( $environment, '', '' );
        if ( '' === $token ) {
            return;
        }

        $this->queue->enqueue_libro( $xml, $environment, $token );
        Settings::update_schedule_last_run( 'libro', $environment, $month_key );
    }

    /**
     * Builds a minimal Libro XML for the provided period (YYYY-MM).
     */
    public function generate_monthly_xml( string $period ): string {
        $period = trim( $period );
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
            return '';
        }

        $rut = $this->settings->get_settings()['rut_emisor'] ?? '';
        if ( '' === $rut ) {
            return '';
        }

        list( $year, $month ) = array_map( 'intval', explode( '-', $period ) );
        $timezone = $this->get_timezone();
        $start    = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );
        $end      = $start->modify( 'last day of this month 23:59:59' );

        $orders = $this->collect_orders( $start, $end );

        $total_amount = 0.0;
        $count        = 0;
        foreach ( $orders as $order ) {
            $total_amount += $order['total'];
            $count++;
        }

        $total_amount = max( 0, $total_amount );
        $net_amount   = round( $total_amount / 1.19 );
        $iva_amount   = max( 0, $total_amount - $net_amount );

        $caf_info = $this->folio_manager->get_caf_info( 39 );
        $fch_resol = $caf_info['FchResol'] ?? '';
        $nro_resol = $caf_info['NroResol'] ?? '';
        if ( '' === $fch_resol ) {
            $fch_resol = $this->format_date( $this->current_timestamp(), 'Y-m-d' );
        }
        if ( '' === $nro_resol ) {
            $nro_resol = '0';
        }

        $doc = new \DOMDocument( '1.0', 'UTF-8' );
        $doc->formatOutput = false;
        $libro = $doc->createElementNS( 'http://www.sii.cl/SiiDte', 'LibroBoleta' );
        $libro->setAttribute( 'version', '1.0' );
        $libro->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#' );
        $doc->appendChild( $libro );

        $envio = $doc->createElement( 'EnvioLibro' );
        $envio->setAttribute( 'ID', 'EnvioLibro' );
        $libro->appendChild( $envio );

        $caratula = $doc->createElement( 'Caratula' );
        $envio->appendChild( $caratula );
        $this->append_text_node( $doc, $caratula, 'RutEmisorLibro', $rut );
        $this->append_text_node( $doc, $caratula, 'RutEnvia', $rut );
        $this->append_text_node( $doc, $caratula, 'PeriodoTributario', $period );
        $this->append_text_node( $doc, $caratula, 'FchResol', $fch_resol );
        $this->append_text_node( $doc, $caratula, 'NroResol', (string) $nro_resol );
        $this->append_text_node( $doc, $caratula, 'TipoLibro', 'ESPECIAL' );
        $this->append_text_node( $doc, $caratula, 'TipoEnvio', 'TOTAL' );

        $resumen = $doc->createElement( 'ResumenSegmento' );
        $envio->appendChild( $resumen );
        $segmento = $doc->createElement( 'TotalesSegmento' );
        $resumen->appendChild( $segmento );
        $this->append_text_node( $doc, $segmento, 'TpoDoc', '39' );

        $totales_servicio = $doc->createElement( 'TotalesServicio' );
        $segmento->appendChild( $totales_servicio );
        $this->append_text_node( $doc, $totales_servicio, 'TpoServ', '3' );
        $this->append_text_node( $doc, $totales_servicio, 'TotDoc', (string) $count );
        $this->append_text_node( $doc, $totales_servicio, 'TotMntNeto', (string) (int) round( $net_amount ) );
        $this->append_text_node( $doc, $totales_servicio, 'TasaIVA', '19.00' );
        $this->append_text_node( $doc, $totales_servicio, 'TotMntIVA', (string) (int) round( $iva_amount ) );
        $this->append_text_node( $doc, $totales_servicio, 'TotMntTotal', (string) (int) round( $total_amount ) );

        $timestamp = $this->format_date( $this->current_timestamp(), 'Y-m-d\TH:i:s' );
        $this->append_text_node( $doc, $envio, 'TmstFirma', $timestamp );

        return $doc->saveXML() ?: '';
    }

    /**
     * @return array<int,array{total:float}>
     */
    private function collect_orders( \DateTimeImmutable $start, \DateTimeImmutable $end ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $args = array(
            'status'       => array( 'completed', 'processing' ),
            'limit'        => -1,
            'date_created' => array(
                'after'  => $start->format( 'Y-m-d H:i:s' ),
                'before' => $end->format( 'Y-m-d H:i:s' ),
            ),
        );

        $orders = wc_get_orders( $args );
        $out    = array();
        foreach ( (array) $orders as $order ) {
            if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
                continue;
            }
            $out[] = array(
                'total' => (float) $order->get_total(),
            );
        }
        return $out;
    }

    private function append_text_node( \DOMDocument $doc, \DOMElement $parent, string $name, string $value ): void {
        $node = $doc->createElement( $name );
        $node->appendChild( $doc->createTextNode( $value ) );
        $parent->appendChild( $node );
    }

    private function current_timestamp(): int {
        if ( function_exists( 'current_time' ) ) {
            return (int) current_time( 'timestamp' );
        }
        return time();
    }

    private function get_timezone(): \DateTimeZone {
        try {
            if ( function_exists( 'wp_timezone' ) ) {
                return wp_timezone();
            }
        } catch ( \Throwable $e ) {
            // Fallback below.
        }
        return new \DateTimeZone( 'UTC' );
    }

    private function timestamp_for_month_day_time( int $day, string $time, int $reference ): int {
        $timezone = $this->get_timezone();
        $date     = new \DateTimeImmutable( '@' . $reference );
        $date     = $date->setTimezone( $timezone );

        $year  = (int) $date->format( 'Y' );
        $month = (int) $date->format( 'm' );

        $candidate = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );
        $days_in_month = (int) $candidate->format( 't' );
        $day = min( max( 1, $day ), $days_in_month );

        list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
        $candidate = $candidate->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 );

        return $candidate->getTimestamp();
    }

    private function previous_month_period( int $timestamp ): string {
        $timezone = $this->get_timezone();
        $date     = new \DateTimeImmutable( '@' . $timestamp );
        $date     = $date->setTimezone( $timezone )->modify( 'first day of last month' );
        return $date->format( 'Y-m' );
    }
}

class_alias( LibroBoletas::class, 'SII_Libro_Boletas' );
