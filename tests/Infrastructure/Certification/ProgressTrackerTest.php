<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Certification\ProgressTracker;

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value ) { $GLOBALS['wp_options'][ $name ] = $value; }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = 0 ) { return $GLOBALS['wp_options'][ $name ] ?? $default; }
}

class ProgressTrackerTest extends TestCase {
    protected function setUp(): void {
        unset( $GLOBALS['test_options'] );
        $GLOBALS['wp_options'] = array();
    }

    public function test_mark_uses_current_time_when_missing(): void {
        ProgressTracker::mark( ProgressTracker::OPTION_TOKEN, 1234 );
        $this->assertSame( 1234, ProgressTracker::last_timestamp( ProgressTracker::OPTION_TOKEN ) );

        unset( $GLOBALS['test_options'] );
        ProgressTracker::mark( ProgressTracker::OPTION_API );
        $this->assertGreaterThan( 0, ProgressTracker::last_timestamp( ProgressTracker::OPTION_API ) );
    }

    public function test_last_timestamp_defaults_to_zero(): void {
        $this->assertSame( 0, ProgressTracker::last_timestamp( ProgressTracker::OPTION_TEST_SEND ) );
    }
}
