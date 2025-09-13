<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * @deprecated Desde la versión 1.0.0 se utiliza SII_LibreDTE_Engine.
 * Esta clase se mantiene solo por compatibilidad y delega en el motor DTE.
 */
class SII_Boleta_XML_Generator {
    /** @var SII_DTE_Engine */
    private $engine;

    public function __construct( SII_Boleta_Settings $settings ) {
        try {
            $this->engine = new SII_LibreDTE_Engine( $settings );
        } catch ( \RuntimeException $e ) {
            $this->engine = new SII_Null_Engine();
        }
    }

    public function generate_dte_xml( array $data, $tipo_dte, $preview = false ) {
        return $this->engine->generate_dte_xml( $data, $tipo_dte, $preview );
    }
}
