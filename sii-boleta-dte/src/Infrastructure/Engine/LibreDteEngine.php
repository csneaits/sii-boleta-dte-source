<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use JsonException;
use SimpleXMLElement;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Engine\Caf\CafProviderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Caf\CafResolutionException;
use Sii\BoletaDte\Infrastructure\Engine\Caf\LibreDteCafProvider;
use Sii\BoletaDte\Infrastructure\Engine\Certificate\CertificateProviderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Certificate\LibreDteCertificateProvider;
use Sii\BoletaDte\Infrastructure\Engine\EmptyReceptorProvider;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\DteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\Factory\DteDocumentFactoryRegistry;
use Sii\BoletaDte\Infrastructure\Engine\Preparation\DocumentPreparationPipelineInterface;
use Sii\BoletaDte\Infrastructure\Engine\Preparation\FactoryBackedDocumentPreparationPipeline;
use Sii\BoletaDte\Infrastructure\Engine\Xml\ReceptorPlaceholderCleaner;
use Sii\BoletaDte\Infrastructure\Engine\Xml\XmlPlaceholderCleaner;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Settings;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentBag;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\BuilderWorker;
use libredte\lib\Core\Package\Billing\Component\Document\Worker\RendererWorker;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorProviderInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\ReceptorFactory;

/**
 * DTE engine backed by LibreDTE library.
 */
class LibreDteEngine implements DteEngine {
    private Settings $settings;
    private BuilderWorker $builder;
    private RendererWorker $renderer;
    private DteDocumentFactoryRegistry $documentFactoryRegistry;
    private SectionSanitizer $sectionSanitizer;
    private XmlPlaceholderCleaner $placeholderCleaner;
    private DocumentPreparationPipelineInterface $preparationPipeline;
    private CafProviderInterface $cafProvider;
    private CertificateProviderInterface $certificateProvider;

    public function __construct(
        Settings $settings,
        ?DteDocumentFactoryRegistry $factoryRegistry = null,
        ?SectionSanitizer $sectionSanitizer = null,
        ?DocumentPreparationPipelineInterface $preparationPipeline = null,
        ?CafProviderInterface $cafProvider = null,
        ?CertificateProviderInterface $certificateProvider = null,
        ?XmlPlaceholderCleaner $placeholderCleaner = null
    ) {
        $this->settings = $settings;
        $this->sectionSanitizer = $sectionSanitizer ?? new SectionSanitizer();
        $templatesRoot = dirname(__DIR__, 2) . '/resources/yaml/';
        $this->documentFactoryRegistry = $factoryRegistry
            ?? DteDocumentFactoryRegistry::createDefault($templatesRoot, $this->sectionSanitizer);
        $this->preparationPipeline = $preparationPipeline
            ?? new FactoryBackedDocumentPreparationPipeline($this->sectionSanitizer);

        $app = Application::getInstance();
        $this->override_receptor_provider($app);
        $registry = $app->getPackageRegistry()->getBillingPackage();
        $component = $registry->getDocumentComponent();
        $this->builder = $component->getBuilderWorker();
        $this->renderer = $component->getRendererWorker();

        $identifier = $registry->getIdentifierComponent();
        $this->cafProvider = $cafProvider
            ?? new LibreDteCafProvider(
                $identifier->getCafLoaderWorker(),
                $identifier->getCafFakerWorker()
            );

        $certificateLoader = new CertificateLoader();
        $certificateFaker = new CertificateFaker($certificateLoader);
        $this->certificateProvider = $certificateProvider
            ?? new LibreDteCertificateProvider($certificateLoader, $certificateFaker);

        $this->placeholderCleaner = $placeholderCleaner ?? new ReceptorPlaceholderCleaner();
    }

