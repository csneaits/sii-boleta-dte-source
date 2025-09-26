<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface TemplateLoader {
        /**
         * Loads the base template for a DTE type.
         *
         * @return array<string, mixed>
         */
        public function load( int $tipo ): array;
}
