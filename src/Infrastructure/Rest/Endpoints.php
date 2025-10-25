<?php
namespace Sii\BoletaDte\Infrastructure\Rest;

/**
 * Public endpoints to view DTE information.
 */
class Endpoints {
        /**
         * Service used to generate and validate signed URLs for secure sharing.
         */
        private SignedUrlService $signed_url_service;

        public function __construct( ?SignedUrlService $signed_url_service = null ) {
                $this->signed_url_service = $signed_url_service ?? new SignedUrlService();
                add_action( 'init', array( $this, 'add_rewrite' ) );
                add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
                add_action( 'template_redirect', array( $this, 'render_boleta' ) );
        }

        public function add_rewrite() {
                add_rewrite_rule( '^boleta/([0-9]+)/?$', 'index.php?sii_boleta_folio=$matches[1]', 'top' );
        }

        public function add_query_vars( array $vars ): array {
                $vars[] = 'sii_boleta_folio';
                $vars[] = 'sii_boleta_token';
                return $vars;
        }

        /**
         * Renders the DTE when hitting /boleta/{folio}.
         */
        public function render_boleta(): void {
                $folio = get_query_var( 'sii_boleta_folio' );
                if ( ! $folio ) {
                        return;
                }

                $token = get_query_var( 'sii_boleta_token' );
                if ( ! $this->is_request_authorized( (int) $folio, is_string( $token ) ? $token : null ) ) {
                        status_header( 403 );
                        wp_die( 'Debes iniciar sesión con un perfil autorizado o utilizar un enlace seguro válido para acceder a esta boleta.', '', array( 'response' => 403 ) );
                }

                // Verificar si se solicita la descarga del PDF
                if ( isset( $_GET['download'] ) && 'pdf' === $_GET['download'] ) {
                        $this->serve_pdf_download( (int) $folio, $token );
                        return;
                }

                $html = $this->get_boleta_html( (int) $folio );
                if ( false === $html ) {
                        status_header( 404 );
                        wp_die( 'Boleta no encontrada' );
                } elseif ( is_wp_error( $html ) ) {
                        status_header( 500 );
                        wp_die( 'Error al cargar el DTE.' );
                }

                status_header( 200 );
                nocache_headers();
                echo $html;
                $this->terminate_request();
        }

        /**
         * Builds HTML for a given folio searching in the secured storage directory.
         *
         * @return string|false
         */
        public function get_boleta_html( int $folio ) {
                $upload_dir = wp_upload_dir();
                $secure_dir = $this->prepare_secure_directory( $upload_dir );
                $xml_file   = $this->locate_secure_xml( $secure_dir, $folio );

                if ( ! $xml_file ) {
                        $xml_file = $this->migrate_legacy_xml( $upload_dir, $secure_dir, $folio );
                }

                if ( ! $xml_file ) {
                        return false;
                }

                $xml = @simplexml_load_file( $xml_file );
                if ( ! $xml ) {
                        return new \WP_Error( 'sii_boleta_invalid_xml' );
                }

                $doc  = $xml->Documento;
                $enc  = $doc->Encabezado;
                $emis = $enc->Emisor;
                $rec  = $enc->Receptor;
                $tot  = $enc->Totales;
                ob_start();
                ?>
                <html><body>
                <h1>Consulta de Boleta</h1>
                <p><strong>Folio:</strong> <?php echo htmlspecialchars( (string) $folio, ENT_QUOTES ); ?></p>
                <p><strong>Emisor:</strong> <?php echo htmlspecialchars( (string) $emis->RznSoc, ENT_QUOTES ); ?> (<?php echo htmlspecialchars( (string) $emis->RUTEmisor, ENT_QUOTES ); ?>)</p>
                <p><strong>Receptor:</strong> <?php echo htmlspecialchars( (string) $rec->RznSocRecep, ENT_QUOTES ); ?> (<?php echo htmlspecialchars( (string) $rec->RUTRecep, ENT_QUOTES ); ?>)</p>
                <p><strong>Fecha:</strong> <?php echo htmlspecialchars( (string) $enc->IdDoc->FchEmis, ENT_QUOTES ); ?></p>
                <table><tbody>
                <?php foreach ( $doc->Detalle as $det ) : ?>
                        <tr>
                                <td><?php echo htmlspecialchars( (string) $det->NmbItem, ENT_QUOTES ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $det->QtyItem, ENT_QUOTES ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $det->PrcItem, ENT_QUOTES ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $det->MontoItem, ENT_QUOTES ); ?></td>
                        </tr>
                <?php endforeach; ?>
                </tbody></table>
                <p><strong>Total:</strong> <?php echo htmlspecialchars( (string) $tot->MntTotal, ENT_QUOTES ); ?></p>
                </body></html>
                <?php
                return ob_get_clean();
        }

