<?php

namespace Sii\BoletaDte\Infrastructure\WooCommerce;

use Sii\BoletaDte\Domain\Dte;
use Sii\BoletaDte\Domain\DteRepository;

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
