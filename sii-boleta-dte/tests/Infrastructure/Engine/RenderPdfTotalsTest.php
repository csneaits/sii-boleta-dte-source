<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Settings;
use libredte\lib\Core\Package\Billing\Component\Document\Contract\DocumentBagInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\RendererWorker;

if ( ! class_exists( 'Dummy_Render_Settings' ) ) {
    class Dummy_Render_Settings extends Settings {
        public function get_settings(): array {
            return array(
                'rut_emisor'   => '11111111-1',
                'razon_social' => 'Test',
                'giro'         => 'Giro',
                'direccion'    => 'Calle 1',
                'comuna'       => 'Santiago',
            );
        }
    }
}

class RenderPdfTotalsTest extends TestCase {
    protected function setUp(): void {
        FoliosDb::purge();
        FoliosDb::insert( 39, 1, 1000 );
    }

    public function test_render_pdf_preserves_totals_from_detail(): void {
        $engine = new LibreDteEngine( new Dummy_Render_Settings() );

        $data = array(
            'Folio'      => 1,
            'FchEmis'    => '2024-05-01',
            'Receptor'   => array(
                'RUTRecep'     => '22222222-2',
                'RznSocRecep'  => 'Cliente',
                'DirRecep'     => 'Dir',
                'CmnaRecep'    => 'Comuna',
            ),
            'Detalles'   => array(
                array(
                    'NroLinDet' => 1,
                    'NmbItem'   => 'A',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1200,
                ),
                array(
                    'NroLinDet' => 2,
                    'NmbItem'   => 'B',
                    'QtyItem'   => 1,
                    'PrcItem'   => 1200,
                ),
            ),
        );

        $xml = $engine->generate_dte_xml( $data, 39, true );

        $stub_renderer = new class() extends RendererWorker {
            public ?DocumentBagInterface $lastBag = null;

            public function __construct() {
                // Override parent constructor to avoid dependency wiring.
            }

            public function render( DocumentBagInterface $bag ): string {
                $this->lastBag = $bag;
                return 'pdf';
            }
        };

        $reflection = new ReflectionClass( LibreDteEngine::class );
        $renderer_property = $reflection->getProperty( 'renderer' );
        $renderer_property->setAccessible( true );
        $renderer_property->setValue( $engine, $stub_renderer );

        $file = $engine->render_pdf( $xml );
        $this->assertFileExists( $file );
        $this->assertSame( 'pdf', file_get_contents( $file ) );
        @unlink( $file );

        $this->assertNotNull( $stub_renderer->lastBag );
        $bag = $stub_renderer->lastBag;
        $this->assertSame( false, $bag->getNormalizerOptions()['normalize'] ?? null );

        $totals = $bag->getNormalizedData()['Encabezado']['Totales'] ?? array();
        $this->assertSame( 2017, $totals['MntNeto'] ?? null );
        $this->assertSame( 383, $totals['IVA'] ?? null );
        $this->assertSame( 2400, $totals['MntTotal'] ?? null );
    }
}
