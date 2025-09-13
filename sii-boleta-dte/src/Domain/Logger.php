<?php
namespace Sii\BoletaDte\Domain;

/**
 * Abstracción para el registro de eventos.
 */
interface Logger {
    public function log( string $level, string $message ): void;
    public function info( string $message ): void;
    public function warn( string $message ): void;
    public function error( string $message ): void;
}
