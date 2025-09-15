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
																												<td><input type="text" id="sii-razon" name="razon" required class="large-text" style="width:25em" /></td>
																								</tr>
																								<tr>
																												<th scope="row"><label for="sii-giro"><?php esc_html_e( 'Giro', 'sii-boleta-dte' ); ?></label></th>
																												<td><input type="text" id="sii-giro" name="giro" required class="regular-text" /></td>
																								</tr>
																								<tr>
																												<th scope="row"><label for="sii-items"><?php esc_html_e( 'Items', 'sii-boleta-dte' ); ?></label></th>
																												<td>
																																<table id="sii-items-table" class="widefat">
																																				<thead>
																																								<tr>
																																												<th><?php esc_html_e( 'Description', 'sii-boleta-dte' ); ?></th>
																																												<th><?php esc_html_e( 'Quantity', 'sii-boleta-dte' ); ?></th>
																																												<th><?php esc_html_e( 'Unit Price', 'sii-boleta-dte' ); ?></th>
																																												<th></th>
																																								</tr>
																																				</thead>
																																				<tbody>
																																								<tr>
																																												<td><input type="text" name="items[0][desc]" class="regular-text" /></td>
																																												<td><input type="number" name="items[0][qty]" value="1" step="0.01" /></td>
																																												<td><input type="number" name="items[0][price]" value="0" step="0.01" /></td>
																																												<td><button type="button" class="button remove-item">×</button></td>
																																								</tr>
																																				</tbody>
																																</table>
																																<p><button type="button" class="button" id="sii-add-item"><?php esc_html_e( 'Add Item', 'sii-boleta-dte' ); ?></button></p>
																												</td>
																								</tr>
																								<tr>
																												<th scope="row"><label for="sii-tipo"><?php esc_html_e( 'DTE Type', 'sii-boleta-dte' ); ?></label></th>
																												<td>
                                                               <select id="sii-tipo" name="tipo">
                                                               <option value="39"><?php esc_html_e( 'Boleta', 'sii-boleta-dte' ); ?></option>
                                                               <option value="41"><?php esc_html_e( 'Boleta Exenta', 'sii-boleta-dte' ); ?></option>
                                                               <option value="33"><?php esc_html_e( 'Factura', 'sii-boleta-dte' ); ?></option>
                                                               <option value="34"><?php esc_html_e( 'Factura Exenta', 'sii-boleta-dte' ); ?></option>
                                                               <option value="43"><?php esc_html_e( 'Liquidación de Factura', 'sii-boleta-dte' ); ?></option>
                                                               <option value="46"><?php esc_html_e( 'Factura de Compra', 'sii-boleta-dte' ); ?></option>
                                                               <option value="52"><?php esc_html_e( 'Guía de Despacho', 'sii-boleta-dte' ); ?></option>
                                                               <option value="56"><?php esc_html_e( 'Nota de Crédito', 'sii-boleta-dte' ); ?></option>
                                                               <option value="61"><?php esc_html_e( 'Nota de Débito', 'sii-boleta-dte' ); ?></option>
                                                               <option value="110"><?php esc_html_e( 'Factura de Exportación', 'sii-boleta-dte' ); ?></option>
                                                               <option value="111"><?php esc_html_e( 'Nota de Débito de Exportación', 'sii-boleta-dte' ); ?></option>
                                                               <option value="112"><?php esc_html_e( 'Nota de Crédito de Exportación', 'sii-boleta-dte' ); ?></option>
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
		$rut           = sanitize_text_field( (string) ( $post['rut'] ?? '' ) );
		$razon         = sanitize_text_field( (string) ( $post['razon'] ?? '' ) );
		$giro          = sanitize_text_field( (string) ( $post['giro'] ?? '' ) );
		$tipo          = (int) ( $post['tipo'] ?? 39 );
				$items = array();
				$n     = 1;
				$raw   = $post['items'] ?? array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
						$qty   = isset( $item['qty'] ) ? (float) $item['qty'] : 1.0;
						$desc  = sanitize_text_field( (string) ( $item['desc'] ?? '' ) );
						$price = isset( $item['price'] ) ? (int) round( (float) $item['price'] ) : 0;
				if ( '' === $desc ) {
					continue;
				}
						$items[] = array(
							'NroLinDet' => $n++,
							'NmbItem'   => $desc,
							'QtyItem'   => $qty,
							'PrcItem'   => $price,
							'IndExe'    => 0,
						);
			}
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
