<?php

namespace Sii\BoletaDte\Infrastructure\WooCommerce;

use Sii\BoletaDte\Domain\Dte;
use Sii\BoletaDte\Domain\DteRepository;

/**
 * Adaptador de persistencia hacia WooCommerce.
 */
class WooCommerceDteRepository implements DteRepository {
    /**
     * Guarda un DTE como meta del pedido en WooCommerce.
     */
    public function save( Dte $dte ): void {
        if ( ! function_exists( 'update_post_meta' ) ) {
            return;
        }

        update_post_meta( (int) $dte->get_id(), '_sii_boleta_dte_data', $dte->get_data() );
    }
}

