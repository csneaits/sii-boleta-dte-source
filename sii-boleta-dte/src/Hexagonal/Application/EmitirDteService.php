<?php

namespace Sii\BoletaDte\Hexagonal\Application;

use Sii\BoletaDte\Hexagonal\Domain\Dte;
use Sii\BoletaDte\Hexagonal\Domain\DteRepository;

/**
 * Caso de uso para emitir un DTE.
 */
class EmitirDteService {
    /**
     * Repositorio de DTE.
     *
     * @var DteRepository
     */
    private DteRepository $repository;

    /**
     * @param DteRepository $repository Puerto de persistencia.
     */
    public function __construct( DteRepository $repository ) {
        $this->repository = $repository;
    }

    /**
     * Ejecuta el caso de uso.
     *
     * @param string               $id   Identificador.
     * @param array<string, mixed> $data Datos del DTE.
     */
    public function __invoke( string $id, array $data ): void {
        $dte = new Dte( $id, $data );
        $this->repository->save( $dte );
    }
}
