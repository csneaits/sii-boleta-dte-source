<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

/**
 * Persistent queue backed by a custom database table.
 *
 * Jobs are arrays with a 'type' key and additional data depending on the
 * operation. Supported types: `dte` and `libro` for sending DTE XML files or
 * Libro/RVD XML strings to SII through the Api class.
 */
class Queue {
        public function __construct() {
                QueueDb::install();
        }

	public function enqueue_dte( string $file, string $environment, string $token ): void {
		QueueDb::enqueue(
			'dte',
			array(
				'file'        => $file,
				'environment' => $environment,
				'token'       => $token,
			)
		);
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

}

class_alias( Queue::class, 'SII_Boleta_Queue' );