        /**
         * Generates a signed URL that allows sharing a DTE without exposing uploads publicly.
         */
        public function generate_signed_url( int $folio, int $ttl = 3600 ): string {
                return $this->signed_url_service->generate_signed_url( $folio, $ttl );
        }

        /**
         * Ensures responses can be intercepted on tests while exiting in production.
         */
        protected function terminate_request(): void {
                exit;
        }

        /**
         * Sirve la descarga del PDF para un folio específico.
         */
        private function serve_pdf_download( int $folio, ?string $token ): void {
                // Buscar el archivo PDF en el directorio seguro
                $pdf_file = $this->locate_secure_pdf( $folio );
                if ( ! $pdf_file || ! file_exists( $pdf_file ) ) {
                        status_header( 404 );
                        wp_die( 'PDF no encontrado' );
                }

                if ( function_exists( 'nocache_headers' ) ) {
                        nocache_headers();
                }

                header( 'Content-Type: application/pdf' );
                header( 'Content-Disposition: inline; filename="boleta-' . $folio . '.pdf"' );
                header( 'Content-Length: ' . filesize( $pdf_file ) );
                @readfile( $pdf_file );
                $this->terminate_request();
        }

        /**
         * Localiza el archivo PDF en el directorio seguro.
         */
        private function locate_secure_pdf( int $folio ): string {
                $upload_dir = wp_upload_dir();
                $secure_dir = $this->prepare_secure_directory( $upload_dir );
                
                if ( ! is_dir( $secure_dir ) ) {
                        return '';
                }

                $iterator = new \DirectoryIterator( $secure_dir );
                foreach ( $iterator as $file ) {
                        if ( $file->isFile() && preg_match( '/^(?:Woo_)?DTE_\d+_' . $folio . '_[a-zA-Z0-9]+\.pdf$/', $file->getFilename() ) ) {
                                return $file->getPathname();
                        }
                }

                return '';
        }

        private function is_request_authorized( int $folio, ?string $token ): bool {
                if ( null !== $token && '' !== $token ) {
                        // Validar token personalizado del correo electrónico
                        if ( $this->validate_custom_token( $folio, $token ) ) {
                                return true;
                        }
                        // Fallback al servicio de URLs firmadas original
                        return $this->signed_url_service->validate_token( $token, $folio );
                }

                if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
                        return false;
                }

                if ( function_exists( 'current_user_can' ) ) {
                        return (bool) current_user_can( 'manage_options' );
                }

