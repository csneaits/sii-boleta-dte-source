<?php
declare(strict_types=1);
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;

/**
 * Persistent queue backed by a custom database table.
 *
 * Jobs are arrays with a 'type' key and additional data depending on the
 * operation. Supported types: `dte`, `libro` and `rvd` for sending DTE XML
 * files or Libro/RVD XML strings to SII through the Api class.
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

		QueueDb::enqueue( 'dte', $payload );
	}

        public function enqueue_libro( string $xml, string $environment, string $token ): void {
                QueueDb::enqueue(
                        'libro',
                        array(
                                'xml'         => $xml,
                                'environment' => $environment,
                                'token'       => $token,
                        )
                );
        }

        public function enqueue_rvd( string $xml, string $environment, string $token ): void {
                QueueDb::enqueue(
                        'rvd',
                        array(
                                'xml'         => $xml,
                                'environment' => $environment,
                                'token'       => $token,
                        )
                );
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
