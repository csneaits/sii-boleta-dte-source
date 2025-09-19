<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;

/**
 * Simple diagnostics page to verify requirements and test connectivity.
 */
class DiagnosticsPage {
	private Settings $settings;
	private TokenManager $token_manager;
	private Api $api;

	public function __construct( Settings $settings, TokenManager $token_manager, Api $api ) {
		$this->settings      = $settings;
		$this->token_manager = $token_manager;
		$this->api           = $api;
	}

	public function register(): void {
		if ( function_exists( 'add_submenu_page' ) ) {
			add_submenu_page(
				'sii-boleta-dte',
				__( 'Diagnostics', 'sii-boleta-dte' ),
				__( 'Diagnostics', 'sii-boleta-dte' ),
				'manage_options',
				'sii-boleta-dte-diagnostics',
				array( $this, 'render_page' )
			);
		}
	}

	public function render_page(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$messages = array();
		if ( isset( $_POST['sii_boleta_diag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( function_exists( 'check_admin_referer' ) ) {
				check_admin_referer( 'sii_boleta_diag' );
			}
			$action = sanitize_text_field( (string) $_POST['sii_boleta_diag'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'token' === $action ) {
				$token = $this->token_manager->get_token( 'boleta' );
				if ( is_wp_error( $token ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => sprintf(
							__( 'No se pudo generar el token: %s', 'sii-boleta-dte' ),
							$token->get_error_message()
						)
					);
				} elseif ( ! $token ) {
					$messages[] = array(
						'type' => 'error',
						'text' => __( 'No se pudo generar el token. El SII no devolvió credenciales.', 'sii-boleta-dte' ),
					);
				} else {
					$messages[] = array(
						'type' => 'success',
						'text' => __( 'Token generado correctamente. El plugin pudo autenticarse contra el SII.', 'sii-boleta-dte' ),
					);
				}
			} elseif ( 'api' === $action ) {
				$token  = $this->token_manager->get_token( 'boleta' );
				$result = is_wp_error( $token ) ? $token : $this->api->get_dte_status( '0', 'boleta', $token );
				if ( is_wp_error( $result ) ) {
					$error_text = $result->get_error_message();
					if ( strpos( $error_text, 'Could not resolve host' ) !== false ) {
						$error_text .= ' ' . __( 'Revisa el ambiente configurado: el host del SII parece incorrecto o no es alcanzable desde el servidor.', 'sii-boleta-dte' );
					}
					$error_data = $result->get_error_data();
					$raw_body   = '';
					if ( is_array( $error_data ) && ! empty( $error_data['body'] ) ) {
						$raw_body = (string) $error_data['body'];
					}
					$body_plain = '' !== $raw_body ? wp_strip_all_tags( $raw_body ) : '';
					if ( strpos( $body_plain, 'Transaccion Rechazada' ) !== false ) {
						$id = '';
						if ( preg_match( '/ID:\s*(\d+)/', $body_plain, $matches ) ) {
							$id = $matches[1];
						}
						$hint = __( 'El SII rechazó la transacción. Esto suele ocurrir cuando el contribuyente aún no se ha habilitado en certificación o la IP/certificado no está autorizado.', 'sii-boleta-dte' );
						if ( $id ) {
							$hint .= ' ' . sprintf( __( 'ID entregado por el SII: %s.', 'sii-boleta-dte' ), $id );
						}
						$error_text .= ' — ' . $hint;
					}
					$messages[] = array(
						'type' => 'error',
						'text' => sprintf(
							__( 'La consulta a la API falló: %s', 'sii-boleta-dte' ),
							$error_text
						),
						'dump' => $raw_body,
					);
				} else {
					$messages[] = array(
						'type' => 'success',
						'text' => __( 'Consulta a la API exitosa. El SII respondió al ping de estado.', 'sii-boleta-dte' ),
					);
				}
			}
		}
		$cfg       = $this->settings->get_settings();
		$cert_file = $cfg['cert_path'] ?? '';
		$cert_ok   = $cert_file && file_exists( $cert_file );
		$cafs     = array();
		if ( ! empty( $cfg['cafs'] ) && is_array( $cfg['cafs'] ) ) {
			$cafs = $cfg['cafs'];
		} elseif ( ! empty( $cfg['caf_path'] ) && is_array( $cfg['caf_path'] ) ) {
			foreach ( $cfg['caf_path'] as $tipo => $path ) {
				$cafs[] = array( 'tipo' => $tipo, 'path' => $path );
			}
		}
		$caf_count = 0;
		$caf_missing = array();
		foreach ( $cafs as $caf ) {
			$path = $caf['path'] ?? '';
			if ( $path && file_exists( $path ) ) {
				++$caf_count;
			} else {
				$caf_missing[] = $caf['tipo'] ?? '?';
			}
		}
		$environment = isset( $cfg['environment'] ) ? (string) $cfg['environment'] : 'test';
		echo '<div class="wrap"><h1>' . esc_html__( 'Diagnósticos', 'sii-boleta-dte' ) . '</h1>';
		echo '<p>' . esc_html__( 'Utiliza esta página para verificar rápidamente los archivos requeridos y la conectividad con el SII. Cada verificación es independiente para que identifiques el paso que está fallando.', 'sii-boleta-dte' ) . '</p>';
		echo '<h2>' . esc_html__( 'Resumen de configuración', 'sii-boleta-dte' ) . '</h2>';
		echo '<ul class="sii-boleta-diag-status">';
		echo '<li>' . ( $cert_ok ? '&#10003;' : '&#10007;' ) . ' ' . sprintf( esc_html__( 'Certificado: %s', 'sii-boleta-dte' ), esc_html( $cert_file ? $cert_file : __( 'sin configurar', 'sii-boleta-dte' ) ) ) . '</li>';
		echo '<li>' . ( $caf_count > 0 ? '&#10003;' : '&#10007;' ) . ' ' . sprintf( esc_html__( 'Archivos CAF detectados: %d', 'sii-boleta-dte' ), (int) $caf_count );
		if ( ! empty( $caf_missing ) ) {
			echo ' — ' . esc_html__( 'Faltan los tipos', 'sii-boleta-dte' ) . ' ' . esc_html( implode( ', ', array_map( 'strval', $caf_missing ) ) );
		}
		echo '</li>';
		echo '<li>' . '&#9432; ' . sprintf( esc_html__( 'Ambiente: %s', 'sii-boleta-dte' ), esc_html( $this->describe_environment( $environment ) ) ) . '</li>';
		echo '</ul>';
		echo '<h2>' . esc_html__( 'Pruebas de conectividad', 'sii-boleta-dte' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ejecuta las siguientes pruebas para confirmar que el plugin puede autenticarse y comunicarse con el SII. Puedes realizarlas las veces que necesites; los resultados se muestran debajo de cada botón.', 'sii-boleta-dte' ) . '</p>';
		echo '<form method="post">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'sii_boleta_diag' );
		}
		echo '<div class="sii-boleta-diag-action">';
		echo '<p><strong>' . esc_html__( 'Generación de token', 'sii-boleta-dte' ) . '</strong><br>' . esc_html__( 'Solicita un token nuevo utilizando el certificado digital y RUT configurados.', 'sii-boleta-dte' ) . '</p>';
		echo '<p><button class="button" name="sii_boleta_diag" value="token">' . esc_html__( 'Ejecutar prueba de token', 'sii-boleta-dte' ) . '</button></p>';
		echo '</div>';
		echo '<div class="sii-boleta-diag-action">';
		echo '<p><strong>' . esc_html__( 'Prueba de API del SII', 'sii-boleta-dte' ) . '</strong><br>' . esc_html__( 'Realiza una consulta de estado a un folio ficticio para comprobar la conectividad.', 'sii-boleta-dte' ) . '</p>';
		echo '<p><button class="button" name="sii_boleta_diag" value="api">' . esc_html__( 'Ejecutar prueba de API', 'sii-boleta-dte' ) . '</button></p>';
		echo '</div>';
		foreach ( $messages as $msg ) {
			$type = $msg['type'] ?? 'info';
			$text = $msg['text'] ?? '';
			if ( '' === $text ) {
				continue;
			}
			$class = 'notice-' . ( 'error' === $type ? 'error' : ( 'success' === $type ? 'success' : 'info' ) );
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $text ) . '</p>';
			if ( ! empty( $msg['dump'] ) ) {
				$dump = mb_substr( (string) $msg['dump'], 0, 4000 );
				echo '<pre class="sii-boleta-diag-dump" style="max-height:240px;overflow:auto;background:#f6f7f7;padding:10px;border:1px solid #ccd0d4;white-space:pre-wrap;">' . esc_html( $dump ) . '</pre>';
			}
			echo '</div>';
		}
		echo '</form></div>';
	}

	/**
	 * Traduce la clave del ambiente a una etiqueta legible.
	 */
	private function describe_environment( string $environment ): string {
		$environment = strtolower( trim( $environment ) );
		return match ( $environment ) {
			'prod', 'production', '1' => __( 'Producción', 'sii-boleta-dte' ),
			'test', 'certificacion', 'certification', '0' => __( 'Certificación', 'sii-boleta-dte' ),
			default => $environment !== '' ? $environment : __( 'desconocido', 'sii-boleta-dte' ),
		};
	}
}

class_alias( DiagnosticsPage::class, 'SII_Boleta_Diagnostics_Page' );
