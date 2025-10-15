<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Domain;

/**
 * Entidad de ejemplo para representar un DTE.
 */
class Dte {
	/**
	 * Identificador del documento.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Datos asociados al documento.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param string $id   Identificador único.
	 * @param array  $data Datos del DTE.
	 */
	public function __construct( string $id, array $data ) {
		$this->id   = $id;
		$this->data = $data;
	}

	/**
	 * Obtiene el identificador del DTE.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Obtiene los datos del DTE.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}
}
