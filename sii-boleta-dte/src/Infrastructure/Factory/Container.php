<?php
namespace Sii\BoletaDte\Infrastructure\Factory;

use Sii\BoletaDte\Domain\DteRepository;
use Sii\BoletaDte\Domain\Logger;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\WooCommerce\WooCommerceDteRepository;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Infrastructure\Cron;
use Sii\BoletaDte\Application\RvdManager;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;
use Sii\BoletaDte\Shared\SharedLogger;
use Sii\BoletaDte\Presentation\Admin\SettingsPage;
use Sii\BoletaDte\Presentation\Admin\LogsPage;
use Sii\BoletaDte\Presentation\Admin\DiagnosticsPage;
use Sii\BoletaDte\Presentation\Admin\Help;
use Sii\BoletaDte\Presentation\WooCommerce\CheckoutFields;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Application\LibroBoletas;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Presentation\Admin\ControlPanelPage;
use Sii\BoletaDte\Presentation\Admin\CafPage;

/**
 * Simple Dependency Injection container.
 */
class Container {
	/** @var array<class-string, callable> */
	private static array $bindings = array();

	public static function init(): void {
		if ( self::$bindings ) {
				return;
		}

			self::bind( Settings::class, fn() => new Settings() );
			self::bind( Logger::class, fn() => new SharedLogger( self::get( Settings::class ) ) );
			self::bind( DteRepository::class, fn() => new WooCommerceDteRepository() );
			self::bind( DteEngine::class, fn() => new LibreDteEngine( self::get( Settings::class ) ) );
			self::bind(
				Api::class,
				function () {
						$settings = self::get( Settings::class );
						$cfg      = $settings->get_settings();
						$retries  = isset( $cfg['api_retries'] ) ? (int) $cfg['api_retries'] : 3;
						return new Api( self::get( Logger::class ), $retries );
				}
			);
			self::bind( TokenManager::class, fn() => new TokenManager( self::get( Api::class ), self::get( Settings::class ) ) );
                        self::bind( PdfGenerator::class, fn() => new PdfGenerator( self::get( DteEngine::class ), self::get( Settings::class ) ) );
						self::bind( Cron::class, fn() => new Cron( self::get( Settings::class ) ) );
                        self::bind( Queue::class, fn() => new Queue() );
                        self::bind( RvdManager::class, fn() => new RvdManager( self::get( Settings::class ), self::get( Api::class ), self::get( Queue::class ) ) );
                        self::bind(
                                LibroBoletas::class,
                                fn() => new LibroBoletas(
                                        self::get( Settings::class ),
                                        self::get( Api::class ),
                                        self::get( Queue::class ),
                                        self::get( FolioManager::class )
                                )
                        );
						self::bind( Woo::class, fn() => new Woo( null ) );
						self::bind( SettingsPage::class, fn() => new SettingsPage( self::get( Settings::class ) ) );
						self::bind( LogsPage::class, fn() => new LogsPage() );
						self::bind( DiagnosticsPage::class, fn() => new DiagnosticsPage( self::get( Settings::class ), self::get( TokenManager::class ), self::get( Api::class ) ) );
						self::bind( Help::class, fn() => new Help() );
						self::bind( CheckoutFields::class, fn() => new CheckoutFields( self::get( Settings::class ) ) );
												self::bind( FolioManager::class, fn() => new FolioManager( self::get( Settings::class ) ) );
                                                                                                self::bind( QueueProcessor::class, fn() => new QueueProcessor( self::get( Api::class ) ) );
                        self::bind( GenerateDtePage::class, fn() => new GenerateDtePage( self::get( Settings::class ), self::get( TokenManager::class ), self::get( Api::class ), self::get( DteEngine::class ), self::get( PdfGenerator::class ), self::get( FolioManager::class ), self::get( Queue::class ) ) );
                                                self::bind( ControlPanelPage::class, fn() => new ControlPanelPage( self::get( Settings::class ), self::get( FolioManager::class ), self::get( QueueProcessor::class ), self::get( RvdManager::class ), self::get( LibroBoletas::class ), self::get( Api::class ), self::get( TokenManager::class ) ) );
								self::bind( CafPage::class, fn() => new CafPage( self::get( Settings::class ) ) );
	}

	/**
	 * Registers a factory for a given identifier.
	 *
	 * @param class-string $id
	 */
	public static function bind( string $id, callable $factory ): void {
		self::$bindings[ $id ] = $factory;
	}

	/**
	 * Resolves an identifier from the container.
	 *
	 * @template T
	 * @param class-string<T> $id
	 * @return T
	 */
	public static function get( string $id ) {
		if ( ! isset( self::$bindings[ $id ] )) {
			throw new \InvalidArgumentException( "No binding registered for {$id}" );
		}
		$factory = self::$bindings[ $id ];
		return $factory();
	}
}
