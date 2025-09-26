<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface ReceptorSanitizer {
        /**
         * Normalizes the receptor section removing placeholder data.
         *
         * @param array<string, mixed> $rawReceptor
         * @return array<string, mixed>
         */
        public function sanitize( array $rawReceptor ): array;
}
