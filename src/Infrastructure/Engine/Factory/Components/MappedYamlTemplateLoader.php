<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

use Symfony\Component\Yaml\Yaml;

/**
 * Template loader that restricts the search space to specific directories for a given TipoDTE.
 */
class MappedYamlTemplateLoader implements TemplateLoaderInterface {
        private string $rootPath;

        /**
         * @var array<int, string>
         */
        private array $directoryMap;
        private TemplateLoaderInterface $fallback;

        /**
         * @param array<int, string> $directoryMap
         */
        public function __construct( string $rootPath, array $directoryMap, ?TemplateLoaderInterface $fallback = null ) {
                $this->rootPath      = rtrim( $rootPath, '/' ) . '/';
                $this->directoryMap  = $directoryMap;
                $this->fallback      = $fallback ?? new YamlTemplateLoader( $this->rootPath );
        }

        public function load( int $tipo ): array {
                if ( isset( $this->directoryMap[ $tipo ] ) ) {
                        $directory = $this->normalizeDirectory( $this->directoryMap[ $tipo ] );
                        $candidates = $this->findCandidates( $directory, $tipo );

                        foreach ( $candidates as $candidate ) {
                                try {
                                        $parsed = Yaml::parseFile( $candidate );
                                        if ( is_array( $parsed ) ) {
                                                return $parsed;
                                        }
                                } catch ( \Throwable $e ) {
                                        // try next candidate
                                }
                        }
                }

                return $this->fallback->load( $tipo );
        }

        private function normalizeDirectory( string $directory ): string {
                $directory = trim( $directory, '/' );
                if ( '' === $directory ) {
                        return $this->rootPath;
                }

                return $this->rootPath . $directory . '/';
        }

        /**
         * @return string[]
         */
        private function findCandidates( string $directory, int $tipo ): array {
                $prefix    = sprintf( '%03d', $tipo );
                $directory = rtrim( $directory, '/' );

                $patterns = array(
                        $directory . '/' . $prefix . '_*.yml',
                        $directory . '/' . $prefix . '_*.yaml',
                        $directory . '/*.yml',
                        $directory . '/*.yaml',
                );

                $files = array();
                foreach ( $patterns as $pattern ) {
                        $matches = glob( $pattern );
                        if ( empty( $matches ) ) {
                                continue;
                        }

                        foreach ( $matches as $match ) {
                                if ( is_string( $match ) && '' !== $match ) {
                                        $files[] = $match;
                                }
                        }
                }

                if ( empty( $files ) ) {
                        return array();
                }

                return array_values( array_unique( $files ) );
        }
}
