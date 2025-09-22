<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

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
        $environment = $this->settings->get_environment();
        $ranges      = FoliosDb::for_type( $type, $environment );
        if ( empty( $ranges ) ) {
            return $this->no_folio_error();
        }

        $last = Settings::get_last_folio_value( $type, $environment );
        $next = null;
        foreach ( $ranges as $range ) {
            $desde        = (int) $range['desde'];
            $hasta_raw    = (int) $range['hasta'];
            $hasta_limit  = $hasta_raw - 1;
            if ( $last < $desde ) {
                $next = $desde;
                break;
            }
            if ( $last >= $desde && $last < $hasta_limit ) {
                $next = $last + 1;
                break;
            }
        }

        if ( null === $next ) {
            return $this->no_folio_error();
        }

        Settings::update_last_folio_value( $type, $environment, $next );

        return $next;
    }

	/**
	 * Extracts CAF info for a given type.
	 *
	 * @return array<string,string>
	 */
    public function get_caf_info( int $type = 39 ): array {
        $ranges = FoliosDb::for_type( $type, $this->settings->get_environment() );
        if ( empty( $ranges ) ) {
            return array();
        }
        $first = $ranges[0];
        $last  = $ranges[ count( $ranges ) - 1 ];
        return array(
            'FchResol' => '',
            'NroResol' => '',
            'D'        => (int) $first['desde'],
            'H'        => (int) $last['hasta'] - 1,
        );
    }

    private function no_folio_error() {
        if ( class_exists( '\\WP_Error' ) ) {
            return new \WP_Error( 'sii_boleta_no_folios', __( 'No hay folios disponibles para este tipo de documento.', 'sii-boleta-dte' ) );
        }
        return 0;
    }
}

class_alias( FolioManager::class, 'SII_Boleta_Folio_Manager' );
