<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;

class LogDbPersistenceTest extends TestCase {
    protected function setUp(): void {
        LogDb::purge();
    }

    public function test_add_entry_and_get_pending_ids(): void {
        LogDb::add_entry( '123', 'sent', 'payload', '0' );
        LogDb::add_entry( '456', 'accepted', 'ok', '0' );
        LogDb::add_entry( '789', 'sent', 'payload2', '0' );

        $pending = LogDb::get_pending_track_ids();
        $this->assertSame( array( '789', '123' ), $pending );

        $limited = LogDb::get_pending_track_ids( 1 );
        $this->assertSame( array( '789' ), $limited );
    }

    public function test_get_logs_filters_by_status(): void {
        LogDb::add_entry( '1', 'sent', 'a', '0' );
        LogDb::add_entry( '2', 'accepted', 'b', '0' );
        LogDb::add_entry( '3', 'rejected', 'c', '0' );

        $all = LogDb::get_logs();
        $this->assertCount( 3, $all );
        $this->assertSame( '3', $all[0]['track_id'] );

        $sent = LogDb::get_logs( array( 'status' => 'sent', 'limit' => 5 ) );
        $this->assertCount( 1, $sent );
        $this->assertSame( '1', $sent[0]['track_id'] );
    }

    public function test_purge_clears_entries(): void {
        LogDb::add_entry( '100', 'sent', 'body', '0' );
        $this->assertNotEmpty( LogDb::get_logs() );

        LogDb::purge();
        $this->assertSame( array(), LogDb::get_logs() );
    }
}
