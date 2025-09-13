<?php
namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Infrastructure\Settings;

class Cron {
    public const HOOK = 'sii_boleta_dte_process_queue';
    public function __construct( Settings $settings ) {}

    public static function activate(): void {
        if ( \function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
            if ( \function_exists( 'wp_schedule_event' ) ) {
                wp_schedule_event( time() + 60, 'daily', self::HOOK );
            }
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
