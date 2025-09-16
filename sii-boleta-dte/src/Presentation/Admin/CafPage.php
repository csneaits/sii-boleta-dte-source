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

		if ( isset( $_GET['delete_caf'] ) && function_exists( 'check_admin_referer' ) && check_admin_referer( 'sii_boleta_delete_caf_' . (int) $_GET['delete_caf'] ) ) {
			$this->handle_delete( (int) $_GET['delete_caf'] );
		}

		$cafs  = $this->get_cafs();
		$types = $this->supported_types();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Folios / CAFs', 'sii-boleta-dte' ) . '</h1>';
		echo '<p>' . esc_html__( 'Sube aquí los archivos CAF entregados por el SII para autorizar el uso de folios en cada tipo de documento.', 'sii-boleta-dte' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'sii_boleta_upload_caf' );
		}
        echo '<input type="file" name="caf_files[]" multiple accept=".xml" />';
		if ( function_exists( 'submit_button' ) ) {
			submit_button( __( 'Subir CAF', 'sii-boleta-dte' ), 'primary', 'upload_caf' );
		}
		echo '</form>';

		if ( ! empty( $cafs ) ) {
			echo '<h2>' . esc_html__( 'CAF cargados', 'sii-boleta-dte' ) . '</h2>';
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Tipo', 'sii-boleta-dte' ) . '</th>';
			echo '<th>' . esc_html__( 'Rango', 'sii-boleta-dte' ) . '</th>';
			echo '<th>' . esc_html__( 'Estado', 'sii-boleta-dte' ) . '</th>';
			echo '<th>' . esc_html__( 'Fecha de carga', 'sii-boleta-dte' ) . '</th>';
			echo '<th>' . esc_html__( 'Acciones', 'sii-boleta-dte' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $cafs as $index => $caf ) {
				$type_label = $types[ (int) $caf['tipo'] ] ?? (string) $caf['tipo'];
				$range      = (int) $caf['desde'] . ' - ' . (int) $caf['hasta'];
				$estado     = esc_html( $caf['estado'] ?? 'vigente' );
				$fecha      = esc_html( $caf['fecha'] ?? '' );
				$url        = add_query_arg( 'delete_caf', (string) $index );
				if ( function_exists( 'wp_nonce_url' ) ) {
					$url = wp_nonce_url( $url, 'sii_boleta_delete_caf_' . $index );
				}
				echo '<tr>';
				echo '<td>' . esc_html( $type_label ) . '</td>';
				echo '<td>' . esc_html( $range ) . '</td>';
				echo '<td>' . $estado . '</td>';
				echo '<td>' . $fecha . '</td>';
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Eliminar', 'sii-boleta-dte' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * Handles CAF file uploads.
	 */
	private function handle_upload(): void {
		if ( ! isset( $_FILES['caf_files'] ) || ! function_exists( 'wp_handle_upload' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return;
		}
			$files = $_FILES['caf_files']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$count = is_array( $files['name'] ) ? count( $files['name'] ) : 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_OK !== $files['error'][ $i ] ) {
				continue;
			}
				$file     = array(
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
                            'xml' => 'application/xml', // fallback aceptado
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
				$d          = (int) ( $xml->CAF->DA->RNG->D ?? 0 );
				$h          = (int) ( $xml->CAF->DA->RNG->H ?? 0 );
				$fa         = (string) ( $xml->CAF->DA->FA ?? '' );
				$year       = defined( 'YEAR_IN_SECONDS' ) ? YEAR_IN_SECONDS : 31536000;
				$estado     = ( $fa && strtotime( $fa ) && strtotime( $fa ) < time() - $year ) ? 'expirado' : 'vigente';
				$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => sys_get_temp_dir() );
				$dir        = ( function_exists( 'trailingslashit' ) ? trailingslashit( $upload_dir['basedir'] ) : $upload_dir['basedir'] . '/' ) . 'sii-boleta-dte/cafs/';
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} elseif ( ! is_dir( $dir ) ) {
				@mkdir( $dir, 0777, true );
			}
				$dest = $dir . basename( $path );
				@rename( $path, $dest );

				$settings             = $this->settings->get_settings();
				$cafs                 = $settings['cafs'] ?? array();
				$cafs[]               = array(
					'tipo'   => $tipo,
					'path'   => $dest,
					'desde'  => $d,
					'hasta'  => $h,
					'estado' => $estado,
					'fecha'  => function_exists( 'date_i18n' ) ? date_i18n( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ),
				);
				$settings['cafs']     = $cafs;
				$caf_path             = $settings['caf_path'] ?? array();
				$caf_path[ $tipo ]    = $dest;
				$settings['caf_path'] = $caf_path;
				if ( function_exists( 'update_option' ) ) {
					update_option( Settings::OPTION_NAME, $settings );
				}
				echo '<div class="notice notice-success"><p>' . esc_html__( 'CAF cargado correctamente.', 'sii-boleta-dte' ) . '</p></div>';
		}
	}

	/**
	 * Handles CAF deletion.
	 */
	private function handle_delete( int $index ): void {
		$settings = $this->settings->get_settings();
		$cafs     = $settings['cafs'] ?? array();
		if ( ! isset( $cafs[ $index ] ) ) {
			return;
		}
		$caf = $cafs[ $index ];
		unset( $cafs[ $index ] );
		$cafs             = array_values( $cafs );
		$settings['cafs'] = $cafs;
		$caf_path         = $settings['caf_path'] ?? array();
		$tipo             = (int) ( $caf['tipo'] ?? 0 );
		if ( isset( $caf_path[ $tipo ] ) && $caf_path[ $tipo ] === $caf['path'] ) {
			unset( $caf_path[ $tipo ] );
			foreach ( $cafs as $c ) {
				if ( (int) $c['tipo'] === $tipo ) {
					$caf_path[ $tipo ] = $c['path'];
					break;
				}
			}
			$settings['caf_path'] = $caf_path;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( Settings::OPTION_NAME, $settings );
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
	 * @return array<int,array<string,mixed>>
	 */
	private function get_cafs(): array {
		$settings = $this->settings->get_settings();
		$cafs     = $settings['cafs'] ?? array();
		return is_array( $cafs ) ? $cafs : array();
	}
}

class_alias( CafPage::class, 'SII_Boleta_Caf_Page' );