                return false;
        }

        /**
         * Valida el token personalizado creado para los correos electrónicos.
         */
        private function validate_custom_token( int $folio, string $token ): bool {
                // Buscar el pedido asociado al folio
                $order_id = $this->get_order_id_by_folio( $folio );
                if ( $order_id <= 0 ) {
                        return false;
                }

                // Obtener los metadatos del pedido
                $meta_prefix = '_sii_boleta';
                $key = get_post_meta( $order_id, $meta_prefix . '_pdf_key', true );
                $nonce = get_post_meta( $order_id, $meta_prefix . '_pdf_nonce', true );

                if ( '' === $key || '' === $nonce ) {
                        return false;
                }

                // Recrear el token y comparar
                $data = $folio . '|' . $key . '|' . $nonce;
                $expected_token = hash( 'sha256', $data . \wp_salt() );

                return hash_equals( $expected_token, $token );
        }

        /**
         * Obtiene el ID del pedido basado en el folio.
         */
        private function get_order_id_by_folio( int $folio ): int {
                global $wpdb;
                
                $meta_key = '_sii_boleta_folio';
                $result = $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                        $meta_key,
                        $folio
                ) );

                return (int) $result;
        }

        private function prepare_secure_directory( array $upload_dir ): string {
                $base_dir = isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] )
                        ? $this->with_trailing_slash( $upload_dir['basedir'] )
                        : $this->with_trailing_slash( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : sys_get_temp_dir() );

                $secure_dir = $base_dir . 'sii-boleta-dte-secure';
                if ( ! is_dir( $secure_dir ) ) {
                        if ( function_exists( 'wp_mkdir_p' ) ) {
                                wp_mkdir_p( $secure_dir );
                        } else {
                                @mkdir( $secure_dir, 0755, true );
                        }
                }

                $this->harden_secure_directory( $secure_dir );

                return $secure_dir;
        }

        private function harden_secure_directory( string $secure_dir ): void {
                if ( ! is_dir( $secure_dir ) ) {
                        return;
                }

                $htaccess = $secure_dir . '/.htaccess';
                if ( ! file_exists( $htaccess ) ) {
                        @file_put_contents( $htaccess, "Require all denied\n" );
                }

                $index = $secure_dir . '/index.php';
                if ( ! file_exists( $index ) ) {
                        @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
                }
        }

        private function locate_secure_xml( string $secure_dir, int $folio ): string {
                if ( ! is_dir( $secure_dir ) ) {
                        return '';
                }

                $iterator = new \DirectoryIterator( $secure_dir );
                foreach ( $iterator as $file ) {
                        if ( $file->isFile() && preg_match( '/^(?:Woo_)?DTE_\d+_' . $folio . '_[a-zA-Z0-9]+\.xml$/', $file->getFilename() ) ) {
                                return $file->getPathname();
                        }
                }

                return '';
        }

        private function migrate_legacy_xml( array $upload_dir, string $secure_dir, int $folio ): string {
                $base_dir = isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] )
                        ? $this->with_trailing_slash( $upload_dir['basedir'] )
                        : '';

                if ( '' === $base_dir || ! is_dir( $base_dir ) ) {
                        return '';
                }

                $xml_file = '';
                $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS )
                );

                foreach ( $iterator as $file ) {
                        $path = $file->getPathname();
                        if ( str_starts_with( $path, $secure_dir ) ) {
                                continue;
                        }

                        if ( $file->isFile() && preg_match( '/^(?:Woo_)?DTE_\d+_' . $folio . '_\d+\.xml$/', $file->getFilename() ) ) {
                                $xml_file = $path;
                                break;
                        }
                }

                if ( ! $xml_file ) {
                        return '';
                }

                $destination = $this->build_secure_destination( $secure_dir, basename( $xml_file ), $folio );
                if ( @rename( $xml_file, $destination ) ) {
                        return $destination;
                }

                if ( @copy( $xml_file, $destination ) ) {
                        @unlink( $xml_file );
                        return $destination;
                }

                return '';
        }

        private function build_secure_destination( string $secure_dir, string $filename, int $folio ): string {
                $fragment = $this->random_fragment();
                if ( ! preg_match( '/^(?:Woo_)?DTE_\d+_' . $folio . '_/i', $filename ) ) {
                        $filename = 'DTE_0_' . $folio . '_' . $fragment . '.xml';
                } else {
                        $filename = preg_replace( '/^(?:Woo_)?DTE_(\d+_' . $folio . ')_\d+\.xml$/', 'DTE_$1_' . $fragment . '.xml', $filename );
                }

                return rtrim( $secure_dir, '/\\' ) . '/' . $filename;
        }

        private function random_fragment(): string {
                try {
                        return bin2hex( random_bytes( 4 ) );
                } catch ( \Exception $e ) {
                        $value = function_exists( 'wp_rand' ) ? wp_rand() : random_int( 0, PHP_INT_MAX );
                        return dechex( (int) $value );
                }
        }

        private function with_trailing_slash( string $path ): string {
                return rtrim( $path, '/\\' ) . '/';
        }
}

class_alias( Endpoints::class, 'SII_Boleta_Endpoints' );
