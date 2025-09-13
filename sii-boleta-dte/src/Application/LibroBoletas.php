<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Signer;

/**
 * Generates and validates Libro de Boletas XML.
 */
class LibroBoletas {
    private Settings $settings;
    private Api $api;
    private Signer $signer;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
        $this->api     = new Api();
        $this->signer  = new Signer();
    }

    /**
     * Validates Libro XML against schema.
     */
    public function validate_libro_xml( string $xml ): bool {
        $doc = new \DOMDocument();
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        libxml_use_internal_errors( true );
        $xsd   = SII_BOLETA_DTE_PATH . 'resources/xml/schemas/libro_boletas.xsd';
        $valid = $doc->schemaValidate( $xsd );
        libxml_clear_errors();
        return $valid;
    }
}

class_alias( LibroBoletas::class, 'SII_Libro_Boletas' );
