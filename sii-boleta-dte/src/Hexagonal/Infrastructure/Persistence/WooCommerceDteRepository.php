<?php

namespace Sii\BoletaDte\Hexagonal\Infrastructure\Persistence;

use Sii\BoletaDte\Hexagonal\Domain\Dte;
use Sii\BoletaDte\Hexagonal\Domain\DteRepository;

/**
 * Adaptador de persistencia hacia WooCommerce.
 */
class WooCommerceDteRepository implements DteRepository {
    /**
     * Guarda un DTE.
     *
     * En una implementación real se interactuaría con WooCommerce.
     */
    public function save( Dte $dte ): void {
        // Implementación de ejemplo; aquí se podría guardar como meta de pedido.
    }
}
