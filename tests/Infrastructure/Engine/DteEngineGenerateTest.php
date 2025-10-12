<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Engine\Xml\XmlPlaceholderCleaner;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

class DteEngineGenerateTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
        FoliosDb::insert( 39, 1, 500 );
    }

    public function test_generate_dte_xml(): void {
        $settings = new class extends Settings { public function get_settings(): array { return array( 'rut_emisor' => '76086428-5', 'razon_social' => 'Test', 'giro' => 'GIRO', 'direccion' => 'Calle', 'comuna' => 'Santiago' ); } };
        $engine   = new LibreDteEngine( $settings );
        $xml = $engine->generate_dte_xml( array( 'Detalles' => array( array( 'NmbItem' => 'Item', 'QtyItem' => 1, 'PrcItem' => 1000 ) ) ), 39 );
        $this->assertIsString( $xml );
        $this->assertStringContainsString( '<DTE', $xml );
    }

    public function test_generate_dte_xml_uses_nested_emitter_data_when_settings_missing(): void {
        FoliosDb::purge();
        FoliosDb::insert( 39, 10, 20 );
        $settings = new class extends Settings { public function get_settings(): array { return array(); } };
        $engine   = new LibreDteEngine( $settings );
        $data     = array(
            'Encabezado' => array(
                'Emisor' => array(
                    'RUTEmisor' => '76192083-9',
                    'RznSoc'    => 'Empresa Demo',
                    'GiroEmis'  => 'Servicios',
                    'DirOrigen' => 'Calle Falsa 123',
                    'CmnaOrigen'=> 'Santiago',
                ),
            ),
            'Detalles'   => array(
                array(
                    'NmbItem' => 'Item',
                    'QtyItem' => 1,
                    'PrcItem' => 1000,
                ),
            ),
        );

        $xml = $engine->generate_dte_xml( $data, 39 );

        $this->assertIsString( $xml );

        $document = simplexml_load_string( $xml );
        $this->assertInstanceOf( \SimpleXMLElement::class, $document );
        $document->registerXPathNamespace( 'dte', 'http://www.sii.cl/SiiDte' );
        $emisor = $document->xpath( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Emisor' );
        $this->assertIsArray( $emisor );
        $this->assertNotEmpty( $emisor );
        $emisor = $emisor[0];

        $this->assertSame( '76192083-9', (string) $emisor->RUTEmisor );
        $this->assertSame( 'Empresa Demo', (string) $emisor->RznSocEmisor );
        $this->assertSame( 'Servicios', (string) $emisor->GiroEmisor );
        $this->assertSame( 'Calle Falsa 123', (string) $emisor->DirOrigen );
        $this->assertSame( 'Santiago', (string) $emisor->CmnaOrigen );
    }

    public function test_generate_dte_xml_prefers_custom_emitter_giro_over_settings(): void {
        $settings = new class extends Settings {
            public function get_settings(): array {
                return array(
                    'rut_emisor'   => '76086428-5',
                    'razon_social' => 'Test',
                    'giro'         => 'Giro Predeterminado',
                    'direccion'    => 'Calle',
                    'comuna'       => 'Santiago',
                );
            }
        };

        $engine = new LibreDteEngine( $settings );

        $data = array(
            'Encabezado' => array(
                'Emisor' => array(
                    'GiroEmis' => 'Giro Personalizado',
                ),
            ),
            'Detalles' => array(
                array(
                    'NmbItem' => 'Item',
                    'QtyItem' => 1,
                    'PrcItem' => 1000,
                ),
            ),
        );

        $xml = $engine->generate_dte_xml( $data, 39 );

        $this->assertIsString( $xml );

        $document = simplexml_load_string( $xml );
        $this->assertInstanceOf( \SimpleXMLElement::class, $document );
        $document->registerXPathNamespace( 'dte', 'http://www.sii.cl/SiiDte' );
        $emisor = $document->xpath( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Emisor' );
        $this->assertIsArray( $emisor );
        $this->assertNotEmpty( $emisor );
        $emisor = $emisor[0];

        $this->assertSame( 'Giro Personalizado', (string) $emisor->GiroEmisor );
    }

    public function test_generate_dte_xml_does_not_prefill_missing_receptor_fields(): void {
        if ( PHP_VERSION_ID < 80400 ) {
            $this->markTestSkipped( 'PDF rendering assertions require PHP 8.4+ stack.' );
        }

        FoliosDb::purge();
        FoliosDb::insert( 39, 1, 50 );
        $settings = new class extends Settings { public function get_settings(): array { return array(
            'rut_emisor' => '76086428-5',
            'razon_social' => 'Test',
            'giro' => 'GIRO',
            'direccion' => 'Calle',
            'comuna' => 'Santiago',
        ); } };

        $engine = new LibreDteEngine( $settings );

        $data = array(
            'Folio'    => 0,
            'FchEmis'  => '2025-09-20',
            'Receptor' => array(
                'RUTRecep'    => '25.915.008-6',
                'RznSocRecep' => 'Carlos Rodriguez',
            ),
            'Detalles' => array(
                array(
                    'NmbItem' => 'Item',
                    'QtyItem' => 1,
                    'PrcItem' => 1200,
                ),
            ),
        );

        $xml = $engine->generate_dte_xml( $data, 39 );

        $this->assertIsString( $xml );

        $document = simplexml_load_string( $xml );
        $this->assertInstanceOf( \SimpleXMLElement::class, $document );
        $document->registerXPathNamespace( 'dte', 'http://www.sii.cl/SiiDte' );

        $dirRecep = $document->xpath( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Receptor/dte:DirRecep' );
        $this->assertSame( array(), $dirRecep );

        $contacto = $document->xpath( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Receptor/dte:Contacto' );
        $this->assertSame( array(), $contacto );

        $correo = $document->xpath( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Receptor/dte:CorreoRecep' );
        $this->assertSame( array(), $correo );

        $referencias = $document->xpath( '/dte:DTE/dte:Documento/dte:Referencia' );
        $this->assertSame( array(), $referencias );

        $pdf = new PdfGenerator( $engine );
        $pdfPath = $pdf->generate( $xml );
        $this->assertFileExists( $pdfPath );
        $contents = file_get_contents( $pdfPath );
        $this->assertNotFalse( $contents );
        $this->assertStringNotContainsString( 'correo.sii@example.com', $contents );
        $this->assertStringNotContainsString( '+56 2 32525575', $contents );
        @unlink( $pdfPath );
    }

    public function test_generate_dte_xml_strips_bom_and_leading_whitespace(): void {
        FoliosDb::purge();
        FoliosDb::insert( 39, 1, 50 );

        $settings = new class extends Settings {
            public function get_settings(): array {
                return array(
                    'rut_emisor'   => '76086428-5',
                    'razon_social' => 'Test',
                    'giro'         => 'GIRO',
                    'direccion'    => 'Calle',
                    'comuna'       => 'Santiago',
                );
            }
        };

        $placeholder = new class implements XmlPlaceholderCleaner {
            public function clean( string $xml, array $rawReceptor, bool $hasReferences ): string {
                return "\xEF\xBB\xBF  \n\t" . $xml;
            }
        };

        $engine = new LibreDteEngine( $settings, null, null, null, null, null, $placeholder );

        $xml = $engine->generate_dte_xml(
            array(
                'Detalles' => array(
                    array(
                        'NmbItem' => 'Item',
                        'QtyItem' => 1,
                        'PrcItem' => 1000,
                    ),
                ),
            ),
            39
        );

        $this->assertIsString( $xml );
        $this->assertNotSame( '', $xml );
        $this->assertSame( '<', substr( $xml, 0, 1 ) );

        $dom = new \DOMDocument();
        $this->assertTrue( $dom->loadXML( $xml ) );
    }
}
