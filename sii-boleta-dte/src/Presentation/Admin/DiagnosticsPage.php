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
				$token      = $this->token_manager->get_token( 'boleta' );
				$messages[] = $token ? __( 'Token generated successfully.', 'sii-boleta-dte' ) : __( 'Token generation failed.', 'sii-boleta-dte' );
			} elseif ( 'api' === $action ) {
				$token      = $this->token_manager->get_token( 'boleta' );
				$result     = $this->api->get_dte_status( '0', 'boleta', $token );
				$messages[] = is_wp_error( $result ) ? __( 'API request failed.', 'sii-boleta-dte' ) : __( 'API request succeeded.', 'sii-boleta-dte' );
			}
		}
		$cfg       = $this->settings->get_settings();
		$cert_file = $cfg['cert_path'] ?? '';
		$cert_ok   = $cert_file && file_exists( $cert_file );
		echo '<div class="wrap"><h1>' . esc_html__( 'Diagnostics', 'sii-boleta-dte' ) . '</h1>';
		echo '<ul>';
		echo '<li>' . ( $cert_ok ? '&#10003;' : '&#10007;' ) . ' ' . esc_html__( 'Certificate file present', 'sii-boleta-dte' ) . '</li>';
		echo '</ul>';
		echo '<form method="post">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'sii_boleta_diag' );
		}
		echo '<p><button class="button" name="sii_boleta_diag" value="token">' . esc_html__( 'Test token generation', 'sii-boleta-dte' ) . '</button></p>';
		echo '<p><button class="button" name="sii_boleta_diag" value="api">' . esc_html__( 'Test API connection', 'sii-boleta-dte' ) . '</button></p>';
		foreach ( $messages as $msg ) {
			echo '<p>' . esc_html( $msg ) . '</p>';
		}
		echo '</form></div>';
	}
}

class_alias( DiagnosticsPage::class, 'SII_Boleta_Diagnostics_Page' );
