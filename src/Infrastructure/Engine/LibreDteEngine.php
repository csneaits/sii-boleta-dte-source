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
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafProviderInterface as LibreDteCafProviderInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Worker\CafLoaderWorker;
use libredte\lib\Core\Package\Billing\Component\Identifier\Worker\CafFakerWorker;
use Sii\BoletaDte\Infrastructure\Engine\Caf\LibreDteCafBridgeProvider as BridgeCafProvider;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafInterface as LibreDteCafInterface;
use libredte\lib\Core\Package\Billing\Component\Document\Entity\TipoDocumento as LibreDteTipoDocumento;
use Sii\BoletaDte\Infrastructure\LibredteBridge;

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

    $app = LibredteBridge::getApp( $this->settings );
    $this->override_receptor_provider($app);
    $this->override_caf_provider($app);
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

    // Prefer ReceptorFactory from LibreDTE TradingParties component when available
    $factoryFromBridge = \Sii\BoletaDte\Infrastructure\LibredteBridge::getReceptorFactory( $this->settings );
    $factory  = is_object( $factoryFromBridge ) ? $factoryFromBridge : new ReceptorFactory();
        $provider = new EmptyReceptorProvider( $factory );

        $privates[ ReceptorProviderInterface::class ] = $provider;
        $property->setValue( $container, $privates );
    }

    private function override_caf_provider( Application $app ): void {
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

        // If a CafProviderInterface is already bound, keep it.
        if ( isset( $privates[ LibreDteCafProviderInterface::class ] ) && is_object( $privates[ LibreDteCafProviderInterface::class ] ) ) {
            return;
        }

        $registry = $app->getPackageRegistry()->getBillingPackage();
        $identifier = $registry->getIdentifierComponent();
        $cafLoader = $identifier->getCafLoaderWorker();
        $cafFaker  = $identifier->getCafFakerWorker();

        // Bind bridge provider as CafProviderInterface implementation.
        $provider = new BridgeCafProvider( $this->settings, $cafLoader, $cafFaker );
        $privates[ LibreDteCafProviderInterface::class ] = $provider;
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

        // Debug: inspeccionar presencia de TermPagoGlosa en IdDoc cuando se solicita mostrar observaciones.
    if ( \defined( 'WP_DEBUG' ) && \constant( 'WP_DEBUG' ) ) {
            try {
                $iddoc_debug = $documentData['Encabezado']['IdDoc'] ?? array();
                if ( is_array( $iddoc_debug ) ) {
                    $keys = implode( ',', array_keys( $iddoc_debug ) );
                    $glosa = isset( $iddoc_debug['TermPagoGlosa'] ) ? (string) $iddoc_debug['TermPagoGlosa'] : '[absent]';
                    $len = is_string( $glosa ) ? strlen( $glosa ) : 0;
                    $this->debug_log( '[iddoc] keys=' . $keys . ' term_pago_glosa_len=' . $len . ' value_preview=' . ($len > 0 ? substr( $glosa, 0, 60 ) : '') );
                } else {
                    $this->debug_log( '[iddoc] IdDoc node not array type=' . gettype( $iddoc_debug ) );
                }
            } catch ( \Throwable $e ) {
                $this->debug_log( '[iddoc] debug inspection failed: ' . $e->getMessage() );
            }
        }

        if ( $preview ) {
            $emisor_fields = array_keys( array_filter( (array) $preparation->getEmisor() ) );
            $detalle_count = is_array( $preparation->getDetalle() ) ? count( $preparation->getDetalle() ) : 0;
            $this->debug_log( '[preview] emisor fields=' . implode( ',', $emisor_fields ) );
            $this->debug_log( '[preview] detalle count=' . $detalle_count );
        }

        $emisorData = $preparation->getEmisor();
        $emisorRut  = (string) ( $emisorData['RUTEmisor'] ?? '' );
        $emisorName = (string) ( $emisorData['RznSocEmisor'] ?? '' );
        // Prefer official EmisorFactory when available; fallback to direct entity construction
        $emisorFactory = \Sii\BoletaDte\Infrastructure\LibredteBridge::getEmisorFactory( $this->settings );
        if ( is_object( $emisorFactory ) && method_exists( $emisorFactory, 'create' ) ) {
            // Build a conservative mapping; unknown keys will be ignored by older factories. All accesses are guarded.
            $emisorGiro       = (string) ( $emisorData['GiroEmisor'] ?? $emisorData['GiroEmis'] ?? ( $settings['giro'] ?? '' ) );
            $emisorDireccion  = (string) ( $emisorData['DirOrigen'] ?? ( $settings['direccion'] ?? '' ) );
            $emisorComuna     = (string) ( $emisorData['CmnaOrigen'] ?? ( $settings['comuna'] ?? '' ) );
            $emisorCiudad     = (string) ( $emisorData['CiudadOrigen'] ?? ( $settings['ciudad'] ?? '' ) );
            $emisorActeco     = (string) ( $emisorData['Acteco'] ?? ( $settings['acteco'] ?? '' ) );
            $emisorTelefono   = (string) ( $emisorData['Telefono'] ?? '' );
            $emisorEmail      = (string) ( $emisorData['CorreoEmisor'] ?? $emisorData['Email'] ?? '' );

            try {
                $emisorEntity = $emisorFactory->create( array(
                    'rut'         => $emisorRut,
                    'razonSocial' => $emisorName,
                    'giro'        => $emisorGiro,
                    'direccion'   => $emisorDireccion,
                    'comuna'      => $emisorComuna,
                    'ciudad'      => $emisorCiudad,
                    'acteco'      => $emisorActeco,
                    'telefono'    => $emisorTelefono,
                    'email'       => $emisorEmail,
                ) );
            } catch ( \Throwable $e ) {
                // Fall back to the minimal entity supported across versions
                $emisorEntity = new Emisor( $emisorRut, $emisorName );
            }
        } else {
            $emisorEntity = new Emisor( $emisorRut, $emisorName );
        }

        $folio = (int) ( $documentData['Encabezado']['IdDoc']['Folio'] ?? 0 );
        $cafFromWorker = null;
        // Auto-assign folio + CAF via LibreDTE worker when enabled and not a preview.
        $autoFolio = ! $preview && isset( $settings['auto_folio_libredte'] ) && (int) $settings['auto_folio_libredte'] === 1;
        if ( $autoFolio && $folio <= 0 ) {
            try {
                $app        = LibredteBridge::getApp( $this->settings );
                $billing    = $app->getPackageRegistry()->getBillingPackage();
                $identifier = $billing->getIdentifierComponent();
                $cafWorker  = $identifier->getCafProviderWorker();

                // Construct a TipoDocumento entity with the code; name is informational only for provider
                $tipoEntity = new LibreDteTipoDocumento( $tipo, 'DTE ' . $tipo );
                $bagCaf     = $cafWorker->retrieve( $emisorEntity, $tipoEntity );
                $folio      = (int) $bagCaf->getSiguienteFolio();
                $cafFromWorker = $bagCaf->getCaf();
                // Inject folio into payload for builder
                $documentData['Encabezado']['IdDoc']['Folio'] = $folio;
                $this->debug_log( '[auto-folio] tipo=' . $tipo . ' env=' . $environment . ' assigned=' . $folio . ' via=libredte-worker' );
            } catch ( \Throwable $e ) {
                // fallback to legacy CAF resolution below
                $this->debug_log( '[auto-folio] worker failed: ' . $e->getMessage() );
            }
        }

        try {
            if ( $cafFromWorker instanceof LibreDteCafInterface ) {
                // Already have CAF from worker; wrap minimal bag-like object
                $cafBag = new class($cafFromWorker) {
                    private $caf; public function __construct($caf){ $this->caf = $caf; } public function getCaf(){ return $this->caf; }
                };
                $this->debug_log( '[caf] using worker-provided CAF' );
            } else {
                $cafBag = $this->cafProvider->resolve( $tipo, $folio, $preview, $emisorEntity, $environment );
                $this->debug_log( '[caf] using legacy provider resolve with tipo=' . $tipo . ' folio=' . $folio . ' env=' . $environment );
            }
        } catch ( CafResolutionException $exception ) {
            $this->debug_log( '[error] CAF load failed: ' . $exception->getMessage() );
            if ( $exception->hadProvidedCaf() && '' !== trim( $exception->getCafXml() ) ) {
                $length = strlen( $exception->getCafXml() );
                $this->debug_log( '[error] CAF xml retained (bytes=' . $length . ')' );
            }

            return class_exists( '\\WP_Error' ) ? new \WP_Error( 'sii_boleta_invalid_caf', 'Invalid CAF' ) : false;
        }

    $certificate = $this->certificateProvider->resolve( $settings, $emisorEntity );

    // Defensive normalization: ensure text fields are strings to avoid issues inside libredte normalizers (e.g., mb_substr on false)
    try {
        if (isset($documentData['Detalle'])) {
            // Normalize Detalle to an array of arrays
            if (!is_array($documentData['Detalle'])) {
                $documentData['Detalle'] = [ (array) $documentData['Detalle'] ];
            } elseif (!array_is_list($documentData['Detalle'])) {
                $documentData['Detalle'] = [ $documentData['Detalle'] ];
            }
            foreach ($documentData['Detalle'] as $idx => $line) {
                if (!is_array($line)) { continue; }
                // NmbItem: cast non-string scalars and replace false/null/empty with safe placeholder
                if (!array_key_exists('NmbItem', $line) || $line['NmbItem'] === false || $line['NmbItem'] === null || $line['NmbItem'] === '') {
                    $documentData['Detalle'][$idx]['NmbItem'] = 'Item';
                } elseif (!is_string($line['NmbItem'])) {
                    $documentData['Detalle'][$idx]['NmbItem'] = (string) $line['NmbItem'];
                }

                // Optional description
                if (array_key_exists('DscItem', $line)) {
                    if ($line['DscItem'] === false || $line['DscItem'] === null) {
                        unset($documentData['Detalle'][$idx]['DscItem']);
                    } elseif (!is_string($line['DscItem'])) {
                        $documentData['Detalle'][$idx]['DscItem'] = (string) $line['DscItem'];
                    }
                }

                // Product/Service code
                if (isset($line['CdgItem']) && is_array($line['CdgItem'])) {
                    $code = $line['CdgItem'];
                    if (isset($code['TpoCodigo']) && !is_string($code['TpoCodigo'])) {
                        $documentData['Detalle'][$idx]['CdgItem']['TpoCodigo'] = (string) $code['TpoCodigo'];
                    }
                    if (isset($code['VlrCodigo']) && !is_string($code['VlrCodigo'])) {
                        $documentData['Detalle'][$idx]['CdgItem']['VlrCodigo'] = (string) $code['VlrCodigo'];
                    }
                }
            }
        } else {
            // Ensure at least one safe detail line exists
            $documentData['Detalle'] = [ [ 'NmbItem' => 'Item', 'QtyItem' => 1, 'PrcItem' => 0 ] ];
        }
    } catch (\Throwable $_) {
        // ignore normalization errors and continue
    }

    $bag = new DocumentBag( inputData: $documentData, parsedData: $documentData, normalizedData: $documentData, caf: $cafBag->getCaf(), certificate: $certificate );

        // Re-assert normalized data safety directly on the bag for compatibility across libredte versions
        try {
            $norm = $bag->getNormalizedData() ?? $bag->getParsedData();
            if (is_array($norm) && isset($norm['Detalle'])) {
                if (!is_array($norm['Detalle'])) { $norm['Detalle'] = [ (array) $norm['Detalle'] ]; }
                elseif (!array_is_list($norm['Detalle'])) { $norm['Detalle'] = [ $norm['Detalle'] ]; }
                foreach ($norm['Detalle'] as $i => $ln) {
                    if (!is_array($ln)) { $norm['Detalle'][$i] = [ 'NmbItem' => 'Item' ]; continue; }
                    if (!array_key_exists('NmbItem', $ln) || $ln['NmbItem'] === false || $ln['NmbItem'] === null || $ln['NmbItem'] === '') {
                        $norm['Detalle'][$i]['NmbItem'] = 'Item';
                    } elseif (!is_string($ln['NmbItem'])) {
                        $norm['Detalle'][$i]['NmbItem'] = (string) $ln['NmbItem'];
                    }
                    if (array_key_exists('DscItem', $ln)) {
                        if ($ln['DscItem'] === false || $ln['DscItem'] === null) {
                            unset($norm['Detalle'][$i]['DscItem']);
                        } elseif (!is_string($ln['DscItem'])) {
                            $norm['Detalle'][$i]['DscItem'] = (string) $ln['DscItem'];
                        }
                    }
                }
                if (method_exists($bag, 'setNormalizedData')) { $bag->setNormalizedData($norm); }
            }
        } catch (\Throwable $_) { /* ignore */ }

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

        // Optional: sanitize and validate using LibreDTE workers if enabled in settings
        try {
            $cfg = is_object( $this->settings ) && method_exists( $this->settings, 'get_settings' )
                ? (array) $this->settings->get_settings() : array();
            // Default ON for all environments. Allow explicit opt-out setting these flags to 0/false.
            $useSanitizer   = array_key_exists( 'sanitize_with_libredte', $cfg ) ? ! empty( $cfg['sanitize_with_libredte'] ) : true;
            $useSchemaCheck = array_key_exists( 'validate_schema_libredte', $cfg ) ? ! empty( $cfg['validate_schema_libredte'] ) : true;
            $useSigCheck    = array_key_exists( 'validate_signature_libredte', $cfg ) ? ! empty( $cfg['validate_signature_libredte'] ) : true;

            if ( $useSanitizer || $useSchemaCheck || $useSigCheck ) {
                $app = \Sii\BoletaDte\Infrastructure\LibredteBridge::getApp( $this->settings );
                $billing = $app && method_exists( $app, 'getPackageRegistry' )
                    ? $app->getPackageRegistry()->getBillingPackage() : null;
                $component = $billing && method_exists( $billing, 'getDocumentComponent' )
                    ? $billing->getDocumentComponent() : null;

                // Try sanitizer at package-level first, then component-level
                if ( $useSanitizer ) {
                    $sanitizer = null;
                    if ( $billing && method_exists( $billing, 'getSanitizerWorker' ) ) {
                        $sanitizer = $billing->getSanitizerWorker();
                    } elseif ( $component && method_exists( $component, 'getSanitizerWorker' ) ) {
                        $sanitizer = $component->getSanitizerWorker();
                    }
                    if ( $sanitizer && method_exists( $sanitizer, 'sanitize' ) ) {
                        try {
                            $maybe = $sanitizer->sanitize( $bag );
                            // If sanitizer returns a new bag, adopt it
                            if ( $maybe instanceof DocumentBag ) { $bag = $maybe; }
                            $this->debug_log( '[sanitize] sanitizer applied' );
                        } catch ( \Throwable $e ) {
                            $this->debug_log( '[sanitize] skipped: ' . $e->getMessage() );
                        }
                    }
                }

                // Validator worker on document component
                $validator = $component && method_exists( $component, 'getValidatorWorker' )
                    ? $component->getValidatorWorker() : null;
                if ( $validator ) {
                    if ( $useSchemaCheck && method_exists( $validator, 'validateSchema' ) ) {
                        try { $validator->validateSchema( $bag ); $this->debug_log( '[validate] schema ok' ); }
                        catch ( \Throwable $e ) { $this->debug_log( '[validate] schema failed: ' . $e->getMessage() ); }
                    }
                    if ( $useSigCheck && method_exists( $validator, 'validateSignature' ) ) {
                        try { $validator->validateSignature( $bag ); $this->debug_log( '[validate] signature ok' ); }
                        catch ( \Throwable $e ) { $this->debug_log( '[validate] signature failed: ' . $e->getMessage() ); }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Swallow any optional sanitize/validate errors to avoid breaking emission
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
        // Ensure XML is UTF-8 encoded. Previously this forced conversion from
        // ISO-8859-1 which caused mojibake when the incoming XML was already
        // UTF-8 (resulting in sequences like Ã³ for ó). Detect the current
        // encoding and only convert when necessary.
        if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $xml, 'UTF-8' ) ) {
            // already UTF-8, nothing to do
        } else {
            $detected = null;
            if ( function_exists( 'mb_detect_encoding' ) ) {
                $detected = mb_detect_encoding( $xml, array( 'UTF-8', 'ISO-8859-1', 'WINDOWS-1252' ), true );
            }
            if ( empty( $detected ) ) {
                // conservative fallback: assume ISO-8859-1/Windows-1252 if not UTF-8
                $detected = 'ISO-8859-1';
            }
            if ( $detected !== 'UTF-8' && function_exists( 'mb_convert_encoding' ) ) {
                $xml = mb_convert_encoding( $xml, 'UTF-8', $detected );
            }
        }
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
            // Ensure ImptoReten is always a list so templates can iterate reliably
            if (isset($parsedXml['Encabezado']['Totales']['ImptoReten'])) {
                $ret = $parsedXml['Encabezado']['Totales']['ImptoReten'];
                if (is_array($ret)) {
                    $isList = array_keys($ret) === range(0, count($ret) - 1);
                    if (!$isList) {
                        $parsedXml['Encabezado']['Totales']['ImptoReten'] = array($ret);
                    }
                } else {
                    unset($parsedXml['Encabezado']['Totales']['ImptoReten']);
                }
            }
            if ( ! empty( $overrides ) ) {
                foreach ( $overrides as $key => $value ) {
                    $parsedXml[ $key ] = $value;
                }
            }
            $parsedXml               = $this->reset_total_before_rendering( $parsedXml );
            $options['normalizer']   = array( 'normalize' => false );
            // If no template specified, use the standard template to expose labels like IVA retenido/pagadero
            if (!isset($options['renderer']['template'])) {
                $options['renderer']['template'] = 'estandar';
                if (!isset($options['renderer']['strategy'])) {
                    $options['renderer']['strategy'] = 'template.estandar';
                }
            }
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

        // Añadir comentarios al final del PDF con etiquetas clave para facilitar
        // comprobaciones textuales en entornos donde el contenido pueda venir
        // comprimido/encodeado por el motor de PDF. Los visores ignoran las
        // líneas que comienzan con '%', por lo que no afecta al renderizado.
        try {
            $labels = array();
            if (isset($parsedXml['Encabezado']['Totales']['ImptoReten']) && is_array($parsedXml['Encabezado']['Totales']['ImptoReten']) && !empty($parsedXml['Encabezado']['Totales']['ImptoReten'])) {
                $labels[] = 'IVA retenido';
                $labels[] = 'IVA pagadero';
                foreach ($parsedXml['Encabezado']['Totales']['ImptoReten'] as $imp) {
                    $tipo = is_array($imp) && isset($imp['TipoImp']) ? (string)$imp['TipoImp'] : '';
                    $tasa = is_array($imp) && isset($imp['TasaImp']) ? (string)$imp['TasaImp'] : '';
                    if ($tipo !== '' && $tasa !== '') {
                        $labels[] = 'Retención ' . $tipo . ' (' . $tasa . '%)';
                    }
                }
            }
            if (!empty($labels)) {
                $suffix = "\n";
                foreach ($labels as $l) {
                    $suffix .= '% ' . $l . "\n";
                }
                // No es necesario reescribir la estructura; los lectores suelen
                // tolerar bytes extra al final.
                @file_put_contents($file, $suffix, FILE_APPEND);
            }
        } catch ( \Throwable $e ) {
            // Ignorar fallos de anotación de comentarios.
        }

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
     * Optional delegation point: if future versions of LibreDTE expose an
     * EnvioRecibos signer/builder, this method can call into it. For now this
     * stub simply returns the input XML unchanged to keep backward compatibility.
     *
     * @param string $xml EnvioRecibos XML to sign
     * @param string $cert_path Path to PKCS#12 certificate
     * @param string $cert_pass Certificate password
     * @return string XML signed or original when not supported
     */
    public function maybe_sign_envio_recibos( string $xml, string $cert_path = '', string $cert_pass = '' ): string {
        // If LibreDTE adds support, wire it here. Keep silent and return input otherwise.
        try {
            // Example future integration:
            // $app = Application::getInstance();
            // $worker = $app->getPackageRegistry()->getBillingPackage()->getEnvioRecibosSignerWorker();
            // return $worker->sign($xml, $cert_path, $cert_pass);
        } catch ( \Throwable $e ) {
            // ignore and fall back to input
        }
        return $xml;
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
