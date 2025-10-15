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
        // 1 minuto
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute', 'sii-boleta-dte' ),
        );
        // 5 minutos (valor por defecto)
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every Five Minutes', 'sii-boleta-dte' ),
        );
        // 15 minutos
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => __( 'Every Fifteen Minutes', 'sii-boleta-dte' ),
        );
        return $schedules;
    }

    public static function activate( string $interval = 'every_five_minutes' ): void {
        self::deactivate(); // Always clear previous schedule on activation
        if ( \function_exists( 'wp_schedule_event' ) ) {
            // Ensure schedules exist
            try { new self( new Settings() ); } catch ( \Throwable $e ) {}
            if ( ! in_array( $interval, array( 'every_minute', 'every_five_minutes', 'every_fifteen_minutes' ), true ) ) {
                $interval = 'every_five_minutes';
            }
            wp_schedule_event( time() + 60, $interval, self::HOOK );
        }
    }

    /** Unschedules and re-schedules using the provided interval slug. */
    public static function reschedule( string $interval ): void {
        self::activate( $interval );
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
