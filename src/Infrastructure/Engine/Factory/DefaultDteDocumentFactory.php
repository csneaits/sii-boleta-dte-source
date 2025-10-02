<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultDetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultEmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DetailNormalizerInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\EmisorDataBuilderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\ReceptorSanitizerInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\TemplateLoaderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\YamlTemplateLoader;
use Sii\BoletaDte\Infrastructure\Engine\Xml\NullTotalsAdjuster;
use Sii\BoletaDte\Infrastructure\Engine\Xml\TotalsAdjusterInterface;

class DefaultDteDocumentFactory implements DteDocumentFactory {
        private SectionSanitizer $sectionSanitizer;
        private string $templateRoot;

        public function __construct( string $templateRoot, ?SectionSanitizer $sectionSanitizer = null ) {
                $this->sectionSanitizer = $sectionSanitizer ?? new SectionSanitizer();
                $this->templateRoot     = $templateRoot;
        }

        public function createTemplateLoader(): TemplateLoaderInterface {
                return new YamlTemplateLoader( $this->templateRoot );
        }

        public function createDetailNormalizer(): DetailNormalizerInterface {
                return new DefaultDetailNormalizer( $this->sectionSanitizer );
        }

        public function createEmisorDataBuilder(): EmisorDataBuilderInterface {
                return new DefaultEmisorDataBuilder();
        }

        public function createReceptorSanitizer(): ReceptorSanitizerInterface {
                return new DefaultReceptorSanitizer( $this->sectionSanitizer );
        }

        public function createTotalsAdjuster(): TotalsAdjusterInterface {
                return new NullTotalsAdjuster();
        }
}
