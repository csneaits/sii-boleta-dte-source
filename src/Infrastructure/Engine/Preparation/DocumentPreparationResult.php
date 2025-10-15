<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Preparation;

use Sii\BoletaDte\Infrastructure\Engine\Builder\DocumentPayload;

class DocumentPreparationResult {
    /**
     * @param array<int,array<string,mixed>> $detalle
     * @param array<int,array<string,mixed>> $globalDiscounts
     * @param array<string,mixed> $emisor
     */
    public function __construct(
        private readonly DocumentPayload $payload,
        private readonly array $detalle,
        private readonly array $globalDiscounts,
        private readonly ?float $tasaIva,
        private readonly array $emisor
    ) {
    }

    public function getPayload(): DocumentPayload {
        return $this->payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getDetalle(): array {
        return $this->detalle;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getGlobalDiscounts(): array {
        return $this->globalDiscounts;
    }

    public function getTasaIva(): ?float {
        return $this->tasaIva;
    }

    /**
     * @return array<string,mixed>
     */
    public function getEmisor(): array {
        return $this->emisor;
    }
}
