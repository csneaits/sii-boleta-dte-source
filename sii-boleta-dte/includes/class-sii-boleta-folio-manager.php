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
     * correctamente, devuelve false.
     *
     * @param int $tipo_dte El código del tipo de documento (por ejemplo 39, 33, 41, 61, 56).
     * @return int|false El siguiente folio disponible o false si no hay folios.
     */
    public function get_next_folio( $tipo_dte = 39 ) {
        $settings = ( new SII_Boleta_Settings() )->get_settings();
        $caf_path = $settings['caf_path'];
        if ( ! $caf_path || ! file_exists( $caf_path ) ) {
            return false;
        }
        $range = $this->get_caf_range( $caf_path );
        if ( ! $range ) {
            return false;
        }
        // Clave de opción específica por tipo de DTE
        $option_key = self::OPTION_LAST_FOLIO_PREFIX . intval( $tipo_dte );
        // Recuperar el último folio para este tipo; si no existe, empezar en D - 1
        $last_folio = intval( get_option( $option_key, $range['D'] - 1 ) );
        $next_folio = $last_folio + 1;
        if ( $next_folio > $range['H'] ) {
            return false; // Se acabaron los folios autorizados
        }
        update_option( $option_key, $next_folio );
        return $next_folio;
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
}