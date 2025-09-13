<?php

namespace Sii\BoletaDte\Hexagonal\Infrastructure\Factory;

use Sii\BoletaDte\Hexagonal\Domain\DteRepository;
use Sii\BoletaDte\Hexagonal\Infrastructure\Persistence\WooCommerceDteRepository;

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
