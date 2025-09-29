<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

class QueueDbTest extends TestCase {
    protected function setUp(): void {
        QueueDb::purge();
    }

    public function test_enqueue_and_fetch_jobs(): void {
        $first = QueueDb::enqueue( 'dte', array( 'order_id' => 10 ) );
        $second = QueueDb::enqueue( 'rvd', array( 'order_id' => 20 ) );

        $this->assertSame( 1, $first );
        $this->assertSame( 2, $second );

        $jobs = QueueDb::get_pending_jobs();
        $this->assertCount( 2, $jobs );
        $this->assertSame( array( 'order_id' => 10 ), $jobs[0]['payload'] );
    }

    public function test_attempts_and_delete(): void {
        $id = QueueDb::enqueue( 'dte', array( 'folio' => 1 ) );

        QueueDb::increment_attempts( $id );
        QueueDb::increment_attempts( $id );

        $jobs = QueueDb::get_pending_jobs();
        $this->assertSame( 2, $jobs[0]['attempts'] );

        QueueDb::reset_attempts( $id );
        $jobs = QueueDb::get_pending_jobs();
        $this->assertSame( 0, $jobs[0]['attempts'] );

        QueueDb::delete( $id );
        $this->assertSame( array(), QueueDb::get_pending_jobs() );
    }

    public function test_purge_resets_queue(): void {
        QueueDb::enqueue( 'dte', array( 'a' => 1 ) );
        $this->assertNotEmpty( QueueDb::get_pending_jobs() );

        QueueDb::purge();
        $this->assertSame( array(), QueueDb::get_pending_jobs() );
        $this->assertSame( 1, QueueDb::enqueue( 'dte', array() ) );
    }
}
