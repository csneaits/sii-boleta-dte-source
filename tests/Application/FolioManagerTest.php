<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        return $text;
    }
}

class FolioManagerTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
        unset( $GLOBALS['test_options'] );
        $GLOBALS['wp_options'] = array();
    }

    protected function tearDown(): void {
        FoliosDb::purge();
        unset( $GLOBALS['test_options'] );
        $GLOBALS['wp_options'] = array();
    }

    public function test_get_next_folio_consumes_range(): void {
        FoliosDb::insert( 39, 100, 105 );
        FoliosDb::insert( 39, 200, 205 );

        $settings = $this->createSettings();
        $manager  = new FolioManager( $settings );

        $first = $manager->get_next_folio( 39 );
        $this->assertSame( 100, $first );
        $this->assertSame( 100, Settings::get_last_folio_value( 39, '0' ) );

        $second = $manager->get_next_folio( 39 );
        $this->assertSame( 101, $second );
        $this->assertSame( 101, Settings::get_last_folio_value( 39, '0' ) );
    }

    public function test_get_next_folio_without_ranges_returns_zero(): void {
        $manager = new FolioManager( $this->createSettings() );

        $result = $manager->get_next_folio( 39 );

        if ( class_exists( '\\WP_Error' ) ) {
            $this->assertInstanceOf( \WP_Error::class, $result );
        } else {
            $this->assertSame( 0, $result );
        }
    }

    public function test_mark_folio_used_updates_expected_value(): void {
        $manager = new FolioManager( $this->createSettings() );

        Settings::update_last_folio_value( 39, '0', 5 );

        $this->assertFalse( $manager->mark_folio_used( 39, 3 ) );
        $this->assertSame( 5, Settings::get_last_folio_value( 39, '0' ) );

        $this->assertTrue( $manager->mark_folio_used( 39, 6 ) );
        $this->assertSame( 6, Settings::get_last_folio_value( 39, '0' ) );
    }

    public function test_mark_folio_used_advances_when_behind_expected_value(): void {
        $manager = new FolioManager( $this->createSettings() );

        Settings::update_last_folio_value( 39, '0', 10 );

        $this->assertTrue( $manager->mark_folio_used( 39, 50 ) );
        $this->assertSame( 50, Settings::get_last_folio_value( 39, '0' ) );
    }

    public function test_get_caf_info_returns_defaults_without_ranges(): void {
        $manager = new FolioManager( $this->createSettings() );

        $info = $manager->get_caf_info( 52 );

        $this->assertSame( array(), $info );
    }

    public function test_get_caf_info_parses_range_data(): void {
        $id = FoliosDb::insert( 39, 150, 190 );

        $caf = <<<XML
<AUTORIZACION xmlns="http://www.sii.cl/SiiDte">
  <CAF>
    <DA>
      <FA>2024-05-01</FA>
      <NroResol>85</NroResol>
      <RNG>
        <D>151</D>
        <H>180</H>
      </RNG>
    </DA>
    <RSASK>-----BEGIN RSA PRIVATE KEY-----AAAAABBBBBCCCCCDDDEEEFFFGGGHHHIIJJKKLLMMNNOOPP-----END RSA PRIVATE KEY-----</RSASK>
  </CAF>
</AUTORIZACION>
XML;

        FoliosDb::store_caf( $id, $caf );

        $manager = new FolioManager( $this->createSettings() );
        $info    = $manager->get_caf_info( 39 );

        $this->assertSame( 150, $info['D'] );
        $this->assertSame( 189, $info['H'] );
    }

    private function createSettings(): Settings {
        return new class extends Settings {
            public function get_settings(): array {
                return array(
                    'environment' => '0',
                );
            }
        };
    }
}
