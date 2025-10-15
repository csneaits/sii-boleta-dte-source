<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Caf;

class CafResolutionException extends \RuntimeException {
    public function __construct(
        string $message,
        private readonly bool $hadProvidedCaf = false,
        private readonly string $cafXml = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function hadProvidedCaf(): bool {
        return $this->hadProvidedCaf;
    }

    public function getCafXml(): string {
        return $this->cafXml;
    }
}
