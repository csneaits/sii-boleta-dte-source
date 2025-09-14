<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Cron;

/**
 * Persistent queue backed by a custom database table.
 *
 * Jobs are arrays with a 'type' key and additional data depending on the
 * operation. Supported types: `dte` and `libro` for sending DTE XML files or
 * Libro/RVD XML strings to SII through the Api class.
 */
class Queue {
	private DteEngine $engine;
	private Settings $settings;
	private Api $api;
	/** @var callable */
	private $sleep;

	public function __construct( DteEngine $engine, Settings $settings, Api $api, callable $sleep = null ) {
		$this->engine   = $engine;
		$this->settings = $settings;
		$this->api      = $api;
		$this->sleep    = $sleep ?? 'sleep';
		QueueDb::install();
		if ( function_exists( 'add_action' ) ) {
			add_action( Cron::HOOK, array( $this, 'process' ) );
		}
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

	/**
	 * Processes queued jobs sequentially using the Api class.
	 * Retries a maximum of three times before discarding the job.
	 */
	public function process(): void {
		$jobs = QueueDb::get_pending_jobs();
		foreach ( $jobs as $job ) {
			$result = null;
			if ( 'dte' === $job['type'] ) {
				$result = $this->api->send_dte_to_sii( $job['payload']['file'], $job['payload']['environment'], $job['payload']['token'] );
			} elseif ( 'libro' === $job['type'] ) {
				$result = $this->api->send_libro_to_sii( $job['payload']['xml'], $job['payload']['environment'], $job['payload']['token'] );
			}
			if ( is_wp_error( $result ) && $job['attempts'] < 3 ) {
				QueueDb::increment_attempts( $job['id'] );
			} else {
				QueueDb::delete( $job['id'] );
			}
			\call_user_func( $this->sleep, 1 );
		}
	}
}

class_alias( Queue::class, 'SII_Boleta_Queue' );
