<?php
namespace Sii\BoletaDte\Infrastructure\Certification;

use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Helper that yields folio numbers for a TipoDTE based on the saved certification plan.
 *
 * The plan structure (per tipo) is expected as:
 *   [ 'ranges' => int[], 'mode' => 'auto'|'manual', 'count' => int, 'manual' => '100,101,...' ]
 */
class CertificationFolioPicker {
    /** @var array<int,array{ranges:array<int>,mode:string,count:int,manual:string}> */
    private array $types;
    private string $environment;

    /** @var array<int,int> */
    private array $counters = array();
    /** @var array<int,int[]> */
    private array $manualLists = array();
    /** @var array<int,int> */
    private array $manualPos = array();
    /** @var array<int,array<int,array{d:int,h:int}>> */
    private array $allowedRanges = array();

    public function __construct( array $planTypes, Settings $settings ) {
        $this->types       = is_array( $planTypes ) ? $planTypes : array();
        $this->environment = $settings->get_environment();
        $this->prepare();
    }

    private function prepare(): void {
        foreach ( $this->types as $tipo => $cfg ) {
            $t = (int) $tipo;
            $rangesIds = isset( $cfg['ranges'] ) && is_array( $cfg['ranges'] ) ? array_map( 'intval', $cfg['ranges'] ) : array();
            $ranges    = array();
            foreach ( FoliosDb::for_type( $t, $this->environment ) as $row ) {
                $id = (int) $row['id'];
                if ( ! empty( $rangesIds ) && ! in_array( $id, $rangesIds, true ) ) {
                    continue;
                }
                $d = (int) $row['desde'];
                $h = (int) $row['hasta'] - 1;
                if ( $d <= 0 || $h <= 0 || $h < $d ) {
                    continue;
                }
                $ranges[] = array( 'd' => $d, 'h' => $h );
            }
            usort( $ranges, static fn( $a, $b ) => $a['d'] <=> $b['d'] );
            $this->allowedRanges[ $t ] = $ranges;

            $this->counters[ $t ] = 0;
            $manual = isset( $cfg['manual'] ) ? (string) $cfg['manual'] : '';
            $this->manualLists[ $t ] = $this->filterManualFolios( $manual, $ranges );
            $this->manualPos[ $t ]   = 0;
        }
    }

    /**
     * Returns the next folio for a tipo according to plan or 0 when exhausted.
     */
    public function next( int $tipo ): int {
        if ( ! isset( $this->types[ $tipo ] ) ) {
            return 0;
        }
        $cfg = $this->types[ $tipo ];
        $mode = isset( $cfg['mode'] ) && 'manual' === $cfg['mode'] ? 'manual' : 'auto';

        if ( 'manual' === $mode ) {
            $list = $this->manualLists[ $tipo ] ?? array();
            $pos  = $this->manualPos[ $tipo ] ?? 0;
            if ( $pos >= count( $list ) ) {
                return 0;
            }
            $folio = (int) $list[ $pos ];
            $this->manualPos[ $tipo ] = $pos + 1;
            return $folio;
        }

        $target = isset( $cfg['count'] ) ? max( 0, (int) $cfg['count'] ) : 0;
        $emitted = $this->counters[ $tipo ] ?? 0;
        if ( $emitted >= $target ) {
            return 0;
        }

        $last = Settings::get_last_folio_value( $tipo, $this->environment );
        $ranges = $this->allowedRanges[ $tipo ] ?? array();
        foreach ( $ranges as $range ) {
            $d = (int) $range['d'];
            $h = (int) $range['h'];
            $candidate = $last < $d ? $d : $last + 1;
            if ( $candidate <= $h ) {
                $this->counters[ $tipo ] = $emitted + 1;
                return $candidate;
            }
        }

        return 0;
    }

    /**
     * Parses manual list and filters only folios inside allowed ranges.
     *
     * @param array<int,array{d:int,h:int}> $ranges
     * @return int[]
     */
    private function filterManualFolios( string $raw, array $ranges ): array {
        $raw = trim( $raw );
        if ( '' === $raw ) { return array(); }
        $raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
        $parts = preg_split( '/[\s,]+/', $raw ) ?: array();
        $nums  = array();
        foreach ( $parts as $p ) {
            if ( ctype_digit( $p ) ) { $nums[] = (int) $p; }
        }
        $nums = array_values( array_unique( $nums ) );
        if ( empty( $ranges ) ) { return $nums; }
        $filtered = array();
        foreach ( $nums as $n ) {
            foreach ( $ranges as $r ) {
                if ( $n >= $r['d'] && $n <= $r['h'] ) { $filtered[] = $n; break; }
            }
        }
        return $filtered;
    }
}
