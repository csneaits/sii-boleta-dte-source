<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Rest\Api;
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
	private Api $api;
		/** @var callable */
		private $get_option;
		/** @var callable */
		private $update_option;
		/** @var callable */
		private $sleep;
		/** @var array<int,array<string,mixed>> */
	private array $jobs = array();

	public function __construct( DteEngine $engine, Settings $settings, Api $api, callable $get_option = null, callable $update_option = null, callable $sleep = null ) {
			$this->engine        = $engine;
			$this->settings      = $settings;
			$this->api           = $api;
			$this->get_option    = $get_option ?? ( \function_exists( 'get_option' ) ? 'get_option' : static fn( string $k, $d = null ) => $d );
			$this->update_option = $update_option ?? ( \function_exists( 'update_option' ) ? 'update_option' : static fn( string $k, $v ): bool => true );
			$this->sleep         = $sleep ?? 'sleep';

			$stored = \call_user_func( $this->get_option, 'sii_boleta_dte_queue', array() );
		if ( \is_array( $stored ) ) {
				$this->jobs = $stored;
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
		if ( \call_user_func( $this->get_option, 'sii_boleta_dte_queue_lock', false ) ) {
				return;
		}
			\call_user_func( $this->update_option, 'sii_boleta_dte_queue_lock', true );
		while ( $job = \array_shift( $this->jobs ) ) {
			if ( 'dte' === ( $job['type'] ?? '' ) ) {
					$this->api->send_dte_to_sii( $job['file'], $job['environment'], $job['token'] );
			} elseif ( 'libro' === ( $job['type'] ?? '' ) ) {
					$this->api->send_libro_to_sii( $job['xml'], $job['environment'], $job['token'] );
			}
				\call_user_func( $this->sleep, 1 );
		}
			$this->persist();
			\call_user_func( $this->update_option, 'sii_boleta_dte_queue_lock', false );
	}

	private function persist(): void {
			\call_user_func( $this->update_option, 'sii_boleta_dte_queue', $this->jobs );
	}
}

class_alias( Queue::class, 'SII_Boleta_Queue' );
