<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultDetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultEmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\EmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\MappedYamlTemplateLoader;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\ReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\TemplateLoader;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\YamlTemplateLoader;

/**
 * Factory specialized for Boletas related TipoDTE values.
 */
class BoletaDteDocumentFactory implements DteDocumentFactory {
        private string $templateRoot;
        private SectionSanitizer $sectionSanitizer;

        public function __construct( string $templateRoot, ?SectionSanitizer $sectionSanitizer = null ) {
                $this->templateRoot     = rtrim( $templateRoot, '/' ) . '/';
                $this->sectionSanitizer = $sectionSanitizer ?? new SectionSanitizer();
        }

        public function createTemplateLoader(): TemplateLoader {
                return new MappedYamlTemplateLoader(
                        $this->templateRoot,
                        array(
                                39 => 'documentos_ok/039_boleta_afecta',
                                41 => 'documentos_ok/041_boleta_exenta',
                        ),
                        new YamlTemplateLoader( $this->templateRoot )
                );
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
