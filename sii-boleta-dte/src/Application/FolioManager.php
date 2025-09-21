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
if ( empty( $settings['cafs'] ) || ! is_array( $settings['cafs'] ) ) {
return array();
}
foreach ( $settings['cafs'] as $caf ) {
if ( (int) ( $caf['tipo'] ?? 0 ) !== $type ) {
continue;
}
return array(
'FchResol' => (string) ( $caf['fecha_resol'] ?? $caf['fecha'] ?? '' ),
'NroResol' => (string) ( $caf['nro_resol'] ?? '' ),
'D'        => (int) ( $caf['desde'] ?? 0 ),
'H'        => (int) ( $caf['hasta'] ?? 0 ),
);
}
return array();
}
}

class_alias( FolioManager::class, 'SII_Boleta_Folio_Manager' );
