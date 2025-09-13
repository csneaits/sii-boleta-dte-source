<?php
namespace Sii\BoletaDte\Infrastructure;

/**
 * Provides access to plugin settings.
 */
class Settings {
	public const OPTION_GROUP = 'sii_boleta_dte_settings_group';
	public const OPTION_NAME  = 'sii_boleta_dte_settings';

	/**
	 * Returns settings from WordPress options.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		if ( function_exists( 'get_option' ) ) {
			$data = get_option( self::OPTION_NAME, array() );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}
}

class_alias( Settings::class, 'SII_Boleta_Settings' );
