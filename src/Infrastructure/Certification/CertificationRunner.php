<?php
namespace Sii\BoletaDte\Infrastructure\Certification;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Application\ConsumoFolios;
use Sii\BoletaDte\Infrastructure\Signer;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

/**
 * Executes the certification plan: emits a set of documents for selected folios
 * and enqueues them to be sent to SII.
 */
class CertificationRunner {
    private Settings $settings;
    private Api $api;
    private TokenManager $tokenManager;
    private DteEngine $engine;
    private PdfGenerator $pdf;
    private Queue $queue;
    private Signer $signer;

    public function __construct( Settings $settings, Api $api, TokenManager $tokenManager, DteEngine $engine, PdfGenerator $pdf, Queue $queue, Signer $signer ) {
        $this->settings     = $settings;
        $this->api          = $api;
        $this->tokenManager = $tokenManager;
        $this->engine       = $engine;
        $this->pdf          = $pdf;
        $this->queue        = $queue;
        $this->signer       = $signer;
    }

    /**
     * Kicks the plan execution. Returns true when at least one job was queued.
     * @param array<string,mixed> $plan
     */
    public function run( array $plan ): bool {
        $environment = '0'; // certification only
        $types       = isset( $plan['types'] ) && is_array( $plan['types'] ) ? $plan['types'] : array();
        $flags       = isset( $plan['flags'] ) && is_array( $plan['flags'] ) ? $plan['flags'] : array();
        $dryRun      = ! empty( $flags['dryRun'] );
        $retry       = array_key_exists( 'retryOnConflict', $flags ) ? (bool) $flags['retryOnConflict'] : true;

        if ( empty( $types ) ) {
            return false;
        }

    $picker = new CertificationFolioPicker( $types, $this->settings );
        $token  = $this->tokenManager->get_token( $environment );
        $queued = 0;
    /** @var array<int,array{tipo:int,folio:int,fch:string,mnt:int,rutEmisor:string,rutRecep:string,stage:string}> $recibos */
    $recibos = array();

        foreach ( $types as $tipoStr => $_cfg ) {
            $tipo = (int) $tipoStr;
            while ( true ) {
                $folio = (int) $picker->next( $tipo );
                if ( $folio <= 0 ) { break; }

                // Build data using YAML templates through the engine's factories.
                $data = $this->buildSampleData( $tipo, $folio );
                $xml  = $this->engine->generate_dte_xml( $data, $tipo, false );
                if ( ! is_string( $xml ) || '' === $xml ) {
                    if ( is_object( $xml ) && \is_wp_error( $xml ) ) { /* ignore */ }
                    if ( $retry ) { continue; }
                    break;
                }

                // Store XML and enqueue job for sending.
                $stored  = XmlStorage::store( $this->createTempXml( $xml ) );
                $path    = $stored['path'] ?? '';
                $fileKey = $stored['key'] ?? '';
                if ( '' === $path ) {
                    if ( $retry ) { continue; }
                    break;
                }

                if ( ! $dryRun ) {
                    $this->queue->enqueue_dte( $path, $environment, $token, $fileKey );
                }

                $queued++;

                // Collect info for Recibos if Factura/Factura Exenta (33/34)
                if ( in_array( $tipo, array( 33, 34 ), true ) ) {
                    $info = $this->extractFacturaInfo( $xml, $data );
                    if ( $info ) { $recibos[] = $info; }
                }

                // Optionally mark folio used to advance counters in plan
                if ( ! $dryRun ) {
                    Settings::update_last_folio_value( $tipo, $environment, $folio );
                }
            }
        }

        // Generate a Consumo de Folios for today to help the certification flow.
        if ( $queued > 0 && ! $dryRun ) {
            try {
                $cdf = new ConsumoFolios( $this->settings, new \Sii\BoletaDte\Application\FolioManager( $this->settings ), $this->api );
                $today = gmdate( 'Y-m-d' );
                $xml = $cdf->generate_cdf_xml( $today );
                if ( is_string( $xml ) && '' !== $xml ) {
                    $this->queue->enqueue_libro( $xml, $environment, $token );
                }
            } catch ( \Throwable $e ) {
                // do not fail the run on CDF
            }
        }

        // Build and enqueue EnvioRecibos acknowledging facturas, if any
        if ( ! $dryRun && ! empty( $recibos ) ) {
            try {
                // Support stages: mercaderias/recepcion/aceptacion via flag
                $stage = isset( $flags['reciboStage'] ) ? (string) $flags['reciboStage'] : '';
                if ( '' !== $stage ) {
                    foreach ( $recibos as &$r ) { $r['stage'] = $stage; }
                    unset( $r );
                }
                $xmlRec = $this->buildEnvioRecibosXml( $recibos );
                if ( '' !== $xmlRec ) {
                    $cfg = $this->settings->get_settings();
                    $certPath = (string) ( $cfg['cert_path'] ?? '' );
                    $certPass = (string) ( $cfg['cert_pass'] ?? '' );

                    // Prefer LibreDTE signing when available and enabled; fall back to xmlseclibs.
                    $preferLibre = ! empty( $cfg['prefer_libredte_recibos'] );
                    $toSend = $xmlRec;
                    if ( $preferLibre && method_exists( $this->engine, 'maybe_sign_envio_recibos' ) ) {
                        try {
                            // Invoke dynamically to avoid static analyzers flagging unknown methods on the interface type
                            /** @var callable $callable */
                            $callable = array( $this->engine, 'maybe_sign_envio_recibos' );
                            $libredteSigned = \is_callable( $callable ) ? \call_user_func( $callable, $xmlRec, $certPath, $certPass ) : null;
                            if ( is_string( $libredteSigned ) && '' !== $libredteSigned ) {
                                $toSend = $libredteSigned;
                            }
                        } catch ( \Throwable $e ) {
                            // ignore and fallback below
                        }
                    }

                    if ( $toSend === $xmlRec ) {
                        $signed = $this->signer->sign_recibos_xml( $xmlRec, $certPath, $certPass );
                        if ( is_string( $signed ) && '' !== $signed ) {
                            $toSend = $signed;
                        }
                    }

                    if ( $this->validateEnvioRecibosXml( $toSend ) ) {
                        $this->queue->enqueue_recibos( $toSend, $environment, $token );
                    }
                }
            } catch ( \Throwable $e ) {
                // ignore recibos failures in phase 2
            }
        }

        return $queued > 0;
    }

