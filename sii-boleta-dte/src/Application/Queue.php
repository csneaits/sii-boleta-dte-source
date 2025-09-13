<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Very small in-memory queue persisted via WordPress options.
 *
 * Jobs are arrays with a 'type' key and additional data depending on the
 * operation. Supported types: `dte` and `libro` for sending DTE XML files or
 * Libro XML strings to SII through the Api class.
 */
class Queue {
	private DteEngine $engine;
	private Settings $settings;
	/** @var array<int,array<string,mixed>> */
	private array $jobs = array();

	public function __construct( DteEngine $engine, Settings $settings ) {
		$this->engine   = $engine;
		$this->settings = $settings;

		if ( \function_exists( 'get_option' ) ) {
			$stored = get_option( 'sii_boleta_dte_queue', array() );
			if ( \is_array( $stored ) ) {
				$this->jobs = $stored;
			}
		}
	}

	public function enqueue_dte( string $file, string $environment, string $token ): void {
		$this->jobs[] = array(
			'type'        => 'dte',
			'file'        => $file,
			'environment' => $environment,
			'token'       => $token,
		);
		$this->persist();
	}

	public function enqueue_libro( string $xml, string $environment, string $token ): void {
		$this->jobs[] = array(
			'type'        => 'libro',
			'xml'         => $xml,
			'environment' => $environment,
			'token'       => $token,
		);
		$this->persist();
	}

	/**
	 * Processes all queued jobs sequentially using the Api class.
	 */
	public function process(): void {
		$api = new \Sii\BoletaDte\Infrastructure\Rest\Api();
		while ( $job = \array_shift( $this->jobs ) ) {
			if ( 'dte' === ( $job['type'] ?? '' ) ) {
				$api->send_dte_to_sii( $job['file'], $job['environment'], $job['token'] );
			} elseif ( 'libro' === ( $job['type'] ?? '' ) ) {
				$api->send_libro_to_sii( $job['xml'], $job['environment'], $job['token'] );
			}
		}
		$this->persist();
	}

	private function persist(): void {
		if ( \function_exists( 'update_option' ) ) {
			update_option( 'sii_boleta_dte_queue', $this->jobs );
		}
	}
}

class_alias( Queue::class, 'SII_Boleta_Queue' );
