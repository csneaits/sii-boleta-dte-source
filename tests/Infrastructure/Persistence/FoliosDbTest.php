<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

class FoliosDbTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
        $GLOBALS['test_options'] = array();
    }

    protected function tearDown(): void {
        FoliosDb::purge();
    }

    public function test_insert_and_retrieve_ranges(): void {
        $first  = FoliosDb::insert( 39, 10, 20 );
        $second = FoliosDb::insert( 52, 30, 40, '1' );
        $third  = FoliosDb::insert( 39, 50, 60 );

        $this->assertSame( 1, $first );
        $this->assertSame( 2, $second );
        $this->assertSame( 3, $third );

        $allTest = FoliosDb::all( '0' );
        $this->assertCount( 2, $allTest );

        $sorted = FoliosDb::for_type( 39 );
        $this->assertSame( array( 10, 50 ), array_column( $sorted, 'desde' ) );
        $this->assertTrue( FoliosDb::has_type( 39 ) );
        $this->assertFalse( FoliosDb::has_type( 33 ) );
    }

    public function test_update_and_delete_range(): void {
        $id = FoliosDb::insert( 39, 10, 20 );

        $this->assertTrue( FoliosDb::update( $id, 39, 15, 25, 'test' ) );

        $range = FoliosDb::get( $id );
        $this->assertNotNull( $range );
        $this->assertSame( 15, $range['desde'] );
        $this->assertSame( 25, $range['hasta'] );
        $this->assertSame( '0', $range['environment'] );

        $this->assertTrue( FoliosDb::delete( $id ) );
        $this->assertNull( FoliosDb::get( $id ) );
    }

    public function test_store_caf_normalizes_keys(): void {
        $id = FoliosDb::insert( 39, 100, 110 );

        $caf = <<<XML
<AUTORIZACION xmlns="http://www.sii.cl/SiiDte">
  <CAF>
    <DA>
      <RNG><D>100</D><H>110</H></RNG>
    </DA>
    <RSASK>-----BEGIN RSA PRIVATE KEY-----AAAAABBBBBCCCCCDDDEEEFFFGGGHHHIIJJKKLLMMNNOOPPQQQRRRSSS-----END RSA PRIVATE KEY-----</RSASK>
  </CAF>
</AUTORIZACION>
XML;

        $this->assertTrue( FoliosDb::store_caf( $id, $caf, 'caf.xml' ) );

        $stored = FoliosDb::get( $id );
        $this->assertNotNull( $stored );
        $this->assertStringContainsString( "-----BEGIN RSA PRIVATE KEY-----\n", $stored['caf'] );
        $this->assertSame( 'caf.xml', $stored['caf_filename'] );
        $this->assertNotEmpty( $stored['caf_uploaded_at'] );
    }

    public function test_overlaps_and_find_for_folio(): void {
        $a = FoliosDb::insert( 39, 1, 10 );
        $b = FoliosDb::insert( 39, 20, 30 );

        $this->assertTrue( FoliosDb::overlaps( 39, 5, 8 ) );
        $this->assertFalse( FoliosDb::overlaps( 39, 10, 20 ) );
        $this->assertFalse( FoliosDb::overlaps( 39, 22, 28, $b ) );

        $match = FoliosDb::find_for_folio( 39, 22 );
        $this->assertNotNull( $match );
        $this->assertSame( 20, $match['desde'] );

        $this->assertNull( FoliosDb::find_for_folio( 39, 99 ) );
    }

    public function test_purge_resets_store(): void {
        FoliosDb::insert( 39, 1, 5 );
        $this->assertNotEmpty( FoliosDb::all() );

        FoliosDb::purge();
        $this->assertSame( array(), FoliosDb::all() );
        $this->assertSame( 1, FoliosDb::insert( 39, 10, 20 ) );
    }
}
