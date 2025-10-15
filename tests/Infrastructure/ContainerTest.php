<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Factory\Container;
use Sii\BoletaDte\Domain\Logger;
use Sii\BoletaDte\Domain\DteRepository;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\WordPress\TokenManager;
use Sii\BoletaDte\Presentation\Admin\SettingsPage;
use Sii\BoletaDte\Presentation\Admin\DiagnosticsPage;
use Sii\BoletaDte\Presentation\WooCommerce\CheckoutFields;

class ContainerTest extends TestCase {
    public function test_bindings_return_instances(): void {
        Container::init();
        $this->assertInstanceOf( Logger::class, Container::get( Logger::class ) );
        $this->assertInstanceOf( DteRepository::class, Container::get( DteRepository::class ) );
        $this->assertInstanceOf( DteEngine::class, Container::get( DteEngine::class ) );
        $this->assertInstanceOf( Api::class, Container::get( Api::class ) );
        $this->assertInstanceOf( TokenManager::class, Container::get( TokenManager::class ) );
        $this->assertInstanceOf( SettingsPage::class, Container::get( SettingsPage::class ) );
        $this->assertInstanceOf( DiagnosticsPage::class, Container::get( DiagnosticsPage::class ) );
        $this->assertInstanceOf( CheckoutFields::class, Container::get( CheckoutFields::class ) );
    }
}
