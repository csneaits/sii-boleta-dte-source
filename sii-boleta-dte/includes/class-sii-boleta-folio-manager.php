<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manejador de folios. Se encarga de cargar el archivo CAF, extraer los
 * rangos de folios autorizados y entregar el siguiente folio disponible.
 * También lleva registro del último folio utilizado en la base de datos de
 * WordPress. Esta clase abstrae la gestión del CAF y del folio, de modo
 * que el resto del plugin no necesita manipular directamente el archivo
 * CAF.
 */
class SII_Boleta_Folio_Manager {

    /**
     * Instancia de configuraciones del plugin.
     *
     * @var SII_Boleta_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param SII_Boleta_Settings $settings Instancia de configuraciones.
     */
    public function __construct( SII_Boleta_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Prefijo de la opción en la base de datos donde se almacena el último folio usado
     * para cada tipo de documento. Cada tipo de DTE debe llevar su propio contador
     * de folios, ya que el SII asigna rangos de folios independientes por tipo.
     */
    const OPTION_LAST_FOLIO_PREFIX = 'sii_boleta_dte_last_folio_';

    /**
     * Obtiene el siguiente folio disponible para un tipo de documento determinado.
     * Si no se pasa tipo se utilizará el tipo 39 por defecto (boleta). Esta función
     * carga el rango de folios del CAF y actualiza el contador almacenado en la
     * base de datos. Si no quedan folios disponibles o el CAF no está configurado
     * correctamente, devuelve false o WP_Error si falta el CAF.
     *
     * @param int $tipo_dte El código del tipo de documento (por ejemplo 39, 33, 41, 61, 56).
     * @return int|\WP_Error|false El siguiente folio disponible o false/WP_Error si no hay folios o falta CAF.
     */
    public function get_next_folio( $tipo_dte = 39 ) {
        $settings  = $this->settings->get_settings();
        $caf_paths = $settings['caf_path'] ?? [];
        $caf_path  = $caf_paths[ $tipo_dte ] ?? '';
        if ( ! $caf_path || ! file_exists( $caf_path ) ) {
            return new \WP_Error( 'sii_boleta_missing_caf', sprintf( __( 'No se encontró CAF para el tipo de DTE %s.', 'sii-boleta-dte' ), $tipo_dte ) );
        }
        $range = $this->get_caf_range( $caf_path );
        if ( ! $range ) {
            return false;
        }
        // Clave de opción específica por tipo de DTE
        $option_key = self::OPTION_LAST_FOLIO_PREFIX . intval( $tipo_dte );
        $lock_key   = $option_key . '_lock';
        $lock_ttl   = 5;
        $start      = time();

        while ( get_transient( $lock_key ) ) {
            if ( time() - $start > $lock_ttl ) {
                break;
            }
            sleep( 1 );
        }

        if ( get_transient( $lock_key ) ) {
            return false;
        }

        set_transient( $lock_key, 1, $lock_ttl );

        try {
            // Recuperar el último folio para este tipo; si no existe, empezar en D - 1
            $last_folio = intval( get_option( $option_key, $range['D'] - 1 ) );
            $next_folio = $last_folio + 1;
            if ( $next_folio > $range['H'] ) {
                return false; // Se acabaron los folios autorizados
            }
            update_option( $option_key, $next_folio, false );
            return $next_folio;
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * Extrae el rango de folios (D y H) desde el archivo CAF. El CAF es un
     * XML con la siguiente estructura simplificada:
     *
     * <CAF>
     *   <DA>
     *     <RNG>
     *       <D>100</D>
     *       <H>200</H>
     *     </RNG>
     *   ...
     *
     * @param string $caf_path Ruta al archivo CAF.
     * @return array|false Array con claves 'D' y 'H' o false si falla.
     */
    private function get_caf_range( $caf_path ) {
        try {
            $xml = new SimpleXMLElement( file_get_contents( $caf_path ) );
            $d = (int) $xml->DA->RNG->D;
            $h = (int) $xml->DA->RNG->H;
            return [ 'D' => $d, 'H' => $h ];
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Obtiene información básica del CAF para un tipo de DTE. Se utiliza para
     * construir el RVD, extrayendo el rango autorizado y los datos de
     * resolución.
     *
     * @param int $tipo_dte Tipo de DTE (por defecto 39 - Boleta).
     * @return array|false Datos del CAF o false si no se encuentra.
     */
    public function get_caf_info( $tipo_dte = 39 ) {
        $settings  = $this->settings->get_settings();
        $caf_paths = $settings['caf_path'] ?? [];
        $caf_path  = $caf_paths[ $tipo_dte ] ?? '';
        if ( ! $caf_path || ! file_exists( $caf_path ) ) {
            return false;
        }
        try {
            $xml = new SimpleXMLElement( file_get_contents( $caf_path ) );
            return [
                'D'        => isset( $xml->DA->RNG->D ) ? (int) $xml->DA->RNG->D : 0,
                'H'        => isset( $xml->DA->RNG->H ) ? (int) $xml->DA->RNG->H : 0,
                'FchResol' => isset( $xml->DA->FA ) ? (string) $xml->DA->FA : '',
                'NroResol' => isset( $xml->DA->NroAut ) ? (string) $xml->DA->NroAut : '',
            ];
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Recorre los DTE generados en la carpeta de uploads y devuelve, para una
     * fecha dada, los folios utilizados y el monto total por tipo de
     * documento.
     *
     * @param string $date Fecha en formato Y-m-d.
     * @return array Arreglo asociativo tipo => [ 'monto' => int, 'folios' => int[] ].
     */
    public function get_folios_by_date( $date ) {
        $upload_dir   = wp_upload_dir();
        $base_dir     = trailingslashit( $upload_dir['basedir'] );
        $totales_tipo = [];
        $iterator     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir ) );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && preg_match( '/DTE_\d+_\d+_\d+\.xml$/', $file->getFilename() ) ) {
                $content = file_get_contents( $file->getPathname() );
                if ( ! $content ) {
                    continue;
                }
                try {
                    $doc      = new SimpleXMLElement( $content );
                    $doc_node = $doc->Documento;
                    if ( ! $doc_node ) {
                        continue;
                    }
                    $idDoc = $doc_node->Encabezado->IdDoc;
                    $fecha = (string) $idDoc->FchEmis;
                    $tipo  = intval( $idDoc->TipoDTE );
                    if ( $fecha !== $date ) {
                        continue;
                    }
                    $folio       = intval( $idDoc->Folio );
                    $totals      = $doc_node->Encabezado->Totales;
                    $monto_total = isset( $totals->MntTotal ) ? intval( $totals->MntTotal ) : 0;
                    if ( ! isset( $totales_tipo[ $tipo ] ) ) {
                        $totales_tipo[ $tipo ] = [ 'monto' => 0, 'folios' => [] ];
                    }
                    $totales_tipo[ $tipo ]['monto']  += $monto_total;
                    $totales_tipo[ $tipo ]['folios'][] = $folio;
                } catch ( Exception $e ) {
                    continue;
                }
            }
        }

        return $totales_tipo;
    }
}