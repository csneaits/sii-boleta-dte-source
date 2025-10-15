<?php
namespace Sii\BoletaDte\Infrastructure\Scheduling;

use Sii\BoletaDte\Infrastructure\WordPress\Settings;

class Cron {
	public const HOOK = 'sii_boleta_dte_process_queue';

	public function __construct( Settings $settings ) {
		$this->register_hooks();
	}

	public function register_hooks(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		}
	}

	public function register_schedule( $schedules ) {
		$schedules['every_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every Five Minutes', 'sii-boleta-dte' ),
		);
		return $schedules;
	}

	public static function activate(): void {
		self::deactivate(); // Always clear previous schedule on activation
		if ( \function_exists( 'wp_schedule_event' ) ) {
			wp_schedule_event( time() + 60, 'every_five_minutes', self::HOOK );
		}
	}

	public static function deactivate(): void {
		if ( \function_exists( 'wp_next_scheduled' ) ) {
			$timestamp = wp_next_scheduled( self::HOOK );
			if ( $timestamp && \function_exists( 'wp_unschedule_event' ) ) {
				wp_unschedule_event( $timestamp, self::HOOK );
			}
		}
	}
}

class_alias( Cron::class, 'SII_Boleta_Cron' );
