<?php
declare(strict_types=1);

namespace {
    if ( ! function_exists( 'add_action' ) ) {
        function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ): void {}
    }

    if ( ! function_exists( 'add_filter' ) ) {
        function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ): void {}
    }

    if ( ! function_exists( 'apply_filters' ) ) {
        function apply_filters( $hook, $value ) {
            return $value;
        }
    }

    if ( ! function_exists( 'do_action' ) ) {
        function do_action( $hook, ...$args ): void {}
    }

    if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = null ) {
            return $text;
        }
    }

    if ( ! function_exists( '_e' ) ) {
        function _e( $text, $domain = null ): void {
            echo $text;
        }
    }
}

namespace Sii\BoletaDte\Tests\Infrastructure\Factory;

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Factory\Container;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;

final class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Container::init();
    }

    public function test_it_resolves_woo_without_type_error(): void
    {
        $woo = Container::get( Woo::class );

        $this->assertInstanceOf( Woo::class, $woo );
    }
}
