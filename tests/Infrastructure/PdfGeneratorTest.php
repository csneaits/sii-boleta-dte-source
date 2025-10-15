<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Engine\PdfGenerator;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;

if ( ! function_exists( 'get_attached_file' ) ) {
    function get_attached_file( $attachment_id ) {
        return $GLOBALS['sii_boleta_test_logo_files'][ $attachment_id ] ?? '';
    }
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
    function wp_check_filetype( $filename ) {
        $extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
        $mime_map  = array(
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
        );

        return array( 'type' => $mime_map[ $extension ] ?? 'image/png' );
    }
}

class PdfGeneratorTest extends TestCase {
    protected function tearDown(): void {
        parent::tearDown();
        unset( $GLOBALS['sii_boleta_test_logo_files'] );
    }

    public function test_generate_includes_logo_when_enabled(): void {
        $tmp    = tempnam( sys_get_temp_dir(), 'logo' );
        $target = $tmp . '.png';
        rename( $tmp, $target );
        file_put_contents( $target, 'fake image' );

        $GLOBALS['sii_boleta_test_logo_files'] = array( 123 => $target );

        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn(
            array(
                'pdf_show_logo' => 1,
                'pdf_logo'      => 123,
            )
        );

        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->once() )
            ->method( 'render_pdf' )
            ->with(
                $this->equalTo( '<xml />' ),
                $this->callback( function ( $options ) {
                    $this->assertIsArray( $options );
                    $this->assertArrayHasKey( 'renderer', $options );
                    $this->assertSame(
                        array( 'template' => 'estandar' ),
                        $options['renderer']
                    );
                    $this->assertArrayHasKey( 'document_overrides', $options );
                    $this->assertArrayHasKey( 'logo', $options['document_overrides'] );
                    $this->assertStringStartsWith( 'data:image/', $options['document_overrides']['logo'] );

                    return true;
                } )
            )
            ->willReturn( $target );

        $generator = new PdfGenerator( $engine, $settings );
        $result    = $generator->generate( '<xml />' );

        $this->assertSame( $target, $result );

        @unlink( $target );
    }

    public function test_generate_skips_logo_when_disabled(): void {
        $settings = $this->createMock( Settings::class );
        $settings->method( 'get_settings' )->willReturn( array( 'pdf_show_logo' => 0 ) );

        $engine = $this->createMock( DteEngine::class );
        $engine->expects( $this->once() )
            ->method( 'render_pdf' )
            ->with(
                $this->equalTo( '<xml />' ),
                $this->callback( function ( $options ) {
                    $this->assertIsArray( $options );
                    $this->assertSame(
                        array( 'template' => 'estandar' ),
                        $options['renderer'] ?? null
                    );
                    $this->assertArrayNotHasKey( 'document_overrides', $options );

                    return true;
                } )
            )
            ->willReturn( '/tmp/pdf' );

        $generator = new PdfGenerator( $engine, $settings );
        $this->assertSame( '/tmp/pdf', $generator->generate( '<xml />' ) );
    }
}
