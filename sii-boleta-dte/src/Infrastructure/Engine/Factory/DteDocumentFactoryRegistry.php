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
                return new self( new DefaultDteDocumentFactory( $templateRoot, $sectionSanitizer ) );
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
