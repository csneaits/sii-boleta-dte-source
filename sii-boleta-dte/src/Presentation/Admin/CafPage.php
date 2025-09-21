<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Admin page to manage CAF uploads.
 */
class CafPage {
        private Settings $settings;

        public function __construct( Settings $settings ) {
                $this->settings = $settings;
        }

        /** Registers hooks if needed. */
        public function register(): void {}

        /**
         * Renders the CAF management page.
         */
        public function render_page(): void {
                if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                if ( isset( $_POST['upload_caf'] ) && function_exists( 'check_admin_referer' ) && check_admin_referer( 'sii_boleta_upload_caf' ) ) {
                        $this->handle_upload();
                }

                // Use namespaced query vars to avoid collisions with other plugins.
                if ( isset( $_GET['caf_action'], $_GET['caf_key'] ) && 'delete' === $_GET['caf_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        if ( function_exists( 'check_admin_referer' ) && check_admin_referer( 'sii_boleta_delete_caf' ) ) {
                                $this->handle_delete( (string) $_GET['caf_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        }
                }

                $settings_view = $this->settings->get_settings();
                $environment   = $settings_view['environment_slug'] ?? Settings::ENV_TEST;
                $hidden        = (int) ( $settings_view['cafs_hidden'] ?? 0 );
                $cafs          = $this->get_cafs( $settings_view );

                if ( is_array( $cafs ) && ! empty( $cafs ) ) {
                        usort(
                                $cafs,
                                function ( $a, $b ) {
                                        $ta = (int) ( $a['tipo'] ?? 0 );
                                        $tb = (int) ( $b['tipo'] ?? 0 );
                                        if ( $ta !== $tb ) {
                                                return $ta <=> $tb;
                                        }
                                        $da = (int) ( $a['desde'] ?? 0 );
                                        $db = (int) ( $b['desde'] ?? 0 );
                                        if ( $da !== $db ) {
                                                return $da <=> $db;
                                        }
                                        $fa = strtotime( (string) ( $a['fecha'] ?? '' ) ) ?: 0;
                                        $fb = strtotime( (string) ( $b['fecha'] ?? '' ) ) ?: 0;
                                        return $fb <=> $fa;
                                }
                        );
                }
                $types = $this->supported_types();

                echo '<div class="wrap">';
                echo '<h1>' . esc_html__( 'Folios / CAFs', 'sii-boleta-dte' ) . '</h1>';
                echo '<p>' . esc_html__( 'Sube aquí los archivos CAF entregados por el SII para autorizar el uso de folios en cada tipo de documento.', 'sii-boleta-dte' ) . '</p>';
                echo '<p class="description">' . sprintf( esc_html__( 'Mostrando CAF para el ambiente %s.', 'sii-boleta-dte' ), esc_html( $this->describe_environment( $environment ) ) ) . '</p>';
                if ( $hidden > 0 ) {
                        echo '<div class="notice notice-info"><p>' . sprintf( esc_html__( 'Hay %d CAF cargados para otros ambientes. Cambia el ambiente en la página de ajustes para administrarlos.', 'sii-boleta-dte' ), (int) $hidden ) . '</p></div>';
                }

                echo '<form method="post" enctype="multipart/form-data">';
                if ( function_exists( 'wp_nonce_field' ) ) {
                        wp_nonce_field( 'sii_boleta_upload_caf' );
                }
                echo '<input type="file" name="caf_files[]" multiple accept=".xml" />';
                if ( function_exists( 'submit_button' ) ) {
                        submit_button( __( 'Subir CAF', 'sii-boleta-dte' ), 'primary', 'upload_caf' );
                }
                echo '</form>';

                echo '<h2>' . esc_html__( 'CAF cargados', 'sii-boleta-dte' ) . '</h2>';
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__( 'Tipo', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Rango', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Consumidos', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Restantes', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Estado', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Fecha de carga', 'sii-boleta-dte' ) . '</th>';
                echo '<th>' . esc_html__( 'Acciones', 'sii-boleta-dte' ) . '</th>';
                echo '</tr></thead><tbody>';
                if ( empty( $cafs ) ) {
                        echo '<tr><td colspan="7">' . esc_html__( 'No hay CAFs cargados.', 'sii-boleta-dte' ) . '</td></tr>';
                } else {
                        foreach ( $cafs as $caf ) {
                                $type_label = $types[ (int) $caf['tipo'] ] ?? (string) $caf['tipo'];
                                $d          = (int) ( $caf['desde'] ?? 0 );
                                $h          = (int) ( $caf['hasta'] ?? 0 );
                                $range      = $d . ' - ' . $h;
                                $estado     = esc_html( $caf['estado'] ?? 'vigente' );
                                $fecha      = esc_html( $caf['fecha'] ?? '' );
                                $last       = function_exists( 'get_option' ) ? (int) get_option( 'sii_boleta_dte_last_folio_' . $caf['tipo'], 0 ) : 0;
                                $consumidos = max( 0, min( $h, $last ) - $d + 1 );
                                $consumidos = $last < $d ? 0 : min( $consumidos, max( 0, $h - $d + 1 ) );
                                $restantes  = max( 0, ( $h - $d + 1 ) - $consumidos );
                                $key        = rawurlencode( base64_encode( (string) ( $caf['path'] ?? '' ) ) );
                                $base_url   = function_exists( 'menu_page_url' ) ? (string) menu_page_url( 'sii-boleta-dte-cafs', false ) : '?page=sii-boleta-dte-cafs';
                                $url        = add_query_arg( array( 'caf_action' => 'delete', 'caf_key' => $key ), $base_url );
                                if ( function_exists( 'wp_nonce_url' ) ) {
                                        $url = wp_nonce_url( $url, 'sii_boleta_delete_caf' );
                                }
                                echo '<tr>';
                                echo '<td>' . esc_html( $type_label ) . '</td>';
                                echo '<td>' . esc_html( $range ) . '</td>';
                                echo '<td>' . (int) $consumidos . '</td>';
                                echo '<td>' . (int) $restantes . '</td>';
                                echo '<td>' . $estado . '</td>';
                                echo '<td>' . $fecha . '</td>';
                                echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Eliminar', 'sii-boleta-dte' ) . '</a></td>';
                                echo '</tr>';
                        }
                }
                echo '</tbody></table>';
                echo '</div>';
        }

        /**
         * Handles CAF file uploads.
         */
        private function handle_upload(): void {
                if ( ! isset( $_FILES['caf_files'] ) || ! function_exists( 'wp_handle_upload' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        return;
                }

                $files       = $_FILES['caf_files']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $count       = is_array( $files['name'] ) ? count( $files['name'] ) : 0;
                $environment = $this->current_environment_slug();
                $raw_settings = $this->settings->get_raw_settings();
                $existing     = isset( $raw_settings['cafs'] ) && is_array( $raw_settings['cafs'] ) ? $raw_settings['cafs'] : array();

                for ( $i = 0; $i < $count; $i++ ) {
                        if ( UPLOAD_ERR_OK !== $files['error'][ $i ] ) {
                                continue;
                        }

                        $file = array(
                                'name'     => $files['name'][ $i ],
                                'type'     => $files['type'][ $i ],
                                'tmp_name' => $files['tmp_name'][ $i ],
                                'error'    => $files['error'][ $i ],
                                'size'     => $files['size'][ $i ],
                        );
                        $uploaded = wp_handle_upload(
                                $file,
                                array(
                                        // Permite subir XML aunque la detección MIME varíe entre servidores.
                                        'test_form' => false,
                                        'test_type' => false,
                                        'mimes'     => array(
                                                'xml' => 'application/xml',
                                        ),
                                )
                        );
                        if ( isset( $uploaded['error'] ) ) {
                                echo '<div class="notice notice-error"><p>' . esc_html( $uploaded['error'] ) . '</p></div>';
                                continue;
                        }

                        $path = $uploaded['file'];
                        $xml  = @simplexml_load_file( $path );
                        if ( ! $xml || ! isset( $xml->CAF->DA->TD ) ) {
                                @unlink( $path );
                                echo '<div class="notice notice-error"><p>' . esc_html__( 'El archivo no corresponde a un CAF válido.', 'sii-boleta-dte' ) . '</p></div>';
                                continue;
                        }

                        $types = $this->supported_types();
                        $tipo  = (int) $xml->CAF->DA->TD;
                        if ( ! isset( $types[ $tipo ] ) ) {
                                @unlink( $path );
                                echo '<div class="notice notice-error"><p>' . esc_html__( 'Tipo de documento no soportado.', 'sii-boleta-dte' ) . '</p></div>';
                                continue;
                        }

                        $d      = (int) ( $xml->CAF->DA->RNG->D ?? 0 );
                        $h      = (int) ( $xml->CAF->DA->RNG->H ?? 0 );
                        $fa     = (string) ( $xml->CAF->DA->FA ?? '' );
                        $year   = defined( 'YEAR_IN_SECONDS' ) ? YEAR_IN_SECONDS : 31536000;
                        $estado = ( $fa && strtotime( $fa ) && strtotime( $fa ) < time() - $year ) ? 'expirado' : 'vigente';

                        // Evitar duplicados y rangos solapados dentro del mismo ambiente.
                        $is_dup = false;
                        foreach ( $existing as $ecaf ) {
                                if ( $this->caf_environment_slug( $ecaf ) !== $environment ) {
                                        continue;
                                }
                                if ( (int) ( $ecaf['tipo'] ?? 0 ) !== $tipo ) {
                                        continue;
                                }
                                $ed = (int) ( $ecaf['desde'] ?? 0 );
                                $eh = (int) ( $ecaf['hasta'] ?? 0 );
                                if ( max( $ed, $d ) <= min( $eh, $h ) ) {
                                        $is_dup = true;
                                        break;
                                }
                        }
                        if ( $is_dup ) {
                                @unlink( $path );
                                echo '<div class="notice notice-error"><p>' . esc_html__( 'Ya existe un CAF cargado para este tipo con un rango que se solapa.', 'sii-boleta-dte' ) . '</p></div>';
                                continue;
                        }

                        $upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => sys_get_temp_dir() );
                        $dir        = ( function_exists( 'trailingslashit' ) ? trailingslashit( $upload_dir['basedir'] ) : $upload_dir['basedir'] . '/' ) . 'sii-boleta-dte/cafs/';
                        if ( function_exists( 'wp_mkdir_p' ) ) {
                                wp_mkdir_p( $dir );
                        } elseif ( ! is_dir( $dir ) ) {
                                @mkdir( $dir, 0777, true );
                        }
                        $dest = $dir . basename( $path );
                        @rename( $path, $dest );

                        $new_entry = array(
                                'tipo'        => $tipo,
                                'path'        => $dest,
                                'desde'       => $d,
                                'hasta'       => $h,
                                'estado'      => $estado,
                                'fecha'       => function_exists( 'date_i18n' ) ? date_i18n( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ),
                                'environment' => $environment,
                        );

                        $existing[]                    = $new_entry;
                        $raw_settings['cafs']           = array_values( $existing );
                        $raw_settings['caf_path']       = $this->rebuild_caf_path( $raw_settings['cafs'] );
                        if ( function_exists( 'update_option' ) ) {
                                update_option( Settings::OPTION_NAME, $raw_settings );
                        }
                        echo '<div class="notice notice-success"><p>' . esc_html__( 'CAF cargado correctamente.', 'sii-boleta-dte' ) . '</p></div>';
                }
        }

        /**
         * Handles CAF deletion.
         */
        private function handle_delete( string $param ): void {
                $environment = $this->current_environment_slug();

                $target_path = '';
                if ( ctype_digit( $param ) ) {
                        $index = (int) $param;
                        $view  = $this->get_cafs();
                        if ( isset( $view[ $index ]['path'] ) ) {
                                $target_path = (string) $view[ $index ]['path'];
                        }
                }
                if ( '' === $target_path ) {
                        $decoded = base64_decode( rawurldecode( $param ), true );
                        if ( is_string( $decoded ) ) {
                                $target_path = $decoded;
                        }
                }

                if ( '' === $target_path ) {
                        return;
                }

                $raw_settings = $this->settings->get_raw_settings();
                $cafs         = isset( $raw_settings['cafs'] ) && is_array( $raw_settings['cafs'] ) ? $raw_settings['cafs'] : array();

                $index = null;
                foreach ( $cafs as $i => $caf ) {
                        if ( $this->caf_environment_slug( $caf ) !== $environment ) {
                                continue;
                        }
                        if ( (string) ( $caf['path'] ?? '' ) === $target_path ) {
                                $index = (int) $i;
                                break;
                        }
                }

                if ( null === $index || ! isset( $cafs[ $index ] ) ) {
                        $this->get_cafs();
                        $raw_settings = $this->settings->get_raw_settings();
                        $cafs         = isset( $raw_settings['cafs'] ) && is_array( $raw_settings['cafs'] ) ? $raw_settings['cafs'] : array();
                        $index        = null;
                        foreach ( $cafs as $i => $caf ) {
                                if ( $this->caf_environment_slug( $caf ) !== $environment ) {
                                        continue;
                                }
                                if ( (string) ( $caf['path'] ?? '' ) === $target_path ) {
                                        $index = (int) $i;
                                        break;
                                }
                        }
                        if ( null === $index || ! isset( $cafs[ $index ] ) ) {
                                return;
                        }
                }

                $caf = $cafs[ $index ];
                unset( $cafs[ $index ] );
                $cafs                         = array_values( $cafs );
                $raw_settings['cafs']         = $cafs;
                $raw_settings['caf_path']     = $this->rebuild_caf_path( $cafs );
                $file                         = (string) ( $caf['path'] ?? '' );
                if ( $file && file_exists( $file ) ) {
                        @unlink( $file );
                }
                if ( function_exists( 'update_option' ) ) {
                        update_option( Settings::OPTION_NAME, $raw_settings );
                }
                if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'menu_page_url' ) ) {
                        $dest = (string) menu_page_url( 'sii-boleta-dte-cafs', false );
                        wp_safe_redirect( $dest );
                        exit;
                }
                echo '<div class="notice notice-success"><p>' . esc_html__( 'CAF eliminado.', 'sii-boleta-dte' ) . '</p></div>';
        }

        /**
         * @return array<int,string>
         */
        private function supported_types(): array {
                return array(
                        33 => __( 'Factura', 'sii-boleta-dte' ),
                        34 => __( 'Factura Exenta', 'sii-boleta-dte' ),
                        39 => __( 'Boleta', 'sii-boleta-dte' ),
                        41 => __( 'Boleta Exenta', 'sii-boleta-dte' ),
                        52 => __( 'Guía de Despacho', 'sii-boleta-dte' ),
                        56 => __( 'Nota de Débito', 'sii-boleta-dte' ),
                        61 => __( 'Nota de Crédito', 'sii-boleta-dte' ),
                );
        }

        /**
         * @param array<string,mixed>|null $settings
         * @return array<int,array<string,mixed>>
         */
        private function get_cafs( ?array $settings = null ): array {
                $settings = $settings ?? $this->settings->get_settings();
                $cafs     = $settings['cafs'] ?? array();
                if ( is_array( $cafs ) && ! empty( $cafs ) ) {
                        return $cafs;
                }

                $environment  = $settings['environment_slug'] ?? Settings::ENV_TEST;
                $raw_settings = $this->settings->get_raw_settings();
                $map          = isset( $raw_settings['caf_path'] ) && is_array( $raw_settings['caf_path'] ) ? $raw_settings['caf_path'] : array();

                if ( ! isset( $map[ Settings::ENV_TEST ] ) && ! isset( $map[ Settings::ENV_PROD ] ) ) {
                        $map = array( $environment => $map );
                }

                $rebuilt = array();
                $env_map = $map[ $environment ] ?? array();
                if ( is_array( $env_map ) ) {
                        foreach ( $env_map as $tipo => $path ) {
                                $tipo = (int) $tipo;
                                if ( ! $tipo || ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
                                        continue;
                                }
                                $xml = @simplexml_load_file( $path );
                                if ( ! $xml || ! isset( $xml->CAF->DA->RNG->D, $xml->CAF->DA->RNG->H ) ) {
                                        continue;
                                }
                                $d      = (int) $xml->CAF->DA->RNG->D;
                                $h      = (int) $xml->CAF->DA->RNG->H;
                                $fa     = (string) ( $xml->CAF->DA->FA ?? '' );
                                $year   = defined( 'YEAR_IN_SECONDS' ) ? YEAR_IN_SECONDS : 31536000;
                                $estado = ( $fa && strtotime( $fa ) && strtotime( $fa ) < time() - $year ) ? 'expirado' : 'vigente';
                                $rebuilt[] = array(
                                        'tipo'        => $tipo,
                                        'path'        => $path,
                                        'desde'       => $d,
                                        'hasta'       => $h,
                                        'estado'      => $estado,
                                        'fecha'       => '',
                                        'environment' => $environment,
                                );
                        }
                }

                if ( ! empty( $rebuilt ) ) {
                        $remaining = array();
                        if ( isset( $raw_settings['cafs'] ) && is_array( $raw_settings['cafs'] ) ) {
                                foreach ( $raw_settings['cafs'] as $caf ) {
                                        if ( $this->caf_environment_slug( $caf ) !== $environment ) {
                                                $remaining[] = $caf;
                                        }
                                }
                        }
                        $raw_settings['cafs']     = array_merge( $remaining, $rebuilt );
                        $raw_settings['caf_path'] = $this->rebuild_caf_path( $raw_settings['cafs'] );
                        if ( function_exists( 'update_option' ) ) {
                                update_option( Settings::OPTION_NAME, $raw_settings );
                        }
                }

                return $rebuilt;
        }

        /**
         * Builds the caf_path map grouping entries by environment.
         *
         * @param array<int,array<string,mixed>> $cafs
         * @return array<string,array<int,string>>
         */
        private function rebuild_caf_path( array $cafs ): array {
                $map = array();
                foreach ( $cafs as $caf ) {
                        if ( ! is_array( $caf ) ) {
                                continue;
                        }
                        $env = $this->caf_environment_slug( $caf );
                        if ( ! isset( $map[ $env ] ) ) {
                                $map[ $env ] = array();
                        }
                        if ( isset( $caf['tipo'], $caf['path'] ) ) {
                                $map[ $env ][ (int) $caf['tipo'] ] = (string) $caf['path'];
                        }
                }
                return $map;
        }

        /**
         * Normalizes the environment of a CAF entry.
         *
         * @param mixed $caf
         */
        private function caf_environment_slug( $caf ): string {
                if ( ! is_array( $caf ) ) {
                        return Settings::ENV_TEST;
                }
                $value = $caf['environment'] ?? '';
                return Settings::normalize_environment_slug( $value );
        }

        private function describe_environment( string $env ): string {
                return Settings::ENV_PROD === $env ? __( 'Producción', 'sii-boleta-dte' ) : __( 'Certificación', 'sii-boleta-dte' );
        }

        private function current_environment_slug(): string {
                $settings = $this->settings->get_settings();
                return $settings['environment_slug'] ?? Settings::normalize_environment_slug( $settings['environment'] ?? '' );
        }
}

class_alias( CafPage::class, 'SII_Boleta_Caf_Page' );