    /**
     * Builds a minimal data array that the engine can transform into a valid DTE
     * using YAML templates. For certification, simple one-item documents suffice.
     * @return array<string,mixed>
     */
    private function buildSampleData( int $tipo, int $folio ): array {
        $cfg = $this->settings->get_settings();
        $rut = (string) ( $cfg['rut_emisor'] ?? '11111111-1' );
        $raz = (string) ( $cfg['razon_social'] ?? 'Empresa de Prueba' );

        $base = array(
            'Encabezado' => array(
                'IdDoc' => array(
                    'TipoDTE' => $tipo,
                    'Folio'   => $folio,
                    'FchEmis' => gmdate( 'Y-m-d' ),
                ),
                'Emisor' => array(
                    'RUTEmisor'   => $rut,
                    'RznSocEmisor'=> $raz,
                    'GiroEmis'    => (string) ( $cfg['giro'] ?? 'Comercio' ),
                    'DirOrigen'   => (string) ( $cfg['direccion'] ?? 'S/D' ),
                    'CmnaOrigen'  => (string) ( $cfg['comuna'] ?? 'S/D' ),
                    'CiudadOrigen'=> (string) ( $cfg['ciudad'] ?? 'S/D' ),
                ),
                'Receptor' => array(
                    'RUTRecep'   => '66666666-6',
                    'RznSocRecep'=> 'Cliente Certificación',
                    'GiroRecep'  => 'Servicios',
                    'DirRecep'   => 'Dirección 123',
                    'CmnaRecep'  => 'Comuna',
                    'CiudadRecep'=> 'Ciudad',
                ),
            ),
            'Detalle' => array(
                array(
                    'NmbItem' => 'Item de prueba',
                    'QtyItem' => 1,
                    'PrcItem' => 1190,
                    'MntBruto'=> 1,
                ),
            ),
        );

        // Exento (41) y Factura de Compra (46) sin IVA
        if ( in_array( $tipo, array( 41, 46 ), true ) ) {
            $base['Detalle'][0]['IndExe'] = 1;
            unset( $base['Detalle'][0]['MntBruto'] );
        }

        // Guía de despacho (52): incluir datos mínimos de traslado
        if ( 52 === $tipo ) {
            $base['Encabezado']['IdDoc']['FmaPago'] = 1;
            $base['Encabezado']['IdDoc']['IndTraslado'] = 1; // Venta
        }

        return $base;
    }

