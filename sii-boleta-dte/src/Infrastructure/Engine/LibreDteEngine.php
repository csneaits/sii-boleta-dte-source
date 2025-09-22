<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use JsonException;
use SimpleXMLElement;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorProviderInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\ReceptorFactory;
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
                $this->override_receptor_provider( $app );
                $registry                        = $app->getPackageRegistry()->getBillingPackage();
                $component                       = $registry->getDocumentComponent();
                $this->builder                   = $component->getBuilderWorker();
                $this->renderer                  = $component->getRendererWorker();
                                $this->cafFaker          = $registry->getIdentifierComponent()->getCafFakerWorker();
                                $this->certificateLoader = new CertificateLoader();
                                $this->certificateFaker  = new CertificateFaker( $this->certificateLoader );
        }

        private function override_receptor_provider( Application $app ): void {
                try {
                        $container = $app->getService( 'service_container' );
                } catch ( \Throwable $e ) {
                        return;
                }

                if ( ! is_object( $container ) ) {
                        return;
                }

                try {
                        $reflection = new \ReflectionObject( $container );
                } catch ( \ReflectionException $e ) {
                        return;
                }

                if ( ! $reflection->hasProperty( 'privates' ) ) {
                        return;
                }

                $property = $reflection->getProperty( 'privates' );
                $property->setAccessible( true );
                $privates = $property->getValue( $container );
                if ( ! is_array( $privates ) ) {
                        $privates = array();
                }

                if ( isset( $privates[ ReceptorProviderInterface::class ] )
                        && $privates[ ReceptorProviderInterface::class ] instanceof EmptyReceptorProvider ) {
                        return;
                }

                $factory  = new ReceptorFactory();
                $provider = new EmptyReceptorProvider( $factory );

                $privates[ ReceptorProviderInterface::class ] = $provider;
                $property->setValue( $container, $privates );
        }

	public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false ) {
                $tipo     = (int) $tipo_dte;
                $settings = $this->settings->get_settings();

                $folio_number = 0;
                if ( isset( $data['Folio'] ) ) {
                        $folio_number = (int) $data['Folio'];
                } elseif ( isset( $data['Encabezado']['IdDoc']['Folio'] ) ) {
                        $folio_number = (int) $data['Encabezado']['IdDoc']['Folio'];
                }
                $environment = $this->settings->get_environment();
                if ( $folio_number > 0 && ! FoliosDb::find_for_folio( $tipo, $folio_number, $environment ) ) {
                        return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_missing_caf', 'Missing folio range' ) : false;
                }

                $template = $this->load_template( $tipo );
                if ( isset( $template['Detalle'] ) ) {
                        $template['Detalle'] = array();
                }
                if ( isset( $template['Encabezado']['Totales'] ) ) {
                        $template['Encabezado']['Totales'] = array();
                }
                if ( isset( $template['DscRcgGlobal'] ) ) {
                        unset( $template['DscRcgGlobal'] );
                }

                $detalles = $data['Detalles'] ?? array();
                $detalle  = array();
                $i        = 1;
                foreach ( $detalles as $d ) {
                        $qty  = (float) ( $d['QtyItem'] ?? 1 );
                        $prc  = (float) ( $d['PrcItem'] ?? 0 );
                        $line = array(
                                'NroLinDet' => $d['NroLinDet'] ?? $i,
                                'NmbItem'   => $d['NmbItem'] ?? '',
                                'QtyItem'   => $qty,
                                'PrcItem'   => (int) round( $prc ),
                        );
                        $line['MontoItem'] = isset( $d['MontoItem'] )
                                ? (int) round( $d['MontoItem'] )
                                : (int) round( $qty * $prc );
                        $is_exento = ! empty( $d['IndExe'] ) || 41 === $tipo || 34 === $tipo;
                        if ( $is_exento ) {
                                $line['IndExe'] = 1;
                        }
                        $detalle[] = $line;
                        ++$i;
                }

                $emisor_data = array();
                if ( isset( $data['Encabezado']['Emisor'] ) && is_array( $data['Encabezado']['Emisor'] ) ) {
                        $emisor_data = $data['Encabezado']['Emisor'];
                }

                $custom_giro = $emisor_data['GiroEmisor']
                        ?? $emisor_data['GiroEmis']
                        ?? $data['GiroEmisor']
                        ?? $data['GiroEmis']
                        ?? '';
                $custom_giro = is_string( $custom_giro ) ? trim( $custom_giro ) : '';

                $emisor = array(
                        'RUTEmisor'    => $settings['rut_emisor']
                                ?? $emisor_data['RUTEmisor']
                                ?? $data['RUTEmisor']
                                ?? $data['RutEmisor']
                                ?? '',
                        'RznSocEmisor' => $settings['razon_social']
                                ?? $emisor_data['RznSocEmisor']
                                ?? $emisor_data['RznSoc']
                                ?? $data['RznSocEmisor']
                                ?? $data['RznSoc']
                                ?? '',
                        'GiroEmisor'   => '' !== $custom_giro
                                ? $custom_giro
                                : ( $settings['giro']
                                        ?? ''
                                ),
                        'DirOrigen'    => $settings['direccion']
                                ?? $emisor_data['DirOrigen']
                                ?? $data['DirOrigen']
                                ?? '',
                        'CmnaOrigen'   => $settings['comuna']
                                ?? $emisor_data['CmnaOrigen']
                                ?? $data['CmnaOrigen']
                                ?? '',
                );

                if ( '' !== $emisor['RznSocEmisor'] ) {
                        $emisor['RznSoc'] = $emisor['RznSocEmisor'];
                }
                if ( '' !== $emisor['GiroEmisor'] ) {
                        $emisor['GiroEmis'] = $emisor['GiroEmisor'];
                }
                if ( $preview ) {
                        $this->debug_log( '[preview] settings=' . json_encode( array(
                                'rut_emisor'    => $settings['rut_emisor'] ?? null,
                                'razon_social'  => $settings['razon_social'] ?? null,
                                'giro'          => $settings['giro'] ?? null,
                                'direccion'     => $settings['direccion'] ?? null,
                                'comuna'        => $settings['comuna'] ?? null,
                        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                }

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

                $rawReceptor = array();
                if ( isset( $data['Receptor'] ) && is_array( $data['Receptor'] ) ) {
                        $rawReceptor = $data['Receptor'];
                } elseif ( isset( $data['Encabezado']['Receptor'] ) && is_array( $data['Encabezado']['Receptor'] ) ) {
                        $rawReceptor = $data['Encabezado']['Receptor'];
                }

                if ( isset( $documentData['Encabezado']['Receptor'] ) ) {
                        if ( ! empty( $rawReceptor ) ) {
                                $documentData['Encabezado']['Receptor'] = $this->sanitize_section( $rawReceptor );
                        } else {
                                $documentData['Encabezado']['Receptor'] = $this->sanitize_section( (array) $documentData['Encabezado']['Receptor'] );
                        }
                }

                if ( $preview ) {
                        $this->debug_log( '[preview] emisor=' . json_encode( $documentData['Encabezado']['Emisor'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                        $this->debug_log( '[preview] detalle=' . json_encode( $documentData['Detalle'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                }

                $tasa_iva = null;
                if ( isset( $data['Encabezado']['Totales']['TasaIVA'] ) ) {
                        $tasa_iva = (float) $data['Encabezado']['Totales']['TasaIVA'];
                } elseif ( isset( $template['Encabezado']['Totales']['TasaIVA'] ) ) {
                        $tasa_iva = (float) $template['Encabezado']['Totales']['TasaIVA'];
                }
                if ( null === $tasa_iva && in_array( $tipo, array( 33, 39, 43, 46 ), true ) ) {
                        $tasa_iva = 19.0;
                }
                if ( ! isset( $documentData['Encabezado'] ) ) {
                        $documentData['Encabezado'] = array();
                }
                $documentData['Encabezado']['Totales'] = array( 'MntTotal' => 0 );
                if ( null !== $tasa_iva ) {
                        $documentData['Encabezado']['Totales']['TasaIVA'] = $tasa_iva;
                }



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

                $xmlString = $bag->getDocument()->saveXml();
                if ( ! is_string( $xmlString ) ) {
                        return '';
                }

                $xmlString = $this->strip_placeholder_fields(
                        $xmlString,
                        $rawReceptor,
                        ! empty( $data['Referencias'] )
                );

                return $xmlString;
        }

        /**
         * Removes null/empty values from a section to avoid leaking placeholder data.
         *
         * @param array<string, mixed> $values Section data.
         * @return array<string, mixed>
         */
        private function sanitize_section( array $values ): array {
                $clean = array();

                foreach ( $values as $key => $value ) {
                        if ( is_array( $value ) ) {
                                $nested = $this->sanitize_section( $value );
                                if ( ! empty( $nested ) ) {
                                        $clean[ $key ] = $nested;
                                }
                                continue;
                        }

                        if ( null === $value ) {
                                continue;
                        }

                        if ( is_string( $value ) ) {
                                $value = trim( $value );
                                if ( '' === $value ) {
                                        continue;
                                }
                        }

                        $clean[ $key ] = $value;
                }

                return $clean;
        }

        /**
         * Strips automatically populated nodes that were not requested by the caller.
         */
        private function strip_placeholder_fields( string $xml, array $rawReceptor, bool $hasReferences ): string {
                $previous = libxml_use_internal_errors( true );
                $document = new \DOMDocument();

                if ( ! $document->loadXML( $xml ) ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return $xml;
                }

                $xpath = new \DOMXPath( $document );
                $xpath->registerNamespace( 'dte', 'http://www.sii.cl/SiiDte' );

                $providedKeys = array();
                foreach ( $rawReceptor as $key => $value ) {
                        if ( is_string( $value ) ) {
                                $value = trim( $value );
                        }
                        if ( '' === $value || null === $value ) {
                                continue;
                        }
                        $providedKeys[ $key ] = true;
                }

                $optionalFields = array(
                        'DirRecep',
                        'CmnaRecep',
                        'CiudadRecep',
                        'Contacto',
                        'CorreoRecep',
                        'DirPostal',
                        'CmnaPostal',
                        'CiudadPostal',
                        'CdgIntRecep',
                        'Telefono',
                        'TelRecep',
                );

                $receptorNodes = $xpath->query( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Receptor' );
                if ( $receptorNodes instanceof \DOMNodeList && $receptorNodes->length > 0 ) {
                        $receptor = $receptorNodes->item( 0 );
                        foreach ( $optionalFields as $field ) {
                                if ( isset( $providedKeys[ $field ] ) ) {
                                        continue;
                                }
                                $fieldNodes = $xpath->query( 'dte:' . $field, $receptor );
                                if ( ! ( $fieldNodes instanceof \DOMNodeList ) ) {
                                        continue;
                                }
                                for ( $i = $fieldNodes->length - 1; $i >= 0; --$i ) {
                                        $node = $fieldNodes->item( $i );
                                        if ( $node instanceof \DOMNode && $node->parentNode === $receptor ) {
                                                $receptor->removeChild( $node );
                                        }
                                }
                        }
                }

                if ( ! $hasReferences ) {
                        $refNodes = $xpath->query( '/dte:DTE/dte:Documento/dte:Referencia' );
                        if ( $refNodes instanceof \DOMNodeList ) {
                                for ( $i = $refNodes->length - 1; $i >= 0; --$i ) {
                                        $node = $refNodes->item( $i );
                                        if ( $node instanceof \DOMNode && $node->parentNode instanceof \DOMNode ) {
                                                $node->parentNode->removeChild( $node );
                                        }
                                }
                        }
                }

                $result = $document->saveXML() ?: $xml;
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
                return $result;
        }

        /**
         * Renders a PDF using LibreDTE templates.
         */
        private function debug_log( string $message ): void {
                if ( ! function_exists( 'wp_upload_dir' ) ) {
                        error_log( $message );
                        return;
                }
                $uploads = wp_upload_dir();
                $base = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
                        ? $uploads['basedir']
                        : ( defined( 'ABSPATH' ) ? ABSPATH : sys_get_temp_dir() );
                $dir = rtrim( (string) $base, '/\\' ) . '/sii-boleta-logs';
                if ( function_exists( 'wp_mkdir_p' ) ) {
                        wp_mkdir_p( $dir );
                } elseif ( ! is_dir( $dir ) ) {
                        @mkdir( $dir, 0755, true );
                }
                $file = $dir . '/debug.log';
                $line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;
                @file_put_contents( $file, $line, FILE_APPEND );
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

                if ( null !== $parsedXml ) {
                        $parsedXml = $this->reset_total_before_rendering( $parsedXml );
                        $options['normalizer'] = array( 'normalize' => false );
                        $bag = new DocumentBag( parsedData: $parsedXml, options: $options );
                        $bag->setNormalizedData( $parsedXml );
                } else {
                        $bag = new DocumentBag( $xml, options: $options );
                }

                $pdf  = $this->renderer->render( $bag );
                $file = tempnam( sys_get_temp_dir(), 'pdf' );
                file_put_contents( $file, $pdf );
                return $file;
        }

       /** Loads YAML template for a given DTE type exclusively from
        * resources/yaml/documentos_ok/ (carpetas por tipo).
         */
        private function load_template( int $tipo ): array {
                $root = dirname( __DIR__, 2 ) . '/resources/yaml/';

                // Foldered layout copied from LibreDTE fixtures
               $dir = $root . 'documentos_ok/' . sprintf( '%03d', $tipo ) . '*';
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
         * Normalizes total fields before passing parsed data into LibreDTE renderers.
         */
        private function reset_total_before_rendering( ?array $parsedXml ): ?array {
                if ( null === $parsedXml || ! isset( $parsedXml['Encabezado']['Totales'] ) ) {
                        return $parsedXml;
                }

                $totals = $parsedXml['Encabezado']['Totales'];
                if ( ! is_array( $totals ) ) {
                        return $parsedXml;
                }

                $parsedXml['Encabezado']['Totales'] = $this->normalize_totals_for_rendering( $totals );

                return $parsedXml;
        }

        /**
         * Normalizes nested amount structures before sending data to the renderer.
         *
         * @param array<string,mixed> $values Totals subsection.
         * @return array<string,mixed>
         */
        private function normalize_totals_for_rendering( array $values ): array {
                foreach ( $values as $key => $value ) {
                        if ( 'TasaIVA' === $key ) {
                                if ( is_numeric( $value ) ) {
                                        $values[ $key ] = (float) $value;
                                } else {
                                        unset( $values[ $key ] );
                                }
                                continue;
                        }

                        if ( is_array( $value ) ) {
                                $values[ $key ] = $this->normalize_totals_for_rendering( $value );
                                if ( empty( $values[ $key ] ) ) {
                                        unset( $values[ $key ] );
                                }
                                continue;
                        }

                        if ( is_numeric( $value ) ) {
                                $values[ $key ] = (int) round( (float) $value );
                                continue;
                        }

                        unset( $values[ $key ] );
                }

                return $values;
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

                if ( isset( $data['Encabezado']['Emisor'] ) && is_array( $data['Encabezado']['Emisor'] ) ) {
                        $emisor = &$data['Encabezado']['Emisor'];

                        if ( isset( $emisor['RznSocEmisor'] ) && ! isset( $emisor['RznSoc'] ) ) {
                                $emisor['RznSoc'] = $emisor['RznSocEmisor'];
                        } elseif ( isset( $emisor['RznSoc'] ) && ! isset( $emisor['RznSocEmisor'] ) ) {
                                $emisor['RznSocEmisor'] = $emisor['RznSoc'];
                        }

                        if ( isset( $emisor['GiroEmisor'] ) && ! isset( $emisor['GiroEmis'] ) ) {
                                $emisor['GiroEmis'] = $emisor['GiroEmisor'];
                        } elseif ( isset( $emisor['GiroEmis'] ) && ! isset( $emisor['GiroEmisor'] ) ) {
                                $emisor['GiroEmisor'] = $emisor['GiroEmis'];
                        }
                }

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
