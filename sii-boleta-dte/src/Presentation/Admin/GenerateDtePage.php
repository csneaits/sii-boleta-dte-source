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
                // Persist selected type and basic fields on reload
                $available     = $this->get_available_types();
                $default_tipo  = (int) ( array_key_first( $available ) ?? 39 );
                $sel_tipo      = isset( $_POST['tipo'] ) ? (int) $_POST['tipo'] : $default_tipo; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( ! isset( $available[ $sel_tipo ] ) ) { $sel_tipo = $default_tipo; }
                $val = static function( string $k ): string {
                        return isset( $_POST[ $k ] ) ? esc_attr( (string) $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                };
                $item0 = isset( $_POST['items'][0] ) && is_array( $_POST['items'][0] ) ? (array) $_POST['items'][0] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $i0d   = isset( $item0['desc'] ) ? esc_attr( (string) $item0['desc'] ) : '';
                $i0q   = isset( $item0['qty'] ) ? esc_attr( (string) $item0['qty'] ) : '1';
                $i0p   = isset( $item0['price'] ) ? esc_attr( (string) $item0['price'] ) : '0';
                ?>
                                <div class="wrap">
                                                <h1><?php esc_html_e( 'Generate DTE', 'sii-boleta-dte' ); ?></h1>
                                                <?php if ( is_array( $result ) && ! empty( $result['preview'] ) ) : ?>
                                                                <div class="notice notice-info"><p><?php esc_html_e( 'Preview generated. Review the document below.', 'sii-boleta-dte' ); ?></p></div>
                                                                <?php
                                                                $pv_url = (string) ( $result['pdf_url'] ?? $result['pdf'] ?? '' );
                                                                if ( ! empty( $pv_url ) ) : ?>
                                                                                <iframe src="<?php echo esc_url( $pv_url ); ?>" style="width:100%;height:600px"></iframe>
                                                                <?php endif; ?>
                                                <?php elseif ( is_array( $result ) && empty( $result['error'] ) ) : ?>
                                                                <div class="updated notice"><p>
                                                                                <?php printf( esc_html__( 'Track ID: %s', 'sii-boleta-dte' ), esc_html( (string) $result['track_id'] ) ); ?>
                                                                                <?php
                                                                                $dl_url = (string) ( $result['pdf_url'] ?? '' );
                                                                                if ( ! empty( $dl_url ) ) : ?>
                                                                                                - <a href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download PDF', 'sii-boleta-dte' ); ?></a>
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
                                                        <th scope="row"><label for="sii-tipo"><?php esc_html_e( 'Tipo de documento', 'sii-boleta-dte' ); ?></label></th>
                                                        <td>
                                                            <select id="sii-tipo" name="tipo">
                                                                <?php foreach ( $available as $code => $label ) : ?>
                                                                    <option value="<?php echo (int) $code; ?>"<?php echo selected( $sel_tipo, (int) $code, false ); ?>><?php echo esc_html( $label ); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <th scope="row"><label for="sii-rut"><?php esc_html_e( 'Customer RUT', 'sii-boleta-dte' ); ?></label></th>
                                                        <td><input type="text" id="sii-rut" name="rut" required class="regular-text" value="<?php echo $val('rut'); ?>" /></td>
                                                    </tr>
                                                    <tr>
                                                        <th scope="row"><label for="sii-razon"><?php esc_html_e( 'Razón Social', 'sii-boleta-dte' ); ?></label></th>
                                                        <td><input type="text" id="sii-razon" name="razon" required class="large-text" style="width:25em" value="<?php echo $val('razon'); ?>" /></td>
                                                    </tr>
                                                    <tr class="dte-section" data-types="33,34,43,46,52,56,61,110,111,112" style="display:none">
                                                        <th scope="row"><label for="sii-giro" id="label-giro"><?php esc_html_e( 'Giro', 'sii-boleta-dte' ); ?></label></th>
                                                        <td><input type="text" id="sii-giro" name="giro" class="regular-text" value="<?php echo $val('giro'); ?>" /></td>
                                                    </tr>
                                                    <!-- Receptor address for invoice/guide types -->
                                                    <tr class="dte-section" data-types="33,34,43,46,52,110" style="display:none">
                                                        <th scope="row"><label for="sii-dir-recep"><?php esc_html_e( 'Dirección Receptor', 'sii-boleta-dte' ); ?></label></th>
                                                        <td><input type="text" id="sii-dir-recep" name="dir_recep" class="regular-text" value="<?php echo $val('dir_recep'); ?>" /></td>
                                                    </tr>
                                                    <tr class="dte-section" data-types="33,34,43,46,52,110" style="display:none">
                                                        <th scope="row"><label for="sii-cmna-recep"><?php esc_html_e( 'Comuna Receptor', 'sii-boleta-dte' ); ?></label></th>
                                                        <td><input type="text" id="sii-cmna-recep" name="cmna_recep" class="regular-text" value="<?php echo $val('cmna_recep'); ?>" /></td>
                                                    </tr>
                                                    <tr class="dte-section" data-types="33,34,43,46,52,110" style="display:none">
                                                        <th scope="row"><label for="sii-ciudad-recep"><?php esc_html_e( 'Ciudad Receptor', 'sii-boleta-dte' ); ?></label></th>
                                                        <td><input type="text" id="sii-ciudad-recep" name="ciudad_recep" class="regular-text" value="<?php echo $val('ciudad_recep'); ?>" /></td>
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
                                                                        <td><input type="text" name="items[0][desc]" class="regular-text" value="<?php echo $i0d; ?>" /></td>
                                                                        <td><input type="number" name="items[0][qty]" value="<?php echo $i0q; ?>" step="0.01" /></td>
                                                                        <td><input type="number" name="items[0][price]" value="<?php echo $i0p; ?>" step="0.01" /></td>
                                                                        <td><button type="button" class="button remove-item">×</button></td>
                                                                    </tr>
                                                                </tbody>
                                                            </table>
                                                            <p><button type="button" class="button" id="sii-add-item"><?php esc_html_e( 'Add Item', 'sii-boleta-dte' ); ?></button></p>
                                                        </td>
                                                    </tr>
                                                    <!-- Reference section for credit/debit notes -->
                                                    <tr class="dte-section" data-types="56,61,111,112" style="display:none">
                                                        <th scope="row"><label for="sii-ref-folio"><?php esc_html_e( 'Referencia', 'sii-boleta-dte' ); ?></label></th>
                                                        <td>
                                                            <label><?php esc_html_e( 'Tipo Doc Ref', 'sii-boleta-dte' ); ?>
                                                                <select name="ref_tipo">
                                                                    <option value="33">Factura</option>
                                                                    <option value="34">Factura Exenta</option>
                                                                    <option value="39">Boleta</option>
                                                                    <option value="41">Boleta Exenta</option>
                                                                    <option value="52">Guía</option>
                                                                </select>
                                                            </label>
                                                            &nbsp; 
                                                            <label><?php esc_html_e( 'Folio', 'sii-boleta-dte' ); ?>
                                                                <input type="number" name="ref_folio" id="sii-ref-folio" min="1" step="1" value="<?php echo $val('ref_folio'); ?>" />
                                                            </label>
                                                            &nbsp; 
                                                            <label><?php esc_html_e( 'Fecha', 'sii-boleta-dte' ); ?>
                                                                <input type="date" name="ref_fecha" value="<?php echo $val('ref_fecha'); ?>" />
                                                            </label>
                                                            &nbsp; 
                                                            <label><?php esc_html_e( 'Razón', 'sii-boleta-dte' ); ?>
                                                                <input type="text" name="ref_razon" class="regular-text" placeholder="Anula/rebaja, etc." value="<?php echo $val('ref_razon'); ?>" />
                                                            </label>
                                                        </td>
                                                    </tr>
                                                               </tbody>
                                                                                                                               </table>
                                                                                                                               <?php submit_button( __( 'Preview', 'sii-boleta-dte' ), 'secondary', 'preview', false ); ?>
                                                                                                                               <?php submit_button( __( 'Send to SII', 'sii-boleta-dte' ) ); ?>
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
                $preview       = isset( $post['preview'] );
                $available     = $this->get_available_types();
                $rut           = sanitize_text_field( (string) ( $post['rut'] ?? '' ) );
                $razon         = sanitize_text_field( (string) ( $post['razon'] ?? '' ) );
                $giro          = sanitize_text_field( (string) ( $post['giro'] ?? '' ) );
                $tipo          = (int) ( $post['tipo'] ?? ( array_key_first( $available ) ?? 39 ) );
                if ( ! isset( $available[ $tipo ] ) ) {
                        $tipo = (int) ( array_key_first( $available ) ?? 39 );
                }
                $dir_recep     = sanitize_text_field( (string) ( $post['dir_recep'] ?? '' ) );
                $cmna_recep    = sanitize_text_field( (string) ( $post['cmna_recep'] ?? '' ) );
                $ciudad_recep  = sanitize_text_field( (string) ( $post['ciudad_recep'] ?? '' ) );
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
        // If Boleta/Boleta Exenta without RUT, use generic SII rut
        if ( in_array( $tipo, array( 39, 41 ), true ) && '' === $rut ) {
                $rut = '66666666-6';
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
        if ( '' !== $dir_recep ) { $data['Receptor']['DirRecep'] = $dir_recep; }
        if ( '' !== $cmna_recep ) { $data['Receptor']['CmnaRecep'] = $cmna_recep; }
        if ( '' !== $ciudad_recep ) { $data['Receptor']['CiudadRecep'] = $ciudad_recep; }

        // Reference data for credit/debit notes
        if ( in_array( $tipo, array(56, 61, 111, 112), true ) ) {
                $ref_tipo  = (int) ( $post['ref_tipo'] ?? 0 );
                $ref_folio = trim( (string) ( $post['ref_folio'] ?? '' ) );
                $ref_fecha = trim( (string) ( $post['ref_fecha'] ?? '' ) );
                $ref_razon = sanitize_text_field( (string) ( $post['ref_razon'] ?? '' ) );
                if ( $ref_folio !== '' ) {
                        $ref = array( 'TpoDocRef' => $ref_tipo ?: 39, 'FolioRef' => $ref_folio );
                        if ( $ref_fecha !== '' ) { $ref['FchRef'] = $ref_fecha; }
                        if ( $ref_razon !== '' ) { $ref['RazonRef'] = $ref_razon; }
                        $data['Referencias'] = array( $ref );
                }
        }
                $xml  = $this->engine->generate_dte_xml( $data, $tipo, $preview );
                if ( is_wp_error( $xml ) ) {
                        $code = method_exists( $xml, 'get_error_code' ) ? $xml->get_error_code() : '';
                        $msg  = $xml->get_error_message();
                        if ( 'sii_boleta_missing_caf' === $code ) {
                                // Mensaje más claro para el usuario final
                                $labels = $this->get_available_types();
                                $tipo_label = $labels[ $tipo ] ?? (string) $tipo;
                                $msg = sprintf( __( 'No hay un CAF configurado para el tipo %s. Sube un CAF en “Folios / CAFs”.', 'sii-boleta-dte' ), $tipo_label );
                        }
                        return array( 'error' => $msg );
                }
                $pdf = $this->pdf->generate( (string) $xml );
                $pdf_url = $this->store_preview_pdf( $pdf );
                if ( $preview ) {
                        return array(
                                'preview' => true,
                                'pdf'     => $pdf,      // keep raw for tests
                                'pdf_url' => $pdf_url,  // public URL for iframe
                        );
                }
                $file = tempnam( sys_get_temp_dir(), 'dte' );
                file_put_contents( $file, (string) $xml );
                $cfg   = $this->settings->get_settings();
                $env   = (string) ( $cfg['environment'] ?? 'test' );
                $token = $this->token_manager->get_token( $env );
                $track = $this->api->send_dte_to_sii( $file, $env, $token );
                return array(
                        'track_id' => $track,
                        'pdf'      => $pdf,      // keep raw path for tests
                        'pdf_url'  => $pdf_url,  // public URL for link
                );
        }

        /**
         * Moves the generated PDF to uploads so it can be displayed via URL.
         * Returns a public URL or empty string on failure.
         */
        private function store_preview_pdf( string $path ): string {
                if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
                        return '';
                }
                $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => sys_get_temp_dir(), 'baseurl' => '' );
                $base    = rtrim( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ), '/\\' );
                $dir     = $base . '/sii-boleta-dte/previews';
                if ( function_exists( 'wp_mkdir_p' ) ) { wp_mkdir_p( $dir ); } else { if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0755, true ); } }
                $suffix = function_exists( 'wp_generate_password' ) ? wp_generate_password( 6, false ) : substr( md5( microtime( true ) ), 0, 6 );
                $dest = $dir . '/preview-' . time() . '-' . $suffix . '.pdf';
                if ( ! @copy( $path, $dest ) ) {
                        return '';
                }
                @chmod( $dest, 0644 );
                // Build an admin-ajax powered viewer URL so proxies (Cloudflare) or theme routing don't interfere.
                if ( function_exists( 'admin_url' ) ) {
                        $nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'sii_boleta_nonce' ) : '';
                        $url   = add_query_arg(
                                array(
                                        'action'   => 'sii_boleta_dte_view_pdf',
                                        'key'      => basename( $dest ),
                                        '_wpnonce' => $nonce,
                                ),
                                admin_url( 'admin-ajax.php' )
                        );
                        return $url;
                }
                return '';
        }

        /**
         * Returns the list of available DTE types based on the presence of
         * fixtures under resources/yaml/documentos_ok copy/.
         *
         * @return array<int,string> code => label
         */
        private function get_available_types(): array {
                $root = dirname( __DIR__, 3 ) . '/resources/yaml/documentos_ok copy/';
                $codes = array();
                if ( is_dir( $root ) ) {
                        foreach ( glob( $root . '*' ) as $dir ) {
                                if ( is_dir( $dir ) ) {
                                        if ( preg_match( '/(\d{3})_/', basename( $dir ), $m ) ) {
                                                $codes[(int) $m[1]] = true;
                                        }
                                }
                        }
                }
                // Map to human labels, keep only codes found.
                $labels = array(
                        33  => __( 'Factura', 'sii-boleta-dte' ),
                        34  => __( 'Factura Exenta', 'sii-boleta-dte' ),
                        39  => __( 'Boleta', 'sii-boleta-dte' ),
                        41  => __( 'Boleta Exenta', 'sii-boleta-dte' ),
                        43  => __( 'Liquidación de Factura', 'sii-boleta-dte' ),
                        46  => __( 'Factura de Compra', 'sii-boleta-dte' ),
                        52  => __( 'Guía de Despacho', 'sii-boleta-dte' ),
                        56  => __( 'Nota de Débito', 'sii-boleta-dte' ),
                        61  => __( 'Nota de Crédito', 'sii-boleta-dte' ),
                        110 => __( 'Factura de Exportación', 'sii-boleta-dte' ),
                        111 => __( 'Nota de Débito de Exportación', 'sii-boleta-dte' ),
                        112 => __( 'Nota de Crédito de Exportación', 'sii-boleta-dte' ),
                );
                $out = array();
                // Filtrar además por existencia de CAF válido para el tipo
                $settings = $this->settings->get_settings();
                foreach ( $labels as $code => $name ) {
                        if ( ! isset( $codes[ $code ] ) ) { continue; }
                        $caf_ok = false;
                        if ( ! empty( $settings['cafs'] ) && is_array( $settings['cafs'] ) ) {
                                foreach ( $settings['cafs'] as $caf ) {
                                        if ( (int) ( $caf['tipo'] ?? 0 ) === (int) $code && ! empty( $caf['path'] ) && file_exists( (string) $caf['path'] ) ) { $caf_ok = true; break; }
                                }
                        }
                        if ( ! $caf_ok && isset( $settings['caf_path'][ $code ] ) && file_exists( (string) $settings['caf_path'][ $code ] ) ) { $caf_ok = true; }
                        if ( $caf_ok ) { $out[ $code ] = $name; }
                }
                // Ensure deterministic order by code
                ksort( $out );
                // Fallback to basic Boleta if nothing found
                if ( empty( $out ) ) {
                        $out = array( 39 => __( 'Boleta', 'sii-boleta-dte' ) );
                }
                return $out;
        }
}

class_alias( GenerateDtePage::class, 'SII_Boleta_Generate_Dte_Page' );
