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
                self::bind( PdfGenerator::class, fn() => new PdfGenerator( self::get( DteEngine::class ) ) );
                self::bind( Cron::class, fn() => new Cron( self::get( Settings::class ) ) );
                self::bind( RvdManager::class, fn() => new RvdManager( self::get( Settings::class ) ) );
                self::bind( Woo::class, fn() => new Woo( null ) );
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
