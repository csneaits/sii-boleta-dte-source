<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultDetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultEmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\EmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\ReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\TemplateLoader;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\YamlTemplateLoader;

class DefaultDteDocumentFactory implements DteDocumentFactory {
        private SectionSanitizer $sectionSanitizer;
        private string $templateRoot;

        public function __construct( string $templateRoot, ?SectionSanitizer $sectionSanitizer = null ) {
                $this->sectionSanitizer = $sectionSanitizer ?? new SectionSanitizer();
                $this->templateRoot     = $templateRoot;
        }

        public function createTemplateLoader(): TemplateLoader {
                return new YamlTemplateLoader( $this->templateRoot );
        }

        public function createDetailNormalizer(): DetailNormalizer {
                return new DefaultDetailNormalizer( $this->sectionSanitizer );
        }

        public function createEmisorDataBuilder(): EmisorDataBuilder {
                return new DefaultEmisorDataBuilder();
        }

        public function createReceptorSanitizer(): ReceptorSanitizer {
                return new DefaultReceptorSanitizer( $this->sectionSanitizer );
        }
}
