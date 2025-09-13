<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Manages folio numbers and CAF information.
 */
class FolioManager {
	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Gets next folio for a given type.
	 */
	public function get_next_folio( int $type ) {
		$key  = 'sii_boleta_dte_last_folio_' . $type;
		$last = function_exists( 'get_option' ) ? (int) get_option( $key, 0 ) : 0;
		if ( function_exists( 'update_option' ) ) {
			update_option( $key, $last + 1 );
		}
		return $last + 1;
	}

	/**
	 * Extracts CAF info for a given type.
	 *
	 * @return array<string,string>
	 */
	public function get_caf_info( int $type = 39 ): array {
		$settings = $this->settings->get_settings();
		$caf_path = $settings['caf_path'][ $type ] ?? '';
		if ( ! $caf_path || ! file_exists( $caf_path ) ) {
			return array();
		}
		$xml = simplexml_load_file( $caf_path );
		if ( ! $xml ) {
			return array();
		}
		return array(
			'FchResol' => (string) $xml->DA->RE->FchResol,
			'NroResol' => (string) $xml->DA->RE->NroResol,
			'D'        => (int) $xml->DA->RNG->D,
			'H'        => (int) $xml->DA->RNG->H,
		);
	}
}

class_alias( FolioManager::class, 'SII_Boleta_Folio_Manager' );
