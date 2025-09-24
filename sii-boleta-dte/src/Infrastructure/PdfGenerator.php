<?php
namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Wrapper around the DteEngine PDF rendering capability.
 */
class PdfGenerator {
        private DteEngine $engine;
        private Settings $settings;

        public function __construct( DteEngine $engine, Settings $settings = null ) {
                $this->engine   = $engine;
                $this->settings = $settings ?? new Settings();
        }

        /**
         * Generates a PDF for the provided DTE XML and returns the path to the
         * generated file.
         */
        public function generate( string $xml ): string {
                $options = $this->build_render_options();

                return (string) $this->engine->render_pdf( $xml, $options );
        }

        /**
         * Builds render options based on plugin configuration.
         *
         * @return array<string,mixed>
         */
        private function build_render_options(): array {
                $settings = $this->settings->get_settings();

                if ( empty( $settings['pdf_show_logo'] ) ) {
                        return array();
                }

                $logo = $this->resolve_logo_data( $settings );

                if ( null === $logo ) {
                        return array();
                }

                return array(
                        'document_overrides' => array(
                                'logo' => $logo,
                        ),
                );
        }

        /**
         * Resolves the configured logo into a data URI understood by the renderer.
         *
         * @param array<string,mixed> $settings Plugin settings.
         */
        private function resolve_logo_data( array $settings ): ?string {
                $attachment_id = isset( $settings['pdf_logo'] ) ? (int) $settings['pdf_logo'] : 0;
                if ( $attachment_id <= 0 ) {
                        return null;
                }

                $path = '';

                if ( function_exists( 'get_attached_file' ) ) {
                        $file = get_attached_file( $attachment_id );
                        if ( is_string( $file ) ) {
                                $path = $file;
                        }
                }

                if ( '' === $path || ! is_string( $path ) ) {
                        return null;
                }

                $path = (string) $path;

                if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                        return null;
                }

                $contents = @file_get_contents( $path );
                if ( false === $contents || '' === $contents ) {
                        return null;
                }

                $mime = '';

                if ( function_exists( 'wp_check_filetype' ) ) {
                        $type = wp_check_filetype( $path );
                        if ( is_array( $type ) && ! empty( $type['type'] ) && is_string( $type['type'] ) ) {
                                $mime = $type['type'];
                        }
                }

                if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
                        $detected = mime_content_type( $path );
                        if ( is_string( $detected ) && '' !== $detected ) {
                                $mime = $detected;
                        }
                }

                if ( '' === $mime ) {
                        $extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
                        $mime      = match ( $extension ) {
                                'jpg', 'jpeg' => 'image/jpeg',
                                'gif'        => 'image/gif',
                                'svg'        => 'image/svg+xml',
                                'webp'       => 'image/webp',
                                default      => 'image/png',
                        };
                }

                return 'data:' . $mime . ';base64,' . base64_encode( $contents );
        }
}

class_alias( PdfGenerator::class, 'SII_Boleta_Pdf_Generator' );
