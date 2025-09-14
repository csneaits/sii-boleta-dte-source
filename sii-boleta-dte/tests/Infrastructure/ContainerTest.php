<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Factory\Container;
use Sii\BoletaDte\Domain\Logger;
use Sii\BoletaDte\Domain\DteRepository;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\TokenManager;

class ContainerTest extends TestCase {
    public function test_bindings_return_instances(): void {
        Container::init();
        $this->assertInstanceOf( Logger::class, Container::get( Logger::class ) );
        $this->assertInstanceOf( DteRepository::class, Container::get( DteRepository::class ) );
        $this->assertInstanceOf( DteEngine::class, Container::get( DteEngine::class ) );
        $this->assertInstanceOf( Api::class, Container::get( Api::class ) );
        $this->assertInstanceOf( TokenManager::class, Container::get( TokenManager::class ) );
    }
}
