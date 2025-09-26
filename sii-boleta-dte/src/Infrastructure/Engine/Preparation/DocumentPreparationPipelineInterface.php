<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Preparation;

use Sii\BoletaDte\Infrastructure\Engine\Factory\DteDocumentFactory;

interface DocumentPreparationPipelineInterface {
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $settings
     */
    public function prepare(
        DteDocumentFactory $factory,
        int $tipo,
        array $data,
        array $settings,
        bool $preview
    ): DocumentPreparationResult;
}
