<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Xml;

class NullTotalsAdjuster implements XmlTotalsAdjuster {
        public function adjust( string $xml, array $detalle, int $tipo, ?float $tasaIva, array $globalDiscounts ): string {
                return $xml;
        }

        public function supports( int $tipo ): bool {
                return true;
        }
}
