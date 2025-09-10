<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Motor DTE que implementa la integración con LibreDTE.
 *
 * Esta clase está refactorizada para:
 * - Respetar la estructura normalizada que LibreDTE espera en los constructores y renderers.
 * - Eliminar reindexaciones de colecciones (especialmente de Detalle) que pueden romper las plantillas PDF.
 * - Mantener montos como enteros (no cast a float) y calcular MontoItem sólo cuando falte.
 * - Centralizar la carga y limpieza del XML y de los certificados para evitar duplicación.
 * - Inyectar logos de manera segura sin tocar la estructura de datos del DTE.
 *
 * @see https://core.libredte.cl/docs/lib/funcionalidades#documentos-tributarios-electronicos-dte
 */
class SII_LibreDTE_Engine implements SII_DTE_Engine
{
    /** @var SII_Boleta_Settings */
    private $settings;

    public function __construct(SII_Boleta_Settings $settings)
    {
        $this->settings = $settings;
        // Verificar disponibilidad de LibreDTE
        if (!class_exists('\\libredte\\lib\\Core\\Application') && !class_exists('\\libredte\\lib\\Core\\Kernel')) {
            throw new \RuntimeException('LibreDTE no está disponible. Este plugin requiere LibreDTE para funcionar.');
        }
    }

