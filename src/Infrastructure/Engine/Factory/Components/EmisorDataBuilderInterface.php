<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface EmisorDataBuilderInterface {
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public function build( array $data, array $settings ): array;
}
