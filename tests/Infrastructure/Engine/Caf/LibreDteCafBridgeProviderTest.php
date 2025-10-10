<?php
declare(strict_types=1);

namespace Tests\Infrastructure\Engine\Caf;

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Engine\Caf\LibreDteCafBridgeProvider;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;
use libredte\lib\Core\Package\Billing\Component\Document\Entity\TipoDocumento;

final class LibreDteCafBridgeProviderTest extends TestCase
{
    private function makeSettings(array $overrides = []): Settings
    {
        $settings = new Settings();
        $opts = array_merge([
            'environment' => '2',
        ], $overrides);
        $GLOBALS['wp_options'][Settings::OPTION_NAME] = $opts;
        return $settings;
    }

    protected function setUp(): void
    {
        parent::setUp();
        FoliosDb::purge();
        Settings::update_last_folio_value(39, '2', 0);
    }

    public function testDevEnvironmentUsesFakerAndAdvancesFolio(): void
    {
        $settings = $this->makeSettings(['environment' => '2']);
        $app = Application::getInstance();
        $identifier = $app->getPackageRegistry()->getBillingPackage()->getIdentifierComponent();
        $loader = $identifier->getCafLoaderWorker();
        $faker  = $identifier->getCafFakerWorker();
        $provider = new LibreDteCafBridgeProvider($settings, $loader, $faker);

        $emisor = new Emisor('11111111-1', 'Test');
        $tipo   = new TipoDocumento(39, 'Boleta');

        $bag = $provider->retrieve($emisor, $tipo, null);
        $firstFolio = $bag->getSiguienteFolio();
        $this->assertGreaterThan(0, $firstFolio);

        // next call should advance
        $next = $provider->retrieve($emisor, $tipo, null);
        $this->assertSame($firstFolio + 1, $next->getSiguienteFolio());
    }

    public function testCertWithoutCafFallsBackToFaker(): void
    {
        $settings = $this->makeSettings(['environment' => '0']);
        $app = Application::getInstance();
        $identifier = $app->getPackageRegistry()->getBillingPackage()->getIdentifierComponent();
        $loader = $identifier->getCafLoaderWorker();
        $faker  = $identifier->getCafFakerWorker();
        $provider = new LibreDteCafBridgeProvider($settings, $loader, $faker);
        $emisor = new Emisor('11111111-1', 'Test');
        $tipo   = new TipoDocumento(39, 'Boleta');

        // no ranges or CAFs defined
        $bag = $provider->retrieve($emisor, $tipo, null);
        $this->assertGreaterThan(0, $bag->getSiguienteFolio());
    }

    public function testCertWithRangesAndCafLoadsCafAndKeepsWithinRange(): void
    {
        $settings = $this->makeSettings(['environment' => '0']);
        $app = Application::getInstance();
        $identifier = $app->getPackageRegistry()->getBillingPackage()->getIdentifierComponent();
        $loader = $identifier->getCafLoaderWorker();
        $faker  = $identifier->getCafFakerWorker();
        $provider = new LibreDteCafBridgeProvider($settings, $loader, $faker);
        $emisor = new Emisor('11111111-1', 'Test');
        $tipo   = new TipoDocumento(39, 'Boleta');

        // Insert range and store CAF XML from fixtures
        $id = FoliosDb::insert(39, 10, 20, '0');
        $caf = file_get_contents(\dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'caf39.xml');
        $this->assertIsString($caf);
        FoliosDb::store_caf($id, (string)$caf, 'caf39.xml');

        $bag = $provider->retrieve($emisor, $tipo, null);
        $this->assertGreaterThanOrEqual(10, $bag->getSiguienteFolio());
        $this->assertLessThanOrEqual(20, $bag->getSiguienteFolio());
    }
}
