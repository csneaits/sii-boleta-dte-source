<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\BuilderWorker;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\RendererWorker;
use libredte\lib\Core\Package\Billing\Component\Identifier\Worker\CafFakerWorker;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;
use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;

/**
 * DTE engine backed by LibreDTE library.
 */
class LibreDteEngine implements DteEngine {
	private Settings $settings;
	private BuilderWorker $builder;
	private RendererWorker $renderer;
	private CafFakerWorker $cafFaker;
        private CertificateFaker $certificateFaker;
        private CertificateLoader $certificateLoader;

	public function __construct( Settings $settings ) {
		$this->settings         = $settings;
		$app                    = Application::getInstance();
		$registry               = $app->getPackageRegistry()->getBillingPackage();
		$component              = $registry->getDocumentComponent();
		$this->builder          = $component->getBuilderWorker();
		$this->renderer         = $component->getRendererWorker();
                $this->cafFaker         = $registry->getIdentifierComponent()->getCafFakerWorker();
                $this->certificateLoader = new CertificateLoader();
                $this->certificateFaker  = new CertificateFaker( $this->certificateLoader );
	}

	public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false ) {
		$tipo     = (int) $tipo_dte;
		$settings = $this->settings->get_settings();

		// Validar CAF proporcionado en la configuración.
		$caf_paths = $settings['caf_path'] ?? array();
		if ( isset( $caf_paths[ $tipo ] ) ) {
			$caf_file = $caf_paths[ $tipo ];
			if ( ! @simplexml_load_file( $caf_file ) ) {
				return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_invalid_caf', 'Invalid CAF' ) : false;
			}
		}

		$detalles = $data['Detalles'] ?? array();
		$detalle  = array();
		$i        = 1;
		foreach ( $detalles as $d ) {
			$qty  = (float) ( $d['QtyItem'] ?? 1 );
			$prc  = (int) round( $d['PrcItem'] ?? 0 );
			$line = array(
				'NroLinDet' => $d['NroLinDet'] ?? $i,
				'NmbItem'   => $d['NmbItem'] ?? '',
				'QtyItem'   => $qty,
				'PrcItem'   => $prc,
			);
			if ( ! empty( $d['IndExe'] ) || 41 === $tipo ) {
				$line['IndExe'] = 1;
			}
			$detalle[] = $line;
			++$i;
		}

		$emisor = array(
			'RUTEmisor'    => $settings['rut_emisor'] ?? $data['RutEmisor'] ?? '',
			'RznSocEmisor' => $settings['razon_social'] ?? $data['RznSoc'] ?? '',
			'GiroEmisor'   => $settings['giro'] ?? $data['GiroEmisor'] ?? '',
			'DirOrigen'    => $settings['direccion'] ?? $data['DirOrigen'] ?? '',
			'CmnaOrigen'   => $settings['comuna'] ?? $data['CmnaOrigen'] ?? '',
		);

		$documentData = array(
			'Encabezado' => array(
				'IdDoc'    => array(
					'TipoDTE' => $tipo,
					'Folio'   => $data['Folio'] ?? 0,
					'FchEmis' => $data['FchEmis'] ?? '',
				),
				'Emisor'   => $emisor,
				'Receptor' => $data['Receptor'] ?? array(),
			),
			'Detalle'    => $detalle,
		);

                $emisorEntity = new Emisor( $emisor['RUTEmisor'], $emisor['RznSocEmisor'] );
                $cafBag       = $this->cafFaker->create( $emisorEntity, $tipo, $documentData['Encabezado']['IdDoc']['Folio'] );

                $cert_file = $settings['cert_path'] ?? '';
                $cert_pass = $settings['cert_pass'] ?? '';
                try {
                        if ( $cert_file && @file_exists( $cert_file ) ) {
                                $certificate = $this->certificateLoader->load( $cert_file, (string) $cert_pass );
                        } else {
                                $certificate = $this->certificateFaker->createFake( id: $emisorEntity->getRUT() );
                        }
                } catch ( \Throwable $e ) {
                        $certificate = $this->certificateFaker->createFake( id: $emisorEntity->getRUT() );
                }

		$bag = new DocumentBag( parsedData: $documentData, caf: $cafBag->getCaf(), certificate: $certificate );
		$this->builder->build( $bag );
		return $bag->getDocument()->saveXml();
	}

	/**
	 * Renders a PDF using LibreDTE templates.
	 */
	public function render_pdf( string $xml, array $options = array() ): string {
		$xml  = mb_convert_encoding( $xml, 'UTF-8', 'ISO-8859-1' );
		$bag  = new DocumentBag(
			$xml,
			options: array(
				'parser'   => array( 'strategy' => 'default.xml' ),
				'renderer' => array( 'format' => 'pdf' ),
			)
		);
		$pdf  = $this->renderer->render( $bag );
		$file = tempnam( sys_get_temp_dir(), 'pdf' );
		file_put_contents( $file, $pdf );
		return $file;
	}
}

class_alias( LibreDteEngine::class, 'SII_LibreDTE_Engine' );
