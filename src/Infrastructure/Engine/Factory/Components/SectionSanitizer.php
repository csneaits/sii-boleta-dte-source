<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

class SectionSanitizer {
        /**
         * Removes null/empty values from a section to avoid leaking placeholder data.
         *
         * @param array<string, mixed> $values
         * @return array<string, mixed>
         */
        public function sanitize( array $values ): array {
                $clean = array();

                foreach ( $values as $key => $value ) {
                        if ( is_array( $value ) ) {
                                $nested = $this->sanitize( $value );
                                if ( ! empty( $nested ) ) {
                                        $clean[ $key ] = $nested;
                                }
                                continue;
                        }

                        if ( null === $value ) {
                                continue;
                        }

                        if ( is_string( $value ) ) {
                                $value = trim( $value );
                                if ( '' === $value ) {
                                        continue;
                                }
                        }

                        if ( is_numeric( $value ) && '' === trim( (string) $value ) ) {
                                continue;
                        }

                        $clean[ $key ] = $value;
                }

                return $clean;
        }
}
