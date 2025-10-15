<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

/**
 * Manages folio numbers and CAF information.
 */
class FolioManager {
	private Settings $settings;
    /**
     * Structure: [ type => ['reserved' => int, 'previous' => int] ]
     * Stores reservations in-memory for the request and persists the reservation
     * by updating Settings::last_folio_value so concurrent requests avoid
     * colliding on the same folio. release_reserved_folio will attempt to
     * restore the previous last value atomically.
     *
     * Note: reservations are per-request (in-memory) and intended for usage
     * within the same request lifecycle where a folio is reserved and then
     * either finalized or released.
     *
     * @return int|null reserved folio or null on error
     */
    private array $temporaryReservations = [];

    public function reserve_folio_temporarily( int $type ): ?int {
        $environment = $this->settings->get_environment();
        // Read current last value
        $current = Settings::get_last_folio_value( $type, $environment );

        // Get next and consume it (persist reservation)
        $next = $this->get_next_folio( $type, true );
        if ( function_exists( 'is_wp_error' ) && is_wp_error( $next ) ) {
            return null;
        }
        if ( $next instanceof \WP_Error ) {
            return null;
        }
        $folio = (int) $next;
        if ( $folio <= 0 ) {
            return null;
        }

        $this->temporaryReservations[ $type ] = array(
            'reserved' => $folio,
            'previous' => (int) $current,
        );

        return $folio;
    }

    /**
     * Releases a temporarily reserved folio by attempting to restore the
     * previous last value using an atomic compare-and-set. Returns true if
     * restored or reservation not found.
     */
    public function release_reserved_folio( int $type ): bool {
        if ( ! isset( $this->temporaryReservations[ $type ] ) ) {
            return true;
        }

        $entry = $this->temporaryReservations[ $type ];
        $reserved = (int) $entry['reserved'];
        $previous = (int) $entry['previous'];

        // Only revert if the current stored last value equals the reserved one.
        $environment = $this->settings->get_environment();
        $current = Settings::get_last_folio_value( $type, $environment );
        if ( (int) $current === $reserved ) {
            $ok = Settings::compare_and_update_last_folio_value( $type, $environment, $reserved, $previous );
            unset( $this->temporaryReservations[ $type ] );
            return (bool) $ok;
        }

        // If current isn't the reserved value, we can't safely revert; just drop reservation record.
        unset( $this->temporaryReservations[ $type ] );
        return true;
    }

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Gets next folio for a given type.
	 */
    public function get_next_folio( int $type, bool $consume = true, bool $temporary = false ) {
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
        } elseif ( $temporary ) {
            $this->temporaryReservations[$type] = $next;
        }

        return $next;
    }

    

    public function mark_folio_used( int $type, int $folio ): bool {
        if ( $folio <= 0 ) {
            return false;
        }

        $environment = $this->settings->get_environment();
        $expected    = max( 0, $folio - 1 );
        $current     = Settings::get_last_folio_value( $type, $environment );

        if ( $current >= $folio ) {
            return $current === $folio;
        }

        if ( $current < $expected ) {
            return Settings::compare_and_update_last_folio_value( $type, $environment, $current, $folio );
        }

        return Settings::compare_and_update_last_folio_value( $type, $environment, $expected, $folio );
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