    /**
     * Genera el XML de un DTE desde un arreglo de datos.
     *
     * Se apoya en los constructores de LibreDTE para normalizar los datos【519346395733974†L90-L97】.
     * No reindexa las colecciones ni convierte montos a float.
     *
     * @param array $data    Datos de negocio del DTE.
     * @param mixed $tipo_dte Tipo de DTE (string o int).
     * @param bool $preview   Si es true, no se timbra (no se agrega TED).
     * @return string|false|\WP_Error
     */
    public function generate_dte_xml(array $data, $tipo_dte, $preview = false)
    {
        try {
            $tipo    = (int) $tipo_dte;
            $folio   = isset($data['Folio'])   ? (int) $data['Folio']   : 0;
            $fchEmis = isset($data['FchEmis']) ? (string) $data['FchEmis'] : date('Y-m-d');
            $is_boleta_exenta = ($tipo === 41);

            // Construir Emisor con fallback a ajustes
            $emisor = [
                'RUTEmisor' => (string)($data['RutEmisor'] ?? ''),
                'RznSoc'    => (string)($data['RznSoc'] ?? ($data['RznSocEmisor'] ?? '')),
                'GiroEmis'  => (string)($data['GiroEmisor'] ?? ($data['GiroEmis'] ?? '')),
                'DirOrigen' => (string)($data['DirOrigen'] ?? ''),
                'CmnaOrigen'=> (string)($data['CmnaOrigen'] ?? ''),
            ];
            $opts_for_emisor = $this->settings->get_settings();
            if (!empty($opts_for_emisor['acteco']) && empty($emisor['Acteco'])) {
                $emisor['Acteco'] = $opts_for_emisor['acteco'];
            }
            if (!empty($opts_for_emisor['cdg_sii_sucur']) && empty($emisor['CdgSIISucur'])) {
                $emisor['CdgSIISucur'] = $opts_for_emisor['cdg_sii_sucur'];
            }

            // Receptor
            $receptor_data = (array)($data['Receptor'] ?? []);
            $receptor = array_filter([
                'RUTRecep'      => (string)($receptor_data['RUTRecep'] ?? ''),
                'RznSocRecep'   => (string)($receptor_data['RznSocRecep'] ?? ''),
                'DirRecep'      => (string)($receptor_data['DirRecep'] ?? ''),
                'CmnaRecep'     => (string)($receptor_data['CmnaRecep'] ?? ''),
                'GiroRecep'     => isset($receptor_data['GiroRecep']) ? (string)$receptor_data['GiroRecep'] : null,
                'CorreoRecep'   => isset($receptor_data['CorreoRecep']) ? (string)$receptor_data['CorreoRecep'] : null,
                'TelefonoRecep' => isset($receptor_data['TelefonoRecep']) ? (string)$receptor_data['TelefonoRecep'] : null,
            ], static function ($v) { return $v !== null && $v !== ''; });

            // Detalle(s). No se reindexa a 0; se usa NroLinDet o índice 1-based.
            $detalles = [];
            $input_detalles = array_values((array)($data['Detalles'] ?? []));
            foreach ($input_detalles as $i => $det) {
                $nro = isset($det['NroLinDet']) ? (int) $det['NroLinDet'] : ($i + 1);
                $qty = isset($det['QtyItem'])   ? (float) $det['QtyItem'] : 1.0;
                $prc = isset($det['PrcItem'])   ? (int)   round($det['PrcItem']) : 0;

                $lin = [
                    'NroLinDet' => $nro,
                    'NmbItem'   => (string)($det['NmbItem'] ?? ''),
                    'QtyItem'   => $qty,
                    'PrcItem'   => $prc,
                ];
                // Boletas (39/41) traen precios con IVA incluido
                if (in_array($tipo, [39, 41], true)) {
                    $lin['MntBruto'] = 1;
                }
                // Exento
                if (!empty($det['IndExe']) || $is_boleta_exenta) {
                    $lin['IndExe'] = 1;
                }
                // Descuento y recargo
                if (isset($det['DescuentoMonto']) && $det['DescuentoMonto'] !== '') {
                    $lin['DescuentoMonto'] = (int)$det['DescuentoMonto'];
                }
                if (isset($det['RecargoMonto']) && $det['RecargoMonto'] !== '') {
                    $lin['RecargoMonto'] = (int)$det['RecargoMonto'];
                }
                // MontoItem (entero)
                if (isset($det['MontoItem']) && $det['MontoItem'] !== '') {
                    $lin['MontoItem'] = (int) round($det['MontoItem']);
                } else {
                    $lin['MontoItem'] = (int) round($qty * $prc);
                }

                // Guardar con índice = NroLinDet
                $detalles[$nro] = $lin;
            }
            ksort($detalles, SORT_NUMERIC);

            // Referencias
            $referencias = [];
            if (!empty($data['Referencias']) && is_array($data['Referencias'])) {
                foreach ($data['Referencias'] as $ref) {
                    $referencias[] = array_filter([
                        'TpoDocRef' => $ref['TpoDocRef'] ?? '',
                        'FolioRef'  => $ref['FolioRef']  ?? '',
                        'FchRef'    => $ref['FchRef']    ?? $fchEmis,
                        'RazonRef'  => $ref['RazonRef']  ?? null,
                    ], static function ($v) { return $v !== null && $v !== ''; });
                }
            }

            // Encabezado y normalización
            $idDoc = array_filter([
                'TipoDTE'       => $tipo,
                'Folio'         => $folio,
                'FchEmis'       => $fchEmis,
                'FmaPago'       => $data['FmaPago']       ?? null,
                'FchVenc'       => $data['FchVenc']       ?? null,
                'MedioPago'     => $data['MedioPago']     ?? null,
                'TpoTranCompra' => $data['TpoTranCompra'] ?? null,
                'TpoTranVenta'  => $data['TpoTranVenta']  ?? null,
            ], static function ($v) { return $v !== null && $v !== ''; });

            $transporte = [];
            if ($tipo === 52) {
                if (!empty($data['IndTraslado'])) { $idDoc['IndTraslado'] = $data['IndTraslado']; }
                if (!empty($data['Patente']))     { $transporte['Patente'] = $data['Patente']; }
                if (!empty($data['RUTTrans']))    { $transporte['RUTTrans'] = $data['RUTTrans']; }
                if (!empty($data['RUTChofer']) && !empty($data['NombreChofer'])) {
                    $transporte['Chofer'] = [
                        'RUTChofer'    => $data['RUTChofer'],
                        'NombreChofer' => $data['NombreChofer'],
                    ];
                }
                if (!empty($data['DirDest']))  { $transporte['DirDest']  = $data['DirDest']; }
                if (!empty($data['CmnaDest'])) { $transporte['CmnaDest'] = $data['CmnaDest']; }
            }

            $normalized = [
                'Encabezado' => [
                    'IdDoc'    => $idDoc,
                    'Emisor'   => $emisor,
                    'Receptor' => $receptor,
                ],
                'Detalle' => $detalles,
            ];
            if (!empty($referencias)) {
                $normalized['Referencia'] = $referencias;
            }
            if ($tipo === 52 && !empty($transporte)) {
                $normalized['Encabezado']['Transporte'] = $transporte;
            }

            // Sanitizar campos de texto: convertir boolean false/null a string vacío donde corresponda
            $normalized = $this->sanitize_string_fields_recursively($normalized);

            // Obtener CAF (solo si no es preview)
            $opts      = $this->settings->get_settings();
            $caf_paths = isset($opts['caf_path']) && is_array($opts['caf_path']) ? $opts['caf_path'] : [];
            $caf_path  = $caf_paths[$tipo] ?? '';
            if (!$preview && (empty($caf_path) || !file_exists($caf_path))) {
                if (class_exists('\\WP_Error')) {
                    return new \WP_Error('sii_boleta_missing_caf', sprintf(__('No se encontró CAF para el tipo de DTE %s.', 'sii-boleta-dte'), $tipo));
                }
                return false;
            }

            // Construir documento con LibreDTE
            $billing  = $this->getBilling();
            $document = $billing->getDocumentComponent();

            $cafForBuild = $preview ? null : $caf_path;
            $this->log_false_string_fields($normalized, 'normalized-pre-bill');
            $bag = $document->bill($normalized, $cafForBuild, null, []);
            $xml = $bag->getXmlDocument()->saveXML();

            return $xml ?: false;

        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE generate_dte_xml error: ' . $e->getMessage(), 'ERROR');
            }
            return class_exists('WP_Error')
                ? new \WP_Error('sii_libredte_error', $e->getMessage())
                : false;
        }
    }

    /**
     * Firma un XML de DTE ya generado.
     *
     * @param string $xml XML del DTE
     * @return string|false
     */
    public function sign_dte_xml($xml)
    {
        try {
            $billing  = $this->getBilling();
            $document = $billing->getDocumentComponent();

            // Limpiar XML
            $clean = $this->cleanXmlString((string) $xml);
            $bag   = $document->getLoaderWorker()->loadXml($clean);
            // No normalizar ni re-timbrar, solo firmar
            $bag->getOptions()->set('normalizer.normalize', false);

            // Cargar certificado
            $opts = $this->settings->get_settings();
            $certificate = $this->loadCertificate($opts['cert_path'] ?? '', $opts['cert_pass'] ?? '');
            if (!$certificate) {
                return false;
            }
            $bag = $bag->withCertificate($certificate);

            // Firmar
            $document->getBuilderWorker()->build($bag);
            $signed = $bag->getXmlDocument()->saveXML();

            return $signed ?: false;

        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE sign_dte_xml error: ' . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Envía un archivo DTE al SII y devuelve el TrackID.
     *
     * @param string $file_path Ruta al archivo XML del DTE
     * @param mixed  $environment 'production' o 'certification'
     * @param string $token       Token de autenticación
     * @param string $cert_path   Ruta al certificado PFX/P12 (opcional)
     * @param string $cert_pass   Clave del certificado (opcional)
     * @return string|false|\WP_Error
     */
    public function send_dte_file($file_path, $environment, $token, $cert_path, $cert_pass)
    {
        try {
            if (!is_readable($file_path)) {
                return false;
            }
            $xml_dte = file_get_contents($file_path);
            if (!$xml_dte) {
                return false;
            }

            $billing    = $this->getBilling();
            $document   = $billing->getDocumentComponent();
            $dispatcher = $document->getDispatcherWorker();
            $integration= $billing->getIntegrationComponent();

            // Limpiar y cargar XML en bolsa
            $cleanXml = $this->cleanXmlString((string) $xml_dte);
            $bag = $document->getLoaderWorker()->loadXml($cleanXml);

            // Cargar certificado
            $certificate = $this->loadCertificate($cert_path, $cert_pass);
            if (!$certificate) {
                return false;
            }
            $bag = $bag->withCertificate($certificate);
            $envelope = $dispatcher->create($bag);

            // RUT del emisor
            $opts = $this->settings->get_settings();
            $rutEmisor = $opts['rut_emisor'] ?? '';
            if (empty($rutEmisor)) {
                return false;
            }

            // Ambiente (production/certification)
            $ambiente = $this->getSiiAmbiente($environment);
            $request = new \libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest(
                $certificate,
                ['ambiente' => $ambiente]
            );

            // Validar y enviar
            $dispatcher->validate($envelope);
            $trackId = $integration->getSiiLazyWorker()->sendXmlDocument(
                $request,
                $envelope->getXmlDocument(),
                $rutEmisor,
                false,
                null
            );

            if (!$trackId) {
                if (function_exists('sii_boleta_write_log')) {
                    sii_boleta_write_log('LibreDTE envío DTE sin TrackID recibido', 'ERROR');
                }
                if (class_exists('SII_Boleta_Log_DB')) {
                    SII_Boleta_Log_DB::add_entry('', 'error', 'Missing TrackID');
                }
                return class_exists('WP_Error')
                    ? new \WP_Error('sii_boleta_missing_trackid', __('No se obtuvo TrackID del SII.', 'sii-boleta-dte'))
                    : false;
            }

            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE envío DTE OK. TrackID: ' . $trackId, 'INFO');
            }
            if (class_exists('SII_Boleta_Log_DB')) {
                SII_Boleta_Log_DB::add_entry((string) $trackId, 'sent', '');
            }

            return (string) $trackId;

        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE send_dte_file error: ' . $e->getMessage(), 'ERROR');
            }
            if (class_exists('SII_Boleta_Log_DB')) {
                SII_Boleta_Log_DB::add_entry('', 'error', $e->getMessage());
            }
            return class_exists('WP_Error')
                ? new \WP_Error('sii_boleta_libredte_send_failed', $e->getMessage())
                : false;
        }
    }

    /**
     * Renderiza un PDF desde un XML o XML firmado.
     *
     * Se carga el XML, se normaliza y se envía al renderer/builder. Sólo se
     * inyecta el logo si se solicita; no se manipula la colección Detalle.
     *
     * @param string $xml_or_signed_xml
     * @param array  $settings Ajustes: 'logo_id', 'pdf_show_logo', etc.
     * @return string|false Ruta al archivo PDF generado o false si falla.
     */
    public function render_pdf($xml_or_signed_xml, array $settings)
    {
        try {
            $billing  = $this->getBilling();
            $document = $billing->getDocumentComponent();

            // Limpiar y cargar XML en bolsa
            $clean = $this->cleanXmlString((string) $xml_or_signed_xml);
            $bag   = $document->getLoaderWorker()->loadXml($clean);

            // Activar normalización
            if (method_exists($bag, 'getOptions')) {
                $opts = $bag->getOptions();
                if ($opts && method_exists($opts, 'set')) {
                    $opts->set('normalizer.normalize', true);
                }
            }

            // Inyectar logo (sin tocar Detalle)
            if (!empty($settings['logo_id']) && !empty($settings['pdf_show_logo']) && function_exists('wp_get_attachment_image_src')) {
                $img = wp_get_attachment_image_src((int) $settings['logo_id'], 'medium');
                if ($img && !empty($img[0])) {
                    if (method_exists($bag, 'get') && method_exists($bag, 'set')) {
                        $doc_data = $bag->get('document');
                        if ($doc_data) {
                            $doc_array = is_array($doc_data) ? $doc_data : (array) $doc_data;
                            $doc_array['logo'] = $img[0];
                            $bag->set('document', $doc_array);
                        }
                    }
                }
            }

            // Render (preferir renderer)
            $pdfContent = null;
            if (method_exists($document, 'getRendererWorker')) {
                $renderer = $document->getRendererWorker();
                if ($renderer && method_exists($renderer, 'render')) {
                    $pdfContent = $renderer->render($bag);
                }
            }
            if (!$pdfContent && method_exists($document, 'getBuilderWorker')) {
                $builder = $document->getBuilderWorker();
                if ($builder && method_exists($builder, 'renderPdf')) {
                    $pdfContent = $builder->renderPdf($bag);
                }
            }

            if (!$pdfContent || !is_string($pdfContent)) {
                return false;
            }

            // Determinar ubicación y nombre del archivo
            try {
                $sx = new \SimpleXMLElement((string) $xml_or_signed_xml);
                $doc = $sx->Documento ?: $sx;
                $tipo  = (string)($doc->Encabezado->IdDoc->TipoDTE ?? 'DTE');
                $folio = (string)($doc->Encabezado->IdDoc->Folio   ?? '0');
                $rut   = (string)($doc->Encabezado->Receptor->RUTRecep ?? 'SIN-RUT');
            } catch (\Throwable $e) {
                $tipo  = 'DTE';
                $folio = '0';
                $rut   = 'SIN-RUT';
            }

            $upload     = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
            $rut_folder = strtoupper(preg_replace('/[^0-9Kk-]/', '', $rut ?: 'SIN-RUT'));
            $base_dir   = rtrim($upload['basedir'], '/\\') . DIRECTORY_SEPARATOR . 'dte' . DIRECTORY_SEPARATOR . $rut_folder . DIRECTORY_SEPARATOR;
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($base_dir);
            } else {
                if (!is_dir($base_dir)) {
                    @mkdir($base_dir, 0755, true);
                }
            }
            $file = 'DTE_' . $tipo . '_' . $folio . '_' . time() . '.pdf';
            $path = $base_dir . $file;

            file_put_contents($path, $pdfContent);

            return $path;

        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE render_pdf error: ' . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Genera el Reporte de Ventas Diarias (RVD).
     *
     * @param string|null $date Fecha para el reporte
     * @return string|false
     */
    public function build_rvd_xml($date = null)
    {
        if (!$this->lib_available()) {
            return false;
        }
        try {
            $rvd = new SII_Boleta_RVD_Manager($this->settings);
            $xml = $rvd->generate_rvd_xml($date);
            return $xml ?: false;
        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE build_rvd_xml error: ' . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Envía el RVD firmado al SII.
     *
     * @param string $xml_signed XML firmado del RVD
     * @param mixed  $environment Ambiente 'production' o 'certification'
     * @param string $token Token de autenticación
     * @return mixed
     */
    public function send_rvd($xml_signed, $environment, $token)
    {
        if (!$this->lib_available()) {
            return false;
        }
        try {
            $settings = $this->settings->get_settings();
            $rvd = new SII_Boleta_RVD_Manager($this->settings);
            return $rvd->send_rvd_to_sii(
                $xml_signed,
                $environment,
                $token,
                $settings['cert_path'] ?? '',
                $settings['cert_pass'] ?? ''
            );
        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE send_rvd error: ' . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Genera el reporte de consumo de folios (CDF).
     *
     * @param string $date Fecha del CDF
     * @return mixed
     */
    public function generate_cdf_xml($date)
    {
        if (!$this->lib_available()) {
            return false;
        }
        try {
            $folio_manager = new SII_Boleta_Folio_Manager($this->settings);
            $api           = new SII_Boleta_API();
            $cdf           = new SII_Boleta_Consumo_Folios($this->settings, $folio_manager, $api);
            return $cdf->generate_cdf_xml($date);
        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE generate_cdf_xml error: ' . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Envía el CDF al SII.
     *
     * @param string $xml_content Contenido XML del CDF
     * @param mixed  $environment Ambiente 'production' o 'certification'
     * @param string $token Token
     * @param string $cert_path Ruta al certificado
     * @param string $cert_pass Contraseña del certificado
     * @return mixed
     */
    public function send_cdf($xml_content, $environment, $token, $cert_path, $cert_pass)
    {
        if (!$this->lib_available()) {
            return false;
        }
        try {
            $folio_manager = new SII_Boleta_Folio_Manager($this->settings);
            $api           = new SII_Boleta_API();
            $cdf           = new SII_Boleta_Consumo_Folios($this->settings, $folio_manager, $api);
            return $cdf->send_cdf_to_sii($xml_content, $environment, $token, $cert_path, $cert_pass);
        } catch (\Throwable $e) {
            if (function_exists('sii_boleta_write_log')) {
                sii_boleta_write_log('LibreDTE send_cdf error: ' . $e->getMessage(), 'ERROR');
            }
            return false;
        }
    }

    /**
     * Genera y envía libros de ventas utilizando la librería legacy sasco\LibreDTE.
     *
     * @param string $fecha_inicio Fecha de inicio (YYYY-mm-dd)
     * @param string $fecha_fin    Fecha fin (YYYY-mm-dd)
     * @return string|false XML del libro o false si falla.
     */
    public function generate_libro($fecha_inicio, $fecha_fin)
    {
        if (!$this->lib_available()) {
            return false;
        }
        try {
            $config = $this->settings->get_settings();
            if (empty($config['rut_emisor'])) {
                throw new \Exception('RUT emisor no configurado');
            }

            $libro = new \sasco\LibreDTE\Sii\LibroCompraVenta();
            $libro->setCaratula([
                'RutEmisorLibro'   => $config['rut_emisor'],
                'PeriodoTributario'=> date('Y-m', strtotime((string) $fecha_inicio)),
                'TipoOperacion'    => 'VENTA',
                'TipoLibro'        => 'ESPECIAL',
                'TipoEnvio'        => 'TOTAL',
            ]);

            global $wpdb;
            $table = $wpdb->prefix . 'sii_boleta_dtes';
            $boletas = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE fecha >= %s AND fecha <= %s AND tipo IN (39, 41) ORDER BY folio ASC",
                $fecha_inicio,
                $fecha_fin
            ));

            foreach ($boletas as $boleta) {
                $tipo  = (int)$boleta->tipo;
                $total = (int)$boleta->total;
                $detalle = [
                    'TpoDoc'   => $tipo,
                    'NroDoc'   => (int)$boleta->folio,
                    'FchDoc'   => (string)$boleta->fecha,
                    'MntTotal' => $total,
                ];
                if ($tipo === 39) { // Boleta afecta
                    $neto = (int) round($total / 1.19);
                    $iva  = (int) ($total - $neto);
                    $detalle['MntNeto'] = $neto;
                    $detalle['MntIVA']  = $iva;
                }
                $libro->agregar($detalle);
            }

            $xml = $libro->generar();
            if (!$xml) {
                throw new \Exception('Error al generar XML del libro');
            }

            return $xml;

        } catch (\Exception $e) {
            error_log('Error al generar libro: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un libro de compras/ventas.
     *
     * @param string $xml XML del libro
     * @param mixed  $environment Ambiente (host)
     * @param string $token Token de autenticación
     * @param string $cert_path Certificado PFX/P12
     * @param string $cert_pass Clave del certificado
     * @return mixed
     */
    public function send_libro($xml, $environment, $token, $cert_path, $cert_pass)
    {
        if (!$this->lib_available()) {
            return false;
        }
        try {
            $libro = new \sasco\LibreDTE\Sii\LibroCompraVenta();
            $libro->loadXML($xml);
            $signature = new \sasco\LibreDTE\FirmaElectronica($cert_path, $cert_pass);
            $libro->sign($signature);
            $track_id = $libro->enviar($token, $environment === 'maullin.sii.cl');
            return $track_id;
        } catch (\Exception $e) {
            error_log('Error al enviar libro: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Comprueba si LibreDTE está disponible.
     *
     * @return bool
     */
    private function lib_available(): bool
    {
        return class_exists('\\libredte\\lib\\Core\\Application') || class_exists('\\libredte\\lib\\Core\\Kernel');
    }

    /**
     * Limpia un string XML eliminando BOM y caracteres de control.
     *
     * @param string $xml
     * @return string
     */
    private function cleanXmlString(string $xml): string
    {
        if (substr($xml, 0, 3) === "\xEF\xBB\xBF") {
            $xml = substr($xml, 3);
        }
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml);
        return is_string($clean) ? $clean : '';
    }

    /**
     * Carga un certificado desde archivo PFX/P12. Si la ruta no existe se intenta
     * con las opciones de configuración del plugin.
     *
     * @param string|null $certPath Ruta al certificado
     * @param string|null $certPass Clave del certificado
     * @return \Derafu\Certificate\Entity\Certificate|null
     */
    private function loadCertificate(?string $certPath, ?string $certPass)
    {
        if (empty($certPath) || !file_exists($certPath)) {
            $opts = $this->settings->get_settings();
            $certPath = $opts['cert_path'] ?? '';
            $certPass = $opts['cert_pass'] ?? '';
        }
        if (empty($certPath) || !file_exists($certPath)) {
            return null;
        }
        $loader = new \Derafu\Certificate\Service\CertificateLoader();
        return $loader->loadFromFile($certPath, (string) $certPass);
    }

    /**
     * Obtiene el paquete de facturación de LibreDTE.
     *
     * @return \libredte\lib\Core\Package\Billing\BillingPackage
     */
    private function getBilling()
    {
        $app = \libredte\lib\Core\Application::getInstance('prod', false);
        return $app->getPackageRegistry()->getPackage('billing');
    }

    /**
     * Determina el ambiente del SII a partir del argumento o de la configuración.
     *
     * @param mixed $environment
     * @return \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente
     */
    private function getSiiAmbiente($environment)
    {
        $opts = $this->settings->get_settings();
        $env  = strtolower((string)($environment ?? $opts['environment'] ?? ''));
        return ('production' === $env)
            ? \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::PRODUCCION
            : \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::CERTIFICACION;
    }

// --- Helper: sanitiza campos string y evita false/null globalmente donde no sean numéricos/flags ---
private function sanitize_string_fields_recursively(array $data): array
{
    // 1) Lista blanca de claves que la lib suele tratar como texto (se amplía)
    static $stringKeys = [
        // Encabezado / Emisor / Receptor
        'RUTEmisor','RznSoc','GiroEmis','DirOrigen','CmnaOrigen',
        'RUTRecep','RznSocRecep','DirRecep','CmnaRecep','GiroRecep','CorreoRecep','TelefonoRecep',
        // IdDoc
        'FchEmis','FmaPago','FchVenc','MedioPago','TpoTranCompra','TpoTranVenta',
        // Transporte
        'Patente','RUTTrans','RUTChofer','NombreChofer','DirDest','CmnaDest',
        // Referencias
        'TpoDocRef','FolioRef','FchRef','RazonRef',
        // Detalle (posibles textos)
        'NmbItem','DscItem','UnmdItem',
        // Códigos de ítem (cuando vienen como arreglo)
        'TpoCodigo','VlrCodigo',
        // Otros posibles campos de plantillas
        'CiudadOrigen','CiudadRecep','Sucursal','Contacto','CorreoEmisor',
    ];

    // 2) Lista de claves que NO debemos convertir (numéricas/flags/enteros)
    static $numericOrFlagKeys = [
        'TipoDTE','Folio','Acteco','CdgSIISucur',
        'QtyItem','PrcItem','MontoItem','DescuentoMonto','RecargoMonto',
        'IndExe','MntBruto',
        'MntNeto','MntIVA','MntExe','MntTotal',
    ];

    // PASO A: Forzar string en claves de texto conocidas
    $forceString = function (&$v, $k) use ($stringKeys) {
        if (in_array($k, $stringKeys, true)) {
            if ($v === false || $v === null) { $v = ''; }
            elseif (is_scalar($v)) { $v = (string)$v; }
            else { $v = ''; }
        }
    };
    array_walk_recursive($data, $forceString);

    // PASO B: Catch-all — convertir false/null a '' en todo el arreglo,
    // excepto en claves que sabemos que son numéricas/flags
    $catchAll = function (&$v, $k) use ($numericOrFlagKeys) {
        if ($v === false || $v === null) {
            if (!in_array($k, $numericOrFlagKeys, true)) {
                // Si no es una clave numérica/flag conocida, convierte a string vacío
                $v = '';
            }
            // si está en numericOrFlagKeys lo dejamos como está (o lo tratará el builder)
        }
    };
    array_walk_recursive($data, $catchAll);

    return $data;
}

// Añade a la clase:
private function log_false_string_fields(array $data, string $context = 'normalized'): void
{
    if (!function_exists('sii_boleta_write_log')) { return; }
    $paths = [];
    $stack = function ($arr, $prefix = '') use (&$paths, &$stack) {
        foreach ($arr as $k => $v) {
            $p = $prefix === '' ? $k : $prefix.'.'.$k;
            if (is_array($v)) {
                $stack($v, $p);
            } else {
                if ($v === false) { $paths[] = $p; }
            }
        }
    };
    $stack($data);
    if (!empty($paths)) {
        sii_boleta_write_log("FALSE fields before bill() [$context]: ".implode(', ', $paths), 'DEBUG');
    }
}

}
