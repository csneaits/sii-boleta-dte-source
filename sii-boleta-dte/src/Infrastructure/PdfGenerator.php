<?php
namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Domain\DteEngine;

/**
 * Wrapper around the DteEngine PDF rendering capability.
 */
class PdfGenerator {
	private DteEngine $engine;

	public function __construct( DteEngine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Generates a PDF for the provided DTE XML and returns the path to the
	 * generated file.
	 */
	public function generate( string $xml ): string {
		return (string) $this->engine->render_pdf( $xml );
	}
}

class_alias( PdfGenerator::class, 'SII_Boleta_Pdf_Generator' );
