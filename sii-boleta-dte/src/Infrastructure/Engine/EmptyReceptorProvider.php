<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorFactoryInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorProviderInterface;

/**
 * Receptor provider that keeps the received values intact.
 *
 * LibreDTE ships with a default provider that hydrates missing fields with
 * placeholder data (e.g. Servicio de Impuestos Internos contact information).
 * That behaviour is helpful for demos, but undesired for the plugin because it
 * causes fake addresses, contact data and references to be injected in the
 * generated DTE when the merchant does not supply that information.
 *
 * This provider simply ensures that a {@see ReceptorInterface} instance is
 * returned without adding any extra data. When the caller passes a RUT string
 * the factory is used to create the receptor entity, mimicking the behaviour
 * of the default provider but without introducing placeholders.
 */
class EmptyReceptorProvider implements ReceptorProviderInterface {
        private ReceptorFactoryInterface $factory;

        public function __construct( ReceptorFactoryInterface $factory ) {
                $this->factory = $factory;
        }

        public function retrieve( int|string|ReceptorInterface $receptor ): ReceptorInterface {
                if ( is_int( $receptor ) || is_string( $receptor ) ) {
                        $receptor = $this->factory->create( array( 'rut' => $receptor ) );
                }

                return $receptor;
        }
}

