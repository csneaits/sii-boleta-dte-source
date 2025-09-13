<?php

namespace Sii\BoletaDte\Infrastructure\Factory;

use Sii\BoletaDte\Domain\DteRepository;
use Sii\BoletaDte\Infrastructure\WooCommerce\WooCommerceDteRepository;

/**
 * Factory para la creación de repositorios.
 */
class RepositoryFactory {
    /**
     * Crea la instancia del repositorio de DTE.
     */
    public static function create_dte_repository(): DteRepository {
        return new WooCommerceDteRepository();
    }
}
