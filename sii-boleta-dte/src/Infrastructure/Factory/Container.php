<?php
namespace Sii\BoletaDte\Infrastructure\Factory;

use Sii\BoletaDte\Domain\DteRepository;
use Sii\BoletaDte\Domain\Logger;
use Sii\BoletaDte\Infrastructure\WooCommerce\WooCommerceDteRepository;
use Sii\BoletaDte\Shared\Logger as SharedLogger;

/**
 * Simple Dependency Injection container.
 */
class Container {
	/** @var array<class-string, callable> */
	private static array $bindings = array();

	public static function init(): void {
		if (self::$bindings) {
			return;
		}
		self::bind( DteRepository::class, fn() => new WooCommerceDteRepository() );
		self::bind( Logger::class, fn() => new SharedLogger() );
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
