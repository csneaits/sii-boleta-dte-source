<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface DetailNormalizer {
        /**
         * Normalizes raw detail lines.
         *
         * @param array<int, array<string, mixed>> $rawDetails
         * @return array<int, array<string, mixed>>
         */
        public function normalize( array $rawDetails, int $tipo ): array;
}
