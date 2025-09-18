<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use JsonException;
use SimpleXMLElement;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\BuilderWorker;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\RendererWorker;
use libredte\lib\Core\Package\Billing\Component\Identifier\Worker\CafFakerWorker;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;
use Symfony\Component\Yaml\Yaml;

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
		$this->settings                  = $settings;
		$app                             = Application::getInstance();
		$registry                        = $app->getPackageRegistry()->getBillingPackage();
		$component                       = $registry->getDocumentComponent();
		$this->builder                   = $component->getBuilderWorker();
		$this->renderer                  = $component->getRendererWorker();
				$this->cafFaker          = $registry->getIdentifierComponent()->getCafFakerWorker();
				$this->certificateLoader = new CertificateLoader();
				$this->certificateFaker  = new CertificateFaker( $this->certificateLoader );
	}

	public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false ) {
		$tipo     = (int) $tipo_dte;
		$settings = $this->settings->get_settings();

				// Validar CAF proporcionado en la configuración.
				$caf_file = '';
		if ( ! empty( $settings['cafs'] ) && is_array( $settings['cafs'] ) ) {
			foreach ( $settings['cafs'] as $caf ) {
				if ( (int) ( $caf['tipo'] ?? 0 ) === $tipo ) {
					$caf_file = $caf['path'] ?? '';
					break;
				}
			}
		} elseif ( isset( $settings['caf_path'][ $tipo ] ) ) {
				$caf_file = $settings['caf_path'][ $tipo ];
		}
		if ( ! $caf_file || ! @file_exists( $caf_file ) ) {
				return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_missing_caf', 'Missing CAF' ) : false;
		}
		if ( ! @simplexml_load_file( $caf_file ) ) {
				return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_invalid_caf', 'Invalid CAF' ) : false;
		}

                $template = $this->load_template( $tipo );

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
                                'MontoItem' => (int) round( $qty * $prc ),
                        );
                        if ( ! empty( $d['IndExe'] ) || 41 === $tipo || 34 === $tipo ) {
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

                $documentData = array_replace_recursive(
                        $template,
                        array(
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
                        )
                );



                if ( ! empty( $data['Referencias'] ) ) {
                        $documentData['Referencia'] = $data['Referencias'];
                }

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
                $xml       = mb_convert_encoding( $xml, 'UTF-8', 'ISO-8859-1' );
                $options   = array(
                        'parser'   => array( 'strategy' => 'default.xml' ),
                        'renderer' => array( 'format' => 'pdf' ),
                );
                $parsedXml = $this->parse_document_data_from_xml( $xml );

                $bag = $parsedXml === null
                        ? new DocumentBag( $xml, options: $options )
                        : new DocumentBag( parsedData: $parsedXml, options: $options );
                $pdf  = $this->renderer->render( $bag );
                $file = tempnam( sys_get_temp_dir(), 'pdf' );
                file_put_contents( $file, $pdf );
                return $file;
        }

        /** Loads YAML template for a given DTE type exclusively from
         * resources/yaml/documentos_ok copy/ (carpetas por tipo).
         */
        private function load_template( int $tipo ): array {
                $root = dirname( __DIR__, 2 ) . '/resources/yaml/';

                // Foldered layout copied from LibreDTE fixtures
                $dir = $root . 'documentos_ok copy/' . sprintf( '%03d', $tipo ) . '*';
                foreach ( glob( $dir ) as $typeDir ) {
                        if ( ! is_dir( $typeDir ) ) {
                                continue;
                        }
                        $candidates = array_merge( glob( $typeDir . '/*.yml' ) ?: array(), glob( $typeDir . '/*.yaml' ) ?: array() );
                        if ( empty( $candidates ) ) {
                                continue;
                        }
                        // Use the first candidate as a base template
                        try {
                                $parsed = Yaml::parseFile( $candidates[0] );
                                return is_array( $parsed ) ? $parsed : array();
                        } catch ( \Throwable $e ) {
                                // try next
                        }
                }

                return array();
        }

        /**
         * Converts a DTE XML string into the array structure expected by LibreDTE.
         */
        private function parse_document_data_from_xml( string $xml ): ?array {
                $previous = libxml_use_internal_errors( true );
                $document = simplexml_load_string( $xml );

                if ( ! $document instanceof SimpleXMLElement ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return null;
                }

                /** @var SimpleXMLElement|null $document_node */
                $document_node = $document->Documento ?? $document->Exportaciones ?? $document->Liquidacion ?? null;
                if ( ! $document_node instanceof SimpleXMLElement ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return null;
                }

                try {
                        $encoded = json_encode( $document_node, JSON_THROW_ON_ERROR );
                        $data    = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
                } catch ( JsonException $e ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return null;
                }

                if ( ! is_array( $data ) ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return null;
                }

                unset( $data['@attributes'] );

                if ( isset( $data['Detalle'] ) ) {
                        $detalles = $data['Detalle'];
                        if ( ! is_array( $detalles ) || ! array_is_list( $detalles ) ) {
                                $detalles = array( $detalles );
                        }

                        $data['Detalle'] = array_values(
                                array_map(
                                        static fn( $detalle ) => is_array( $detalle ) ? $detalle : (array) $detalle,
                                        $detalles
                                )
                        );
                }

                libxml_clear_errors();
                libxml_use_internal_errors( $previous );

                return $data;
        }
}

class_alias( LibreDteEngine::class, 'SII_LibreDTE_Engine' );
