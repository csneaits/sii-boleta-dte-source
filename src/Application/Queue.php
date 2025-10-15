<?php
declare(strict_types=1);
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;

/**
 * Persistent queue backed by a custom database table.
 *
 * Jobs are arrays with a 'type' key and additional data depending on the
 * operation. Supported types: `dte`, `recibos`, etc.
 */
class Queue {
        public function __construct() {
                QueueDb::install();
        }

	public function enqueue_dte( string $file, string $environment, string $token, string $storage_key = '', array $metadata = array() ): void {
		if ( '' === $storage_key ) {
			$stored = XmlStorage::store( $file );
			if ( ! empty( $stored['path'] ) ) {
				$file = $stored['path'];
			}
			if ( ! empty( $stored['key'] ) ) {
				$storage_key = (string) $stored['key'];
			}
		}

		$payload = array(
			'file'        => $file,
			'environment' => $environment,
			'token'       => $token,
		);

		if ( '' !== $storage_key ) {
			$payload['file_key'] = $storage_key;
		}

		if ( ! empty( $metadata ) ) {
			$payload['meta'] = $metadata;
			if ( isset( $metadata['type'] ) ) {
				$payload['document_type'] = (int) $metadata['type'];
			}
			if ( isset( $metadata['folio'] ) ) {
				$payload['folio'] = (int) $metadata['folio'];
			}
			if ( isset( $metadata['label'] ) ) {
				$payload['label'] = (string) $metadata['label'];
			}
		}

		// Encolar y registrar (si corresponde) para que aparezca en "DTE recientes".
		$job_id = QueueDb::enqueue( 'dte', $payload );

		// Evitar duplicados si el origen ya escribe su propio log.
		$source = isset( $metadata['source'] ) ? (string) $metadata['source'] : '';
		if ( ! in_array( $source, array( 'manual_generator', 'woocommerce' ), true ) ) {
			$log_message = __( 'Documento encolado para su procesamiento.', 'sii-boleta-dte' );
			$meta        = is_array( $metadata ) ? $metadata : array();
			if ( isset( $payload['document_type'] ) && ! isset( $meta['type'] ) ) {
				$meta['type'] = (int) $payload['document_type'];
			}
			if ( isset( $payload['folio'] ) && ! isset( $meta['folio'] ) ) {
				$meta['folio'] = (int) $payload['folio'];
			}
			LogDb::add_entry( 'QJOB-' . (string) $job_id, 'queued', $log_message, $environment, $meta );
		}
	}

        /** Enqueue an EnvioRecibos XML to be sent to SII. */
        public function enqueue_recibos( string $xml, string $environment, string $token ): void {
                QueueDb::enqueue(
                        'recibos',
                        array(
                                'xml'         => $xml,
                                'environment' => $environment,
                                'token'       => $token,
                        )
                );
        }

}

class_alias( Queue::class, 'SII_Boleta_Queue' );
