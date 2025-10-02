<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;

class LogDbTest extends TestCase {
    protected function setUp(): void {
        unset( $GLOBALS['wpdb'] );
        LogDb::purge();
    }

    public function test_add_and_query_entries(): void {
        LogDb::add_entry( '123', 'sent', 'body' );
        LogDb::add_entry( '456', 'accepted', 'ok' );
        $pending = LogDb::get_pending_track_ids();
        $this->assertContains( '123', $pending );
        $logs = LogDb::get_logs();
        $this->assertCount( 2, $logs );
    }

    public function test_purge_clears_entries(): void {
        LogDb::add_entry( '1', 'sent', 'a' );
        LogDb::purge();
        $this->assertSame( array(), LogDb::get_logs() );
    }
}
