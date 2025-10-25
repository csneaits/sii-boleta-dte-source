<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Scheduling\Cron;
use Sii\BoletaDte\Application\ConsumoFolios;
use Sii\BoletaDte\Application\FolioManager;

/**
 * Generates and sends the daily sales summary (RVD).
 */
class RvdManager {
    private Settings $settings;
    private Api $api;
    private Queue $queue;

    public function __construct( Settings $settings, ?Api $api = null, ?Queue $queue = null ) {
        $this->settings = $settings;
        $this->api      = $api ?? new Api();
        if ( method_exists( $this->api, 'setSettings' ) ) {
            $this->api->setSettings( $settings );
        }
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
		$summary = $this->get_daily_summary();
		$fecha = $summary['date'] ?? gmdate( 'Y-m-d' );
		
		// Usar la clase ConsumoFolios existente que genera XML válido según el esquema
		$folio_manager = new FolioManager( $this->settings );
		$consumo_folios = new ConsumoFolios( $this->settings, $folio_manager, $this->api );
		
		$xml = $consumo_folios->generate_cdf_xml( $fecha );
		
		// Si no se pudo generar el XML, retornar string vacío
		return is_string( $xml ) ? $xml : '';
	}

	/**
	 * Returns an at-a-glance summary of the data that will be sent in the next RVD.
	 *
	 * @return array{date:string,documents:int,net:int,iva:int,total:int,details:array<int,array<string,mixed>>}
	 */
	public function get_daily_summary(): array {
		$timestamp = $this->current_timestamp();
		$orders    = $this->query_orders_for_day( $timestamp );
		$totals    = $this->summarize_orders( $orders );
		$totals['date'] = $this->format_date( $timestamp, 'Y-m-d' );
		return $totals;
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

	private function summarize_orders( array $orders ): array {
		$documents   = 0;
		$net_total   = 0.0;
		$tax_total   = 0.0;
		$gross_total = 0.0;
		$details     = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
				continue;
			}

			$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
			$gross    = (float) $order->get_total();
			$tax      = method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0;
			$net      = max( 0.0, $gross - $tax );

			$documents++;
			$gross_total += $gross;
			$tax_total   += $tax;
			$net_total   += $net;

			$doc_type = '';
			if ( $order_id > 0 && function_exists( 'get_post_meta' ) ) {
				$doc_type = (string) get_post_meta( $order_id, '_sii_boleta_doc_type', true );
			}

			$created_at = '';
			if ( method_exists( $order, 'get_date_created' ) ) {
				$date = $order->get_date_created();
				if ( $date instanceof \WC_DateTime ) {
					$created_at = function_exists( 'wc_format_datetime' )
						? (string) wc_format_datetime( $date, 'Y-m-d H:i' )
						: $date->date( 'Y-m-d H:i' );
				} elseif ( $date instanceof \DateTimeInterface ) {
					$created_at = $date->format( 'Y-m-d H:i' );
				}
			}

			$customer = '';
			if ( method_exists( $order, 'get_formatted_billing_full_name' ) ) {
				$customer = (string) $order->get_formatted_billing_full_name();
			}
			if ( '' === $customer && method_exists( $order, 'get_formatted_shipping_full_name' ) ) {
				$customer = (string) $order->get_formatted_shipping_full_name();
			}
			if ( '' === $customer && method_exists( $order, 'get_billing_email' ) ) {
				$customer = (string) $order->get_billing_email();
			}

			$details[] = array(
				'id'             => $order_id,
				'number'         => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order_id,
				'status'         => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
				'total'          => $gross,
				'net'            => $net,
				'tax'            => $tax,
				'document_type'  => $doc_type,
				'created_at'     => $created_at,
				'customer'       => $customer,
			);
		}

		return array(
			'documents' => $documents,
			'net'       => (int) round( $net_total ),
			'iva'       => (int) round( $tax_total ),
			'total'     => (int) round( $gross_total ),
			'details'   => $details,
		);
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

	/**
	 * Returns WooCommerce orders matching the current day and configured filters.
	 *
	 * @param int $timestamp Reference timestamp.
	 * @return array<int,mixed>
	 */
	private function query_orders_for_day( int $timestamp ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		list( $start, $end ) = $this->get_day_bounds( $timestamp );

		$statuses = apply_filters(
			'sii_boleta_rvd_order_statuses',
			array( 'completed', 'processing' )
		);
		$doc_types = apply_filters(
			'sii_boleta_rvd_document_types',
			array( '39', '41' )
		);

		$query = array(
			'type'         => 'shop_order',
			'status'       => $statuses,
			'limit'        => -1,
			'return'       => 'objects',
			'date_created' => array(
				'after'     => $start->format( 'Y-m-d H:i:s' ),
				'before'    => $end->format( 'Y-m-d H:i:s' ),
				'inclusive' => true,
			),
		);

		if ( ! empty( $doc_types ) ) {
			$query['meta_query'] = array(
				array(
					'key'     => '_sii_boleta_doc_type',
					'value'   => array_map( 'strval', $doc_types ),
					'compare' => 'IN',
				),
			);
		}

		return wc_get_orders( $query );
	}

	/**
	 * Returns the start and end of the day in the site's timezone.
	 *
	 * @param int $timestamp Reference timestamp.
	 * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
	 */
	private function get_day_bounds( int $timestamp ): array {
		try {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		} catch ( \Throwable $e ) {
			$timezone = new \DateTimeZone( 'UTC' );
		}

		$start = ( new \DateTimeImmutable( '@' . $timestamp ) )
			->setTimezone( $timezone )
			->setTime( 0, 0, 0 );
		$end = $start->setTime( 23, 59, 59 );

		return array( $start, $end );
	}
}

class_alias( RvdManager::class, 'SII_Boleta_RVD_Manager' );
