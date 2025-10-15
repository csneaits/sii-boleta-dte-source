<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Sii\BoletaDte\Domain\DteEngine;

class NullEngine implements DteEngine {
	public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false ) {
		return false;
	}

	public function render_pdf( string $xml, array $options = array() ) {
		return false;
	}
}

class_alias( NullEngine::class, 'SII_Null_Engine' );
