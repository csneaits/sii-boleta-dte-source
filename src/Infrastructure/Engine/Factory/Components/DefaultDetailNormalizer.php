<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

class DefaultDetailNormalizer implements DetailNormalizerInterface {
        private SectionSanitizer $sectionSanitizer;

        public function __construct( SectionSanitizer $sectionSanitizer ) {
                $this->sectionSanitizer = $sectionSanitizer;
        }

        public function normalize( array $rawDetails, int $tipo ): array {
                $detalle = array();
                $i       = 1;

                foreach ( $rawDetails as $d ) {
                        if ( ! is_array( $d ) ) {
                                continue;
                        }

                        $line = $this->sectionSanitizer->sanitize( $d );

                        $qty = isset( $line['QtyItem'] ) ? (float) $line['QtyItem'] : (float) ( $d['QtyItem'] ?? 1 );
                        if ( $qty <= 0 ) {
                                $qty = 1.0;
                        }

                        $price = isset( $line['PrcItem'] ) ? (float) $line['PrcItem'] : (float) ( $d['PrcItem'] ?? 0 );
                        if ( $price < 0 ) {
                                $price = 0.0;
                        }

                        $line_number = isset( $line['NroLinDet'] ) ? (int) $line['NroLinDet'] : (int) ( $d['NroLinDet'] ?? $i );
                        if ( $line_number <= 0 ) {
                                $line_number = $i;
                        }

                        $line['NroLinDet'] = $line_number;
                        $line['NmbItem']   = isset( $line['NmbItem'] ) ? (string) $line['NmbItem'] : ( $d['NmbItem'] ?? '' );
                        $line['QtyItem']   = $qty;
                        $line['PrcItem']   = (int) round( $price );

                        if ( isset( $line['MontoItem'] ) ) {
                                $line['MontoItem'] = (int) round( (float) $line['MontoItem'] );
                        } else {
                                $line['MontoItem'] = (int) round( $qty * (float) $line['PrcItem'] );
                        }
                        if ( $line['MontoItem'] < 0 ) {
                                $line['MontoItem'] = 0;
                        }

                        if ( isset( $line['MntBruto'] ) ) {
                                $gross_flag = (int) $line['MntBruto'];
                                if ( $gross_flag > 0 ) {
                                        $line['MntBruto'] = 1;
                                } else {
                                        unset( $line['MntBruto'] );
                                }
                        }

                        $indicator = isset( $line['IndExe'] ) ? (int) $line['IndExe'] : 0;
                        if ( 0 === $indicator && isset( $d['IndExe'] ) ) {
                                $indicator = (int) $d['IndExe'];
                        }
                        if ( $indicator <= 0 && in_array( $tipo, array( 34, 41 ), true ) ) {
                                $indicator = 1;
                        }
                        if ( $indicator > 0 ) {
                                $line['IndExe'] = $indicator;
                        } else {
                                unset( $line['IndExe'] );
                        }

                        $detalle[] = $line;
                        ++$i;
                }

                return $detalle;
        }
}
