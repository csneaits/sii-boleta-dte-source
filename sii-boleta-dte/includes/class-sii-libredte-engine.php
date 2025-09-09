<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Motor DTE basado en LibreDTE. Si la librería no está disponible aún,
 * delega temporalmente en el motor nativo para mantener funcionalidad.
 */
class SII_LibreDTE_Engine implements SII_DTE_Engine {
    /** @var SII_Native_Engine */
    private $fallback;
    /** @var SII_Boleta_Settings */
    private $settings;

    public function __construct( SII_Native_Engine $fallback, SII_Boleta_Settings $settings ) {
        $this->fallback = $fallback;
        $this->settings = $settings;
    }

    private function lib_available() {
        return class_exists( '\\libredte\\lib\\Core\\Application' ) || class_exists( '\\libredte\\lib\\Core\\Kernel' );
    }

    public function generate_dte_xml( array $data, $tipo_dte, $preview = false ) {
        if ( ! $this->lib_available() ) {
            return class_exists('WP_Error') ? new \WP_Error('sii_libredte_missing', __('LibreDTE no está disponible.', 'sii-boleta-dte')) : false;
        }

        try {
            // Construir estructura normalizada esperada por LibreDTE a partir de los datos del plugin.
            $tipo = intval( $tipo_dte );
            $folio = isset( $data['Folio'] ) ? intval( $data['Folio'] ) : 0;
            $fchEmis = isset( $data['FchEmis'] ) ? (string) $data['FchEmis'] : date( 'Y-m-d' );

            $emisor = [
                'RUTEmisor' => (string) ( $data['RutEmisor'] ?? '' ),
                'RznSoc'    => (string) ( $data['RznSoc'] ?? ( $data['RznSocEmisor'] ?? '' ) ),
                'GiroEmis'  => (string) ( $data['GiroEmisor'] ?? ( $data['GiroEmis'] ?? '' ) ),
                'DirOrigen' => (string) ( $data['DirOrigen'] ?? '' ),
                'CmnaOrigen'=> (string) ( $data['CmnaOrigen'] ?? '' ),
            ];
            // Enriquecer con Ajustes (Acteco, CdgSIISucur) si no vienen en $data
            $opts_for_emisor = $this->settings->get_settings();
            if ( ! empty( $opts_for_emisor['acteco'] ) && empty( $emisor['Acteco'] ) ) {
                $emisor['Acteco'] = $opts_for_emisor['acteco'];
            }
            if ( ! empty( $opts_for_emisor['cdg_sii_sucur'] ) && empty( $emisor['CdgSIISucur'] ) ) {
                $emisor['CdgSIISucur'] = $opts_for_emisor['cdg_sii_sucur'];
            }
            $receptor_data = (array) ( $data['Receptor'] ?? [] );
            $receptor = [
                'RUTRecep'    => (string) ( $receptor_data['RUTRecep'] ?? '' ),
                'RznSocRecep' => (string) ( $receptor_data['RznSocRecep'] ?? '' ),
                'DirRecep'    => (string) ( $receptor_data['DirRecep'] ?? '' ),
                'CmnaRecep'   => (string) ( $receptor_data['CmnaRecep'] ?? '' ),
            ];
            if ( ! empty( $receptor_data['GiroRecep'] ) ) {
                $receptor['GiroRecep'] = (string) $receptor_data['GiroRecep'];
            }
            if ( ! empty( $receptor_data['CorreoRecep'] ) ) {
                $receptor['CorreoRecep'] = (string) $receptor_data['CorreoRecep'];
            }
            if ( ! empty( $receptor_data['TelefonoRecep'] ) ) {
                $receptor['TelefonoRecep'] = (string) $receptor_data['TelefonoRecep'];
            }

            $detalles = [];
            $sum_total = 0; // solo diagnóstico
            $is_boleta_exenta = ($tipo === 41);
            // Normalizar los detalles para asegurar índices numéricos secuenciales
            $input_detalles = array_values( (array) ( $data['Detalles'] ?? [] ) );
            foreach ( $input_detalles as $i => $det ) {
                $lin = [
                    'NroLinDet' => intval( $det['NroLinDet'] ?? ( $i + 1 ) ),
                    'NmbItem'   => (string) ( $det['NmbItem'] ?? '' ),
                    'QtyItem'   => (float)  ( $det['QtyItem'] ?? 1 ),
                    'PrcItem'   => (float)  ( $det['PrcItem'] ?? 0 ),
                ];
                // En boletas (39/41) los precios vienen con IVA incluido
                if ( in_array( $tipo, [39,41], true ) ) {
                    $lin['MntBruto'] = 1;
                }
                if ( isset( $det['IndExe'] ) && $det['IndExe'] ) {
                    $lin['IndExe'] = 1;
                }
                // Para boleta exenta (41), marcar exento por defecto
                if ( $is_boleta_exenta && ! isset( $lin['IndExe'] ) ) {
                    $lin['IndExe'] = 1;
                }
                if ( isset( $det['DescuentoMonto'] ) ) {
                    $lin['DescuentoMonto'] = intval( $det['DescuentoMonto'] );
                }
                if ( isset( $det['RecargoMonto'] ) ) {
                    $lin['RecargoMonto'] = intval( $det['RecargoMonto'] );
                }
                // No setear MontoItem; dejar que LibreDTE lo calcule
                $sum_total += intval( round( $lin['QtyItem'] * $lin['PrcItem'] ) );
                $detalles[] = $lin;
            }

            $normalized = [
                'Encabezado' => [
                    'IdDoc' => array_filter([
                        'TipoDTE'       => $tipo,
                        'Folio'         => $folio,
                        'FchEmis'       => $fchEmis,
                        // Campos opcionales si vienen en $data
                        'FmaPago'       => $data['FmaPago']       ?? null,
                        'FchVenc'       => $data['FchVenc']       ?? null,
                        'MedioPago'     => $data['MedioPago']     ?? null,
                        'TpoTranCompra' => $data['TpoTranCompra'] ?? null,
                        'TpoTranVenta'  => $data['TpoTranVenta']  ?? null,
                    ], function($v){ return $v !== null && $v !== ''; }),
                    'Emisor'   => $emisor,
                    'Receptor' => $receptor,
                    // Totales los calculará LibreDTE a partir del detalle
                ],
                'Detalle' => $detalles,
            ];

            // Datos de transporte para Guía (52).
            if ( $tipo === 52 ) {
                if ( ! empty( $data['IndTraslado'] ) ) {
                    $normalized['Encabezado']['IdDoc']['IndTraslado'] = $data['IndTraslado'];
                }
                $trans = [];
                if ( ! empty( $data['Patente'] ) ) { $trans['Patente'] = $data['Patente']; }
                if ( ! empty( $data['RUTTrans'] ) ) { $trans['RUTTrans'] = $data['RUTTrans']; }
                if ( ! empty( $data['RUTChofer'] ) && ! empty( $data['NombreChofer'] ) ) {
                    $trans['Chofer'] = [
                        'RUTChofer'    => $data['RUTChofer'],
                        'NombreChofer' => $data['NombreChofer'],
                    ];
                }
                if ( ! empty( $data['DirDest'] ) )  { $trans['DirDest']  = $data['DirDest']; }
                if ( ! empty( $data['CmnaDest'] ) ) { $trans['CmnaDest'] = $data['CmnaDest']; }
                if ( $trans ) {
                    $normalized['Encabezado']['Transporte'] = $trans;
                }
            }

            // Referencias si se incluyen.
            if ( ! empty( $data['Referencias'] ) && is_array( $data['Referencias'] ) ) {
                $refs = [];
                foreach ( $data['Referencias'] as $ref ) {
                    $refs[] = [
                        'TpoDocRef' => $ref['TpoDocRef'] ?? '',
                        'FolioRef'  => $ref['FolioRef']  ?? '',
                        'FchRef'    => $ref['FchRef']    ?? $fchEmis,
                        'RazonRef'  => $ref['RazonRef']  ?? false,
                    ];
                }
                $normalized['Referencia'] = $refs;
            }

            // Determinar CAF para timbrado.
            $opts      = $this->settings->get_settings();
            $caf_paths = isset( $opts['caf_path'] ) && is_array( $opts['caf_path'] ) ? $opts['caf_path'] : [];
            $caf_path  = $caf_paths[ $tipo ] ?? '';
            if ( ! $preview && ( empty( $caf_path ) || ! file_exists( $caf_path ) ) ) {
                if ( class_exists( '\\WP_Error' ) ) {
                    return new \WP_Error( 'sii_boleta_missing_caf', sprintf( __( 'No se encontró CAF para el tipo de DTE %s.', 'sii-boleta-dte' ), $tipo ) );
                }
                return false;
            }

            // Instanciar LibreDTE y construir el documento.
            $app = \libredte\lib\Core\Application::getInstance('prod', false);
            /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
            $billing  = $app->getPackageRegistry()->getPackage('billing');
            $document = $billing->getDocumentComponent();

            // Intento 1: construir y renderizar un bag desde datos normalizados
            $pdfContent = null;
            try {
                $raw = (string) $xml_or_signed_xml;
                // Sanear posibles BOM/controles
                if ( substr($raw,0,3) === "\xEF\xBB\xBF" ) { $raw = substr($raw,3); }
                $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$raw);
                libxml_use_internal_errors(true);
                $sx = simplexml_load_string($raw);
                if ( $sx ) {
                    $docNodes = $sx->xpath('//*[local-name()="Documento"]');
                    if ( $docNodes && !empty($docNodes[0]) ) {
                        $d    = $docNodes[0];
                        $tipo = intval((string)$d->Encabezado->IdDoc->TipoDTE);
                        $norm = [
                            'Encabezado' => [
                                'IdDoc' => [
                                    'TipoDTE' => $tipo,
                                    'Folio'   => intval((string)$d->Encabezado->IdDoc->Folio),
                                    'FchEmis' => (string)$d->Encabezado->IdDoc->FchEmis ?: date('Y-m-d'),
                                ],
                                'Emisor' => [
                                    'RUTEmisor' => (string)$d->Encabezado->Emisor->RUTEmisor,
                                    'RznSoc'    => (string)$d->Encabezado->Emisor->RznSoc,
                                    'GiroEmis'  => (string)$d->Encabezado->Emisor->GiroEmis,
                                    'DirOrigen' => (string)$d->Encabezado->Emisor->DirOrigen,
                                    'CmnaOrigen'=> (string)$d->Encabezado->Emisor->CmnaOrigen,
                                ],
                                'Receptor' => array_filter([
                                    'RUTRecep'      => (string)$d->Encabezado->Receptor->RUTRecep,
                                    'RznSocRecep'   => (string)$d->Encabezado->Receptor->RznSocRecep,
                                    'DirRecep'      => (string)$d->Encabezado->Receptor->DirRecep,
                                    'CmnaRecep'     => (string)$d->Encabezado->Receptor->CmnaRecep,
                                    'CorreoRecep'   => (string)$d->Encabezado->Receptor->CorreoRecep,
                                    'TelefonoRecep' => (string)$d->Encabezado->Receptor->TelefonoRecep,
                                ], function($v){ return $v!=='' && $v!==null; }),
                            ],
                        ];
                        $lines = $d->xpath('./*[local-name()="Detalle"]');
                        $items = [];
                        $i=1;
                        foreach ( (array)$lines as $ln ) {
                            $row = [
                                'NroLinDet' => intval((string)$ln->NroLinDet ?: $i),
                                'NmbItem'   => (string)$ln->NmbItem,
                                'QtyItem'   => (float)((string)$ln->QtyItem ?: 1),
                                'PrcItem'   => (float)((string)$ln->PrcItem ?: 0),
                            ];
                            if ( (string)$ln->IndExe === '1' ) { $row['IndExe'] = 1; }
                            if ( (string)$ln->DescuentoMonto !== '' ) { $row['DescuentoMonto'] = intval((string)$ln->DescuentoMonto); }
                            if ( (string)$ln->RecargoMonto   !== '' ) { $row['RecargoMonto']   = intval((string)$ln->RecargoMonto); }
                            if ( in_array($tipo,[39,41],true) ) { $row['MntBruto'] = 1; }
                            $items[] = $row; $i++;
                        }
                        $norm['Detalle'] = $items;
                        if ( !empty($items) ) {
                            $bagN = $document->bill($norm, null, null, []);
                            $renderer = method_exists($document,'getRendererWorker') ? $document->getRendererWorker() : null;
                            if ( $renderer && method_exists($renderer,'render') ) { $pdfContent = $renderer->render($bagN); }
                            if ( !$pdfContent && method_exists($document,'getBuilderWorker') ) {
                                $builder = $document->getBuilderWorker();
                                if ( $builder && method_exists($builder,'renderPdf') ) { $pdfContent = $builder->renderPdf($bagN); }
                            }
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                if ( function_exists('sii_boleta_write_log') ) { sii_boleta_write_log('LibreDTE render(normalized) error: '.$e->getMessage(), 'ERROR'); }
            }

            // (debug): se eliminó intento de render desde XML dentro de generate_dte_xml

            // Pasar CAF solo si no es previsualización (para generar TED).
            $cafForBuild = $preview ? null : $caf_path;

            $bag = $document->bill(
                $normalized,
                $cafForBuild,
                null,
                []
            );

            // Obtener XML (sin firma si no se pasó certificado).
            $xml = $bag->getXmlDocument()->saveXML();
            return $xml ?: false;
        } catch ( \Throwable $e ) {
            if ( function_exists( 'sii_boleta_write_log' ) ) {
                sii_boleta_write_log( 'LibreDTE generate_dte_xml error: ' . $e->getMessage(), 'ERROR' );
            }
            return class_exists('WP_Error') ? new \WP_Error('sii_libredte_error', $e->getMessage()) : false;
        }
    }

    public function sign_dte_xml( $xml ) {
        if ( ! $this->lib_available() ) {
            return false;
        }

        try {
            // Cargar settings y certificado.
            $opts      = $this->settings->get_settings();
            $cert_path = $opts['cert_path'] ?? '';
            $cert_pass = $opts['cert_pass'] ?? '';
            if ( ! $cert_path || ! file_exists( $cert_path ) ) {
                return false;
            }

            // Instanciar aplicación LibreDTE y obtener componentes.
            $app = \libredte\lib\Core\Application::getInstance('prod', false);
            /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
            $billing = $app->getPackageRegistry()->getPackage('billing');
            $document = $billing->getDocumentComponent();

            // Crear bolsa desde XML existente.
            $bag = $document->getLoaderWorker()->loadXml( (string) $xml );

            // No re-normalizar ni re-timbrar, solo firmar con el certificado.
            $bag->getOptions()->set('normalizer.normalize', false);

            // Cargar certificado PFX/P12.
            $loader = new \Derafu\Certificate\Service\CertificateLoader();
            $certificate = $loader->loadFromFile( $cert_path, $cert_pass );
            $bag = $bag->withCertificate( $certificate );

            // Firmar el DTE y obtener XML firmado.
            $document->getBuilderWorker()->build( $bag );
            $signed = $bag->getXmlDocument()->saveXML();
            return $signed ?: false;
        } catch ( \Throwable $e ) {
            if ( function_exists( 'sii_boleta_write_log' ) ) {
                sii_boleta_write_log( 'LibreDTE sign_dte_xml error: ' . $e->getMessage(), 'ERROR' );
            }
            return false;
        }
    }

    public function send_dte_file( $file_path, $environment, $token, $cert_path, $cert_pass ) {
        if ( ! $this->lib_available() ) {
            return $this->fallback->send_dte_file( $file_path, $environment, $token, $cert_path, $cert_pass );
        }

        try {
            if ( ! is_readable( $file_path ) ) {
                return false;
            }

            $xml_dte = file_get_contents( $file_path );
            if ( ! $xml_dte ) {
                return false;
            }

            // Instanciar aplicación y componentes de LibreDTE.
            $app = \libredte\lib\Core\Application::getInstance('prod', false);
            /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
            $billing = $app->getPackageRegistry()->getPackage('billing');

            $document   = $billing->getDocumentComponent();
            $dispatcher = $billing->getDocumentComponent()->getDispatcherWorker();
            $integration = $billing->getIntegrationComponent();

            // Cargar bolsa desde el XML del DTE.
            $bag = $document->getLoaderWorker()->loadXml( $xml_dte );

            // Cargar certificado (usar parámetros si vienen, sino settings).
            if ( empty( $cert_path ) || ! file_exists( $cert_path ) ) {
                $opts = $this->settings->get_settings();
                $cert_path = $opts['cert_path'] ?? '';
                $cert_pass = $opts['cert_pass'] ?? '';
            }
            $loader = new \Derafu\Certificate\Service\CertificateLoader();
            $certificate = $loader->loadFromFile( $cert_path, $cert_pass );

            // Asegurar que el sobre de envío se cree con firma (agregando certificado a la bolsa/envelope).
            $bag = $bag->withCertificate( $certificate );
            $envelope = $dispatcher->create( $bag );

            // Preparar solicitud al SII con ambiente.
            $opts = $this->settings->get_settings();
            $rutEmisor = $opts['rut_emisor'] ?? '';
            if ( empty( $rutEmisor ) ) {
                return false;
            }

            $ambiente = ( 'production' === strtolower( (string) $opts['environment'] ) || 'production' === strtolower( (string) $environment ) )
                ? \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::PRODUCCION
                : \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::CERTIFICACION;

            $request = new \libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest(
                $certificate,
                [ 'ambiente' => $ambiente ]
            );

            // Validar sobre antes de enviar.
            $dispatcher->validate( $envelope );

            // Enviar y obtener TrackID.
            $trackId = $integration->getSiiLazyWorker()->sendXmlDocument(
                $request,
                $envelope->getXmlDocument(),
                $rutEmisor,
                false,
                null
            );

            if ( function_exists( 'sii_boleta_write_log' ) ) {
                sii_boleta_write_log( 'LibreDTE envío DTE OK. TrackID: ' . $trackId, 'INFO' );
            }
            if ( class_exists( 'SII_Boleta_Log_DB' ) ) {
                SII_Boleta_Log_DB::add_entry( (string) $trackId, 'sent', '' );
            }
            return (string) $trackId;
        } catch ( \Throwable $e ) {
            if ( function_exists( 'sii_boleta_write_log' ) ) {
                sii_boleta_write_log( 'LibreDTE send_dte_file error: ' . $e->getMessage(), 'ERROR' );
            }
            return false;
        }
    }

    public function render_pdf( $xml_or_signed_xml, array $settings ) {
        if ( ! $this->lib_available() ) {
            return false;
        }
        try {
            // Cargar LibreDTE y construir la bolsa desde el XML proporcionado
            $app = \libredte\lib\Core\Application::getInstance('prod', false);
            /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
            $billing  = $app->getPackageRegistry()->getPackage('billing');
            $document = $billing->getDocumentComponent();

            if ( ! $pdfContent ) { $bag = $document->getLoaderWorker()->loadXml( (string) $xml_or_signed_xml ); }
            // Al renderizar desde XML no se vuelve a normalizar: el renderer
            // trabaja sobre el XML del bag. Evita el error de normalización.
            if ( ! $pdfContent && method_exists( $bag, 'getOptions' ) ) {
                $opts = $bag->getOptions();
                if ( $opts && method_exists( $opts, 'set' ) ) {
                    $opts->set( 'normalizer.normalize', false );
                }
            }

            if ( ! $pdfContent && method_exists( $document, 'getRendererWorker' ) ) {
                $renderer = $document->getRendererWorker();
                if ( $renderer && method_exists( $renderer, 'render' ) ) {
                    $pdfContent = $renderer->render( $bag );
                }
            }
            // Alternativa: algunos builds exponen renderPDF en el builder
            if ( ! $pdfContent && method_exists( $document, 'getBuilderWorker' ) ) {
                $builder = $document->getBuilderWorker();
                if ( $builder && method_exists( $builder, 'renderPdf' ) ) {
                    $pdfContent = $builder->renderPdf( $bag );
                }
            }

            if ( ! $pdfContent ) {
                return false;
            }

            // Si devolvió un objeto TCPDF, inyectar logo y asegurar detalle visible
            if ( is_object( $pdfContent ) && method_exists( $pdfContent, 'Output' ) ) {
                // Inyectar logo si existe
                try {
                    if ( ! empty( $settings['logo_id'] ) && ! empty( $settings['pdf_show_logo'] ) && function_exists( 'wp_get_attachment_image_src' ) ) {
                        $img = wp_get_attachment_image_src( $settings['logo_id'], 'medium' );
                        if ( $img && ! empty( $img[0] ) && function_exists( 'wp_upload_dir' ) ) {
                            $uploads = wp_upload_dir();
                            $local   = str_replace( $uploads['baseurl'], $uploads['basedir'], $img[0] );
                            if ( is_readable( $local ) && method_exists( $pdfContent, 'Image' ) ) {
                                // Posicionar en esquina superior izquierda, ancho 40mm
                                @$pdfContent->Image( $local, 10, 10, 40 );
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    // Ignorar fallos de imagen y continuar
                }

                // Asegurar que el detalle sea visible: agregar una página con tabla basada en XML
                try {
                    $raw = (string) $xml_or_signed_xml;
                    if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) { $raw = substr( $raw, 3 ); }
                    $raw = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw );
                    libxml_use_internal_errors( true );
                    $sx  = simplexml_load_string( $raw );
                    if ( ! $sx ) { throw new \Exception('XML parse error'); }
                    $docNodes  = $sx->xpath('//*[local-name()="Documento"]');
                    $doc       = ( $docNodes && ! empty( $docNodes[0] ) ) ? $docNodes[0] : $sx;
                    $lineNodes = $doc->xpath('./*[local-name()="Detalle"]');
                    if ( method_exists( $pdfContent, 'AddPage' ) && ! empty( $lineNodes ) ) {
                        $format = isset( $settings['pdf_format'] ) ? strtoupper( (string) $settings['pdf_format'] ) : 'A4';
                        if ( '80MM' === $format ) {
                            $pdfContent->AddPage( 'P', [80, 297] );
                        } else {
                            $pdfContent->AddPage( 'P', 'A4' );
                        }
                        if ( method_exists( $pdfContent, 'SetFont' ) ) {
                            $pdfContent->SetFont( 'helvetica', 'B', 12 );
                            $pdfContent->Cell( 0, 8, 'Detalle del Documento', 0, 1, 'L' );
                            $pdfContent->SetFont( 'helvetica', 'B', 9 );
                            $pdfContent->SetFillColor( 230, 230, 230 );
                            $pdfContent->Cell( 10, 6, '#', 1, 0, 'C', true );
                            $pdfContent->Cell( 100, 6, 'Descripcion', 1, 0, 'L', true );
                            $pdfContent->Cell( 20, 6, 'Cant.', 1, 0, 'R', true );
                            $pdfContent->Cell( 30, 6, 'Precio', 1, 0, 'R', true );
                            $pdfContent->Cell( 30, 6, 'Subtotal', 1, 1, 'R', true );
                            $pdfContent->SetFont( 'helvetica', '', 9 );
                            $line = 1;
                            foreach ( $lineNodes as $det ) {
                                $pdfContent->Cell( 10, 6, (string) $line, 1, 0, 'C' );
                                $pdfContent->Cell( 100, 6, (string) $det->NmbItem, 1, 0, 'L' );
                                $pdfContent->Cell( 20, 6, number_format( (float) $det->QtyItem, 0, ',', '.' ), 1, 0, 'R' );
                                $pdfContent->Cell( 30, 6, number_format( (float) $det->PrcItem, 0, ',', '.' ), 1, 0, 'R' );
                                $pdfContent->Cell( 30, 6, number_format( (float) $det->MontoItem, 0, ',', '.' ), 1, 1, 'R' );
                                $line++;
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    // Si falla, continuamos sin la tabla adicional
                }

                // Obtener contenido binario del PDF
                $pdfContent = $pdfContent->Output( '', 'S' );
            }
            if ( ! is_string( $pdfContent ) || '' === $pdfContent ) {
                return false;
            }

            // Determinar nombre y guardar en uploads, en carpeta por RUT del receptor
            try {
                $sx = new \SimpleXMLElement( (string) $xml_or_signed_xml );
                $doc = $sx->Documento ?: $sx; // tolerancia
                $tipo = (string) $doc->Encabezado->IdDoc->TipoDTE;
                $folio = (string) $doc->Encabezado->IdDoc->Folio;
                $rut  = (string) $doc->Encabezado->Receptor->RUTRecep;
            } catch ( \Throwable $e ) {
                $tipo = 'DTE';
                $folio = '0';
                $rut  = 'SIN-RUT';
            }
            $upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => sys_get_temp_dir() ];
            $rut_folder = strtoupper( preg_replace( '/[^0-9Kk-]/', '', $rut ?: 'SIN-RUT' ) );
            $base_dir   = rtrim( $upload['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . 'dte' . DIRECTORY_SEPARATOR . $rut_folder . DIRECTORY_SEPARATOR;
            if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $base_dir ); } else { if ( ! is_dir( $base_dir ) ) { @mkdir( $base_dir, 0755, true ); } }
            $file = 'DTE_' . $tipo . '_' . $folio . '_' . time() . '.pdf';
            $path = $base_dir . $file;
            file_put_contents( $path, $pdfContent );
            return $path;
        } catch ( \Throwable $e ) {
            if ( function_exists( 'sii_boleta_write_log' ) ) {
                sii_boleta_write_log( 'LibreDTE render_pdf error: ' . $e->getMessage(), 'ERROR' );
            }
            return false;
        }
    }

    public function build_rvd_xml( $date = null ) {
        if ( ! $this->lib_available() ) {
            return false;
        }
        // TODO: Implementar con LibreDTE.
        return false;
    }

    public function send_rvd( $xml_signed, $environment, $token ) {
        if ( ! $this->lib_available() ) {
            return false;
        }
        // TODO: Implementar con LibreDTE.
        return false;
    }

    public function generate_cdf_xml( $date ) {
        if ( ! $this->lib_available() ) {
            return false;
        }
        // TODO: Implementar con LibreDTE.
        return false;
    }

    public function send_cdf( $xml_content, $environment, $token, $cert_path, $cert_pass ) {
        if ( ! $this->lib_available() ) {
            return false;
        }
        // TODO: Implementar con LibreDTE.
        return false;
    }

    public function generate_libro( $fecha_inicio, $fecha_fin ) {
        if ( ! $this->lib_available() ) {
            return $this->fallback->generate_libro( $fecha_inicio, $fecha_fin );
        }
        // TODO: Implementar con LibreDTE.
        return $this->fallback->generate_libro( $fecha_inicio, $fecha_fin );
    }

    public function send_libro( $xml, $environment, $token, $cert_path, $cert_pass ) {
        if ( ! $this->lib_available() ) {
            return $this->fallback->send_libro( $xml, $environment, $token, $cert_path, $cert_pass );
        }
        // TODO: Implementar con LibreDTE.
        return $this->fallback->send_libro( $xml, $environment, $token, $cert_path, $cert_pass );
    }
}
