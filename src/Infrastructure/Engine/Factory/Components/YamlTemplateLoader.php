<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

use Symfony\Component\Yaml\Yaml;

class YamlTemplateLoader implements TemplateLoaderInterface {
        private string $rootPath;

        public function __construct( string $rootPath ) {
                $this->rootPath = rtrim( $rootPath, '/' ) . '/';
        }

        public function load( int $tipo ): array {
                $dir = $this->rootPath . 'documentos_ok/' . sprintf( '%03d', $tipo ) . '*';
                foreach ( glob( $dir ) as $typeDir ) {
                        if ( ! is_dir( $typeDir ) ) {
                                continue;
                        }
                        $candidates = array_merge( glob( $typeDir . '/*.yml' ) ?: array(), glob( $typeDir . '/*.yaml' ) ?: array() );
                        if ( empty( $candidates ) ) {
                                continue;
                        }

                        try {
                                $parsed = Yaml::parseFile( $candidates[0] );
                                return is_array( $parsed ) ? $parsed : array();
                        } catch ( \Throwable $e ) {
                                // try next candidate
                        }
                }

                return array();
        }
}