    private function createTempXml( string $xml ): string {
        $tmp = tempnam( sys_get_temp_dir(), 'dte' );
        file_put_contents( $tmp, $xml );
        return $tmp;
    }

    /**
     * Extracts minimal info from a Factura XML for building Recibos later.
     * @param array<string,mixed> $data
    * @return array{tipo:int,folio:int,fch:string,mnt:int,rutEmisor:string,rutRecep:string,stage:string}|null
     */
    private function extractFacturaInfo( string $xml, array $data ): ?array {
        $tipo = (int) ( $data['Encabezado']['IdDoc']['TipoDTE'] ?? 0 );
        $folio = (int) ( $data['Encabezado']['IdDoc']['Folio'] ?? 0 );
        $rutEmisor = (string) ( $data['Encabezado']['Emisor']['RUTEmisor'] ?? '' );
        $rutRecep  = (string) ( $data['Encabezado']['Receptor']['RUTRecep'] ?? '' );
        $fch = (string) ( $data['Encabezado']['IdDoc']['FchEmis'] ?? gmdate( 'Y-m-d' ) );
        $mnt = 0;
        try {
            \libxml_use_internal_errors( true );
            $sx = simplexml_load_string( $xml );
            if ( false !== $sx ) {
                $sx->registerXPathNamespace( 's', 'http://www.sii.cl/SiiDte' );
                $nodes = $sx->xpath( '//s:DTE/s:Documento/s:Encabezado/s:Totales/s:MntTotal' );
                if ( is_array( $nodes ) && isset( $nodes[0] ) ) {
                    $mnt = (int) $nodes[0];
                }
                $fchNodes = $sx->xpath( '//s:DTE/s:Documento/s:Encabezado/s:IdDoc/s:FchEmis' );
                if ( is_array( $fchNodes ) && isset( $fchNodes[0] ) ) {
                    $fch = (string) $fchNodes[0];
                }
            }
            \libxml_clear_errors();
        } catch ( \Throwable $e ) {
            // ignore XML parsing issues
        }
        if ( $tipo <= 0 || $folio <= 0 || '' === $rutEmisor || '' === $rutRecep ) {
            return null;
        }
        if ( $mnt <= 0 ) { $mnt = 1190; }
        return array(
            'tipo' => $tipo,
            'folio'=> $folio,
            'fch'  => $fch,
            'mnt'  => $mnt,
            'rutEmisor' => $rutEmisor,
            'rutRecep'  => $rutRecep,
            'stage' => '',
        );
    }

