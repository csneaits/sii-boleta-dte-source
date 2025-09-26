<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

class DefaultReceptorSanitizer implements ReceptorSanitizerInterface {
        private SectionSanitizer $sectionSanitizer;

        public function __construct( SectionSanitizer $sectionSanitizer ) {
                $this->sectionSanitizer = $sectionSanitizer;
        }

        public function sanitize( array $rawReceptor ): array {
                return $this->sectionSanitizer->sanitize( $rawReceptor );
        }
}
