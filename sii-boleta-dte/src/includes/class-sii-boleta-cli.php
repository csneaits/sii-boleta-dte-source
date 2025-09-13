<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Comandos WP-CLI para operaciones del plugin.
 */
class SII_Boleta_CLI {
    /**
     * Emite una Boleta (39) desde CLI y opcionalmente la envía al SII.
     *
     * Requiere datos mínimos del receptor e ítem. Usa el siguiente folio disponible.
     *
     * ## OPTIONS
     *
     * [--send]
     * : Si se indica, envía el DTE al SII al finalizar.
     *
     * [--type=<tipo>]
     * : Tipo de DTE a emitir (por defecto 39).
     * ---
     * default: 39
     * ---
     *
     * [--rut=<RUT>]
     * : RUT del receptor, ej: 66666666-6
     *
     * [--name=<Nombre>]
     * : Razón social o nombre del receptor.
     *
     * [--addr=<Dirección>]
     * : Dirección del receptor.
     *
     * [--comuna=<Comuna>]
     * : Comuna del receptor.
     *
     * [--desc=<Descripción>]
     * : Descripción del ítem.
     *
     * [--qty=<Cantidad>]
     * : Cantidad del ítem (entero, default 1).
     * ---
     * default: 1
     * ---
     *
     * [--price=<Precio>]
     * : Precio unitario (entero).
     *
     * [--fmapago=<int>]
     * : Forma de pago (1 contado, 2 crédito, etc.).
     *
     * [--fchvenc=<YYYY-MM-DD>]
     * : Fecha de vencimiento.
     *
     * [--mediopago=<glosa>]
     * : Glosa del medio de pago.
     *
     * [--tpotrancompra=<int>] [--tpotranventa=<int>]
     * : Tipo transacción compra/venta (facturas).
     *
     * [--girorecep=<giro>] [--correorecep=<email>] [--telefonorecep=<fono>]
     * : Datos del receptor adicionales.
     *
     * [--tpodocref=<tipo>] [--folioref=<folio>] [--fchref=<YYYY-MM-DD>] [--razonref=<glosa>]
     * : Referencia única (p.ej. para NC/ND 56/61).
     *
     * [--refs=<json>]
     * : Lista de referencias en JSON (array de objetos con TpoDocRef, FolioRef, FchRef, RazonRef).
     *
     * Guía de despacho (52):
     * [--indtraslado=<int>] [--patente=<patente>] [--ruttrans=<RUT>] [--rutchofer=<RUT>] [--nombrechofer=<Nombre>]
     * [--dirdest=<dir>] [--cmnadest=<comuna>]
     *
     * ## EXAMPLES
     *
     *    wp sii:dte emitir --rut=66666666-6 --name="Consumidor Final" --addr="Calle 123" --comuna="Santiago" --desc="Servicio" --qty=1 --price=1000 --send
     *
     * @param array $args       Posicionales (no usados).
     * @param array $assoc_args Asociativos.
     */
    public static function dte_emitir( $args, $assoc_args ) {
        $type  = isset( $assoc_args['type'] ) ? intval( $assoc_args['type'] ) : 39;
        $send  = isset( $assoc_args['send'] );

        $rut   = isset( $assoc_args['rut'] ) ? trim( $assoc_args['rut'] ) : '';
        $name  = isset( $assoc_args['name'] ) ? trim( $assoc_args['name'] ) : '';
        $addr  = isset( $assoc_args['addr'] ) ? trim( $assoc_args['addr'] ) : '';
        $com   = isset( $assoc_args['comuna'] ) ? trim( $assoc_args['comuna'] ) : '';
        $desc  = isset( $assoc_args['desc'] ) ? trim( $assoc_args['desc'] ) : '';
        $qty   = isset( $assoc_args['qty'] ) ? max( 1, intval( $assoc_args['qty'] ) ) : 1;
        $price = isset( $assoc_args['price'] ) ? max( 0, intval( $assoc_args['price'] ) ) : 0;

        if ( empty( $rut ) || empty( $name ) || empty( $addr ) || empty( $com ) || empty( $desc ) || $price <= 0 ) {
            WP_CLI::error( 'Parámetros insuficientes. Debe indicar --rut, --name, --addr, --comuna, --desc y --price (>0).' );
        }

        $settings_obj = new SII_Boleta_Settings();
        $settings     = $settings_obj->get_settings();

        // Folio
        $folio_manager = new SII_Boleta_Folio_Manager( $settings_obj );
        $folio = $folio_manager->get_next_folio( $type );
        if ( is_wp_error( $folio ) ) {
            WP_CLI::error( $folio->get_error_message() );
        }
        if ( ! $folio ) {
            WP_CLI::error( 'No hay folios disponibles. Cargue un CAF válido.' );
        }

        $total = round( $qty * $price );
        $dte_data = [
            'TipoDTE'    => $type,
            'Folio'      => $folio,
            'FchEmis'    => date( 'Y-m-d' ),
            'RutEmisor'  => $settings['rut_emisor'],
            'RznSoc'     => $settings['razon_social'],
            'GiroEmisor' => $settings['giro'],
            'DirOrigen'  => $settings['direccion'],
            'CmnaOrigen' => $settings['comuna'],
            'Receptor'   => [
                'RUTRecep'    => $rut,
                'RznSocRecep' => $name,
                'DirRecep'    => $addr,
                'CmnaRecep'   => $com,
            ],
            'Detalles' => [
                [
                    'NroLinDet' => 1,
                    'NmbItem'   => $desc,
                    'QtyItem'   => $qty,
                    'PrcItem'   => $price,
                    'MontoItem' => $total,
                ],
            ],
        ];

        // Encabezado/IdDoc opcionales
        if ( isset( $assoc_args['fmapago'] ) )        { $dte_data['FmaPago'] = intval( $assoc_args['fmapago'] ); }
        if ( isset( $assoc_args['fchvenc'] ) )        { $dte_data['FchVenc'] = $assoc_args['fchvenc']; }
        if ( isset( $assoc_args['mediopago'] ) )      { $dte_data['MedioPago'] = $assoc_args['mediopago']; }
        if ( isset( $assoc_args['tpotrancompra'] ) )  { $dte_data['TpoTranCompra'] = intval( $assoc_args['tpotrancompra'] ); }
        if ( isset( $assoc_args['tpotranventa'] ) )   { $dte_data['TpoTranVenta']  = intval( $assoc_args['tpotranventa'] ); }

        // Receptor opcional
        if ( isset( $assoc_args['girorecep'] ) )      { $dte_data['Receptor']['GiroRecep']     = $assoc_args['girorecep']; }
        if ( isset( $assoc_args['correorecep'] ) )    { $dte_data['Receptor']['CorreoRecep']   = $assoc_args['correorecep']; }
        if ( isset( $assoc_args['telefonorecep'] ) )  { $dte_data['Receptor']['TelefonoRecep'] = $assoc_args['telefonorecep']; }

        // Referencias
        if ( isset( $assoc_args['refs'] ) ) {
            $refs = json_decode( $assoc_args['refs'], true );
            if ( ! is_array( $refs ) ) {
                WP_CLI::error( 'El parámetro --refs debe ser un JSON válido (array de referencias).' );
            }
            foreach ( $refs as $r ) {
                if ( empty( $r['TpoDocRef'] ) || empty( $r['FolioRef'] ) ) {
                    WP_CLI::error( 'Cada referencia en --refs debe incluir TpoDocRef y FolioRef.' );
                }
                $dte_data['Referencias'][] = [
                    'TpoDocRef' => $r['TpoDocRef'],
                    'FolioRef'  => $r['FolioRef'],
                    'FchRef'    => $r['FchRef'] ?? date( 'Y-m-d' ),
                    'RazonRef'  => $r['RazonRef'] ?? 'Referencia',
                ];
            }
        } elseif ( isset( $assoc_args['tpodocref'] ) && isset( $assoc_args['folioref'] ) ) {
            // Referencia única (útil para NC/ND)
            $dte_data['Referencias'][] = [
                'TpoDocRef' => $assoc_args['tpodocref'],
                'FolioRef'  => $assoc_args['folioref'],
                'FchRef'    => $assoc_args['fchref'] ?? date( 'Y-m-d' ),
                'RazonRef'  => $assoc_args['razonref'] ?? 'Referencia',
            ];
        }

        // Datos de guía de despacho (52)
        if ( $type === 52 ) {
            if ( isset( $assoc_args['indtraslado'] ) )   { $dte_data['IndTraslado']  = intval( $assoc_args['indtraslado'] ); }
            if ( isset( $assoc_args['patente'] ) )       { $dte_data['Patente']      = $assoc_args['patente']; }
            if ( isset( $assoc_args['ruttrans'] ) )      { $dte_data['RUTTrans']     = $assoc_args['ruttrans']; }
            if ( isset( $assoc_args['rutchofer'] ) )     { $dte_data['RUTChofer']    = $assoc_args['rutchofer']; }
            if ( isset( $assoc_args['nombrechofer'] ) )  { $dte_data['NombreChofer'] = $assoc_args['nombrechofer']; }
            if ( isset( $assoc_args['dirdest'] ) )       { $dte_data['DirDest']      = $assoc_args['dirdest']; }
            if ( isset( $assoc_args['cmnadest'] ) )      { $dte_data['CmnaDest']     = $assoc_args['cmnadest']; }
        }

        // Motor activo
        $engine = new SII_LibreDTE_Engine( $settings_obj );

        // Generar y firmar
        $xml = $engine->generate_dte_xml( $dte_data, $type );
        if ( is_wp_error( $xml ) || ! $xml ) {
            WP_CLI::error( is_wp_error( $xml ) ? $xml->get_error_message() : 'Error al generar XML.' );
        }

        $signed = $engine->sign_dte_xml( $xml );
        if ( ! $signed ) {
            WP_CLI::error( 'Error al firmar XML. Verifique certificado.' );
        }

        // Guardar
        $upload_dir = wp_upload_dir();
        $file_name  = 'DTE_' . $type . '_' . $folio . '_' . time() . '.xml';
        $file_path  = trailingslashit( $upload_dir['basedir'] ) . $file_name;
        file_put_contents( $file_path, $signed );

        WP_CLI::log( 'XML: ' . $file_path );

        $track = false;
        if ( $send ) {
            $track = $engine->send_dte_file( $file_path, $settings['environment'] ?? 'test', $settings['api_token'] ?? '', $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );
            if ( ! $track ) {
                WP_CLI::warning( 'No se obtuvo Track ID (revise logs).' );
            } else {
                WP_CLI::log( 'Track ID: ' . $track );
            }
        }

        // PDF (si procede)
        $pdf_path = $engine->render_pdf( $signed, $settings );
        if ( $pdf_path ) {
            WP_CLI::log( 'PDF: ' . $pdf_path );
        }

        WP_CLI::success( 'Boleta emitida' . ( $track ? ' y enviada' : '' ) . '.' );
    }
    /**
     * Consulta el estado de un DTE mediante track ID.
     *
     * ## EXAMPLES
     *
     *     wp sii:dte status --track=12345
     *
     * @param array $args       Argumentos posicionales.
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function dte_status( $args, $assoc_args ) {
        $track = $assoc_args['track'] ?? '';
        if ( empty( $track ) ) {
            WP_CLI::error( 'Debe indicar el track ID mediante --track.' );
        }
        $settings_obj = new SII_Boleta_Settings();
        $settings     = $settings_obj->get_settings();

        // Si LibreDTE está disponible, usar su integración para consultar estado.
        if ( class_exists( '\\libredte\\lib\\Core\\Application' ) ) {
            try {
                $app_env = ( 'production' === strtolower( (string) ( $settings['environment'] ?? 'test' ) ) ) ? 'prod' : 'cert';
                $app = \libredte\lib\Core\Application::getInstance( $app_env, false );
                /** @var \libredte\lib\Core\Package\Billing\BillingPackage $billing */
                $billing = $app->getPackageRegistry()->getPackage('billing');
                $integration = $billing->getIntegrationComponent();

