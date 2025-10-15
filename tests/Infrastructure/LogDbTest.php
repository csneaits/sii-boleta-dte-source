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

    public function test_legacy_environment_aliases_are_supported(): void {
        $ref       = new \ReflectionClass( LogDb::class );
        $entries   = $ref->getProperty( 'entries' );
        $useMemory = $ref->getProperty( 'use_memory' );
        $entries->setAccessible( true );
        $useMemory->setAccessible( true );

        $originalEntries   = $entries->getValue();
        $originalUseMemory = $useMemory->getValue();

        $legacyRow = array(
            'track_id'      => 'LEG-1',
            'status'        => 'sent',
            'response'      => '{}',
            'environment'   => 'dev',
            'document_type' => 33,
            'folio'         => 99,
            'created_at'    => '2025-01-01 00:00:00',
        );

    $entries->setValue( null, array( $legacyRow ) );
    $useMemory->setValue( null, true );

        try {
            $pending = LogDb::get_pending_track_ids( 10, '2' );
            $this->assertSame( array( 'LEG-1' ), $pending );

            $logs = LogDb::get_logs( array( 'environment' => '2' ) );
            $this->assertCount( 1, $logs );
            $this->assertSame( 'LEG-1', $logs[0]['track_id'] ?? '' );

            $paginated = LogDb::get_logs_paginated( array( 'environment' => 'development' ) );
            $this->assertSame( 1, $paginated['total'] );
            $this->assertSame( 'LEG-1', $paginated['rows'][0]['track_id'] ?? '' );

            $types = LogDb::get_distinct_types( '2' );
            $this->assertSame( array( 33 ), $types );
        } finally {
            $entries->setValue( null, $originalEntries );
            $useMemory->setValue( null, $originalUseMemory );
        }
    }
}
