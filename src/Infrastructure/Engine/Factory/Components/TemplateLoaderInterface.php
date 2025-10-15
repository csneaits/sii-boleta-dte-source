<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

interface TemplateLoaderInterface {
    /**
     * @return array<string,mixed>
     */
    public function load( int $tipo ): array;
}
