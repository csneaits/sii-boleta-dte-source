<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;
use Sii\BoletaDte\Infrastructure\Rest\Api;

/**
 * Processes queued jobs, handles retries and logs outcomes.
 * 
 * COMPORTAMIENTO DE REINTENTOS:
 * - Intento 1-3: Se reintenta automáticamente con un delay de 120 segundos
 * - Después de 3 intentos fallidos: El trabajo NO se elimina, permanece en la cola
 *   con estado "fallido" para que el administrador decida manualmente qué hacer
 *   (procesarlo de nuevo, reintentar, o cancelar)
 */
class QueueProcessor {
        private const RETRY_DELAY_SECONDS = 120;
        private Api $api;
        private ?FolioManager $folio_manager;
        /** @var callable */
        private $sleep;

	public function __construct( Api $api, ?callable $sleep = null, ?FolioManager $folio_manager = null ) {
		$this->api   = $api;
		$this->folio_manager = $folio_manager;
		$this->sleep = $sleep ?? 'sleep';
		QueueDb::install();
	}

	/**
	 * Processes jobs. When an ID is provided only that job is executed.
	 * 
	 * @param int $id Si se proporciona, procesa solo ese trabajo específico (incluso si tiene 3+ intentos).
	 *                Si es 0, procesa automáticamente solo trabajos con menos de 3 intentos.
	 */
	public function process( int $id = 0 ): void {
		// Use a transient to lock the process and avoid race conditions.
		$lock_key = 'sii_dte_queue_lock';
		if ( ! defined( 'WP_CLI' ) && function_exists( 'get_transient' ) ) {
			if ( get_transient( $lock_key ) ) {
				return; // Another process is already running.
			}
			set_transient( $lock_key, true, 300 ); // Lock for 5 minutes.
		}

		try {
			$jobs = QueueDb::get_pending_jobs();
			
			if ( $id ) {
				// Procesamiento manual: procesa el trabajo específico independientemente de los intentos
				$jobs = array_filter(
					$jobs,
					static fn( array $job ) => $job['id'] === $id
				);
			} else {
				// Procesamiento automático (cron): excluye trabajos con 3+ intentos fallidos
				$jobs = array_filter(
					$jobs,
					static fn( array $job ) => ( (int) ( $job['attempts'] ?? 0 ) ) < 3
				);
			}
					foreach ( $jobs as $job ) {
							$meta = array();
							if ( isset( $job['payload']['meta'] ) && is_array( $job['payload']['meta'] ) ) {
									$meta = $job['payload']['meta'];
							} else {
									if ( isset( $job['payload']['document_type'] ) ) {
											$meta['type'] = (int) $job['payload']['document_type'];
									}
									if ( isset( $job['payload']['folio'] ) ) {
											$meta['folio'] = (int) $job['payload']['folio'];
									}
							}
							$environment = isset( $job['payload']['environment'] ) ? (string) $job['payload']['environment'] : '0';
							$result = null;
							if ( 'dte' === $job['type'] ) {
									$file_path = isset( $job['payload']['file'] ) ? (string) $job['payload']['file'] : '';
									if ( isset( $job['payload']['file_key'] ) ) {
											$resolved = XmlStorage::resolve_path( (string) $job['payload']['file_key'] );
											if ( '' !== $resolved ) {
													$file_path = $resolved;
											}
									}

									if ( '' === $file_path || ! file_exists( $file_path ) ) {
											// Permanent error: file missing. Mark as failed and remove without retrying.
											LogDb::add_entry( '', 'failed', 'Queued XML file is missing.', $environment, $meta );
											QueueDb::delete( (int) $job['id'] );
											continue;
									} else {
											$result = $this->api->send_dte_to_sii(
													$file_path,
													$environment,
													$job['payload']['token']
											);
									}
			    } elseif ( 'recibos' === $job['type'] ) {
									if ( method_exists( $this->api, 'send_recibos_to_sii' ) ) {
											$result = $this->api->send_recibos_to_sii(
													$job['payload']['xml'],
													$environment,
													$job['payload']['token']
											);
									} else {
											$result = new \WP_Error( 'sii_boleta_recibos_not_supported', 'Recibos sending not supported in current API implementation.' );
									}
							}
							if ( is_wp_error( $result ) ) {
									LogDb::add_entry( '', 'error', $result->get_error_message(), $environment, $meta );
									if ( $job['attempts'] < 3 ) {
											QueueDb::increment_attempts( $job['id'] );
											QueueDb::schedule_retry( $job['id'], self::RETRY_DELAY_SECONDS );
									} else {
											// Después de 3 intentos fallidos, incrementar el contador pero NO eliminar.
											// El trabajo permanece en la cola para que el usuario decida manualmente qué hacer.
											QueueDb::increment_attempts( $job['id'] );
											// No se programa reintento automático, pero tampoco se elimina.
									}
							} else {
									$track = is_array( $result ) ? ( $result['trackId'] ?? '' ) : (string) $result;
									LogDb::add_entry( $track, 'sent', '', $environment, $meta );

									// CONSUMIR el folio ahora que el envío fue exitoso
									if ( $this->folio_manager 
									     && isset( $meta['type'] ) 
									     && isset( $meta['folio'] ) 
									     && $meta['folio'] > 0 ) {
										$this->folio_manager->mark_folio_used( (int) $meta['type'], (int) $meta['folio'] );
									}

									// Generar y enviar el PDF al cliente solo si el envío fue exitoso
									// Se requiere order_id y datos mínimos
									if ( isset( $meta['order_id'] ) && $meta['order_id'] > 0 && isset( $meta['type'] ) ) {
											// Obtener el XML desde el archivo encolado
											$file_path = isset( $job['payload']['file'] ) ? (string) $job['payload']['file'] : '';
											if ( isset( $job['payload']['file_key'] ) ) {
													$resolved = \Sii\BoletaDte\Infrastructure\Queue\XmlStorage::resolve_path( (string) $job['payload']['file_key'] );
													if ( '' !== $resolved ) {
															$file_path = $resolved;
													}
											}
											if ( $file_path && file_exists( $file_path ) ) {
													$xml = file_get_contents( $file_path );
													if ( $xml ) {
															// Instanciar PDFGenerator y Plugin
															$plugin = \Sii\BoletaDte\Infrastructure\Factory\Container::get(\Sii\BoletaDte\Infrastructure\WordPress\Plugin::class);
															$pdf_generator = $plugin->get_pdf_generator();
															$pdf = $pdf_generator->generate( $xml );
															if ( is_string( $pdf ) && '' !== $pdf ) {
																	$order_id = (int) $meta['order_id'];
																	$order = null;
																	if ( function_exists('wc_get_order') ) {
																			$order = wc_get_order( $order_id );
																	}
																	if ( $order ) {
																			$stored_pdf = $plugin->persist_pdf_for_order( $pdf, $order, (int)$meta['type'], $order_id );
																			$pdf_path   = $stored_pdf['path'] ?? $pdf;
																			$pdf_key    = $stored_pdf['key'] ?? '';
																			$pdf_nonce  = $stored_pdf['nonce'] ?? '';
																			$meta_prefix = '_sii_boleta';
																			if ( '' !== $pdf_key && '' !== $pdf_nonce ) {
																					$plugin->update_order_meta( $order_id, $meta_prefix . '_pdf_key', $pdf_key );
																					$plugin->update_order_meta( $order_id, $meta_prefix . '_pdf_nonce', $pdf_nonce );
																			}
																			$plugin->clear_legacy_pdf_meta( $order_id, $meta_prefix );
																			$download_link = $plugin->build_pdf_download_link( $order_id, $meta_prefix, $pdf_key, $pdf_nonce );
																			$plugin->send_document_email( $order, $pdf_path, (int)$meta['type'], false, $download_link );
																	}
															}
													}
											}
									}

									QueueDb::delete( $job['id'] );
							}
					}
		} finally {
			if ( ! defined( 'WP_CLI' ) && function_exists( 'delete_transient' ) ) {
				delete_transient( $lock_key );
			}
		}
	}

