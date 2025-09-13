<?php
namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Infrastructure\Settings;

class Cron {
    public function __construct( Settings $settings ) {}
    public static function activate() {}
    public static function deactivate() {}
}

class_alias( Cron::class, 'SII_Boleta_Cron' );
