<?php
namespace Sii\BoletaDte\Application;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

class Queue {
    public function __construct( DteEngine $engine, Settings $settings ) {}
}

class_alias( Queue::class, 'SII_Boleta_Queue' );