    /**
     * Builds a minimal EnvioRecibos for the provided facturas list.
    * @param array<int,array{tipo:int,folio:int,fch:string,mnt:int,rutEmisor:string,rutRecep:string,stage:string}> $items
     */
    private function buildEnvioRecibosXml( array $items ): string {
        if ( empty( $items ) ) { return ''; }
        $cfg = $this->settings->get_settings();
        $rutEmisor = (string) ( $cfg['rut_emisor'] ?? '11111111-1' );
        // Recibo por proveedor: si existe en plan, usarlo como RutResponde/RutFirma
        $plan = array();
        if ( function_exists( 'get_option' ) ) { $plan = (array) get_option( 'sii_boleta_cert_plan', array() ); }
        $flags = isset( $plan['flags'] ) && is_array( $plan['flags'] ) ? $plan['flags'] : array();
        $rutProveedor = isset( $flags['rut_proveedor'] ) ? (string) $flags['rut_proveedor'] : '';
        $rutReceptor = '' !== $rutProveedor ? $rutProveedor : '66666666-6';
        $now = gmdate( 'Y-m-d\TH:i:s' );
        $idSet = 'SR' . substr( sha1( $now ), 0, 6 );
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>';
        $xml .= '<EnvioRecibos xmlns="http://www.sii.cl/SiiDte" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">';
        $xml .= '<SetRecibos ID="' . htmlspecialchars( $idSet, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '" version="1.0">';
        $xml .= '<Caratula version="1.0">';
        $xml .= '<RutResponde>' . htmlspecialchars( $rutReceptor, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</RutResponde>';
        $xml .= '<RutRecibe>' . htmlspecialchars( $rutEmisor, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</RutRecibe>';
        $xml .= '<TmstFirmaEnv>' . htmlspecialchars( $now, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</TmstFirmaEnv>';
        $xml .= '</Caratula>';
        $i = 1;
        foreach ( $items as $it ) {
            $rid = 'R' . $i++;
            $xml .= '<Recibo version="1.0">';
            $xml .= '<DocumentoRecibo ID="' . htmlspecialchars( $rid, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '">';
            $xml .= '<TipoDoc>' . (int) $it['tipo'] . '</TipoDoc>';
            $xml .= '<Folio>' . (int) $it['folio'] . '</Folio>';
            $xml .= '<FchEmis>' . htmlspecialchars( $it['fch'], ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</FchEmis>';
            $xml .= '<RUTEmisor>' . htmlspecialchars( $it['rutEmisor'], ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</RUTEmisor>';
            $xml .= '<RUTRecep>' . htmlspecialchars( $it['rutRecep'], ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</RUTRecep>';
            $xml .= '<MntTotal>' . (int) $it['mnt'] . '</MntTotal>';
            $xml .= '<Recinto>' . htmlspecialchars( (string) ( $cfg['direccion'] ?? 'Casa Matriz' ), ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</Recinto>';
            $xml .= '<RutFirma>' . htmlspecialchars( $rutReceptor, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</RutFirma>';
            $xml .= '<Declaracion>El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4, y la letra c) del Art. 5 de la Ley 19.983, acredita que la entrega de mercaderias o servicio(s) prestado(s) ha(n) sido recibido(s).</Declaracion>';
            $xml .= '<TmstFirmaRecibo>' . htmlspecialchars( $now, ENT_QUOTES | ENT_SUBSTITUTE, 'ISO-8859-1' ) . '</TmstFirmaRecibo>';
            $xml .= '</DocumentoRecibo>';
            $xml .= '</Recibo>';
        }
        $xml .= '</SetRecibos>';
        $xml .= '</EnvioRecibos>';
        return $xml;
    }

    private function validateEnvioRecibosXml( string $xml ): bool {
        $doc = new \DOMDocument();
        if ( ! $doc->loadXML( $xml ) ) { return false; }
        \libxml_use_internal_errors( true );
        $xsdPath = SII_BOLETA_DTE_PATH . 'resources/schemas/EnvioRecibos_v10.xsd';
        $ok = $doc->schemaValidate( $xsdPath );
        \libxml_clear_errors();
        return (bool) $ok;
    }
}
