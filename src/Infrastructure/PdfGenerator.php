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
        /** @var array<string,string> */
        private static array $logo_cache = array();

        public function __construct( DteEngine $engine, Settings $settings = null ) {
                $this->engine   = $engine;
                $this->settings = $settings ?? new Settings();
        }

        /**
         * Generates a PDF for the provided DTE XML and returns the path to the
         * generated file.
         */
        public function generate( string $xml ): string {
                $options = $this->build_render_options( $xml );
                try {
                        $result = $this->engine->render_pdf( $xml, $options );
                } catch ( \Throwable $e ) {
                        // Log and return empty string to preserve signature without breaking callers.
                        try { error_log( '[sii-boleta-dte] render_pdf exception: ' . $e->getMessage() ); } catch ( \Throwable $ignore ) {}
                        return '';
                }
                if ( false === $result ) {
                        try { error_log( '[sii-boleta-dte] render_pdf returned false' ); } catch ( \Throwable $ignore ) {}
                        return '';
                }
                return (string) $result;
        }

        /**
         * Builds render options based on plugin configuration.
         * If $xml is provided, attempts to detect the TipoDTE to apply per-type overrides.
         *
         * @param string|null $xml
         * @return array<string,mixed>
         */
        private function build_render_options( ?string $xml = null ): array {
                        $settings = $this->settings->get_settings();
                        $tipo = null;
                        $transporte_data = array();
                        // Default template from global setting
                        $format   = isset( $settings['pdf_format'] ) ? strtolower( trim( (string) $settings['pdf_format'] ) ) : '';
                        $template = 'boleta' === $format ? 'boleta_ticket' : 'estandar';

                        $options = array(
                                'renderer' => array(
                                        'template' => $template,
                                ),
                        );

                        // If XML provided, try to extract the TipoDTE and apply per-type PDF settings
                        if ( null !== $xml && '' !== trim( $xml ) ) {
                                $prev_libxml = libxml_use_internal_errors( true );
                                $doc = simplexml_load_string( $xml );
                                if ( $doc !== false ) {
                                        // Namespace-agnostic lookup for Encabezado/IdDoc/TipoDTE
                                        $encabezado = $doc->xpath('//Encabezado');
                                        if ( ! empty( $encabezado ) ) {
                                                $id = $encabezado[0]->xpath('.//IdDoc');
                                                if ( ! empty( $id ) ) {
                                                        $tipoNode = $id[0]->xpath('.//TipoDTE');
                                                        if ( ! empty( $tipoNode ) ) {
                                                                $tipo = (string) $tipoNode[0];
                                                        }
                                                }
                                                // Extraer datos de transporte sólo para guías (52) si existen
                                                $transporte_nodes = $encabezado[0]->xpath('.//Transporte');
                                                if ( ! empty( $transporte_nodes ) ) {
                                                        $t = $transporte_nodes[0];
                                                        $map = array(
                                                                'Patente'  => 'patente',
                                                                'RUTTrans' => 'rut_transportista',
                                                                'DirDest'  => 'dir_destino',
                                                                'CmnaDest' => 'comuna_destino',
                                                        );
                                                        foreach ( $map as $xmlKey => $key ) {
                                                                if ( isset( $t->{$xmlKey} ) && (string) $t->{$xmlKey} !== '' ) {
                                                                        $transporte_data[ $key ] = (string) $t->{$xmlKey};
                                                                }
                                                        }
                                                        // Chofer (subnodo)
                                                        $chofer_nodes = $t->xpath('.//Chofer');
                                                        if ( ! empty( $chofer_nodes ) ) {
                                                                $c = $chofer_nodes[0];
                                                                if ( isset( $c->RUTChofer ) && (string) $c->RUTChofer !== '' ) {
                                                                        $transporte_data['rut_chofer'] = (string) $c->RUTChofer;
                                                                }
                                                                if ( isset( $c->NombreChofer ) && (string) $c->NombreChofer !== '' ) {
                                                                        $transporte_data['nombre_chofer'] = (string) $c->NombreChofer;
                                                                }
                                                        }
                                                }
                                        }
                                        // Fallback: if XPath failed (namespaces or unexpected), try a regex on raw XML
                                        if ( null === $tipo || '' === trim( (string) $tipo ) ) {
                                                $matches = array();
                                                if ( preg_match('/<(?:[^:>]+:)?TipoDTE[^>]*>(\d+)<\/(?:[^:>]+:)?TipoDTE>/i', (string) $xml, $matches ) ) {
                                                        $tipo = $matches[1];
                                                }
                                        }
                                        if ( null !== $tipo && isset( $settings['pdf_per_type'][ (int) $tipo ] ) ) {
                                                $cfg = $settings['pdf_per_type'][ (int) $tipo ];
                                                if ( isset( $cfg['template'] ) ) {
                                                        $options['renderer']['template'] = $cfg['template'];
                                                }
                                                if ( isset( $cfg['paper_width'] ) || isset( $cfg['paper_height'] ) ) {
                                                        // Convert numeric millimeter values into the renderer-expected
                                                        // width/height strings (e.g. "80mm") so engines like mPDF
                                                        // receive the proper MediaBox size.
                                                        $w = isset( $cfg['paper_width'] ) ? (string) $cfg['paper_width'] : '';
                                                        $h = isset( $cfg['paper_height'] ) ? (string) $cfg['paper_height'] : '';
                                                        $paper = array();
                                                        if ( '' !== $w ) {
                                                                $w_trim = trim( (string) $w );
                                                                $paper['width'] = \is_numeric( $w_trim ) ? $w_trim . 'mm' : $w_trim;
                                                        }
                                                        if ( '' !== $h ) {
                                                                $h_trim = trim( (string) $h );
                                                                $paper['height'] = \is_numeric( $h_trim ) ? $h_trim . 'mm' : $h_trim;
                                                        }
                                                        if ( ! empty( $paper ) ) {
                                                                $options['renderer']['paper'] = $paper;
                                                        }
                                                }
                                        }
                                        // Inyectar datos de transporte al contexto del documento para que la plantilla pueda mostrarlos.
                                        if ( ! empty( $transporte_data ) ) {
                                                if ( ! isset( $options['document_overrides'] ) || ! is_array( $options['document_overrides'] ) ) {
                                                        $options['document_overrides'] = array();
                                                }
                                                $options['document_overrides']['transporte'] = $transporte_data;
                                        }
                                }
                                libxml_clear_errors();
                                libxml_use_internal_errors( $prev_libxml );
                        }

                        // Debug: log detected tipo and resolved renderer options when WP_DEBUG is enabled.
                        try {
                                if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
                                        $detected = isset( $tipo ) ? (string) $tipo : '(none)';
                                        $ro = isset( $options['renderer'] ) ? $options['renderer'] : array();
                                        error_log( '[sii-boleta-dte] build_render_options: detected TipoDTE=' . $detected . ' renderer=' . json_encode( $ro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                                }
                        } catch ( \Throwable $e ) {
                                // ignore logging failures
                        }


                if ( ! empty( $settings['pdf_show_logo'] ) ) {
                        $logo = $this->resolve_logo_data( $settings );
                        if ( null !== $logo ) {
                                if ( ! isset( $options['document_overrides'] ) || ! is_array( $options['document_overrides'] ) ) {
                                        $options['document_overrides'] = array();
                                }
                                $options['document_overrides']['logo'] = $logo;
                        }
                }

                // Footer note configured in settings: expose to templates as document.footer
                if ( ! empty( $settings['pdf_footer'] ) ) {
                        if ( ! isset( $options['document_overrides'] ) || ! is_array( $options['document_overrides'] ) ) {
                                $options['document_overrides'] = array();
                        }
                        // Cast to string, sanitize and normalize whitespace
                        $footer_raw = (string) $settings['pdf_footer'];
                        if ( \function_exists( 'wp_strip_all_tags' ) ) {
                                $footer_sanitized = (string) \call_user_func( 'wp_strip_all_tags', $footer_raw );
                        } else {
                                $footer_sanitized = \strip_tags( $footer_raw );
                        }
                        $footer = trim( (string) preg_replace( '/\s+/', ' ', $footer_sanitized ) );
                        if ( '' !== $footer ) {
                                $options['document_overrides']['footer'] = $footer;
                        }
                }

                return $options;
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

                // Cache key includes file mtime to invalidate on logo changes
                $mtime = @filemtime( $path ) ?: 0;
                $cache_key = $attachment_id . ':' . $mtime;
                if ( isset( self::$logo_cache[ $cache_key ] ) ) {
                        return self::$logo_cache[ $cache_key ];
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

                $data_uri = 'data:' . $mime . ';base64,' . base64_encode( $contents );
                self::$logo_cache[ $cache_key ] = $data_uri;
                return $data_uri;
        }
}

class_alias( PdfGenerator::class, 'SII_Boleta_Pdf_Generator' );
