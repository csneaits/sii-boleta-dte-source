<?php

use PHPUnit\\Framework\\TestCase;
use Sii\\BoletaDte\\Infrastructure\\Persistence\\FoliosDb;

class FoliosDbHasTypeTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
    }

    public function test_has_type_detects_single_folio_range(): void {
        FoliosDb::insert( 46, 100, 100 );
        $this->assertTrue( FoliosDb::has_type( 46 ) );
    }

    public function test_has_type_honours_environment(): void {
        FoliosDb::insert( 46, 1, 10, '1' );
        $this->assertFalse( FoliosDb::has_type( 46, '0' ) );
        $this->assertTrue( FoliosDb::has_type( 46, '1' ) );
    }

    public function test_has_type_returns_false_when_no_ranges(): void {
        $this->assertFalse( FoliosDb::has_type( 33 ) );
    }
}