    public function register_document_factory( int $tipo, DteDocumentFactory $factory ): void {
        $this->documentFactoryRegistry->registerFactory( $tipo, $factory );
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
        $environment = $this->settings->get_environment();

        $folioNumber = $this->extractFolio( $data );
        if ( ! $preview && $folioNumber > 0 && ! FoliosDb::find_for_folio( $tipo, $folioNumber, $environment ) ) {
            return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_missing_caf', 'Missing folio range' ) : false;
        }

        if ( $preview ) {
            $available_settings = array();
            foreach ( array( 'rut_emisor', 'razon_social', 'giro', 'direccion', 'comuna', 'cdg_vendedor' ) as $field ) {
                if ( isset( $settings[ $field ] ) && '' !== trim( (string) $settings[ $field ] ) ) {
                    $available_settings[] = $field;
                }
            }
            $this->debug_log( '[preview] settings fields=' . implode( ',', $available_settings ) );
        }

        $factory = $this->documentFactoryRegistry->getFactory( $tipo );
        $preparation = $this->preparationPipeline->prepare( $factory, $tipo, $data, $settings, $preview );

        $payload      = $preparation->getPayload();
        $documentData = $payload->getDocument();
        $rawReceptor  = $payload->getRawReceptor();

        if ( $preview ) {
            $emisor_fields = array_keys( array_filter( (array) $preparation->getEmisor() ) );
            $detalle_count = is_array( $preparation->getDetalle() ) ? count( $preparation->getDetalle() ) : 0;
            $this->debug_log( '[preview] emisor fields=' . implode( ',', $emisor_fields ) );
            $this->debug_log( '[preview] detalle count=' . $detalle_count );
        }

        $emisorData = $preparation->getEmisor();
        $emisorRut = (string) ( $emisorData['RUTEmisor'] ?? '' );
        $emisorName = (string) ( $emisorData['RznSocEmisor'] ?? '' );
        $emisorEntity = new Emisor( $emisorRut, $emisorName );

        $folio = (int) ( $documentData['Encabezado']['IdDoc']['Folio'] ?? 0 );

        try {
            $cafBag = $this->cafProvider->resolve( $tipo, $folio, $preview, $emisorEntity, $environment );
        } catch ( CafResolutionException $exception ) {
            $this->debug_log( '[error] CAF load failed: ' . $exception->getMessage() );
            if ( $exception->hadProvidedCaf() && '' !== trim( $exception->getCafXml() ) ) {
                $length = strlen( $exception->getCafXml() );
                $this->debug_log( '[error] CAF xml retained (bytes=' . $length . ')' );
            }

            return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_invalid_caf', 'Invalid CAF' ) : false;
        }

        $certificate = $this->certificateProvider->resolve( $settings, $emisorEntity );

        $bag = new DocumentBag( parsedData: $documentData, caf: $cafBag->getCaf(), certificate: $certificate );

        try {
            $this->builder->build( $bag );
        } catch ( \Throwable $e ) {
            $context = array(
                'tipo'        => $tipo,
                'preview'     => $preview,
                'folio'       => $documentData['Encabezado']['IdDoc']['Folio'] ?? null,
                'environment' => $environment,
            );
            $this->debug_log( '[error] Builder failed: ' . $e->getMessage() );
            $this->debug_log( '[error] Context=' . json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
            throw $e;
        }

        $xmlString = $bag->getDocument()->saveXml();
        if ( ! is_string( $xmlString ) ) {
            return '';
        }

        $totalsAdjuster = $factory->createTotalsAdjuster();
        if ( $totalsAdjuster->supports( $tipo ) ) {
            $xmlString = $totalsAdjuster->adjust(
                $xmlString,
                $preparation->getDetalle(),
                $tipo,
                $preparation->getTasaIva(),
                $preparation->getGlobalDiscounts()
            );
        }

        $xmlString = $this->placeholderCleaner->clean(
            $xmlString,
            $rawReceptor,
            $payload->hasReferences()
        );

        return $xmlString;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractFolio( array $data ): int {
        if ( isset( $data['Folio'] ) ) {
            return (int) $data['Folio'];
        }

        if ( isset( $data['Encabezado']['IdDoc']['Folio'] ) ) {
            return (int) $data['Encabezado']['IdDoc']['Folio'];
        }

        return 0;
    }

    /**
     * Renders a PDF using LibreDTE templates.
     */
    private function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && ! constant('WP_DEBUG') ) {
            return;
        }

        $sanitized = $this->sanitize_debug_message( $message );
        if ( '' === $sanitized ) {
            return;
        }

        $dir = $this->resolve_secure_log_directory();
        if ( '' === $dir ) {
            error_log( $sanitized );
            return;
        }

        if ( function_exists( 'wp_mkdir_p' ) ) {
            wp_mkdir_p( $dir );
        } elseif ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0755, true );
        }

        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }

        $file = $dir . '/debug.log';
        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $sanitized . PHP_EOL;
        @file_put_contents( $file, $line, FILE_APPEND );
    }

    private function resolve_secure_log_directory(): string {
        if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
            $base = WP_CONTENT_DIR;
        } elseif ( function_exists( 'wp_upload_dir' ) ) {
            $uploads = wp_upload_dir();
            $base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';
        } else {
            $base = sys_get_temp_dir();
        }

        $base = rtrim( (string) $base, '/\\' );
        if ( '' === $base ) {
            return '';
        }

        return $base . '/sii-boleta-dte/private/logs';
    }

    private function sanitize_debug_message( string $message ): string {
        $message = trim( preg_replace( '/[\r\n]+/', ' ', $message ) );
        if ( '' === $message ) {
            return '';
        }

        $limit = 600;
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $message, 'UTF-8' ) > $limit ) {
                $message = mb_substr( $message, 0, $limit, 'UTF-8' ) . '…';
            }
        } elseif ( strlen( $message ) > $limit ) {
            $message = substr( $message, 0, $limit ) . '…';
        }

        return $message;
    }

    /**
     * Renders a PDF using LibreDTE templates.
     */
    public function render_pdf( string $xml, array $options = array() ): string {
        $xml       = mb_convert_encoding( $xml, 'UTF-8', 'ISO-8859-1' );
        $overrides = array();

        if ( isset( $options['document_overrides'] ) && is_array( $options['document_overrides'] ) ) {
            $overrides = $options['document_overrides'];
            unset( $options['document_overrides'] );
        }

        $options = array_replace_recursive(
            array(
                'parser'   => array( 'strategy' => 'default.xml' ),
                'renderer' => array( 'format' => 'pdf' ),
            ),
            $options
        );

        $parsedXml = $this->parse_document_data_from_xml( $xml );

        if ( null !== $parsedXml ) {
            if ( ! empty( $overrides ) ) {
                foreach ( $overrides as $key => $value ) {
                    $parsedXml[ $key ] = $value;
                }
            }
            $parsedXml               = $this->reset_total_before_rendering( $parsedXml );
            $options['normalizer']   = array( 'normalize' => false );
            // Normalize renderer options: if a template key is provided but no
            // explicit strategy, add a conservative default so older/newer
            // renderer implementations pick it up.
            if ( isset( $options['renderer'] ) && is_array( $options['renderer'] ) ) {
                if ( isset( $options['renderer']['template'] ) && ! isset( $options['renderer']['strategy'] ) ) {
                    $options['renderer']['strategy'] = 'template.estandar';
                }
                // If the template is the compact ticket (boleta), ensure we
                // provide an explicit paper size so HTML->PDF engines like mPDF
                // can create a proper 80mm-wide page instead of defaulting to A4.
                if ( isset( $options['renderer']['template'] ) && 'boleta_ticket' === $options['renderer']['template'] ) {
                    if ( ! isset( $options['renderer']['paper'] ) || ! is_array( $options['renderer']['paper'] ) ) {
                        // Use the page size provided by the user example (in mm).
                        $options['renderer']['paper'] = array(
                            'width'  => '215.9mm',
                            'height' => '225.8mm',
                        );
                    }
                    try {
                        if ( defined( 'WP_DEBUG' ) && constant('WP_DEBUG') ) {
                            $this->debug_log( '[debug] forcing boleta renderer paper=' . json_encode( $options['renderer']['paper'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                        }
                    } catch ( \Throwable $e ) {
                        // ignore logging failures
                    }
                }
            }

            $bag                     = new DocumentBag( parsedData: $parsedXml, options: $options );
            $bag->setNormalizedData( $parsedXml );
        } else {
            $bag = new DocumentBag( $xml, options: $options );
        }

        // Debug: capture renderer options and rendered HTML when debugging
        try {
            if ( defined( 'WP_DEBUG' ) && constant('WP_DEBUG') ) {
                $rendererOptions = $bag->getRendererOptions();
                $this->debug_log( '[debug] renderer options=' . json_encode( $rendererOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
            }
        } catch ( \Throwable $e ) {
            $this->debug_log( '[debug] renderer debug failed: ' . $e->getMessage() );
        }

        $pdf  = $this->renderer->render( $bag );
        $file = tempnam( sys_get_temp_dir(), 'pdf' );
        file_put_contents( $file, $pdf );

        // If possible, mirror the generated PDF into the WP uploads area
        // under sii-boleta-dte/private/last_renders so admins can download it
        // and inspect properties (Producer, page size). This is only attempted
        // when WP functions are available.
        try {
            $dest = '';
            if ( function_exists( 'wp_upload_dir' ) ) {
                $uploads = wp_upload_dir();
                $basedir = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? rtrim( $uploads['basedir'], '/\\' ) : '';
                if ( '' !== $basedir ) {
                    $dir = $basedir . '/sii-boleta-dte/private/last_renders';
                        if ( function_exists( 'wp_mkdir_p' ) ) {
                            wp_mkdir_p( $dir );
                        } elseif ( ! is_dir( $dir ) ) {
                            @mkdir( $dir, 0755, true );
                        }

                        // Use a dedicated debug folder so final PDFs never mix with
                        // transient/preview renders. Directory: last_renders_debug
                        $dir = $basedir . '/sii-boleta-dte/private/last_renders_debug';
                        if ( function_exists( 'wp_mkdir_p' ) ) {
                            wp_mkdir_p( $dir );
                        } elseif ( ! is_dir( $dir ) ) {
                            @mkdir( $dir, 0755, true );
                        }

                        $name = 'debug_render_' . gmdate( 'Ymd_His' ) . '_' . basename( $file ) . '.pdf';
                        $dest = $dir . '/' . $name;
                        @copy( $file, $dest );
                }
            }

            if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
                if ( '' !== $dest ) {
                    $this->debug_log( '[debug] rendered_pdf_copy=' . $dest );
                } else {
                    $this->debug_log( '[debug] rendered_pdf_temp=' . $file );
                }
            }
        } catch ( \Throwable $e ) {
            // Don't break rendering on logging/copy failures.
            try {
                $this->debug_log( '[debug] rendered_pdf_error=' . $e->getMessage() );
            } catch ( \Throwable $_ ) {
            }
        }

        return $file;
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