	/** Resets attempts counter for a job. */
	public function retry( int $id ): void {
		QueueDb::reset_attempts( $id );
	}

	/** Deletes a job from the queue. */
	public function cancel( int $id ): void {
		$job = QueueDb::find( $id );
		QueueDb::delete( $id );
		if ( ! is_array( $job ) || ( $job['type'] ?? '' ) !== 'dte' ) {
			return;
		}
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$meta    = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
		if ( isset( $payload['document_type'] ) && ! isset( $meta['type'] ) ) {
			$meta['type'] = (int) $payload['document_type'];
		}
		if ( isset( $payload['folio'] ) && ! isset( $meta['folio'] ) ) {
			$meta['folio'] = (int) $payload['folio'];
		}
		$environment = isset( $payload['environment'] ) ? (string) $payload['environment'] : '0';
		$message     = \__( 'Trabajo cancelado manualmente desde el panel de control.', 'sii-boleta-dte' );
		LogDb::add_entry( 'QJOB-' . (string) $id, 'cancelled', $message, $environment, $meta );
	}

	/** Retries all failed jobs by resetting their attempts counter. */
	public function retry_all_failed(): int {
		return QueueDb::retry_all_failed();
	}

	/** Gets queue statistics for monitoring. */
	public function get_stats(): array {
		return QueueDb::get_stats();
	}
}

class_alias( QueueProcessor::class, 'SII_Boleta_Queue_Processor' );
