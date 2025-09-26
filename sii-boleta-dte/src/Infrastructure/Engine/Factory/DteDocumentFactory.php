<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory;

use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\DetailNormalizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\EmisorDataBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\ReceptorSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\TemplateLoader;

/**
 * Abstract factory that groups the collaborators required to build a DTE.
 */
interface DteDocumentFactory {
        public function createTemplateLoader(): TemplateLoader;

        public function createDetailNormalizer(): DetailNormalizer;

        public function createEmisorDataBuilder(): EmisorDataBuilder;

        public function createReceptorSanitizer(): ReceptorSanitizer;
}
