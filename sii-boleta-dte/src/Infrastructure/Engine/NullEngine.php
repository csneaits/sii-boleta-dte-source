<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use Sii\BoletaDte\Domain\DteEngine;

class NullEngine implements DteEngine {
    public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false ) {
        return false;
    }
}

class_alias( NullEngine::class, 'SII_Null_Engine' );
