<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Application\FolioManager;

/**
 * Admin page that allows manually generating a DTE without WooCommerce orders.
 */
class GenerateDtePage {
	private Settings $settings;
	private TokenManager $token_manager;
	private Api $api;
	private DteEngine $engine;
	private PdfGenerator $pdf;
	private FolioManager $folio_manager;

	public function __construct( Settings $settings, TokenManager $token_manager, Api $api, DteEngine $engine, PdfGenerator $pdf, FolioManager $folio_manager ) {
		$this->settings      = $settings;
		$this->token_manager = $token_manager;
		$this->api           = $api;
		$this->engine        = $engine;
		$this->pdf           = $pdf;
		$this->folio_manager = $folio_manager;
	}

	/** Registers the submenu page. */
	public function register(): void {
		\add_submenu_page(
			'sii-boleta-dte',
			\__( 'Generate DTE', 'sii-boleta-dte' ),
			\__( 'Generate DTE', 'sii-boleta-dte' ),
			'manage_options',
			'sii-boleta-dte-generate',
			array( $this, 'render_page' )
		);
	}

	/** Outputs the page markup. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				$result = $this->process_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		?>
				<div class="wrap">
						<h1><?php esc_html_e( 'Generate DTE', 'sii-boleta-dte' ); ?></h1>
						<?php if ( is_array( $result ) && empty( $result['error'] ) ) : ?>
								<div class="updated notice"><p>
										<?php printf( esc_html__( 'Track ID: %s', 'sii-boleta-dte' ), esc_html( (string) $result['track_id'] ) ); ?>
										<?php if ( ! empty( $result['pdf'] ) ) : ?>
												- <a href="<?php echo esc_url( (string) $result['pdf'] ); ?>"><?php esc_html_e( 'Download PDF', 'sii-boleta-dte' ); ?></a>
										<?php endif; ?>
								</p></div>
						<?php elseif ( is_array( $result ) && ! empty( $result['error'] ) ) : ?>
								<div class="error notice"><p><?php echo esc_html( (string) $result['error'] ); ?></p></div>
						<?php endif; ?>
						<form method="post">
								<?php wp_nonce_field( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' ); ?>
								<table class="form-table" role="presentation">
										<tbody>
												<tr>
														<th scope="row"><label for="sii-rut"><?php esc_html_e( 'Customer RUT', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-rut" name="rut" required class="regular-text" /></td>
												</tr>
												<tr>
														<th scope="row"><label for="sii-razon"><?php esc_html_e( 'Razón Social', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-razon" name="razon" required class="regular-text" /></td>
												</tr>
												<tr>
														<th scope="row"><label for="sii-giro"><?php esc_html_e( 'Giro', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-giro" name="giro" required class="regular-text" /></td>
												</tr>
												<tr>
														<th scope="row"><label for="sii-items"><?php esc_html_e( 'Items', 'sii-boleta-dte' ); ?></label></th>
														<td>
																<textarea id="sii-items" name="items" rows="5" cols="60" class="large-text code"></textarea>
																<p class="description"><?php esc_html_e( 'One per line: qty|description|price|taxable', 'sii-boleta-dte' ); ?></p>
														</td>
												</tr>
												<tr>
														<th scope="row"><label for="sii-tipo"><?php esc_html_e( 'DTE Type', 'sii-boleta-dte' ); ?></label></th>
														<td>
																<select id="sii-tipo" name="tipo">
																		<option value="39"><?php esc_html_e( 'Boleta', 'sii-boleta-dte' ); ?></option>
																		<option value="33"><?php esc_html_e( 'Factura', 'sii-boleta-dte' ); ?></option>
																		<option value="34"><?php esc_html_e( 'Factura Exenta', 'sii-boleta-dte' ); ?></option>
																		<option value="52"><?php esc_html_e( 'Guía de Despacho', 'sii-boleta-dte' ); ?></option>
																</select>
														</td>
												</tr>
										</tbody>
								</table>
								<?php submit_button( __( 'Generate', 'sii-boleta-dte' ) ); ?>
						</form>
				</div>
				<?php
	}

	/**
	 * Processes form submission and returns result data.
	 *
	 * @param array<string,mixed> $post Raw POST data.
	 * @return array<string,mixed>
	 */
	public function process_post( array $post ): array {
		if ( empty( $post['sii_boleta_generate_dte_nonce'] ) || ! \wp_verify_nonce( $post['sii_boleta_generate_dte_nonce'], 'sii_boleta_generate_dte' ) ) {
			return array( 'error' => \__( 'Invalid nonce.', 'sii-boleta-dte' ) );
		}
		$rut   = sanitize_text_field( (string) ( $post['rut'] ?? '' ) );
		$razon = sanitize_text_field( (string) ( $post['razon'] ?? '' ) );
		$giro  = sanitize_text_field( (string) ( $post['giro'] ?? '' ) );
		$tipo  = (int) ( $post['tipo'] ?? 39 );
		$items = array();
		$lines = preg_split( '/\r?\n/', (string) ( $post['items'] ?? '' ) );
		$n     = 1;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( '|', $line ) );
			$qty     = isset( $parts[0] ) ? (float) $parts[0] : 1.0;
			$desc    = $parts[1] ?? '';
			$price   = isset( $parts[2] ) ? (int) round( (float) $parts[2] ) : 0;
			$tax     = ! empty( $parts[3] ) ? (int) $parts[3] : 1;
			$items[] = array(
				'NroLinDet' => $n++,
				'NmbItem'   => $desc,
				'QtyItem'   => $qty,
				'PrcItem'   => $price,
				'IndExe'    => $tax ? 0 : 1,
			);
		}
		$data = array(
			'Folio'    => $this->folio_manager->get_next_folio( $tipo ),
			'FchEmis'  => gmdate( 'Y-m-d' ),
			'Receptor' => array(
				'RUTRecep'    => $rut,
				'RznSocRecep' => $razon,
				'GiroRecep'   => $giro,
			),
			'Detalles' => $items,
		);
		$xml  = $this->engine->generate_dte_xml( $data, $tipo );
		if ( is_wp_error( $xml ) ) {
			return array( 'error' => $xml->get_error_message() );
		}
		$file = tempnam( sys_get_temp_dir(), 'dte' );
		file_put_contents( $file, (string) $xml );
		$cfg   = $this->settings->get_settings();
		$env   = (string) ( $cfg['environment'] ?? 'test' );
		$token = $this->token_manager->get_token( $env );
		$track = $this->api->send_dte_to_sii( $file, $env, $token );
		$pdf   = $this->pdf->generate( (string) $xml );
		return array(
			'track_id' => $track,
			'pdf'      => $pdf,
		);
	}
}

class_alias( GenerateDtePage::class, 'SII_Boleta_Generate_Dte_Page' );
