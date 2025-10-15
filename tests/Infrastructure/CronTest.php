<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Scheduling\Cron;

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook ) {
        return $GLOBALS['scheduled'][ $hook ] ?? false;
    }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook ) {
        $GLOBALS['scheduled'][ $hook ] = $timestamp;
    }
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
    function wp_unschedule_event( $timestamp, $hook ) {
        unset( $GLOBALS['scheduled'][ $hook ] );
    }
}

class CronTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['scheduled'] = [];
    }

    public function test_activate_schedules_event() {
        Cron::activate();
        $this->assertArrayHasKey( Cron::HOOK, $GLOBALS['scheduled'] );
    }

    public function test_deactivate_unschedules_event() {
        $GLOBALS['scheduled'][ Cron::HOOK ] = time();
        Cron::deactivate();
        $this->assertArrayNotHasKey( Cron::HOOK, $GLOBALS['scheduled'] );
    }
}
