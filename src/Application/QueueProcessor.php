<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;
use Sii\BoletaDte\Infrastructure\Rest\Api;

/**
 * Processes queued jobs, handles retries and logs outcomes.
 */
class QueueProcessor {
	private Api $api;
	/** @var callable */
	private $sleep;

	public function __construct( Api $api, callable $sleep = null ) {
		$this->api   = $api;
		$this->sleep = $sleep ?? 'sleep';
		QueueDb::install();
	}

	/**
	 * Processes jobs. When an ID is provided only that job is executed.
	 */
	public function process( int $id = 0 ): void {
		$jobs = QueueDb::get_pending_jobs();
		if ( $id ) {
			$jobs = array_filter(
				$jobs,
				static fn( array $job ) => $job['id'] === $id
			);
		}
                foreach ( $jobs as $job ) {
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
                                        LogDb::add_entry( '', 'failed', 'Queued XML file is missing.', $environment );
                                        QueueDb::delete( (int) $job['id'] );
                                        continue;
                                } else {
                                        $result = $this->api->send_dte_to_sii(
                                                $file_path,
                                                $environment,
                                                $job['payload']['token']
                                        );
                                }
                        } elseif ( in_array( $job['type'], array( 'libro', 'rvd' ), true ) ) {
                                $result = $this->api->send_libro_to_sii(
                                        $job['payload']['xml'],
                                        $environment,
                                        $job['payload']['token']
                                );
                        } elseif ( 'recibos' === $job['type'] ) {
                                if ( method_exists( $this->api, 'send_recibos_to_sii' ) ) {
                                        $result = $this->api->send_recibos_to_sii(
                                                $job['payload']['xml'],
                                                $environment,
                                                $job['payload']['token']
                                        );
                                } else {
                                        $result = $this->api->send_libro_to_sii(
                                                $job['payload']['xml'],
                                                $environment,
                                                $job['payload']['token']
                                        );
                                }
                        }
                        if ( is_wp_error( $result ) ) {
                                LogDb::add_entry( '', 'error', $result->get_error_message(), $environment );
                                if ( $job['attempts'] < 3 ) {
                                        QueueDb::increment_attempts( $job['id'] );
                                } else {
                                        QueueDb::delete( $job['id'] );
                                }
                        } else {
                                $track = is_array( $result ) ? ( $result['trackId'] ?? '' ) : (string) $result;
                                LogDb::add_entry( $track, 'sent', '', $environment );
                                QueueDb::delete( $job['id'] );
                        }
                }
        }

	/** Resets attempts counter for a job. */
	public function retry( int $id ): void {
		QueueDb::reset_attempts( $id );
	}

	/** Deletes a job from the queue. */
	public function cancel( int $id ): void {
		QueueDb::delete( $id );
	}
}

class_alias( QueueProcessor::class, 'SII_Boleta_Queue_Processor' );
