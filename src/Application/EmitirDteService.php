<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Application;

use InvalidArgumentException;
use RuntimeException;
use Sii\BoletaDte\Domain\Dte;
use Sii\BoletaDte\Domain\DteRepository;
use Throwable;

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
		if ( '' === \trim( $id ) ) {
				throw new InvalidArgumentException( 'ID requerido.' );
		}
		if ( empty( $data ) ) {
				throw new InvalidArgumentException( 'Datos del DTE requeridos.' );
		}

			$dte = new Dte( $id, $data );
		try {
				$this->repository->save( $dte );
		} catch ( Throwable $e ) {
				throw new RuntimeException( 'No se pudo persistir el DTE.', 0, $e );
		}
	}
}
