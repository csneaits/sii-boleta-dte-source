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
    public function get_next_folio( int $type, bool $consume = true ) {
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

        if ( $consume ) {
            Settings::update_last_folio_value( $type, $environment, $next );
        }

        return $next;
    }

    public function mark_folio_used( int $type, int $folio ): void {
        if ( $folio <= 0 ) {
            return;
        }

        $environment = $this->settings->get_environment();
        Settings::update_last_folio_value( $type, $environment, $folio );
    }

	/**
	 * Extracts CAF info for a given type.
	 *
	 * @return array<string,string>
	 */
    public function get_caf_info( int $type = 39 ): array {
        $environment = $this->settings->get_environment();
        $ranges      = FoliosDb::for_type( $type, $environment );
        if ( empty( $ranges ) ) {
            return array();
        }

        $caf_data = null;
        foreach ( $ranges as $range ) {
            if ( ! empty( $range['caf'] ) ) {
                $caf_data = $range['caf'];
                break;
            }
        }

        $info = array(
            'FchResol' => '',
            'NroResol' => '',
            'D'        => (int) $ranges[0]['desde'],
            'H'        => (int) $ranges[ count( $ranges ) - 1 ]['hasta'] - 1,
        );

        if ( null === $caf_data || '' === trim( $caf_data ) ) {
            return $info;
        }

        try {
            $xml = new \SimpleXMLElement( $caf_data );
        } catch ( \Throwable $e ) {
            return $info;
        }

        $namespaces = $xml->getDocNamespaces( true );
        if ( isset( $namespaces[''] ) ) {
            $xml->registerXPathNamespace( 'caf', $namespaces[''] );
        } else {
            $xml->registerXPathNamespace( 'caf', 'http://www.sii.cl/SiiDte' );
        }

        $resolve = static function ( \SimpleXMLElement $root, string $path ): string {
            $nodes = $root->xpath( $path );
            if ( ! is_array( $nodes ) || empty( $nodes ) ) {
                return '';
            }
            $value = (string) $nodes[0];
            return trim( $value );
        };

        $fch_resol = $resolve( $xml, '//AUTORIZACION/CAF/DA/FA' );
        $nro_resol = $resolve( $xml, '//AUTORIZACION/CAF/DA/NroResol' );
        if ( '' === $nro_resol ) {
            $nro_resol = $resolve( $xml, '//AUTORIZACION/CAF/DA/NroRes' );
        }

        $desde = $resolve( $xml, '//AUTORIZACION/CAF/DA/RNG/D' );
        $hasta = $resolve( $xml, '//AUTORIZACION/CAF/DA/RNG/H' );

        if ( '' !== $desde && ctype_digit( $desde ) ) {
            $info['D'] = (int) $desde;
        }
        if ( '' !== $hasta && ctype_digit( $hasta ) ) {
            $info['H'] = (int) $hasta;
        }
        if ( '' !== $fch_resol ) {
            $info['FchResol'] = $fch_resol;
        }
        if ( '' !== $nro_resol ) {
            $info['NroResol'] = $nro_resol;
        }

        return $info;
    }

    private function no_folio_error() {
        if ( class_exists( '\\WP_Error' ) ) {
            return new \WP_Error( 'sii_boleta_no_folios', __( 'No hay folios disponibles para este tipo de documento.', 'sii-boleta-dte' ) );
        }
        return 0;
    }
}

class_alias( FolioManager::class, 'SII_Boleta_Folio_Manager' );
