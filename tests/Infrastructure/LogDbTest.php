<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;

class LogDbTest extends TestCase {
    protected function setUp(): void {
        unset( $GLOBALS['wpdb'] );
        LogDb::purge();
    }

    public function test_add_and_query_entries(): void {
        LogDb::add_entry( '123', 'sent', 'body', '0' );
        LogDb::add_entry( '456', 'accepted', 'ok', '1' );

        $pending_cert = LogDb::get_pending_track_ids( 50, '0' );
        $pending_prod = LogDb::get_pending_track_ids( 50, '1' );
        $this->assertSame( array( '123' ), $pending_cert );
        $this->assertSame( array(), $pending_prod );

        $logs_cert = LogDb::get_logs( array( 'environment' => '0' ) );
        $logs_prod = LogDb::get_logs( array( 'environment' => '1' ) );

        $this->assertCount( 1, $logs_cert );
        $this->assertCount( 1, $logs_prod );
    }

    public function test_purge_clears_entries(): void {
        LogDb::add_entry( '1', 'sent', 'a', '0' );
        LogDb::purge();
        $this->assertSame( array(), LogDb::get_logs() );
    }
}
