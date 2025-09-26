<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface EmisorDataBuilder {
        /**
         * Builds emisor data merging plugin settings and request payload.
         *
         * @param array<string, mixed> $payload
         * @param array<string, mixed> $settings
         * @return array<string, mixed>
         */
        public function build( array $payload, array $settings ): array;
}
