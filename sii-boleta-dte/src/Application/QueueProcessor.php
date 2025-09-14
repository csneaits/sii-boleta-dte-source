<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
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
			$result = null;
			if ( 'dte' === $job['type'] ) {
				$result = $this->api->send_dte_to_sii(
					$job['payload']['file'],
					$job['payload']['environment'],
					$job['payload']['token']
				);
			} elseif ( 'libro' === $job['type'] ) {
				$result = $this->api->send_libro_to_sii(
					$job['payload']['xml'],
					$job['payload']['environment'],
					$job['payload']['token']
				);
			}
			if ( is_wp_error( $result ) ) {
				LogDb::add_entry( '', 'error', $result->get_error_message() );
				if ( $job['attempts'] < 3 ) {
					QueueDb::increment_attempts( $job['id'] );
				} else {
					QueueDb::delete( $job['id'] );
				}
			} else {
				LogDb::add_entry( (string) $result, 'sent', '' );
				QueueDb::delete( $job['id'] );
			}
			\call_user_func( $this->sleep, 1 );
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
