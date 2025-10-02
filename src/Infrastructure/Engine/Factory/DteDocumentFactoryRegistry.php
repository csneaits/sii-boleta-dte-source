<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;

class DteDocumentFactoryRegistry {
        /** @var array<int, DteDocumentFactory> */
        private array $factories = array();
        private DteDocumentFactory $defaultFactory;

        public function __construct( DteDocumentFactory $defaultFactory ) {
                $this->defaultFactory = $defaultFactory;
        }

        public static function createDefault( string $templateRoot, ?SectionSanitizer $sectionSanitizer = null ): self {
                $sanitizer = $sectionSanitizer ?? new SectionSanitizer();

                $default  = new DefaultDteDocumentFactory( $templateRoot, $sanitizer );
                $registry = new self( $default );

                $boletaFactory = new BoletaDteDocumentFactory( $templateRoot, $sanitizer );
                foreach ( array( 39, 41 ) as $tipo ) {
                        $registry->registerFactory( $tipo, $boletaFactory );
                }

                $facturaFactory = new FacturaDteDocumentFactory( $templateRoot, $sanitizer );
                foreach ( array( 33, 34, 46 ) as $tipo ) {
                        $registry->registerFactory( $tipo, $facturaFactory );
                }

                $vatInclusiveFactory = new VatInclusiveDteDocumentFactory(
                        $templateRoot,
                        array(
                                52 => 'documentos_ok/052_guia_despacho',
                                56 => 'documentos_ok/056_nota_debito',
                                61 => 'documentos_ok/061_nota_credito',
                        ),
                        $sanitizer
                );
                foreach ( array( 52, 56, 61 ) as $tipo ) {
                        $registry->registerFactory( $tipo, $vatInclusiveFactory );
                }

                return $registry;
        }

        public function registerFactory( int $tipo, DteDocumentFactory $factory ): void {
                $this->factories[ $tipo ] = $factory;
        }

        public function getFactory( int $tipo ): DteDocumentFactory {
                if ( isset( $this->factories[ $tipo ] ) ) {
                        return $this->factories[ $tipo ];
                }

                return $this->defaultFactory;
        }
}
