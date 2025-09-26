<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface ReceptorSanitizerInterface {
    /**
     * @param array<string,mixed> $receptor
     *
     * @return array<string,mixed>
     */
    public function sanitize( array $receptor ): array;
}
