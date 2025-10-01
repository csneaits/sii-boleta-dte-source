<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Xml;

class VatInclusiveTotalsAdjuster implements TotalsAdjusterInterface {
        /**
         * @param array<int,array<string,mixed>> $detalle
         * @param array<int,array<string,mixed>> $globalDiscounts
         */
        public function adjust( string $xml, array $detalle, int $tipo, ?float $tasaIva, array $globalDiscounts ): string {
                $totals = $this->calculateTotals( $detalle, $tasaIva, $globalDiscounts );
                if ( empty( $totals ) ) {
                        return $xml;
                }

                $previous = libxml_use_internal_errors( true );
                $document = new \DOMDocument();

                if ( ! $document->loadXML( $xml ) ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return $xml;
                }

                $xpath = new \DOMXPath( $document );
                $xpath->registerNamespace( 'dte', 'http://www.sii.cl/SiiDte' );
                $nodes = $xpath->query( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Totales' );
                if ( ! ( $nodes instanceof \DOMNodeList ) || 0 === $nodes->length ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return $xml;
                }

                $totalsNode = $nodes->item( 0 );
                if ( ! ( $totalsNode instanceof \DOMElement ) ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return $xml;
                }

                $fields = array( 'MntNeto', 'MntExe', 'IVA', 'MntTotal', 'TasaIVA' );
                foreach ( $fields as $field ) {
                        $childNodes = $xpath->query( 'dte:' . $field, $totalsNode );
                        if ( ! ( $childNodes instanceof \DOMNodeList ) ) {
                                continue;
                        }
                        for ( $i = $childNodes->length - 1; $i >= 0; --$i ) {
                                $node = $childNodes->item( $i );
                                if ( $node instanceof \DOMNode ) {
                                        $totalsNode->removeChild( $node );
                                }
                        }
                }

                $namespace = 'http://www.sii.cl/SiiDte';
                foreach ( $fields as $field ) {
                        if ( ! array_key_exists( $field, $totals ) ) {
                                continue;
                        }

                        $value = $totals[ $field ];
                        if ( null === $value ) {
                                continue;
                        }

                        $text = 'TasaIVA' === $field
                                ? $this->formatTaxRate( (float) $value )
                                : (string) $value;

                        $element = $document->createElementNS( $namespace, $field, $text );
                        $totalsNode->appendChild( $element );
                }

                $result = $document->saveXML() ?: $xml;
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );

                return $result;
        }

        public function supports( int $tipo ): bool {
                return in_array( $tipo, array( 33, 46, 52, 56, 61 ), true );
        }

        /**
         * @param array<int,array<string,mixed>> $detalle
         * @param array<int,array<string,mixed>> $globalDiscounts
         *
         * @return array<string,mixed>
         */
        private function calculateTotals( array $detalle, ?float $tasaIva, array $globalDiscounts ): array {
                $taxable         = 0.0;
                $exempt          = 0.0;
                $hasGrossTaxable = false;

                foreach ( $detalle as $line ) {
                        $baseAmount = (float) ( $line['MontoItem'] ?? 0 );
                        if ( $baseAmount < 0 ) {
                                $baseAmount = 0.0;
                        }

                        $amount = $baseAmount;

                        if ( isset( $line['DescuentoMonto'] ) ) {
                                $amount -= (float) $line['DescuentoMonto'];
                        } elseif ( isset( $line['DescuentoPct'] ) ) {
                                $amount -= $baseAmount * ( (float) $line['DescuentoPct'] / 100 );
                        }

                        if ( isset( $line['RecargoMonto'] ) ) {
                                $amount += (float) $line['RecargoMonto'];
                        } elseif ( isset( $line['RecargoPct'] ) ) {
                                $amount += $baseAmount * ( (float) $line['RecargoPct'] / 100 );
                        }

                        if ( $amount < 0 ) {
                                $amount = 0.0;
                        }

                        if ( ! empty( $line['IndExe'] ) ) {
                                $exempt += $amount;
                                continue;
                        }

                        if ( ! empty( $line['MntBruto'] ) && null !== $tasaIva && $tasaIva > 0 ) {
                                $hasGrossTaxable = true;
                                $amount          = $this->convertGrossToNet( $amount, $tasaIva );
                        }

                        $taxable += $amount;
                }

                foreach ( $globalDiscounts as $discount ) {
                        if ( ! is_array( $discount ) ) {
                                continue;
                        }

                        $movement = strtoupper( (string) ( $discount['TpoMov'] ?? '' ) );
                        if ( ! in_array( $movement, array( 'D', 'R' ), true ) ) {
                                continue;
                        }

                        $valueType = strtoupper( (string) ( $discount['TpoValor'] ?? '' ) );
                        $rawValue  = (float) ( $discount['ValorDR'] ?? 0 );
                        if ( $rawValue <= 0 ) {
                                continue;
                        }

                        $indicator = isset( $discount['IndExeDR'] ) ? (int) $discount['IndExeDR'] : 0;
                        if ( 1 === $indicator ) {
                                $target =& $exempt;
                        } else {
                                $target =& $taxable;
                        }

                        if ( $target <= 0 ) {
                                continue;
                        }

                        $baseAmount = $target;
                        if ( '%' === $valueType ) {
                                $change = $baseAmount * ( $rawValue / 100 );
                        } else {
                                $change = $rawValue;
                                if ( 1 !== $indicator && $hasGrossTaxable && null !== $tasaIva && $tasaIva > 0 ) {
                                        $change = $this->convertGrossToNet( $change, $tasaIva );
                                }
                        }

                        if ( $change <= 0 ) {
                                continue;
                        }

                        if ( 'D' === $movement ) {
                                $target -= $change;
                                if ( $target < 0 ) {
                                        $target = 0.0;
                                }
                        } else {
                                $target += $change;
                        }
                }

                $taxable = max( 0.0, $taxable );
                $exempt  = max( 0.0, $exempt );

                $totals = array();

                $roundedExempt = (int) round( $exempt );
                if ( $roundedExempt > 0 ) {
                        $totals['MntExe'] = $roundedExempt;
                }

                if ( null !== $tasaIva ) {
                        $totals['TasaIVA'] = $tasaIva;
                }

                if ( null !== $tasaIva && $tasaIva > 0 && $taxable > 0 ) {
                        $roundedNet = (int) round( $taxable );
                        $roundedIva = (int) round( $taxable * ( $tasaIva / 100 ) );

                        $totals['MntNeto'] = $roundedNet;
                        if ( $roundedIva > 0 ) {
                                $totals['IVA'] = $roundedIva;
                        }

                        $totals['MntTotal'] = $roundedNet + $roundedIva + $roundedExempt;

                        return $totals;
                }

                $roundedTaxable = (int) round( $taxable );
                if ( $roundedTaxable > 0 ) {
                        $totals['MntNeto'] = $roundedTaxable;
                }

                $totals['MntTotal'] = $roundedTaxable + $roundedExempt;

                return $totals;
        }

        private function formatTaxRate( float $rate ): string {
                $formatted = sprintf( '%.2F', $rate );
                $formatted = rtrim( rtrim( $formatted, '0' ), '.' );
                return '' === $formatted ? '0' : $formatted;
        }

        private function convertGrossToNet( float $amount, float $taxRate ): float {
                if ( $amount <= 0 ) {
                        return 0.0;
                }

                if ( $taxRate <= 0 ) {
                        return $amount;
                }

                $divisor = 1 + ( $taxRate / 100 );
                if ( $divisor <= 0 ) {
                        return $amount;
                }

                return $amount / $divisor;
        }
}