                $loader = new \Derafu\Certificate\Service\CertificateLoader();
                $cert   = $loader->loadFromFile( $settings['cert_path'] ?? '', $settings['cert_pass'] ?? '' );

                $ambiente = ( 'production' === strtolower( (string) $settings['environment'] ) )
                    ? \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::PRODUCCION
                    : \libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiAmbiente::CERTIFICACION;
                $request = new \libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest( $cert, [ 'ambiente' => $ambiente ] );

                $resp = $integration->getSiiLazyWorker()->checkXmlDocumentSentStatus( $request, intval( $track ), (string) ( $settings['rut_emisor'] ?? '' ) );
                WP_CLI::print_value( $resp, [ 'format' => 'json' ] );
                WP_CLI::success( 'Consulta completada (LibreDTE).' );
                return;
            } catch ( \Throwable $e ) {
                WP_CLI::warning( 'Fallo consulta con LibreDTE, se usará API nativa: ' . $e->getMessage() );
            }
        }

        // Fallback: API nativa del plugin.
        $api  = new SII_Boleta_API();
        $data = $api->get_dte_status(
            $track,
            $settings['environment'],
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );
        if ( is_wp_error( $data ) ) {
            WP_CLI::error( $data->get_error_message() );
        }
        if ( false === $data ) {
            WP_CLI::error( 'Error al consultar el estado del DTE.' );
        }
        WP_CLI::print_value( $data, [ 'format' => 'json' ] );
        WP_CLI::success( 'Consulta completada.' );
    }

    /**
     * Genera y envía el Libro de Boletas para un rango de meses.
     *
     * ## EXAMPLES
     *
     *     wp sii:libro --from=2024-01 --to=2024-03
     *
     * @param array $args       Argumentos posicionales.
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function libro( $args, $assoc_args ) {
        $from = $assoc_args['from'] ?? '';
        $to   = $assoc_args['to'] ?? '';
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}$/', $to ) ) {
            WP_CLI::error( 'Debe indicar rangos válidos con --from=YYYY-MM y --to=YYYY-MM.' );
        }
        $start = $from . '-01';
        $end   = date( 'Y-m-t', strtotime( $to . '-01' ) );
        $settings_obj = new SII_Boleta_Settings();
        $settings     = $settings_obj->get_settings();
        $manager      = new SII_Libro_Boletas( $settings_obj );
        $xml = $manager->generate_libro_xml( $start, $end );
        if ( ! $xml ) {
            WP_CLI::error( 'Error al generar el Libro.' );
        }
        $sent = $manager->send_libro_to_sii(
            $xml,
            $settings['environment'],
            $settings['api_token'] ?? '',
            $settings['cert_path'] ?? '',
            $settings['cert_pass'] ?? ''
        );
        if ( $sent ) {
            WP_CLI::success( 'Libro enviado correctamente.' );
        } else {
            WP_CLI::error( 'Error al enviar el Libro.' );
        }
    }

    /**
     * Sincroniza los recursos de LibreDTE desde vendor hacia la carpeta del plugin.
     *
     * ## EXAMPLES
     *
     *     wp sii resources sync
     *
     * @param array $args       Argumentos posicionales (no usados).
     * @param array $assoc_args Argumentos asociativos (no usados).
     */
    public static function resources_sync( $args, $assoc_args ) {
        $src  = wp_normalize_path( SII_BOLETA_DTE_PATH . 'vendor/libredte/libredte-lib-core/resources' );
        $dest = wp_normalize_path( SII_BOLETA_DTE_PATH . 'resources' );

        if ( ! is_dir( $src ) ) {
            WP_CLI::error( 'No se encontró la carpeta de recursos de LibreDTE. Ejecuta composer install.' );
        }

        self::recursive_copy( $src, $dest );
        WP_CLI::success( 'Recursos de LibreDTE sincronizados.' );
    }

    /**
     * Importa un certificado PFX/P12 y lo guarda en los ajustes.
     *
     * ## OPTIONS
     *
     * [--file=<ruta>]
     * : Ruta al archivo del certificado.
     *
     * [--pass=<clave>]
     * : Contraseña del certificado.
     *
     * ## EXAMPLE
     *
     *     wp sii cert import --file=mi-cert.p12 --pass=secreto
     *
     * @param array $args       Argumentos posicionales (no usados).
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function cert_import( $args, $assoc_args ) {
        $file = $assoc_args['file'] ?? '';
        $pass = $assoc_args['pass'] ?? '';
        if ( empty( $file ) || ! is_readable( $file ) ) {
            WP_CLI::error( 'Debe indicar --file con la ruta al certificado (.p12/.pfx).' );
        }
        if ( empty( $pass ) ) {
            WP_CLI::error( 'Debe indicar --pass con la contraseña del certificado.' );
        }

        $uploads  = wp_upload_dir();
        $dest_dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'sii-boleta-dte' );
        wp_mkdir_p( $dest_dir );
        $dest = wp_normalize_path( $dest_dir . '/' . basename( $file ) );
        if ( ! @copy( $file, $dest ) ) {
            WP_CLI::error( 'No se pudo copiar el certificado al directorio de uploads.' );
        }

        $opts = get_option( SII_Boleta_Settings::OPTION_NAME, [] );
        $opts['cert_path'] = $dest;
        $opts['cert_pass'] = self::encrypt_value( $pass );
        update_option( SII_Boleta_Settings::OPTION_NAME, $opts );

        WP_CLI::success( 'Certificado importado correctamente.' );
    }

    /**
     * Importa un archivo CAF para un tipo de DTE específico.
     *
     * ## OPTIONS
     *
     * [--type=<tipo>]
     * : Tipo de DTE (39, 41, 33, etc.).
     *
     * [--file=<ruta>]
     * : Ruta al archivo XML del CAF.
     *
     * ## EXAMPLE
     *
     *     wp sii caf import --type=39 --file=caf_boleta.xml
     *
     * @param array $args       Argumentos posicionales (no usados).
     * @param array $assoc_args Argumentos asociativos.
     */
    public static function caf_import( $args, $assoc_args ) {
        $type = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : '';
        $file = $assoc_args['file'] ?? '';
        if ( empty( $type ) ) {
            WP_CLI::error( 'Debe indicar --type con el tipo de DTE.' );
        }
        if ( empty( $file ) || ! is_readable( $file ) ) {
            WP_CLI::error( 'Debe indicar --file con la ruta al CAF (.xml).' );
        }

        $uploads  = wp_upload_dir();
        $dest_dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'sii-boleta-dte' );
        wp_mkdir_p( $dest_dir );
        $dest = wp_normalize_path( $dest_dir . '/' . basename( $file ) );
        if ( ! @copy( $file, $dest ) ) {
            WP_CLI::error( 'No se pudo copiar el CAF al directorio de uploads.' );
        }

        $opts = get_option( SII_Boleta_Settings::OPTION_NAME, [] );
        if ( ! isset( $opts['caf_path'] ) || ! is_array( $opts['caf_path'] ) ) {
            $opts['caf_path'] = [];
        }
        $opts['caf_path'][ $type ] = $dest;
        update_option( SII_Boleta_Settings::OPTION_NAME, $opts );

        WP_CLI::success( sprintf( 'CAF importado para el tipo %s.', $type ) );
    }

    /**
     * Cifra un valor utilizando la misma estrategia que la clase de ajustes.
     *
     * @param string $value Valor a cifrar.
     * @return string
     */
    private static function encrypt_value( $value ) {
        $key = hash( 'sha256', wp_salt( 'sii_boleta_dte_cert' ) );
        $iv  = substr( hash( 'sha256', 'sii_boleta_dte_iv' ), 0, 16 );
        $enc = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );
        return $enc ? base64_encode( $enc ) : '';
    }

    /**
     * Copia recursivamente el contenido de un directorio.
     *
     * @param string $src  Directorio de origen.
     * @param string $dest Directorio de destino.
     */
    private static function recursive_copy( $src, $dest ) {
        $dir = opendir( $src );
        wp_mkdir_p( $dest );
        while ( false !== ( $file = readdir( $dir ) ) ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }
            $src_path  = $src . '/' . $file;
            $dest_path = $dest . '/' . $file;
            if ( is_dir( $src_path ) ) {
                self::recursive_copy( $src_path, $dest_path );
            } else {
                copy( $src_path, $dest_path );
            }
        }
        closedir( $dir );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'sii dte status', [ 'SII_Boleta_CLI', 'dte_status' ] );
    WP_CLI::add_command( 'sii libro', [ 'SII_Boleta_CLI', 'libro' ] );
    WP_CLI::add_command( 'sii dte emitir', [ 'SII_Boleta_CLI', 'dte_emitir' ] );
    WP_CLI::add_command( 'sii resources sync', [ 'SII_Boleta_CLI', 'resources_sync' ] );
    WP_CLI::add_command( 'sii cert import', [ 'SII_Boleta_CLI', 'cert_import' ] );
    WP_CLI::add_command( 'sii caf import', [ 'SII_Boleta_CLI', 'caf_import' ] );
}
