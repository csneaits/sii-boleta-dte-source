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
                $modal_preview_url = '';
                if ( is_array( $result ) && ! empty( $result['preview'] ) ) {
                        $modal_preview_url = (string) ( $result['pdf_url'] ?? $result['pdf'] ?? '' );
                }
                ?>
                                <div class="wrap">
                                                <h1><?php esc_html_e( 'Generate DTE', 'sii-boleta-dte' ); ?></h1>
                                                <div id="sii-dte-modal" class="sii-dte-modal" style="display:none"<?php if ( ! empty( $modal_preview_url ) ) { echo ' data-preview-url="' . esc_attr( $modal_preview_url ) . '"'; } ?>>
                                                        <div class="sii-dte-modal-backdrop"></div>
                                                        <div class="sii-dte-modal-content">
                                                                <button type="button" class="sii-dte-modal-close">&times;</button>
                                                                <iframe id="sii-dte-modal-frame" src="" style="width:100%;height:100%;border:0"></iframe>
                                                        </div>
                                                </div>
                                                <style>
                                                .sii-dte-modal{position:fixed;inset:0;z-index:100000}
                                                .sii-dte-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.5)}
                                                .sii-dte-modal-content{position:absolute;top:5%;left:50%;transform:translateX(-50%);width:85%;height:85%;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.3);border-radius:6px;overflow:hidden}
                                                .sii-dte-modal-close{position:absolute;top:6px;right:10px;border:0;background:#f0f0f1;border-radius:3px;padding:2px 8px;cursor:pointer;z-index:1}
                                                body.sii-dte-modal-open{overflow:hidden}
                                                .sii-generate-actions{display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap}
                                                .sii-generate-actions .submit{margin:0}
                                                .sii-rut-invalid{border-color:#d63638 !important; box-shadow:0 0 0 1px rgba(214,54,56,0.3);}
                                                </style>
                                                <div id="sii-generate-dte-notices">
                                                <?php if ( is_array( $result ) && ! empty( $result['preview'] ) ) : ?>
                                                                <div class="notice notice-info"><p><?php esc_html_e( 'Preview generated. Review the document below.', 'sii-boleta-dte' ); ?>
                                                                <?php if ( ! empty( $modal_preview_url ) ) : ?> - <a target="_blank" rel="noopener" href="<?php echo esc_url( $modal_preview_url ); ?>"><?php esc_html_e( 'Open preview in a new tab', 'sii-boleta-dte' ); ?></a><?php endif; ?></p></div>
                                                                <?php if ( ! empty( $modal_preview_url ) ) : ?>
                                                                        <script>
                                                                        (function(){
                                                                                var url = <?php echo json_encode( (string) $modal_preview_url ); ?>;
                                                                                if(!url){return;}
                                                                                function trigger(){
                                                                                        if ( typeof window === 'undefined' ) { return; }
                                                                                        var evt;
                                                                                        try {
                                                                                                evt = new CustomEvent('sii-boleta-open-preview', { detail: { url: url } });
                                                                                        } catch (err) {
                                                                                                evt = document.createEvent('CustomEvent');
                                                                                                evt.initCustomEvent('sii-boleta-open-preview', false, false, { url: url });
                                                                                        }
                                                                                        window.dispatchEvent( evt );
                                                                                }
                                                                                if (document.readyState === 'loading') {
                                                                                        document.addEventListener('DOMContentLoaded', trigger);
                                                                                } else {
                                                                                        trigger();
                                                                                }
                                                                        })();
                                                                        </script>
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
                                                </div>
                                                <form method="post" id="sii-generate-dte-form">
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
                                                                        <td><input type="text" name="items[0][desc]" data-field="desc" class="regular-text" value="<?php echo $i0d; ?>" /></td>
                                                                        <td><input type="number" name="items[0][qty]" data-field="qty" value="<?php echo $i0q; ?>" step="0.01" /></td>
                                                                        <td><input type="number" name="items[0][price]" data-field="price" value="<?php echo $i0p; ?>" step="0.01" /></td>
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
                                                               <div class="sii-generate-actions">
                                                                       <?php submit_button( __( 'Preview', 'sii-boleta-dte' ), 'secondary', 'preview', false ); ?>
                                                                       <?php submit_button( __( 'Send to SII', 'sii-boleta-dte' ) ); ?>
                                                               </div>
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
                $rut_raw       = sanitize_text_field( (string) ( $post['rut'] ?? '' ) );
                $razon         = sanitize_text_field( (string) ( $post['razon'] ?? '' ) );
                $giro          = sanitize_text_field( (string) ( $post['giro'] ?? '' ) );
                $tipo          = (int) ( $post['tipo'] ?? ( array_key_first( $available ) ?? 39 ) );
                if ( ! isset( $available[ $tipo ] ) ) {
                        $tipo = (int) ( array_key_first( $available ) ?? 39 );
                }
                $requires_rut = ! in_array( $tipo, array( 39, 41 ), true );
                $rut          = '';
                $has_rut      = '' !== trim( $rut_raw );
                if ( $has_rut ) {
                        $normalized = $this->normalize_rut( $rut_raw );
                        if ( '' === $normalized || ! $this->is_valid_rut( $normalized ) ) {
                                return array( 'error' => __( 'El RUT ingresado no es válido.', 'sii-boleta-dte' ) );
                        }
                        $rut = $normalized;
                        $_POST['rut'] = $rut; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                } elseif ( $requires_rut ) {
                        return array( 'error' => __( 'El RUT del receptor es obligatorio para este tipo de documento.', 'sii-boleta-dte' ) );
                }
                $dir_recep     = sanitize_text_field( (string) ( $post['dir_recep'] ?? '' ) );
                $cmna_recep    = sanitize_text_field( (string) ( $post['cmna_recep'] ?? '' ) );
                $ciudad_recep  = sanitize_text_field( (string) ( $post['ciudad_recep'] ?? '' ) );
				$items = array();
				$n     = 1;
		$raw   = $post['items'] ?? array();
		if ( $preview ) {
			$this->debug_log( '[preview] raw items=' . print_r( $raw, true ) );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
						$qty   = isset( $item['qty'] ) ? $this->parse_amount( $item['qty'] ) : 1.0;
						$desc  = sanitize_text_field( (string) ( $item['desc'] ?? '' ) );
						if ( $desc !== '' ) {
							$desc = preg_replace( '/\s+\(([^()]+)\)\s+\(\1\)$/u', ' ($1)', $desc );
							$desc = preg_replace( '/\s{2,}/', ' ', (string) $desc );
							$desc = trim( (string) $desc );
						}
						$price = isset( $item['price'] ) ? (int) round( $this->parse_amount( $item['price'] ) ) : 0;
				if ( '' === $desc ) {
					continue;
				}
                                if ( $qty <= 0 ) {
                                        $qty = 1.0;
                                }
                                if ( $price < 0 ) {
                                        $price = 0;
                                }
                                $line_total = (int) round( $qty * $price );

                                $items[] = array(
                                        'NroLinDet' => $n++,
                                        'NmbItem'   => $desc,
                                        'QtyItem'   => $qty,
                                        'PrcItem'   => $price,
                                        'MontoItem' => $line_total,
                                        'IndExe'    => 0,
                                );
			}
		}
		if ( $preview ) {
			$this->debug_log( '[preview] parsed items=' . wp_json_encode( $items ) );
		}
        // If Boleta/Boleta Exenta without RUT, use generic SII rut
        if ( in_array( $tipo, array( 39, 41 ), true ) && '' === $rut ) {
                $rut = '66666666-6';
        }

        $folio = $preview ? 0 : $this->folio_manager->get_next_folio( $tipo );

        $data = array(
                'Folio'    => $folio,
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
        $settings_cfg = $this->settings->get_settings();

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
                $pdf_label = $available[ $tipo ] ?? sprintf( 'DTE %d', $tipo );
                $pdf_url   = $this->store_preview_pdf(
                        $pdf,
                        array(
                                'label'        => $pdf_label,
                                'type'         => $tipo,
                                'folio'        => $folio,
                                'rut_emisor'   => (string) ( $settings_cfg['rut_emisor'] ?? '' ),
                                'rut_receptor' => $rut,
                        )
                );
                if ( $preview ) {
                        return array(
                                'preview' => true,
                                'pdf'     => $pdf,      // keep raw for tests
                                'pdf_url' => $pdf_url,  // public URL for iframe
                        );
                }

                $file = tempnam( sys_get_temp_dir(), 'dte' );
                file_put_contents( $file, (string) $xml );
                $env   = (string) ( $settings_cfg['environment'] ?? 'test' );
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
         *
         * @param array<string,mixed> $context Metadata used to build a friendly filename.
         */
        private function store_preview_pdf( string $path, array $context = array() ): string {
                if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
                        return '';
                }
                $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => sys_get_temp_dir(), 'baseurl' => '' );
                $base    = rtrim( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ), '/\\' );
                $dir     = $base . '/sii-boleta-dte/previews';
                if ( function_exists( 'wp_mkdir_p' ) ) {
                        wp_mkdir_p( $dir );
                } elseif ( ! is_dir( $dir ) ) {
                        @mkdir( $dir, 0755, true );
                }
                $filename = $this->build_pdf_filename( $context );
                $extension = pathinfo( $filename, PATHINFO_EXTENSION );
                $name_only = pathinfo( $filename, PATHINFO_FILENAME );
                if ( '' === (string) $name_only ) {
                        $name_only = 'dte';
                }
                if ( '' === (string) $extension ) {
                        $extension = 'pdf';
                        $filename  = $name_only . '.pdf';
                }
                $dest    = $dir . '/' . $filename;
                $counter = 2;
                while ( file_exists( $dest ) ) {
                        $dest = $dir . '/' . $name_only . '-' . $counter . '.' . $extension;
                        ++$counter;
                }
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
         * Builds a descriptive filename for a generated PDF.
         *
         * @param array<string,mixed> $context
         */
        private function build_pdf_filename( array $context ): string {
                $label        = isset( $context['label'] ) ? (string) $context['label'] : '';
                $type         = isset( $context['type'] ) ? (int) $context['type'] : 0;
                $folio        = isset( $context['folio'] ) ? (int) $context['folio'] : 0;
                $rut_emisor   = isset( $context['rut_emisor'] ) ? (string) $context['rut_emisor'] : '';
                $rut_receptor = isset( $context['rut_receptor'] ) ? (string) $context['rut_receptor'] : '';

                if ( '' === $label && $type > 0 ) {
                        $label = sprintf( 'DTE %d', $type );
                }

                $parts = array();
                if ( '' !== trim( $label ) ) {
                        $parts[] = trim( $label );
                }
                if ( $folio >= 0 ) {
                        $parts[] = 'N' . $folio;
                }
                $rut_target = '' !== $rut_emisor ? $rut_emisor : $rut_receptor;
                if ( '' !== $rut_target ) {
                        $parts[] = $rut_target;
                }
                if ( empty( $parts ) ) {
                        $parts[] = 'dte';
                }

                $slug = $this->slugify_filename( implode( '-', $parts ) );
                if ( '' === $slug ) {
                        $slug = 'dte';
                }

                return $slug . '.pdf';
        }

        /**
         * Normalizes a filename string into a safe slug.
         */
        private function slugify_filename( string $text ): string {
                $text = strip_tags( $text );
                if ( function_exists( 'remove_accents' ) ) {
                        $text = remove_accents( $text );
                }
                $text = str_replace( array( '.', ',', ';', ':', "'", '"' ), '', $text );
                $text = str_replace( array( '/', '\\', '|' ), '-', $text );
                $text = preg_replace( '/[^A-Za-z0-9\-]+/', '-', $text );
                $text = trim( (string) $text, '-' );
                $text = preg_replace( '/-+/', '-', $text );

                if ( function_exists( 'mb_strtolower' ) ) {
                        return (string) mb_strtolower( (string) $text, 'UTF-8' );
                }

                return strtolower( (string) $text );
        }

	/**
	 * Escribe mensajes de depuración en uploads/sii-boleta-logs/.
	 */
	private function debug_log( string $message ): void {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
			? $uploads['basedir']
			: ( defined( 'ABSPATH' ) ? ABSPATH : sys_get_temp_dir() );
		$dir = rtrim( (string) $base, '/\\' ) . '/sii-boleta-logs';
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} elseif ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		$file = $dir . '/debug.log';
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;
		@file_put_contents( $file, $line, FILE_APPEND );
	}

	/**
	 * Normaliza números ingresados con separadores locales (puntos miles, comas decimales).
	 */
	private function parse_amount( $value ): float {
                if ( is_string( $value ) ) {
                        $value = str_replace( array( ' ', "\xC2\xA0" ), '', $value );
                        if ( '' === $value ) {
                                return 0.0;
                        }

                        if ( str_contains( $value, ',' ) ) {
                                $value = str_replace( '.', '', $value );
                                $value = str_replace( ',', '.', $value );
                        } else {
                                if ( substr_count( $value, '.' ) > 1 ) {
                                        $value = str_replace( '.', '', $value );
                                } elseif ( false !== ( $dot = strpos( $value, '.' ) ) ) {
                                        $fraction = substr( $value, $dot + 1 );
                                        if ( ctype_digit( $fraction ) && strlen( $fraction ) === 3 && $dot <= 3 ) {
                                                $value = str_replace( '.', '', $value );
                                        }
                                }
                        }
                }

                return is_numeric( $value ) ? (float) $value : 0.0;
        }

        /**
         * Limpia y formatea un RUT, devolviendo la parte numérica y el dígito verificador separados por guion.
         */
        private function normalize_rut( string $rut ): string {
                $rut = strtoupper( trim( $rut ) );
                if ( '' === $rut ) {
                        return '';
                }

                // Sólo permitir números, K, puntos y guion.
                if ( preg_match( '/[^0-9K\.\-]/', $rut ) ) {
                        return '';
                }

                $rut = str_replace( array( '.', '-' ), '', $rut );
                if ( strlen( $rut ) < 2 ) {
                        return '';
                }

                $body = substr( $rut, 0, -1 );
                $dv   = substr( $rut, -1 );

                if ( ! ctype_digit( $body ) ) {
                        return '';
                }

                $body = ltrim( $body, '0' );
                if ( '' === $body ) {
                        $body = '0';
                }

                return $body . '-' . $dv;
        }

        /**
         * Revisa el dígito verificador del RUT.
         */
        private function is_valid_rut( string $rut ): bool {
                $rut = $this->normalize_rut( $rut );
                if ( '' === $rut ) {
                        return false;
                }

                list( $body, $dv ) = explode( '-', $rut );
                $dv = strtoupper( $dv );

                $sum    = 0;
                $factor = 2;
                for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
                        $sum    += (int) $body[ $i ] * $factor;
                        $factor  = ( $factor === 7 ) ? 2 : $factor + 1;
                }

                $mod = 11 - ( $sum % 11 );
                if ( 11 === $mod ) {
                        $expected = '0';
                } elseif ( 10 === $mod ) {
                        $expected = 'K';
                } else {
                        $expected = (string) $mod;
                }

                return $expected === $dv;
        }

       /**
        * Returns the list of available DTE types based on the presence of
        * fixtures under resources/yaml/documentos_ok/.
         *
         * @return array<int,string> code => label
         */
        private function get_available_types(): array {
               $root = dirname( __DIR__, 3 ) . '/resources/yaml/documentos_ok/';
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
