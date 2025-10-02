<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Xml;

interface TotalsAdjusterInterface {
    /**
     * @param array<int,array<string,mixed>> $detalle
     * @param array<int,array<string,mixed>> $globalDiscounts
     */
    public function adjust( string $xml, array $detalle, int $tipo, ?float $tasaIva, array $globalDiscounts ): string;

    public function supports( int $tipo ): bool;
}
