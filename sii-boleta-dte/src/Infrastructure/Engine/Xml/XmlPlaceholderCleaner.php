<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Xml;

interface XmlPlaceholderCleaner {
        /**
         * @param array<string,mixed> $rawReceptor
         */
        public function clean( string $xml, array $rawReceptor, bool $hasReferences ): string;
}
