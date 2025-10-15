<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Builder;

/**
 * Immutable value object that exposes the assembled document payload
 * alongside metadata required by post-processing steps.
 */
class DocumentPayload {
        /**
         * @param array<string,mixed> $document
         * @param array<string,mixed> $rawReceptor
         */
        public function __construct(
                private readonly array $document,
                private readonly array $rawReceptor,
                private readonly bool $hasReferences
        ) {
        }

        /**
         * Returns the payload that will be sent to LibreDTE builders.
         *
         * @return array<string,mixed>
         */
        public function getDocument(): array {
                return $this->document;
        }

        /**
         * Returns the un-sanitized receptor data provided by the caller.
         *
         * @return array<string,mixed>
         */
        public function getRawReceptor(): array {
                return $this->rawReceptor;
        }

        public function hasReferences(): bool {
                return $this->hasReferences;
        }
}
