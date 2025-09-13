<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Builds and validates daily sales summary (RVD).
 */
class RvdManager {
	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Validates RVD XML against schema.
	 */
	public function validate_rvd_xml( string $xml ): bool {
		$doc = new \DOMDocument();
		if ( ! $doc->loadXML( $xml ) ) {
			return false;
		}
		libxml_use_internal_errors( true );
		$xsd   = SII_BOLETA_DTE_PATH . 'resources/xml/schemas/consumo_folios.xsd';
		$valid = $doc->schemaValidate( $xsd );
		libxml_clear_errors();
		return $valid;
	}
}

class_alias( RvdManager::class, 'SII_Boleta_RVD_Manager' );
