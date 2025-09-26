<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DetailNormalizerInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\EmisorDataBuilderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\ReceptorSanitizerInterface;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\TemplateLoaderInterface;
use Sii\BoletaDte\Infrastructure\Engine\Xml\TotalsAdjusterInterface;

/**
 * Abstract factory that groups the collaborators required to build a DTE.
 */
interface DteDocumentFactory {
        public function createTemplateLoader(): TemplateLoaderInterface;

        public function createDetailNormalizer(): DetailNormalizerInterface;

        public function createEmisorDataBuilder(): EmisorDataBuilderInterface;

        public function createReceptorSanitizer(): ReceptorSanitizerInterface;

        public function createTotalsAdjuster(): TotalsAdjusterInterface;
}
