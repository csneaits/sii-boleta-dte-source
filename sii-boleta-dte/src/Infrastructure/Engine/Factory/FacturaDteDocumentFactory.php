<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultDetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultEmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DefaultReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DetailNormalizerInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\EmisorDataBuilderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\MappedYamlTemplateLoader;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\ReceptorSanitizerInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\TemplateLoaderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\YamlTemplateLoader;
use Sii\BoletaDte\Infrastructure\Engine\Xml\TotalsAdjusterInterface;
use Sii\BoletaDte\Infrastructure\Engine\Xml\VatInclusiveTotalsAdjuster;

/**
 * Factory specialized for Facturas related TipoDTE values.
 */
class FacturaDteDocumentFactory implements DteDocumentFactory {
        private string $templateRoot;
        private SectionSanitizer $sectionSanitizer;

        public function __construct( string $templateRoot, ?SectionSanitizer $sectionSanitizer = null ) {
                $this->templateRoot     = rtrim( $templateRoot, '/' ) . '/';
                $this->sectionSanitizer = $sectionSanitizer ?? new SectionSanitizer();
        }

        public function createTemplateLoader(): TemplateLoaderInterface {
                return new MappedYamlTemplateLoader(
                        $this->templateRoot,
                        array(
                                33 => 'documentos_ok/033_factura_afecta',
                                34 => 'documentos_ok/034_factura_exenta',
                                46 => 'documentos_ok/046_factura_compra',
                        ),
                        new YamlTemplateLoader( $this->templateRoot )
                );
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
                return new VatInclusiveTotalsAdjuster();
        }
}
