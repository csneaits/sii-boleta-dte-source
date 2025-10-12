<?php
namespace Sii\BoletaDte\Presentation\Admin;

// Fallback stubs for WordPress helper functions when static analysis or tests run
// outside a full WP context. These are lightweight and defer to global WP
// implementations when available.
if ( ! function_exists( __NAMESPACE__ . '\sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'sanitize_text_field' ) ) {
			return call_user_func( 'sanitize_text_field', $str );
		}
		return is_string( $str ) ? trim( preg_replace( '/[\r\n\t]+/', ' ', $str ) ) : '';
	}
}
if ( ! function_exists( __NAMESPACE__ . '\sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			return call_user_func( 'sanitize_textarea_field', $str );
		}
		return is_string( $str ) ? trim( $str ) : '';
	}
}
if ( ! function_exists( __NAMESPACE__ . '\sanitize_email' ) ) {
	function sanitize_email( $str ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'sanitize_email' ) ) {
			return call_user_func( 'sanitize_email', $str );
		}
		$str = is_string( $str ) ? trim( $str ) : '';
		return filter_var( $str, FILTER_VALIDATE_EMAIL ) ? $str : '';
	}
}
if ( ! function_exists( __NAMESPACE__ . '\sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'sanitize_key' ) ) {
			return call_user_func( 'sanitize_key', $key );
		}
		$key = is_string( $key ) ? strtolower( $key ) : '';
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( __NAMESPACE__ . '\checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'checked' ) ) {
			return call_user_func( 'checked', $checked, $current, $echo );
		}
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $echo ) { echo $result; }
		return $result;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\remove_accents' ) ) {
	function remove_accents( $string ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'remove_accents' ) ) {
			return call_user_func( 'remove_accents', $string );
		}
		return $string;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\wc_prices_include_tax' ) ) {
	function wc_prices_include_tax() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( function_exists( 'wc_prices_include_tax' ) ) {
			return (bool) call_user_func( 'wc_prices_include_tax' );
		}
		return false;
	}
}

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Queue\XmlStorage;
use Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage;
use Sii\BoletaDte\Infrastructure\Certification\ProgressTracker;

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
	private Queue $queue;

	/**
	 * Cache en memoria de datos de preview por tipo para comparación posterior.
	 * @var array<int,array{hash:string,normalized:string}>
	 */
	private static array $xml_preview_hash_cache = array();

    private const PREVIEW_TRANSIENT_PREFIX = 'sii_boleta_preview_';
    private const PREVIEW_TTL              = 900;
    private const MANUAL_TRANSIENT_PREFIX  = 'sii_boleta_manual_pdf_';
    private const MANUAL_TTL               = 86400; // 24 hours for manual downloads

	/**
	 * Supported motives for credit notes (type 61) mapped to SII CodRef values.
	 *
	 * @var array<string,string>
	 */
	private const CREDIT_NOTE_MOTIVES = array(
		'anula'  => '1',
		'texto'  => '2',
		'montos' => '3',
	);

    /**
     * @var array<string,array{path:string,expires:int}>
     */
    private static array $preview_cache = array();

    /**
     * @var array<string,array{path:string,token:string,filename:string,expires:int}>
     */
    private static array $manual_cache = array();

	public function __construct( Settings $settings, TokenManager $token_manager, Api $api, DteEngine $engine, PdfGenerator $pdf, FolioManager $folio_manager, Queue $queue ) {
			$this->settings      = $settings;
			$this->token_manager = $token_manager;
			$this->api           = $api;
			$this->engine        = $engine;
			$this->pdf           = $pdf;
			$this->folio_manager = $folio_manager;
			$this->queue         = $queue;
	}

	/** Registers the submenu page. */
	public function register(): void {
		\add_submenu_page(
			'sii-boleta-dte',
			\__( 'Generar DTE', 'sii-boleta-dte' ),
			\__( 'Generar DTE', 'sii-boleta-dte' ),
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

		$settings_cfg     = $this->settings->get_settings();
		$configured_giros = array();
		if ( isset( $settings_cfg['giros'] ) && is_array( $settings_cfg['giros'] ) ) {
			foreach ( $settings_cfg['giros'] as $giro_option ) {
				$giro_value = (string) $giro_option;
				if ( '' !== $giro_value ) {
					$configured_giros[] = $giro_value;
				}
			}
		}

		$default_emitter_giro = '';
		if ( isset( $settings_cfg['giro'] ) ) {
			$default_emitter_giro = (string) $settings_cfg['giro'];
		}
		if ( '' === $default_emitter_giro && ! empty( $configured_giros ) ) {
			$default_emitter_giro = $configured_giros[0];
		}

		$result = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result = $this->process_post( $_POST );
		}

		$available    = $this->get_available_types();
		$default_tipo = (int) ( array_key_first( $available ) ?? 39 );
		$sel_tipo     = isset( $_POST['tipo'] ) ? (int) $_POST['tipo'] : $default_tipo; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $available[ $sel_tipo ] ) ) {
			$sel_tipo = $default_tipo;
		}

		$val = static function ( string $k ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			return isset( $_POST[ $k ] ) ? esc_attr( (string) $_POST[ $k ] ) : '';
		};

		$current_emitter_giro = isset( $_POST['giro_emisor'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? (string) $_POST['giro_emisor'] // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: $default_emitter_giro;

		$item0             = isset( $_POST['items'][0] ) && is_array( $_POST['items'][0] ) ? (array) $_POST['items'][0] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$i0d               = isset( $item0['desc'] ) ? esc_attr( (string) $item0['desc'] ) : '';
		$i0q               = isset( $item0['qty'] ) ? esc_attr( (string) $item0['qty'] ) : '1';
		$i0p               = isset( $item0['price'] ) ? esc_attr( (string) $item0['price'] ) : '0';
		$i0code_type       = isset( $item0['code_type'] ) ? esc_attr( (string) $item0['code_type'] ) : '';
		$i0code_value      = isset( $item0['code_value'] ) ? esc_attr( (string) $item0['code_value'] ) : '';
		$i0extra_desc      = isset( $item0['extra_desc'] ) ? esc_textarea( (string) $item0['extra_desc'] ) : '';
		$i0unit_item       = isset( $item0['unit_item'] ) ? esc_attr( (string) $item0['unit_item'] ) : '';
		$i0unit_ref        = isset( $item0['unit_ref'] ) ? esc_attr( (string) $item0['unit_ref'] ) : '';
		$i0discount_pct    = isset( $item0['discount_pct'] ) ? esc_attr( (string) $item0['discount_pct'] ) : '';
		$i0discount_amount = isset( $item0['discount_amount'] ) ? esc_attr( (string) $item0['discount_amount'] ) : '';
		$i0tax_code        = isset( $item0['tax_code'] ) ? esc_attr( (string) $item0['tax_code'] ) : '';
		$i0retained_indicator = isset( $item0['retained_indicator'] ) ? esc_attr( (string) $item0['retained_indicator'] ) : '';
		$i0exempt_indicator   = isset( $item0['exempt_indicator'] ) ? esc_attr( (string) $item0['exempt_indicator'] ) : '';

		$current_fma_pago      = isset( $_POST['fma_pago'] ) ? (string) $_POST['fma_pago'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_ind_servicio  = isset( $_POST['ind_servicio'] ) ? (string) $_POST['ind_servicio'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_tipo_despacho = isset( $_POST['tipo_despacho'] ) ? (string) $_POST['tipo_despacho'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_ind_traslado  = isset( $_POST['ind_traslado'] ) ? (string) $_POST['ind_traslado'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_dsc_mov       = isset( $_POST['dsc_global_mov'] ) ? (string) $_POST['dsc_global_mov'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_dsc_tipo      = isset( $_POST['dsc_global_tipo'] ) ? (string) $_POST['dsc_global_tipo'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$current_dsc_ind_exe   = isset( $_POST['dsc_global_ind_exe'] ) ? (string) $_POST['dsc_global_ind_exe'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$ref0             = isset( $_POST['references'][0] ) && is_array( $_POST['references'][0] ) ? (array) $_POST['references'][0] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ref0_tipo        = isset( $ref0['tipo'] ) ? esc_attr( (string) $ref0['tipo'] ) : '';
		$ref0_folio       = isset( $ref0['folio'] ) ? esc_attr( (string) $ref0['folio'] ) : '';
		$ref0_fecha       = isset( $ref0['fecha'] ) ? esc_attr( (string) $ref0['fecha'] ) : '';
		$ref0_codref      = isset( $ref0['codref'] ) ? esc_attr( (string) $ref0['codref'] ) : '';
		$ref0_razon       = isset( $ref0['razon'] ) ? esc_attr( (string) $ref0['razon'] ) : '';
		$ref0_global      = isset( $ref0['global'] ) ? '1' : '';
		$ref0_global_attr = function_exists( 'checked' )
			? checked( '1', $ref0_global, false )
			: ( '1' === $ref0_global ? 'checked="checked"' : '' );

		// Determinar el tipo de documento actualmente seleccionado para adaptar
		// el listado de tipos referenciables (p.ej., permitir referenciar NC solo desde ND).
		$current_tipo_ui = 0;
		if ( isset( $_POST['tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$current_tipo_ui = (int) $_POST['tipo']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} else {
			$current_tipo_ui = (int) ( array_key_first( $available ) ?? 39 );
		}

		$reference_type_options = array(
			'33' => __( 'Factura', 'sii-boleta-dte' ),
			'34' => __( 'Factura Exenta', 'sii-boleta-dte' ),
			'39' => __( 'Boleta', 'sii-boleta-dte' ),
			'41' => __( 'Boleta Exenta', 'sii-boleta-dte' ),
			'52' => __( 'Guía de despacho', 'sii-boleta-dte' ),
		);
		// Solo permitir referenciar una Nota de Crédito (61) cuando el documento actual es Nota de Débito (56).
		if ( 56 === $current_tipo_ui ) {
			$reference_type_options['61'] = __( 'Nota de crédito', 'sii-boleta-dte' );
		}
		$reference_code_options = array(
			'1' => __( 'Anula documento de referencia', 'sii-boleta-dte' ),
			'2' => __( 'Corrige texto documento ref.', 'sii-boleta-dte' ),
			'3' => __( 'Corrige montos', 'sii-boleta-dte' ),
			'4' => __( 'Deja sin efecto parcialmente', 'sii-boleta-dte' ),
			'5' => __( 'Corrige montos en más', 'sii-boleta-dte' ),
			'6' => __( 'Corrige montos en menos', 'sii-boleta-dte' ),
			'7' => __( 'Referencia a otro DTE', 'sii-boleta-dte' ),
		);

		$current_nc_motive = 'anula';
		if ( isset( $_POST['nc_motivo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_nc_motive = sanitize_key( (string) $_POST['nc_motivo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( array_key_exists( $raw_nc_motive, self::CREDIT_NOTE_MOTIVES ) ) {
				$current_nc_motive = $raw_nc_motive;
			}
		}

		$modal_preview_url = '';
		if ( is_array( $result ) && ! empty( $result['preview'] ) ) {
			$modal_preview_url = (string) ( $result['pdf_url'] ?? $result['pdf'] ?? '' );
		}

                $environment               = $this->settings->get_environment();
                $environment_label         = Settings::environment_label( $environment );
                $is_development_environment = '2' === Settings::normalize_environment( $environment );
                $dev_simulation_mode       = 'disabled';
                if ( $is_development_environment ) {
                        $allowed_modes   = array( 'disabled', 'success', 'error' );
                        $configured_mode = isset( $settings_cfg['dev_sii_simulation_mode'] ) ? (string) $settings_cfg['dev_sii_simulation_mode'] : '';
                        if ( '' === $configured_mode ) {
                                $dev_simulation_mode = 'success';
                        } elseif ( in_array( $configured_mode, $allowed_modes, true ) ) {
                                $dev_simulation_mode = $configured_mode;
                        } else {
                                $dev_simulation_mode = 'success';
                        }
                }
                $rvd_automation            = ! empty( $settings_cfg['rvd_auto_enabled'] );
                $libro_automation          = ! empty( $settings_cfg['libro_auto_enabled'] );
		?>
							<?php AdminStyles::open_container( 'sii-generate-dte' ); ?>
												<h1><?php esc_html_e( 'Generar DTE', 'sii-boleta-dte' ); ?></h1>
									<p class="sii-generate-dte-subtitle"><?php esc_html_e( 'Crea y envía documentos tributarios electrónicos con una interfaz diseñada para guiar cada paso del proceso.', 'sii-boleta-dte' ); ?></p>
						<div id="sii-dte-modal" class="sii-dte-modal" style="display:none"
						<?php
						if ( ! empty( $modal_preview_url ) ) {
							echo ' data-preview-url="' . esc_attr( $modal_preview_url ) . '"'; }
						?>
						>
								<div class="sii-dte-modal-backdrop"></div>
								<div class="sii-dte-modal-content">
										<button type="button" class="sii-dte-modal-close" aria-label="<?php esc_attr_e( 'Close preview', 'sii-boleta-dte' ); ?>">&times;</button>
										<iframe id="sii-dte-modal-frame" class="sii-dte-modal-frame" src="" title="<?php esc_attr_e( 'DTE preview', 'sii-boleta-dte' ); ?>"></iframe>
								</div>
						</div>
												<div class="sii-generate-dte-layout">
								<div class="sii-generate-dte-card">
										<div id="sii-generate-dte-notices">
												<?php if ( is_array( $result ) && ! empty( $result['preview'] ) ) : ?>
																<div class="notice notice-info"><p><?php esc_html_e( 'Vista previa generada. Revisa el documento a continuación.', 'sii-boleta-dte' ); ?>
																<?php
																if ( ! empty( $modal_preview_url ) ) :
																	?>
																	- <a target="_blank" rel="noopener" href="<?php echo esc_url( $modal_preview_url ); ?>"><?php esc_html_e( 'Abrir vista previa en una pestaña nueva', 'sii-boleta-dte' ); ?></a><?php endif; ?></p></div>
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
                                                                                                                               <?php
                                                                                                                               $notice_type  = isset( $result['notice_type'] ) ? (string) $result['notice_type'] : 'success';
                                                                                                                               $notice_class = 'notice notice-success';
                                                                                                                               if ( 'info' === $notice_type ) {
                                                                                                                               $notice_class = 'notice notice-info';
                                                                                                                               } elseif ( 'warning' === $notice_type ) {
                                                                                                                               $notice_class = 'notice notice-warning';
                                                                                                                               } elseif ( 'error' === $notice_type ) {
                                                                                                                               $notice_class = 'notice notice-error';
                                                                                                                               }
                                                                                                                               $track_id = (string) ( $result['track_id'] ?? '' );
                                                                                                                               $message  = '';
                                                                                                                               if ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
                                                                                                                               $message = (string) $result['message'];
                                                                                                                               }
                                                                                                                               if ( '' === $message ) {
                                                                                                                               $message = '' !== $track_id
                                                                                                                               ? sprintf( __( 'Track ID: %s', 'sii-boleta-dte' ), $track_id )
                                                                                                                               : __( 'Documento enviado correctamente.', 'sii-boleta-dte' );
                                                                                                                               }
                                                                                                                               $dl_url = (string) ( $result['pdf_url'] ?? '' );
                                                                                                                               ?>
                                                                                                                               <div class="<?php echo esc_attr( $notice_class ); ?>"><p>
                                                                                                                               <?php echo esc_html( $message ); ?>
                                                                                                                               <?php if ( ! empty( $track_id ) && false === strpos( $message, $track_id ) ) : ?>
                                                                                                                               <br /><?php printf( esc_html__( 'Track ID: %s', 'sii-boleta-dte' ), esc_html( $track_id ) ); ?>
                                                                                                                               <?php endif; ?>
                                                                                                                               <?php if ( ! empty( $dl_url ) ) : ?>
                                                                                                                               - <a href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Descargar PDF', 'sii-boleta-dte' ); ?></a>
                                                                                                                               <?php endif; ?>
                                                                                                                               </p></div>
												<?php elseif ( is_array( $result ) && ! empty( $result['error'] ) ) : ?>
																<div class="error notice"><p><?php echo esc_html( (string) $result['error'] ); ?></p></div>
												<?php endif; ?>
										</div>
                                               <form method="post" id="sii-generate-dte-form" class="sii-generate-dte-form"
                                                       data-step-incomplete="<?php esc_attr_e( 'Completa los campos obligatorios antes de continuar.', 'sii-boleta-dte' ); ?>"
                                                       data-required-label="<?php esc_attr_e( 'Obligatorio', 'sii-boleta-dte' ); ?>"
                                                       data-optional-label="<?php esc_attr_e( 'Opcional', 'sii-boleta-dte' ); ?>"
                                                       data-nc-required="<?php esc_attr_e( 'Debes ingresar al menos una referencia con folio y fecha para la nota de crédito.', 'sii-boleta-dte' ); ?>"
                                                       data-nc-incomplete="<?php esc_attr_e( 'Completa el tipo, el folio y la fecha del documento referenciado antes de continuar.', 'sii-boleta-dte' ); ?>"
                                                       data-nc-reason="<?php esc_attr_e( 'Describe la corrección en la glosa de la referencia para finalizar la nota de crédito.', 'sii-boleta-dte' ); ?>"
                                                       data-nc-reason-placeholder="<?php esc_attr_e( 'Describe la corrección aplicada al documento original', 'sii-boleta-dte' ); ?>"
                                                       data-environment="<?php echo esc_attr( (string) $environment ); ?>"
                                                       data-dev-simulation-mode="<?php echo esc_attr( $dev_simulation_mode ); ?>">
                                <?php wp_nonce_field( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' ); ?>
                                <ol class="sii-generate-dte-steps" id="sii-generate-dte-steps" data-current-step="identificacion" aria-label="<?php esc_attr_e( 'Progreso del formulario', 'sii-boleta-dte' ); ?>">
                                                <li data-step="identificacion" class="is-active">
                                                                <button type="button" class="sii-generate-dte-step-button" data-step-target="identificacion">
                                                                                <span class="sii-generate-dte-step-title"><?php esc_html_e( 'Identificación', 'sii-boleta-dte' ); ?></span>
                                                                </button>
                                                </li>
                                                <li data-step="items">
                                                                <button type="button" class="sii-generate-dte-step-button" data-step-target="items">
                                                                                <span class="sii-generate-dte-step-title"><?php esc_html_e( 'Ítems', 'sii-boleta-dte' ); ?></span>
                                                                </button>
                                                </li>
                                                <li data-step="resumen">
                                                                <button type="button" class="sii-generate-dte-step-button" data-step-target="resumen">
                                                                                <span class="sii-generate-dte-step-title"><?php esc_html_e( 'Resumen', 'sii-boleta-dte' ); ?></span>
                                                                </button>
                                                </li>
                                </ol>
                                <section class="sii-dte-step is-active" data-step="identificacion" aria-labelledby="sii-dte-step-identificacion">
                                                <h2 id="sii-dte-step-identificacion" class="sii-dte-step-heading"><?php esc_html_e( 'Identificación', 'sii-boleta-dte' ); ?></h2>
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
														<th scope="row"><label for="sii-giro-emisor"><?php esc_html_e( 'Giro emisor', 'sii-boleta-dte' ); ?></label></th>
														<td>
															<?php if ( ! empty( $configured_giros ) ) : ?>
																<select id="sii-giro-emisor" name="giro_emisor">
																	<?php
																	foreach ( $configured_giros as $giro_option ) :
																		$value       = esc_attr( $giro_option );
																		$is_selected = (string) $current_emitter_giro === (string) $giro_option ? ' selected' : '';
																		?>
																		<option value="<?php echo $value; ?>"<?php echo $is_selected; ?>><?php echo esc_html( $giro_option ); ?></option>
																	<?php endforeach; ?>
																</select>
															<?php else : ?>
																<input type="text" id="sii-giro-emisor" name="giro_emisor" class="regular-text" value="<?php echo esc_attr( (string) $current_emitter_giro ); ?>" />
															<?php endif; ?>
														</td>
													</tr>
													<tr>
												<th scope="row"><label for="sii-rut"><?php esc_html_e( 'RUT del cliente', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-rut" name="rut" required class="regular-text" value="<?php echo $val( 'rut' ); ?>" /></td>
													</tr>
													<tr>
														<th scope="row"><label for="sii-razon"><?php esc_html_e( 'Razón Social', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-razon" name="razon" required class="large-text" value="<?php echo $val( 'razon' ); ?>" /></td>
													</tr>
													<tr class="dte-section" data-types="33,34,43,46,52,56,61,110,111,112" style="display:none">
														<th scope="row"><label for="sii-giro" id="label-giro"><?php esc_html_e( 'Giro', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-giro" name="giro" class="regular-text" value="<?php echo $val( 'giro' ); ?>" /></td>
													</tr>
													<!-- Receptor address for invoice/guide types -->
																										<tr class="dte-section" data-types="33,34,39,41,43,46,52,56,61,110" style="display:none">
																																					<th scope="row"><label for="sii-dir-recep"><?php esc_html_e( 'Dirección Receptor', 'sii-boleta-dte' ); ?></label></th>
																																					<td><input type="text" id="sii-dir-recep" name="dir_recep" class="regular-text" value="<?php echo $val( 'dir_recep' ); ?>" /></td>
																																			</tr>
																										<tr class="dte-section" data-types="33,34,39,41,43,46,52,56,61,110" style="display:none">
																							<th scope="row"><label for="sii-cmna-recep"><?php esc_html_e( 'Comuna Receptor', 'sii-boleta-dte' ); ?></label></th>
																							<td><input type="text" id="sii-cmna-recep" name="cmna_recep" class="regular-text" value="<?php echo $val( 'cmna_recep' ); ?>" /></td>
																						</tr>
																										<tr class="dte-section" data-types="33,34,39,41,43,46,52,56,61,110" style="display:none">
																							<th scope="row"><label for="sii-ciudad-recep"><?php esc_html_e( 'Ciudad Receptor', 'sii-boleta-dte' ); ?></label></th>
																													<td><input type="text" id="sii-ciudad-recep" name="ciudad_recep" class="regular-text" value="<?php echo $val( 'ciudad_recep' ); ?>" /></td>
																																			</tr>
																										<tr class="dte-section" data-types="52" style="display:none">
																												<th scope="row"><label for="sii-tipo-despacho"><?php esc_html_e( 'Tipo de despacho', 'sii-boleta-dte' ); ?></label></th>
																												<td>
																														<select id="sii-tipo-despacho" name="tipo_despacho">
																																<option value="">—</option>
																																<option value="1" <?php selected( $current_tipo_despacho, '1', true ); ?>><?php esc_html_e( 'Por cuenta del vendedor', 'sii-boleta-dte' ); ?></option>
																																<option value="2" <?php selected( $current_tipo_despacho, '2', true ); ?>><?php esc_html_e( 'Por cuenta del comprador', 'sii-boleta-dte' ); ?></option>
																																<option value="3" <?php selected( $current_tipo_despacho, '3', true ); ?>><?php esc_html_e( 'Por cuenta de un tercero', 'sii-boleta-dte' ); ?></option>
																														</select>
																														<p class="description"><?php esc_html_e( 'Indica quién realiza el despacho cuando la guía acompaña mercaderías.', 'sii-boleta-dte' ); ?></p>
																												</td>
																										</tr>
																										<tr class="dte-section" data-types="52" style="display:none">
																												<th scope="row"><label for="sii-ind-traslado"><?php esc_html_e( 'Indicador de traslado', 'sii-boleta-dte' ); ?></label></th>
																												<td>
																														<select id="sii-ind-traslado" name="ind_traslado" required>
																																<option value="">—</option>
																																<option value="1" <?php selected( $current_ind_traslado, '1', true ); ?>><?php esc_html_e( 'Constituye venta', 'sii-boleta-dte' ); ?></option>
<option value="2" <?php selected( $current_ind_traslado, '2', true ); ?>><?php esc_html_e( 'Venta por efectuar', 'sii-boleta-dte' ); ?></option>
<option value="3" <?php selected( $current_ind_traslado, '3', true ); ?>><?php esc_html_e( 'Consignación', 'sii-boleta-dte' ); ?></option>
<option value="4" <?php selected( $current_ind_traslado, '4', true ); ?>><?php esc_html_e( 'Entrega gratuita', 'sii-boleta-dte' ); ?></option>
<option value="5" <?php selected( $current_ind_traslado, '5', true ); ?>><?php esc_html_e( 'Traslado interno', 'sii-boleta-dte' ); ?></option>
<option value="6" <?php selected( $current_ind_traslado, '6', true ); ?>><?php esc_html_e( 'Otros traslados no venta', 'sii-boleta-dte' ); ?></option>
<option value="7" <?php selected( $current_ind_traslado, '7', true ); ?>><?php esc_html_e( 'Guía de devolución', 'sii-boleta-dte' ); ?></option>
																														</select>
																														<p class="description"><?php esc_html_e( 'Define el motivo del movimiento de mercancías.', 'sii-boleta-dte' ); ?></p>
																												</td>
																										</tr>
																										<tr class="dte-section" data-types="52" style="display:none">
																												<th scope="row"><?php esc_html_e( 'Datos de transporte', 'sii-boleta-dte' ); ?></th>
																												<td>
																														<div class="sii-generate-grid">
																																<label>
																																		<span><?php esc_html_e( 'Patente del vehículo', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="transporte_patente" value="<?php echo $val( 'transporte_patente' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'RUT transportista', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="transporte_rut" value="<?php echo $val( 'transporte_rut' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'RUT chofer', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="transporte_chofer_rut" value="<?php echo $val( 'transporte_chofer_rut' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Nombre chofer', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="transporte_chofer_nombre" value="<?php echo $val( 'transporte_chofer_nombre' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Dirección destino', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="transporte_dir_dest" value="<?php echo $val( 'transporte_dir_dest' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Comuna destino', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="transporte_cmna_dest" value="<?php echo $val( 'transporte_cmna_dest' ); ?>" />
																																</label>
																														</div>
																												</td>
																										</tr>
<tr class="dte-section" data-types="33,34,52" style="display:none">
																												<th scope="row"><label for="sii-fma-pago"><?php esc_html_e( 'Condiciones de pago', 'sii-boleta-dte' ); ?></label></th>
																												<td>
																														<div class="sii-generate-grid">
																																<label>
																																		<span><?php esc_html_e( 'Forma de pago', 'sii-boleta-dte' ); ?></span>
																																		<select id="sii-fma-pago" name="fma_pago">
																																				<option value="">—</option>
				<option value="1" <?php selected( $current_fma_pago, '1', true ); ?>><?php esc_html_e( 'Contado', 'sii-boleta-dte' ); ?></option>
				<option value="2" <?php selected( $current_fma_pago, '2', true ); ?>><?php esc_html_e( 'Crédito', 'sii-boleta-dte' ); ?></option>
				<option value="3" <?php selected( $current_fma_pago, '3', true ); ?>><?php esc_html_e( 'Entrega gratuita', 'sii-boleta-dte' ); ?></option>
																																		</select>
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Glosa / condiciones', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" name="term_pago_glosa" value="<?php echo $val( 'term_pago_glosa' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Fecha de vencimiento', 'sii-boleta-dte' ); ?></span>
																																		<input type="date" name="fch_venc" value="<?php echo $val( 'fch_venc' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Indicador de servicio', 'sii-boleta-dte' ); ?></span>
																																		<select name="ind_servicio">
																																				<option value="">—</option>
				<option value="1" <?php selected( $current_ind_servicio, '1', true ); ?>><?php esc_html_e( 'Servicios periódicos domiciliarios', 'sii-boleta-dte' ); ?></option>
				<option value="2" <?php selected( $current_ind_servicio, '2', true ); ?>><?php esc_html_e( 'Facturación diaria', 'sii-boleta-dte' ); ?></option>
				<option value="3" <?php selected( $current_ind_servicio, '3', true ); ?>><?php esc_html_e( 'Otros servicios periódicos', 'sii-boleta-dte' ); ?></option>
																																		</select>
																																</label>
																														</div>
																												</td>
																										</tr>
<tr class="dte-section" data-types="39,41" style="display:none">
<th scope="row"><label for="sii-term-pago-glosa-boleta"><?php esc_html_e( 'Glosa / condiciones', 'sii-boleta-dte' ); ?></label></th>
<td>
<input type="text" id="sii-term-pago-glosa-boleta" name="term_pago_glosa" class="large-text" value="<?php echo $val( 'term_pago_glosa' ); ?>" />
<p class="description"><?php esc_html_e( 'Se envía como observación opcional en la boleta.', 'sii-boleta-dte' ); ?></p>
</td>
</tr>
<tr class="dte-section" data-types="41" style="display:none">
<th scope="row"><?php esc_html_e( 'Servicio periódico', 'sii-boleta-dte' ); ?></th>
<td>
<div class="sii-generate-grid">
<label>
<span><?php esc_html_e( 'Indicador de servicio', 'sii-boleta-dte' ); ?></span>
<select id="sii-ind-servicio-boleta" name="ind_servicio">
<option value="">—</option>
<option value="1" <?php selected( $current_ind_servicio, '1', true ); ?>><?php esc_html_e( 'Servicios periódicos domiciliarios', 'sii-boleta-dte' ); ?></option>
<option value="2" <?php selected( $current_ind_servicio, '2', true ); ?>><?php esc_html_e( 'Facturación diaria', 'sii-boleta-dte' ); ?></option>
<option value="3" <?php selected( $current_ind_servicio, '3', true ); ?>><?php esc_html_e( 'Otros servicios periódicos', 'sii-boleta-dte' ); ?></option>
</select>
</label>
<label>
<span><?php esc_html_e( 'Periodo desde', 'sii-boleta-dte' ); ?></span>
<input type="date" name="periodo_desde" value="<?php echo $val( 'periodo_desde' ); ?>" />
</label>
<label>
<span><?php esc_html_e( 'Periodo hasta', 'sii-boleta-dte' ); ?></span>
<input type="date" name="periodo_hasta" value="<?php echo $val( 'periodo_hasta' ); ?>" />
</label>
<label>
<span><?php esc_html_e( 'Fecha de vencimiento', 'sii-boleta-dte' ); ?></span>
<input type="date" name="fch_venc" value="<?php echo $val( 'fch_venc' ); ?>" />
</label>
</div>
</td>
</tr>
<tr class="dte-section" data-types="33" style="display:none">
<th scope="row"><label for="sii-contacto-recep"><?php esc_html_e( 'Contacto del receptor', 'sii-boleta-dte' ); ?></label></th>
<td>
<div class="sii-generate-grid">
<label>
																																		<span><?php esc_html_e( 'Correo electrónico', 'sii-boleta-dte' ); ?></span>
																																		<input type="email" id="sii-correo-recep" name="correo_recep" value="<?php echo $val( 'correo_recep' ); ?>" />
																																</label>
																																<label>
																																		<span><?php esc_html_e( 'Contacto / teléfono', 'sii-boleta-dte' ); ?></span>
																																		<input type="text" id="sii-contacto-recep" name="contacto_recep" value="<?php echo $val( 'contacto_recep' ); ?>" />
																																</label>
</div>
</td>
</tr>
<tr class="dte-section" data-types="41" style="display:none">
<th scope="row"><label for="sii-cdg-int-recep"><?php esc_html_e( 'Código interno del receptor', 'sii-boleta-dte' ); ?></label></th>
<td><input type="text" id="sii-cdg-int-recep" name="cod_int_recep" class="regular-text" value="<?php echo $val( 'cod_int_recep' ); ?>" /></td>
</tr>
                                                                </tbody>
                                                </table>
                                                <div class="sii-step-navigation sii-step-navigation--right">
                                                                <button type="button" class="button button-primary sii-step-next"><?php esc_html_e( 'Siguiente', 'sii-boleta-dte' ); ?></button>
                                                </div>
                                </section>
                                <section class="sii-dte-step" data-step="items" aria-labelledby="sii-dte-step-items">
                                                <h2 id="sii-dte-step-items" class="sii-dte-step-heading"><?php esc_html_e( 'Ítems', 'sii-boleta-dte' ); ?></h2>
                                                <table class="form-table" role="presentation">
                                                                <tbody>
                                                                <tr>
                                                                                                <th scope="row"><label for="sii-items"><?php esc_html_e( 'Ítems', 'sii-boleta-dte' ); ?></label></th>
                                                                                                                <td>
                                                                                                                               <table id="sii-items-table" class="widefat">
                                                                                                                               <thead>
                                                                                                                               <tr>
                                                                                                                               <th><?php esc_html_e( 'Descripción', 'sii-boleta-dte' ); ?></th>
                                                                                                                               <th><?php esc_html_e( 'Acciones', 'sii-boleta-dte' ); ?></th>
                                                                                                                               </tr>
                                                                                                                               </thead>
                                                                                                                               <tbody>
                                                                                                                               <tr>
                                                                                                                               <td data-label="<?php esc_attr_e( 'Descripción', 'sii-boleta-dte' ); ?>">
                                                                                                                               <div class="sii-item-stack">
                                                                                                                               <div class="sii-item-primary">
<input type="text" name="items[0][desc]" data-field="desc" class="regular-text" value="<?php echo $i0d; ?>" />
                                                                                                                               </div>
                                                                                                                               <div class="sii-item-metrics">
                                                                                                                               <label class="sii-item-metric">
                                                                                                                               <span><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></span>
<input type="number" name="items[0][qty]" data-field="qty" value="<?php echo $i0q; ?>" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" />
                                                                                                                               </label>
                                                                                                                               <label class="sii-item-metric">
                                                                                                                               <span><?php esc_html_e( 'Precio unitario', 'sii-boleta-dte' ); ?></span>
<input type="number" name="items[0][price]" data-field="price" value="<?php echo $i0p; ?>" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" />
                                                                                                                               </label>
                                                                                                                               </div>
<details class="sii-item-advanced dte-section" data-types="33,34,41,43,46,52,56,61,110,111,112" style="display:none">
<summary><?php esc_html_e( 'Opciones avanzadas del ítem', 'sii-boleta-dte' ); ?></summary>
<div class="sii-item-advanced-grid">
<label class="dte-section" data-types="34,41,46">
<span><?php esc_html_e( 'Indicador de exención', 'sii-boleta-dte' ); ?></span>
<select name="items[0][exempt_indicator]" data-field="exempt_indicator">
<option value=""><?php esc_html_e( 'Automático', 'sii-boleta-dte' ); ?></option>
<option value="1" <?php selected( $i0exempt_indicator, '1', true ); ?>><?php esc_html_e( 'No afecto o exento de IVA', 'sii-boleta-dte' ); ?></option>
<option value="2" <?php selected( $i0exempt_indicator, '2', true ); ?>><?php esc_html_e( 'Producto o servicio no facturable', 'sii-boleta-dte' ); ?></option>
</select>
</label>
<label class="dte-section" data-types="34,41,46">
<span><?php esc_html_e( 'Indicador de exención', 'sii-boleta-dte' ); ?></span>
<select name="dsc_global_ind_exe">
<option value="">—</option>
<option value="1" <?php selected( $current_dsc_ind_exe, '1', true ); ?>><?php esc_html_e( 'Exento o no afecto', 'sii-boleta-dte' ); ?></option>
<option value="2" <?php selected( $current_dsc_ind_exe, '2', true ); ?>><?php esc_html_e( 'No facturable', 'sii-boleta-dte' ); ?></option>
</select>
</label>
                                            <label>
                                                <span><?php esc_html_e( 'Tipo de código', 'sii-boleta-dte' ); ?></span>
                                                <input type="text" name="items[0][code_type]" data-field="code_type" value="<?php echo $i0code_type; ?>" />
                                                <p class="description"><?php esc_html_e( 'Ejemplo: SKU, EAN13 u otro identificador definido por tu negocio.', 'sii-boleta-dte' ); ?></p>
                                            </label>
                                            <label>
                                                <span><?php esc_html_e( 'Código', 'sii-boleta-dte' ); ?></span>
                                                <input type="text" name="items[0][code_value]" data-field="code_value" value="<?php echo $i0code_value; ?>" />
                                                <p class="description"><?php esc_html_e( 'Ejemplo: 123456789 o el código que usas en tu sistema para el producto.', 'sii-boleta-dte' ); ?></p>
                                            </label>
                                                                                                                               <label class="sii-item-advanced-wide">
                                                                                                                               <span><?php esc_html_e( 'Descripción adicional', 'sii-boleta-dte' ); ?></span>
                                                                                                                               <textarea name="items[0][extra_desc]" data-field="extra_desc" rows="3"><?php echo $i0extra_desc; ?></textarea>
                                                                                                                               </label>
                                            <label>
                                                <span><?php esc_html_e( 'Unidad del ítem', 'sii-boleta-dte' ); ?></span>
                                                <input type="text" name="items[0][unit_item]" data-field="unit_item" value="<?php echo $i0unit_item; ?>" />
                                                <p class="description"><?php esc_html_e( 'Ejemplo: UND, KG, CAJA. Corresponde a cómo vendes el producto en la línea del documento.', 'sii-boleta-dte' ); ?></p>
                                            </label>
                                            <label>
                                                <span><?php esc_html_e( 'Unidad de referencia', 'sii-boleta-dte' ); ?></span>
                                                <input type="text" name="items[0][unit_ref]" data-field="unit_ref" value="<?php echo $i0unit_ref; ?>" />
                                                <p class="description"><?php esc_html_e( 'Úsalo sólo si necesitas informar una unidad alternativa (por ejemplo, paquete de 12 UND).', 'sii-boleta-dte' ); ?></p>
                                            </label>
                                                                                                                               <label>
                                                                                                                               <span><?php esc_html_e( 'Descuento %', 'sii-boleta-dte' ); ?></span>
                                                                                                                               <input type="number" name="items[0][discount_pct]" data-field="discount_pct" value="<?php echo $i0discount_pct; ?>" step="0.01" inputmode="decimal" />
                                                                                                                               </label>
                                                                                                                               <label>
                                                                                                                               <span><?php esc_html_e( 'Descuento $', 'sii-boleta-dte' ); ?></span>
                                                                                                                               <input type="number" name="items[0][discount_amount]" data-field="discount_amount" value="<?php echo $i0discount_amount; ?>" step="0.01" inputmode="decimal" />
                                                                                                                               </label>
                                                                                                                               <label>
                                                                                                                               <span><?php esc_html_e( 'Impuesto adicional', 'sii-boleta-dte' ); ?></span>
                                                                                                                               <input type="text" name="items[0][tax_code]" data-field="tax_code" value="<?php echo $i0tax_code; ?>" />
                                                                                                                               </label>
                                                                                                                               <label>
                                                                                                                               <span><?php esc_html_e( 'Indicador retenedor', 'sii-boleta-dte' ); ?></span>
                                                                                                                               <input type="text" name="items[0][retained_indicator]" data-field="retained_indicator" value="<?php echo $i0retained_indicator; ?>" />
                                                                                                                               </label>
                                                                                                                               </div>
                                                                                                                               </details>
                                                                                                                               </div>
                                                                                                                               </td>
                                                                                                                               <td data-label="<?php esc_attr_e( 'Acciones', 'sii-boleta-dte' ); ?>"><button type="button" class="button remove-item" aria-label="<?php esc_attr_e( 'Eliminar ítem', 'sii-boleta-dte' ); ?>">×</button></td>
                                                                                                                               </tr>
                                                                                                                               </tbody>
                                                                                                                        </table>
																																											<fieldset class="dte-section" data-types="46" style="display:none; margin-top:1em; border:1px solid #ccd0d4; padding:10px;">
																																												<legend style="font-weight:600;"><?php esc_html_e( 'Retención IVA', 'sii-boleta-dte' ); ?></legend>
																																												<div class="sii-generate-grid">
																																													<label>
																																														<span><?php esc_html_e( 'Tipo de retención', 'sii-boleta-dte' ); ?></span>
																																														<select name="retencion_tipo">
																																															<option value="">—</option>
																																															<option value="total" <?php selected( $val('retencion_tipo'), 'total', true ); ?>><?php esc_html_e( 'Total (código 15)', 'sii-boleta-dte' ); ?></option>
																																															<option value="parcial" <?php selected( $val('retencion_tipo'), 'parcial', true ); ?>><?php esc_html_e( 'Parcial (seleccionar código)', 'sii-boleta-dte' ); ?></option>
																																														</select>
																																													</label>
																																													<label class="retencion-parcial-wrapper" style="<?php echo ( 'parcial' === $val('retencion_tipo') ) ? '' : 'display:none;'; ?>">
																																														<span><?php esc_html_e( 'Código parcial', 'sii-boleta-dte' ); ?></span>
																																														<select name="retencion_codigo">
																																															<option value="">—</option>
																															<?php
																															// Lista simplificada de códigos parciales (tipo R) distintos al 15.
																															$ret_codes = array(30,31,32,33,34,36,37,38,39,41,47,48);
																															foreach ( $ret_codes as $rc ) {
																																echo '<option value="' . esc_attr( (string) $rc ) . '" ' . selected( $val('retencion_codigo'), (string) $rc, false ) . '>' . esc_html( $rc ) . '</option>';
																															}
																															?>
																																														</select>
																																													</label>
																																													<label>
																																														<span><?php esc_html_e( 'Aplicar a todas las líneas', 'sii-boleta-dte' ); ?></span>
																																														<input type="checkbox" name="retencion_aplicar_todo" value="1" <?php checked( $val('retencion_aplicar_todo', '1'), '1', true ); ?> />
																																													</label>
																																													<p class="description" style="grid-column:1/-1;"><?php esc_html_e( 'Aplica CodImpAdic y marca agente retenedor en líneas afectas. No se aplica a líneas exentas.', 'sii-boleta-dte' ); ?></p>
																																												</div>
																																											</fieldset>

																																											<fieldset class="dte-section" data-types="46" style="display:none; margin-top:1em; border:1px solid #ccd0d4; padding:10px;">
																																												<legend style="font-weight:600;"><?php esc_html_e( 'Proveedor extranjero', 'sii-boleta-dte' ); ?></legend>
																																												<div class="sii-generate-grid">
																																													<label>
																																														<span><?php esc_html_e( 'Compra a proveedor extranjero', 'sii-boleta-dte' ); ?></span>
																																														<input type="checkbox" name="foreign_supplier" value="1" <?php checked( $val('foreign_supplier'), '1', true ); ?> />
																																													</label>
																																													<label>
																																														<span><?php esc_html_e( 'País', 'sii-boleta-dte' ); ?></span>
																																														<input type="text" name="foreign_country" value="<?php echo esc_attr( $val('foreign_country') ); ?>" maxlength="3" />
																																													</label>
																																													<label>
																																														<span><?php esc_html_e( 'ID Fiscal / Ref', 'sii-boleta-dte' ); ?></span>
																																														<input type="text" name="foreign_tax_id" value="<?php echo esc_attr( $val('foreign_tax_id') ); ?>" />
																																													</label>
																																													<label class="sii-item-advanced-wide">
																																														<span><?php esc_html_e( 'Descripción / Observación', 'sii-boleta-dte' ); ?></span>
																																														<textarea name="foreign_desc" rows="2"><?php echo esc_textarea( $val('foreign_desc') ); ?></textarea>
																																													</label>
																																													<p class="description" style="grid-column:1/-1;"><?php esc_html_e( 'Se añadirá a la glosa (TermPagoGlosa). Si se marca proveedor extranjero se recomienda no aplicar retención local.', 'sii-boleta-dte' ); ?></p>
																																												</div>
																																											</fieldset>
														<p><button type="button" class="button" id="sii-add-item"><?php esc_html_e( 'Agregar ítem', 'sii-boleta-dte' ); ?></button></p>
														<script>(function(){
															function onReady(fn){if(document.readyState!=='loading'){fn();}else{document.addEventListener('DOMContentLoaded',fn);} }
															onReady(function(){
																var tipoSelect=document.querySelector('select[name="retencion_tipo"]');
																if(!tipoSelect){return;}
																var wrapper=document.querySelector('.retencion-parcial-wrapper');
																var codigoSelect=document.querySelector('select[name="retencion_codigo"]');
																var foreignCountry=document.querySelector('input[name="foreign_country"]');
																function update(){
																	var v=tipoSelect.value;
																	if(v==='parcial'){
																		if(wrapper){wrapper.style.display='inline-block';}
																	}else{
																		if(wrapper){wrapper.style.display='none';}
																		if(codigoSelect){codigoSelect.selectedIndex=0;}
																	}
																}
																tipoSelect.addEventListener('change',update);
																update();
																if(foreignCountry){
																	foreignCountry.addEventListener('input',function(){
																		var v=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,3);
																		if(v!==this.value){this.value=v;}
																	});
																}
															});
														})();</script>
                                                                                                               </td>
                                                                                                       </tr>
												</tbody>
										</table>
										<div class="sii-step-navigation sii-step-navigation--between">
												<button type="button" class="button sii-step-prev"><?php esc_html_e( 'Anterior', 'sii-boleta-dte' ); ?></button>
												<button type="button" class="button button-primary sii-step-next"><?php esc_html_e( 'Siguiente', 'sii-boleta-dte' ); ?></button>
										</div>
								</section>
								<section class="sii-dte-step" data-step="resumen" aria-labelledby="sii-dte-step-resumen">
										<h2 id="sii-dte-step-resumen" class="sii-dte-step-heading"><?php esc_html_e( 'Resumen y referencias', 'sii-boleta-dte' ); ?></h2>
								<div class="sii-dte-tabla-scroll">		
								<table class="form-table" role="presentation">
												<tbody>

																				<tr class="dte-section" data-types="33,34,39,41" style="display:none">
                                                                                                <th scope="row"><label for="sii-descuento-global"><?php esc_html_e( 'Descuentos o recargos globales', 'sii-boleta-dte' ); ?></label></th>
                                                                                                <td>
                                                                                                                <div class="sii-generate-grid">
                                                                                                                        <label>
                                                                                                                                <span><?php esc_html_e( 'Movimiento', 'sii-boleta-dte' ); ?></span>
                                                                                                                                <select id="sii-descuento-global" name="dsc_global_mov">
                                                                                                                                        <option value="">—</option>
                                <option value="D" <?php selected( $current_dsc_mov, 'D', true ); ?>><?php esc_html_e( 'Descuento', 'sii-boleta-dte' ); ?></option>
                                <option value="R" <?php selected( $current_dsc_mov, 'R', true ); ?>><?php esc_html_e( 'Recargo', 'sii-boleta-dte' ); ?></option>
                                                                                                                                </select>
                                                                                                                        </label>
                                                                                                                        <label>
                                                                                                                                <span><?php esc_html_e( 'Tipo de valor', 'sii-boleta-dte' ); ?></span>
                                                                                                                                <select name="dsc_global_tipo">
                                                                                                                                        <option value="">—</option>
                                <option value="%" <?php selected( $current_dsc_tipo, '%', true ); ?>><?php esc_html_e( 'Porcentaje', 'sii-boleta-dte' ); ?></option>
                                <option value="$" <?php selected( $current_dsc_tipo, '$', true ); ?>><?php esc_html_e( 'Monto', 'sii-boleta-dte' ); ?></option>
                                                                                                                                </select>
                                                                                                                        </label>
                                                                                                                        <label>
                                                                                                                                <span><?php esc_html_e( 'Valor', 'sii-boleta-dte' ); ?></span>
                                                                                                                                <input type="number" name="dsc_global_valor" step="0.01" inputmode="decimal" value="<?php echo $val( 'dsc_global_valor' ); ?>" />
                                                                                                                        </label>
                                                                                                                </div>
                                                                                                </td>
                                                                                </tr>
                                                                                <tr class="dte-section" data-types="61" style="display:none" id="sii-nc-motivo-row">
												<th scope="row"><label for="sii-nc-motivo"><?php esc_html_e( 'Motivo de la nota de crédito', 'sii-boleta-dte' ); ?></label></th>
												<td>
														<select id="sii-nc-motivo" name="nc_motivo">
																<option value="anula" <?php selected( $current_nc_motive, 'anula', true ); ?>><?php esc_html_e( 'Anula documento', 'sii-boleta-dte' ); ?></option>
																<option value="texto" <?php selected( $current_nc_motive, 'texto', true ); ?>><?php esc_html_e( 'Corrige texto', 'sii-boleta-dte' ); ?></option>
																<option value="montos" <?php selected( $current_nc_motive, 'montos', true ); ?>><?php esc_html_e( 'Corrige montos', 'sii-boleta-dte' ); ?></option>
														</select>
														<p class="description"><?php esc_html_e( 'El motivo selecciona automáticamente el código de referencia exigido por el SII.', 'sii-boleta-dte' ); ?></p>
														<p class="description" id="sii-nc-zero-notice" style="display:none;">
																<?php esc_html_e( 'Para correcciones de texto, los ítems se enviarán con montos en cero (cantidad 1 y precio 0).', 'sii-boleta-dte' ); ?>
														</p>
												</td>
										</tr>
										<!-- Reference section for invoices, guides and notes -->
										<tr class="dte-section" data-types="33,34,43,46,52,56,61,110,111,112" style="display:none">
												<th scope="row"><label for="sii-ref-table"><?php esc_html_e( 'Referencias', 'sii-boleta-dte' ); ?></label></th>
												<td>
														<p class="description" id="sii-nc-reference-hint" data-hint-anula="<?php esc_attr_e( 'La referencia usará el código 1 para anular el documento original. Completa folio y fecha.', 'sii-boleta-dte' ); ?>" data-hint-texto="<?php esc_attr_e( 'La referencia usará el código 2 para corregir texto. Describe la corrección en la glosa y completa folio y fecha.', 'sii-boleta-dte' ); ?>" data-hint-montos="<?php esc_attr_e( 'La referencia usará el código 3 para corregir montos. Completa folio y fecha del documento base.', 'sii-boleta-dte' ); ?>" style="display:none;"></p>
                                                                                                                <div class="sii-ref-table-wrapper">
                                                                                                                               <table id="sii-ref-table" class="widefat">
																<thead>
																		<tr>
																				<th><?php esc_html_e( 'Tipo', 'sii-boleta-dte' ); ?></th>
																				<th><?php esc_html_e( 'Folio', 'sii-boleta-dte' ); ?></th>
																				<th><?php esc_html_e( 'Fecha', 'sii-boleta-dte' ); ?></th>
																				<th><?php esc_html_e( 'Código ref.', 'sii-boleta-dte' ); ?></th>
																				<th><?php esc_html_e( 'Razón / glosa', 'sii-boleta-dte' ); ?></th>
																				<th><?php esc_html_e( 'Global', 'sii-boleta-dte' ); ?></th>
																				<th></th>
																		</tr>
																</thead>
																<tbody>
																		<tr data-ref-row="0">
																				<td data-label="<?php esc_attr_e( 'Tipo', 'sii-boleta-dte' ); ?>">
                                                                                                                               <select name="references[0][tipo]" data-ref-field="tipo">
                                                                                                                               <option value=""><?php echo esc_html_x( '—', 'Reference empty option', 'sii-boleta-dte' ); ?></option>
                                                                                                                               <?php foreach ( $reference_type_options as $code => $label ) : ?>
                                                                                                                               <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $ref0_tipo, (string) $code, true ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                               <?php endforeach; ?>
                                                                                                                               </select>
																				</td>
																				<td data-label="<?php esc_attr_e( 'Folio', 'sii-boleta-dte' ); ?>"><input type="number" name="references[0][folio]" data-ref-field="folio" value="<?php echo $ref0_folio; ?>" step="1" /></td>
																				<td data-label="<?php esc_attr_e( 'Fecha', 'sii-boleta-dte' ); ?>"><input type="date" name="references[0][fecha]" data-ref-field="fecha" value="<?php echo $ref0_fecha; ?>" /></td>
																				<td data-label="<?php esc_attr_e( 'Código ref.', 'sii-boleta-dte' ); ?>">
                                                                                                                               <select name="references[0][codref]" data-ref-field="codref">
                                                                                                                               <option value=""><?php echo esc_html_x( '—', 'Reference empty option', 'sii-boleta-dte' ); ?></option>
                                                                                                                               <?php foreach ( $reference_code_options as $code => $label ) : ?>
                                                                                                                               <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $ref0_codref, (string) $code, true ); ?>><?php echo esc_html( $label ); ?></option>
                                                                                                                               <?php endforeach; ?>
                                                                                                                               </select>
																				</td>
																				<td data-label="<?php esc_attr_e( 'Razón / glosa', 'sii-boleta-dte' ); ?>"><input type="text" name="references[0][razon]" data-ref-field="razon" value="<?php echo $ref0_razon; ?>" /></td>
																				<td data-label="<?php esc_attr_e( 'Global', 'sii-boleta-dte' ); ?>" class="sii-ref-checkbox">
																						<label>
																								<input type="checkbox" name="references[0][global]" data-ref-field="global" value="1" <?php echo $ref0_global_attr; ?> />
																								<span class="screen-reader-text"><?php esc_html_e( 'Referencia global', 'sii-boleta-dte' ); ?></span>
																						</label>
																				</td>
																				<td data-label="<?php esc_attr_e( 'Acciones', 'sii-boleta-dte' ); ?>">
																						<button type="button" class="button remove-reference" aria-label="<?php esc_attr_e( 'Eliminar referencia', 'sii-boleta-dte' ); ?>">×</button>
																				</td>
																		</tr>
                                                                                                                               </tbody>
                                                                                                                               </table>
                                                                                                                <template id="sii-ref-row-template">
                                                                                                                               <tr data-ref-row="__index__">
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Tipo', 'sii-boleta-dte' ) ); ?>">
                                                                                                                                                             <select name="references[__index__][tipo]" data-ref-field="tipo">
                                                                                                                                                                            <option value=""><?php echo esc_html_x( '—', 'Reference empty option', 'sii-boleta-dte' ); ?></option>
                                                                                                                                                                            <?php foreach ( $reference_type_options as $code => $label ) : ?>
                                                                                                                                                                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                            <?php endforeach; ?>
                                                                                                                                                             </select>
                                                                                                                                              </td>
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Folio', 'sii-boleta-dte' ) ); ?>"><input type="number" name="references[__index__][folio]" data-ref-field="folio" value="" step="1" /></td>
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Fecha', 'sii-boleta-dte' ) ); ?>"><input type="date" name="references[__index__][fecha]" data-ref-field="fecha" value="" /></td>
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Código ref.', 'sii-boleta-dte' ) ); ?>">
                                                                                                                                                             <select name="references[__index__][codref]" data-ref-field="codref">
                                                                                                                                                                            <option value=""><?php echo esc_html_x( '—', 'Reference empty option', 'sii-boleta-dte' ); ?></option>
                                                                                                                                                                            <?php foreach ( $reference_code_options as $code => $label ) : ?>
                                                                                                                                                                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
                                                                                                                                                                            <?php endforeach; ?>
                                                                                                                                                             </select>
                                                                                                                                              </td>
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Razón / glosa', 'sii-boleta-dte' ) ); ?>"><input type="text" name="references[__index__][razon]" data-ref-field="razon" value="" /></td>
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Global', 'sii-boleta-dte' ) ); ?>" class="sii-ref-checkbox">
                                                                                                                                                             <label>
                                                                                                                                                                            <input type="checkbox" name="references[__index__][global]" data-ref-field="global" value="1" />
                                                                                                                                                                            <span class="screen-reader-text"><?php echo esc_html__( 'Referencia global', 'sii-boleta-dte' ); ?></span>
                                                                                                                                                             </label>
                                                                                                                                              </td>
                                                                                                                                              <td data-label="<?php echo esc_attr( __( 'Acciones', 'sii-boleta-dte' ) ); ?>">
                                                                                                                                                             <button type="button" class="button remove-reference" aria-label="<?php echo esc_attr( __( 'Eliminar referencia', 'sii-boleta-dte' ) ); ?>">×</button>
                                                                                                                                              </td>
                                                                                                                               </tr>
                                                                                                                </template>
                                                                                                               </div>
														<p><button type="button" class="button" id="sii-add-reference"><?php esc_html_e( 'Agregar referencia', 'sii-boleta-dte' ); ?></button></p>
														<p class="description" id="sii-nc-global-note" style="display:none;">
																<?php esc_html_e( 'Las notas de crédito 61 siempre refieren a un documento específico, por lo que la casilla “Global” permanecerá desactivada.', 'sii-boleta-dte' ); ?>
														</p>
												</td>
										</tr>
																</tbody>
								</table>
								</div>
												<div class="sii-step-navigation sii-step-navigation--start">
														<button type="button" class="button sii-step-prev"><?php esc_html_e( 'Anterior', 'sii-boleta-dte' ); ?></button>
												</div>
												<div class="sii-generate-actions">
													<button type="submit" class="button sii-action-icon" name="preview" aria-label="<?php echo esc_attr__( 'Previsualizar PDF', 'sii-boleta-dte' ); ?>" title="<?php echo esc_attr__( 'Previsualizar PDF', 'sii-boleta-dte' ); ?>" data-label="<?php echo esc_attr__( 'Previsualizar PDF', 'sii-boleta-dte' ); ?>">
														<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
														<span class="sii-action-text"><?php esc_html_e( 'Previsualizar PDF', 'sii-boleta-dte' ); ?></span>
													</button>
													<button type="button" class="button sii-action-icon" id="sii-preview-xml-btn" aria-label="<?php echo esc_attr__( 'Previsualizar XML', 'sii-boleta-dte' ); ?>" title="<?php echo esc_attr__( 'Previsualizar XML', 'sii-boleta-dte' ); ?>" data-label="<?php echo esc_attr__( 'Previsualizar XML', 'sii-boleta-dte' ); ?>">
														<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
														<span class="sii-action-text"><?php esc_html_e( 'Previsualizar XML', 'sii-boleta-dte' ); ?></span>
													</button>
                                                                                                        <button type="submit" class="button button-primary sii-action-icon" name="submit" aria-label="<?php echo esc_attr__( 'Enviar al SII', 'sii-boleta-dte' ); ?>" title="<?php echo esc_attr__( 'Enviar al SII', 'sii-boleta-dte' ); ?>" data-label="<?php echo esc_attr__( 'Enviar al SII', 'sii-boleta-dte' ); ?>" data-dev-simulation-mode="<?php echo esc_attr( $dev_simulation_mode ); ?>">
                                                                                                                <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                                                                                                                <span class="sii-action-text"><?php esc_html_e( 'Enviar al SII', 'sii-boleta-dte' ); ?></span>
                                                                                                        </button>
												</div>
                                                                                                <?php if ( $is_development_environment ) : ?>
                                                                                                        <p class="description" style="margin-top:8px;">
                                                                                                                <?php
                                                                                                                if ( 'error' === $dev_simulation_mode ) {
                                                                                                                        esc_html_e( 'Los envíos se simulan con error en desarrollo para probar los reintentos automáticos.', 'sii-boleta-dte' );
                                                                                                                } elseif ( 'disabled' === $dev_simulation_mode ) {
                                                                                                                        esc_html_e( 'Has desactivado la simulación: el plugin intentará contactar al SII real incluso en desarrollo.', 'sii-boleta-dte' );
                                                                                                                } else {
                                                                                                                        esc_html_e( 'Los envíos al SII se simulan como exitosos en desarrollo. Ajusta este comportamiento desde Ajustes → Integraciones.', 'sii-boleta-dte' );
                                                                                                                }
                                                                                                                ?>
                                                                                                        </p>
                                                                                                <?php endif; ?>
												<div id="sii-xml-preview-modal" class="sii-xml-modal" style="display:none">
																	<div class="sii-xml-modal-backdrop"></div>
																	<div class="sii-xml-modal-content" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'XML Preview', 'sii-boleta-dte' ); ?>">
																		<button type="button" class="sii-xml-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'sii-boleta-dte' ); ?>">&times;</button>
																		<h3 style="margin-top:0;"><?php esc_html_e( 'Previsualización XML (sin folio definitivo)', 'sii-boleta-dte' ); ?></h3>
																		<div class="sii-xml-actions" style="margin-bottom:8px; display:flex; gap:8px; flex-wrap:wrap;">
																			<button type="button" class="button" id="sii-xml-copy"><?php esc_html_e( 'Copiar', 'sii-boleta-dte' ); ?></button>
																			<button type="button" class="button" id="sii-xml-download"><?php esc_html_e( 'Descargar', 'sii-boleta-dte' ); ?></button>
																			<button type="button" class="button" id="sii-xml-validate"><?php esc_html_e( 'Validar XSD', 'sii-boleta-dte' ); ?></button>
																			<button type="button" class="button" id="sii-xml-validate-envio"><?php esc_html_e( 'Validar Envío', 'sii-boleta-dte' ); ?></button>
																			<button type="button" class="button" id="sii-xml-wrap" aria-pressed="false">Wrap</button>
																			<div style="flex-basis:100%;font-size:11px;color:#666;line-height:1.4;">
																				<strong><?php esc_html_e( 'Ayuda:', 'sii-boleta-dte' ); ?></strong>
																				<?php esc_html_e( '“Validar XSD” revisa sólo el XML del documento. “Validar Envío” genera un sobre EnvioDTE temporal y valida la estructura completa.', 'sii-boleta-dte' ); ?>
																			</div>
																			<span id="sii-xml-meta" style="align-self:center;font-size:11px;color:#555;"></span>
																		</div>
																		<pre id="sii-xml-code" style="background:#111;color:#f8f8f2;padding:12px;max-height:420px;overflow:auto;border:1px solid #444;font-size:12px;line-height:1.4;"><code></code></pre>
																		<div id="sii-xml-validation" style="margin-top:10px;position:relative;">
																			<div id="sii-xml-validation-spinner" style="display:none;position:absolute;top:4px;right:4px;width:18px;height:18px;border:2px solid #999;border-top-color:#1e8cbe;border-radius:50%;animation:siiSpin 0.8s linear infinite"></div>
																		</div>
																	</div>
																</div>
								</section>
										</form>
								</div>
								<aside class="sii-generate-dte-aside">
										<div class="sii-generate-dte-card sii-generate-dte-card--accent">
																								<h2><?php esc_html_e( 'Resumen del espacio de trabajo', 'sii-boleta-dte' ); ?></h2>
												<ul class="sii-generate-dte-summary">
																												<li><?php printf( esc_html__( 'Ambiente: %s', 'sii-boleta-dte' ), esc_html( $environment_label ) ); ?></li>
																												<li><?php echo esc_html( $rvd_automation ? __( 'La automatización diaria del RVD está habilitada.', 'sii-boleta-dte' ) : __( 'La automatización del RVD está deshabilitada actualmente.', 'sii-boleta-dte' ) ); ?></li>
																												<li><?php echo esc_html( $libro_automation ? __( 'La validación del libro se ejecuta de forma programada.', 'sii-boleta-dte' ) : __( 'La validación del libro está configurada de forma manual.', 'sii-boleta-dte' ) ); ?></li>
												</ul>
																								<p><?php esc_html_e( 'Utiliza la previsualización para revisar timbres, folios y totales de ítems antes de enviar el documento al SII.', 'sii-boleta-dte' ); ?></p>
										</div>
										<div class="sii-generate-dte-card sii-generate-dte-card--tips">
																								<h2><?php esc_html_e( 'Consejos útiles', 'sii-boleta-dte' ); ?></h2>
																								<ol class="sii-generate-dte-tips">
																												<li data-tip-type="*"><?php esc_html_e( 'Agrega descripciones claras a los ítems y mantén precios unitarios en pesos enteros para totales precisos.', 'sii-boleta-dte' ); ?></li>
																												<li data-tip-type="*"><?php esc_html_e( 'Al emitir facturas o guías, completa los campos de dirección del receptor para agilizar la logística.', 'sii-boleta-dte' ); ?></li>
																												<li data-tip-type="*"><?php esc_html_e( 'Si tu cliente no tiene RUT para boletas, el sistema aplicará automáticamente el identificador genérico del SII.', 'sii-boleta-dte' ); ?></li>
																												<li data-tip-type="61" style="display:none;"><?php esc_html_e( 'Para notas de crédito 61, referencia siempre el documento original con su tipo, folio y fecha.', 'sii-boleta-dte' ); ?></li>
																												<li data-tip-type="61" style="display:none;"><?php esc_html_e( 'Si solo corriges texto, deja los ítems en cero y describe la modificación en la glosa de la referencia.', 'sii-boleta-dte' ); ?></li>
																								</ol>
										</div>
								</aside>
												</div>
								<?php AdminStyles::close_container(); ?>
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
				$settings_cfg     = $this->settings->get_settings();
				$configured_giros = array();
		if ( isset( $settings_cfg['giros'] ) && is_array( $settings_cfg['giros'] ) ) {
			foreach ( $settings_cfg['giros'] as $giro_option ) {
						$giro_value = (string) $giro_option;
				if ( '' !== $giro_value ) {
					$configured_giros[] = $giro_value;
				}
			}
		}
				$default_emitter_giro = '';
		if ( isset( $settings_cfg['giro'] ) ) {
				$default_emitter_giro = (string) $settings_cfg['giro'];
		}
		if ( '' === $default_emitter_giro && ! empty( $configured_giros ) ) {
				$default_emitter_giro = $configured_giros[0];
		}
				$preview     = isset( $post['preview'] );
				$available   = $this->get_available_types();
				$rut_raw     = sanitize_text_field( (string) ( $post['rut'] ?? '' ) );
				$razon       = sanitize_text_field( (string) ( $post['razon'] ?? '' ) );
				$giro        = sanitize_text_field( (string) ( $post['giro'] ?? '' ) );
				$giro_emisor = isset( $post['giro_emisor'] ) ? sanitize_text_field( (string) $post['giro_emisor'] ) : $default_emitter_giro;
		if ( '' === $giro_emisor ) {
				$giro_emisor = $default_emitter_giro;
		}
		if ( '' !== $giro_emisor ) {
				$_POST['giro_emisor'] = $giro_emisor; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$tipo = (int) ( $post['tipo'] ?? ( array_key_first( $available ) ?? 39 ) );
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
				$rut          = $normalized;
				$_POST['rut'] = $rut; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( $requires_rut ) {
				return array( 'error' => __( 'El RUT del receptor es obligatorio para este tipo de documento.', 'sii-boleta-dte' ) );
		}
				$dir_recep     = sanitize_text_field( (string) ( $post['dir_recep'] ?? '' ) );
				$cmna_recep    = sanitize_text_field( (string) ( $post['cmna_recep'] ?? '' ) );
				$ciudad_recep  = sanitize_text_field( (string) ( $post['ciudad_recep'] ?? '' ) );
				$tipo_despacho = isset( $post['tipo_despacho'] ) ? trim( (string) $post['tipo_despacho'] ) : '';
		if ( ! in_array( $tipo_despacho, array( '1', '2', '3' ), true ) ) {
						$tipo_despacho = '';
		}
		if ( '' !== $tipo_despacho ) {
						$_POST['tipo_despacho'] = $tipo_despacho; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$ind_traslado = isset( $post['ind_traslado'] ) ? trim( (string) $post['ind_traslado'] ) : '';
		if ( ! in_array( $ind_traslado, array( '1', '2', '3', '4', '5', '6', '7' ), true ) ) {
						$ind_traslado = '';
		}
		if ( '' !== $ind_traslado ) {
						$_POST['ind_traslado'] = $ind_traslado; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$nc_motive = '';
		if ( 61 === $tipo ) {
						$raw_nc_motive = isset( $post['nc_motivo'] ) ? sanitize_key( (string) $post['nc_motivo'] ) : '';
			if ( array_key_exists( $raw_nc_motive, self::CREDIT_NOTE_MOTIVES ) ) {
						$nc_motive = $raw_nc_motive;
			} else {
								$nc_motive = 'anula';
			}
						$_POST['nc_motivo'] = $nc_motive; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_POST['nc_motivo'] ) ) {
						unset( $_POST['nc_motivo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$transporte_patente = sanitize_text_field( (string) ( $post['transporte_patente'] ?? '' ) );
		if ( '' !== $transporte_patente ) {
						$_POST['transporte_patente'] = $transporte_patente; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$transporte_rut_raw = isset( $post['transporte_rut'] ) ? (string) $post['transporte_rut'] : '';
				$transporte_rut     = '';
		if ( '' !== trim( $transporte_rut_raw ) ) {
				$normalized = $this->normalize_rut( $transporte_rut_raw );
			if ( '' === $normalized || ! $this->is_valid_rut( $normalized ) ) {
										return array( 'error' => __( 'El RUT del transportista no es válido.', 'sii-boleta-dte' ) );
			}
				$transporte_rut          = $normalized;
				$_POST['transporte_rut'] = $transporte_rut; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$transporte_chofer_rut_raw = isset( $post['transporte_chofer_rut'] ) ? (string) $post['transporte_chofer_rut'] : '';
				$transporte_chofer_rut     = '';
		if ( '' !== trim( $transporte_chofer_rut_raw ) ) {
				$normalized = $this->normalize_rut( $transporte_chofer_rut_raw );
			if ( '' === $normalized || ! $this->is_valid_rut( $normalized ) ) {
										return array( 'error' => __( 'El RUT del chofer no es válido.', 'sii-boleta-dte' ) );
			}
				$transporte_chofer_rut          = $normalized;
				$_POST['transporte_chofer_rut'] = $transporte_chofer_rut; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$transporte_chofer_nombre = sanitize_text_field( (string) ( $post['transporte_chofer_nombre'] ?? '' ) );
		if ( '' !== $transporte_chofer_nombre ) {
						$_POST['transporte_chofer_nombre'] = $transporte_chofer_nombre; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$transporte_dir_dest = sanitize_text_field( (string) ( $post['transporte_dir_dest'] ?? '' ) );
		if ( '' !== $transporte_dir_dest ) {
						$_POST['transporte_dir_dest'] = $transporte_dir_dest; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				$transporte_cmna_dest = sanitize_text_field( (string) ( $post['transporte_cmna_dest'] ?? '' ) );
		if ( '' !== $transporte_cmna_dest ) {
						$_POST['transporte_cmna_dest'] = $transporte_cmna_dest; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( 52 === $tipo && '' === $ind_traslado ) {
						return array( 'error' => __( 'Debes seleccionar el indicador de traslado para la guía de despacho.', 'sii-boleta-dte' ) );
		}
								$fma_pago        = isset( $post['fma_pago'] ) ? trim( (string) $post['fma_pago'] ) : '';
								$term_pago_glosa = sanitize_text_field( (string) ( $post['term_pago_glosa'] ?? '' ) );
								$fch_venc        = trim( (string) ( $post['fch_venc'] ?? '' ) );
								$ind_servicio    = isset( $post['ind_servicio'] ) ? trim( (string) $post['ind_servicio'] ) : '';
								$correo_recep    = isset( $post['correo_recep'] ) ? sanitize_email( (string) $post['correo_recep'] ) : '';
								$contacto_recep  = sanitize_text_field( (string) ( $post['contacto_recep'] ?? '' ) );
		$dsc_global_mov                          = isset( $post['dsc_global_mov'] ) ? strtoupper( substr( sanitize_text_field( (string) $post['dsc_global_mov'] ), 0, 1 ) ) : '';
		$dsc_global_tipo                         = isset( $post['dsc_global_tipo'] ) ? substr( sanitize_text_field( (string) $post['dsc_global_tipo'] ), 0, 1 ) : '';
		$dsc_global_raw                          = $post['dsc_global_valor'] ?? '';
		$dsc_global_val                          = $this->parse_amount( $dsc_global_raw );
               $has_dsc_global_ind_exe                  = array_key_exists( 'dsc_global_ind_exe', $post );
               $dsc_global_ind_exe_raw                  = $has_dsc_global_ind_exe ? trim( (string) $post['dsc_global_ind_exe'] ) : '';
               $has_dsc_global_ind_exe                  = '' !== $dsc_global_ind_exe_raw;
               $dsc_global_ind_exe                      = $has_dsc_global_ind_exe ? (int) $dsc_global_ind_exe_raw : 0;
                if ( ! in_array( $dsc_global_ind_exe, array( 1, 2 ), true ) ) {
                        $dsc_global_ind_exe = 0;
                }
                if ( 0 === $dsc_global_ind_exe && ! $has_dsc_global_ind_exe && in_array( $tipo, array( 34, 41 ), true ) ) {
                        $dsc_global_ind_exe = 1;
                }
		$periodo_desde = isset( $post['periodo_desde'] ) ? sanitize_text_field( (string) $post['periodo_desde'] ) : '';
		$periodo_hasta = isset( $post['periodo_hasta'] ) ? sanitize_text_field( (string) $post['periodo_hasta'] ) : '';
		$cod_int_recep = isset( $post['cod_int_recep'] ) ? sanitize_text_field( (string) $post['cod_int_recep'] ) : '';
				$items = array();
				$n     = 1;
		$raw           = $post['items'] ?? array();
                if ( $preview ) {
                        $count = is_array( $raw ) ? count( $raw ) : 0;
                        $this->debug_log( '[preview] raw items count=' . $count );
                }
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item_index => $item ) {
						$qty  = isset( $item['qty'] ) ? $this->parse_amount( $item['qty'] ) : 1.0;
						$desc = sanitize_text_field( (string) ( $item['desc'] ?? '' ) );
				if ( '' !== $desc ) {
					$desc = preg_replace( '/\s+\(([^()]+)\)\s+\(\1\)$/u', ' ($1)', $desc );
					$desc = preg_replace( '/\s{2,}/', ' ', (string) $desc );
					$desc = trim( (string) $desc );
				}
						$price = isset( $item['price'] ) ? (int) round( $this->parse_amount( $item['price'] ) ) : 0;
				if ( '' === $desc ) {
					continue;
				}
						$discount_pct    = isset( $item['discount_pct'] ) ? $this->parse_amount( $item['discount_pct'] ) : 0.0;
						$discount_amount = isset( $item['discount_amount'] ) ? $this->parse_amount( $item['discount_amount'] ) : 0.0;
				if ( 61 === $tipo && 'texto' === $nc_motive ) {
						$qty             = 1.0;
						$price           = 0;
						$discount_pct    = 0.0;
						$discount_amount = 0.0;
					if ( isset( $_POST['items'][ $item_index ] ) && is_array( $_POST['items'][ $item_index ] ) ) {
							$_POST['items'][ $item_index ]['qty']             = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
							$_POST['items'][ $item_index ]['price']           = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
							$_POST['items'][ $item_index ]['discount_pct']    = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
							$_POST['items'][ $item_index ]['discount_amount'] = '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
					}
				} else {
					if ( $qty <= 0 ) {
								$qty = 1.0;
					}
					if ( $price < 0 ) {
									$price = 0;
					}
				}
						$line_total = (int) round( $qty * $price );

				$line = array(
					'NroLinDet' => $n++,
					'NmbItem'   => $desc,
					'QtyItem'   => $qty,
					'PrcItem'   => $price,
					'MontoItem' => $line_total,
				);

														$code_type  = isset( $item['code_type'] ) ? sanitize_text_field( (string) $item['code_type'] ) : '';
														$code_value = isset( $item['code_value'] ) ? sanitize_text_field( (string) $item['code_value'] ) : '';
				if ( '' !== $code_type || '' !== $code_value ) {
						$line['CdgItem'] = array(
							'TpoCodigo' => $code_type,
							'VlrCodigo' => $code_value,
						);
				}

														$extra_desc = isset( $item['extra_desc'] ) ? sanitize_textarea_field( (string) $item['extra_desc'] ) : '';
				if ( '' !== $extra_desc ) {
								$line['DscItem'] = $extra_desc;
				}

														$unit_item = isset( $item['unit_item'] ) ? sanitize_text_field( (string) $item['unit_item'] ) : '';
				if ( '' !== $unit_item ) {
								$line['UnmdItem'] = $unit_item;
				}

														$unit_ref = isset( $item['unit_ref'] ) ? sanitize_text_field( (string) $item['unit_ref'] ) : '';
				if ( '' !== $unit_ref ) {
								$line['UnmdRef'] = $unit_ref;
				}

				if ( $discount_pct > 0 ) {
						$line['DescuentoPct'] = $discount_pct;
				}

				if ( $discount_amount > 0 ) {
						$line['DescuentoMonto'] = (int) round( $discount_amount );
				}

														$tax_code = isset( $item['tax_code'] ) ? sanitize_text_field( (string) $item['tax_code'] ) : '';
				if ( '' !== $tax_code ) {
								$line['CodImpAdic'] = $tax_code;
				}

				$retainer = isset( $item['retained_indicator'] ) ? sanitize_text_field( (string) $item['retained_indicator'] ) : '';
				if ( '' !== $retainer ) {
					$line['Retenedor'] = array( 'IndAgente' => $retainer );
				}

				$exempt_indicator = isset( $item['exempt_indicator'] ) ? (int) $item['exempt_indicator'] : 0;
				if ( ! in_array( $exempt_indicator, array( 1, 2 ), true ) ) {
					$exempt_indicator = 0;
				}
				if ( 0 === $exempt_indicator && in_array( $tipo, array( 34, 41 ), true ) ) {
					$exempt_indicator = 1;
				}
				if ( $exempt_indicator > 0 ) {
					$line['IndExe'] = $exempt_indicator;
				}
				if ( isset( $_POST['items'][ $item_index ] ) && is_array( $_POST['items'][ $item_index ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					if ( $exempt_indicator > 0 ) {
						$_POST['items'][ $item_index ]['exempt_indicator'] = (string) $exempt_indicator; // phpcs:ignore WordPress.Security.NonceVerification.Missing
					} else {
						unset( $_POST['items'][ $item_index ]['exempt_indicator'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
					}
				}

				// Lógica de interpretación de precios:
				//  - Boleta (39): valores ingresados se consideran BRUTOS (con IVA) => MntBruto = 1.
				//  - Guía de Despacho (52): por requerimiento se fuerza a NETO (sin IVA) => nunca MntBruto.
				//  - Factura (33) y Factura de Compra (46): NETO.
				//  - Otros tipos: se consulta la configuración global (WooCommerce) para mantener comportamiento previo.
				$prices_include_tax = false;
				if ( 39 === $tipo ) {
					$prices_include_tax = true; // Boleta: bruto
				} elseif ( 52 === $tipo ) {
					$prices_include_tax = false; // Guía: siempre neto (cambio solicitado)
				} elseif ( in_array( $tipo, array( 33, 46 ), true ) ) {
					$prices_include_tax = false; // Facturas: neto
				} else {
					if ( function_exists( 'wc_prices_include_tax' ) ) {
						$prices_include_tax = (bool) wc_prices_include_tax();
					} elseif ( function_exists( 'get_option' ) ) {
						$prices_include_tax = ( 'yes' === get_option( 'woocommerce_prices_include_tax', 'no' ) );
					}
				}
				if ( $prices_include_tax ) {
					$line['MntBruto'] = 1;
				} else {
					unset( $line['MntBruto'] );
				}

				$items[] = $line;
			}
		}
		// --- Post-procesamiento para Factura de Compra (46) ---
		if ( 46 === $tipo && ! empty( $items ) ) {
			// Exención global (dsc_global_ind_exe) extendida a tipo 46.
			$global_exe = isset( $_POST['dsc_global_ind_exe'] ) ? (string) $_POST['dsc_global_ind_exe'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( in_array( $global_exe, array( '1', '2' ), true ) ) {
				foreach ( $items as &$it ) {
					if ( empty( $it['IndExe'] ) ) {
						$it['IndExe'] = (int) $global_exe;
					}
				}
				unset( $it );
			}

			// Retención IVA.
			$retencion_tipo  = isset( $_POST['retencion_tipo'] ) ? sanitize_text_field( (string) $_POST['retencion_tipo'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$retencion_codigo = '';
			if ( 'total' === $retencion_tipo ) {
				$retencion_codigo = '15';
			} elseif ( 'parcial' === $retencion_tipo ) {
				$raw_code = isset( $_POST['retencion_codigo'] ) ? (string) $_POST['retencion_codigo'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( preg_match( '/^(30|31|32|33|34|36|37|38|39|41|47|48)$/', $raw_code ) ) {
					$retencion_codigo = $raw_code;
				}
			}
			$apply_all = ! empty( $_POST['retencion_aplicar_todo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( '' !== $retencion_codigo ) {
				// Evitar retención si todas las líneas quedaron exentas.
				$has_afecta = false;
				foreach ( $items as $it ) {
					if ( empty( $it['IndExe'] ) ) { $has_afecta = true; break; }
				}
				if ( $has_afecta ) {
					foreach ( $items as &$it ) {
						if ( ! empty( $it['IndExe'] ) ) { continue; } // no aplicar a exentas
						if ( $apply_all || empty( $it['CodImpAdic'] ) ) {
							$it['CodImpAdic'] = $retencion_codigo;
							// Marcar agente retenedor si no viene
							if ( empty( $it['Retenedor'] ) ) {
								$it['Retenedor'] = array( 'IndAgente' => '1' );
							}
						}
					}
					unset( $it );
				}
			}

			// Proveedor extranjero: agregar glosa a TermPagoGlosa.
			$is_foreign = ! empty( $_POST['foreign_supplier'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $is_foreign ) {
				$country = isset( $_POST['foreign_country'] ) ? strtoupper( sanitize_text_field( (string) $_POST['foreign_country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$country = substr( preg_replace( '/[^A-Z0-9]/', '', $country ), 0, 3 );
				$tax_id  = isset( $_POST['foreign_tax_id'] ) ? sanitize_text_field( (string) $_POST['foreign_tax_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$fdesc   = isset( $_POST['foreign_desc'] ) ? sanitize_textarea_field( (string) $_POST['foreign_desc'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$foreign_glosa_parts = array();
				$foreign_glosa_parts[] = 'Proveedor extranjero';
				if ( $country ) { $foreign_glosa_parts[] = 'País: ' . $country; }
				if ( $tax_id ) { $foreign_glosa_parts[] = 'ID: ' . $tax_id; }
				if ( $fdesc ) { $foreign_glosa_parts[] = $fdesc; }
				$foreign_glosa = implode( ' | ', $foreign_glosa_parts );
				// Guardar para uso posterior al armar encabezado (se añadirá a TermPagoGlosa si existe más abajo).
				$extra_notes = isset( $encabezado['IdDoc']['TermPagoGlosa'] ) ? (string) $encabezado['IdDoc']['TermPagoGlosa'] : '';
				if ( false === stripos( $extra_notes, $foreign_glosa ) ) {
					$encabezado['IdDoc']['TermPagoGlosa'] = trim( $extra_notes . ( $extra_notes ? ' | ' : '' ) . $foreign_glosa );
				}
			}
		}
                if ( $preview ) {
                        $this->debug_log( '[preview] parsed items count=' . count( $items ) );
                }
		// If Boleta/Boleta Exenta without RUT, use generic SII rut
		if ( in_array( $tipo, array( 39, 41 ), true ) && '' === $rut ) {
				$rut = '66666666-6';
		}

                $folio      = 0;
                $engine_err = function ( $result ) use ( $tipo, $available ) {
                        if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
                                $code = method_exists( $result, 'get_error_code' ) ? $result->get_error_code() : '';
                                $msg  = $result->get_error_message();
                                if ( 'sii_boleta_missing_caf' === $code ) {
                                        $tipo_label = $available[ $tipo ] ?? (string) $tipo;
                                        $msg        = sprintf( __( 'No hay un CAF configurado para el tipo %s. Sube un CAF en “Folios / CAFs”.', 'sii-boleta-dte' ), $tipo_label );
                                }
                                return array( 'error' => $msg );
                        }
                        if ( $result instanceof \WP_Error ) {
                                $msg = method_exists( $result, 'get_error_message' ) ? $result->get_error_message() : '';
                                if ( '' === $msg ) {
                                        $msg = __( 'No fue posible generar el XML del documento tributario.', 'sii-boleta-dte' );
                                }
                                return array( 'error' => $msg );
                        }

                        return null;
                };

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
				if ( '' !== $dir_recep ) {
						$data['Receptor']['DirRecep'] = $dir_recep; }
				if ( '' !== $cmna_recep ) {
						$data['Receptor']['CmnaRecep'] = $cmna_recep; }
				if ( '' !== $ciudad_recep ) {
						$data['Receptor']['CiudadRecep'] = $ciudad_recep; }
				if ( '' !== $correo_recep ) {
						$data['Receptor']['CorreoRecep'] = $correo_recep; }
				if ( '' !== $contacto_recep ) {
					$data['Receptor']['Contacto'] = $contacto_recep; }
				if ( '' !== $cod_int_recep ) {
					$data['Receptor']['CdgIntRecep'] = $cod_int_recep; }

				$encabezado = array(
					'IdDoc'    => array(
						'TipoDTE' => $tipo,
						'Folio'   => $folio,
						'FchEmis' => $data['FchEmis'],
					),
					'Receptor' => $data['Receptor'],
				);

				if ( '' !== $giro_emisor ) {
						$encabezado['Emisor'] = array(
							'GiroEmisor' => $giro_emisor,
							'GiroEmis'   => $giro_emisor,
						);
				}

				if ( '' !== $tipo_despacho ) {
						$encabezado['IdDoc']['TipoDespacho'] = (int) $tipo_despacho;
				}
				if ( '' !== $ind_traslado ) {
						$encabezado['IdDoc']['IndTraslado'] = (int) $ind_traslado;
				}

				$transporte = array();
				if ( '' !== $transporte_patente ) {
						$transporte['Patente'] = $transporte_patente;
				}
				if ( '' !== $transporte_rut ) {
						$transporte['RUTTrans'] = $transporte_rut;
				}
				$transporte_chofer = array();
				if ( '' !== $transporte_chofer_nombre ) {
						$transporte_chofer['NombreChofer'] = $transporte_chofer_nombre;
				}
				if ( '' !== $transporte_chofer_rut ) {
						$transporte_chofer['RUTChofer'] = $transporte_chofer_rut;
				}
				if ( ! empty( $transporte_chofer ) ) {
						$transporte['Chofer'] = $transporte_chofer;
				}
				if ( '' !== $transporte_dir_dest ) {
						$transporte['DirDest'] = $transporte_dir_dest;
				}
				if ( '' !== $transporte_cmna_dest ) {
						$transporte['CmnaDest'] = $transporte_cmna_dest;
				}
				if ( ! empty( $transporte ) ) {
						$encabezado['Transporte'] = $transporte;
				}

				// Fallback: para Guía de Despacho (52) inyectar datos de transporte en TermPagoGlosa
				// cuando el usuario no ingresó una glosa manual. Esto asegura que la información
				// sea visible incluso si el template activo no muestra explícitamente <Transporte>.
				if ( 52 === $tipo && '' === $term_pago_glosa && ! empty( $encabezado['Transporte'] ) ) {
					$t = $encabezado['Transporte'];
					$parts = array();
					$parts[] = 'Transporte';
					if ( ! empty( $t['Patente'] ) ) { $parts[] = 'Patente: ' . $t['Patente']; }
					if ( ! empty( $t['RUTTrans'] ) ) { $parts[] = 'RUT: ' . $t['RUTTrans']; }
					if ( ! empty( $t['Chofer'] ) && ( ! empty( $t['Chofer']['NombreChofer'] ) || ! empty( $t['Chofer']['RUTChofer'] ) ) ) {
						$chofer_txt = 'Chofer';
						if ( ! empty( $t['Chofer']['NombreChofer'] ) ) { $chofer_txt .= ': ' . $t['Chofer']['NombreChofer']; }
						if ( ! empty( $t['Chofer']['RUTChofer'] ) ) { $chofer_txt .= ( strpos( $chofer_txt, ':' ) === false ? ': ' : ' ' ) . '(' . $t['Chofer']['RUTChofer'] . ')'; }
						$parts[] = $chofer_txt;
					}
					$dest_parts = array();
					if ( ! empty( $t['DirDest'] ) ) { $dest_parts[] = $t['DirDest']; }
					if ( ! empty( $t['CmnaDest'] ) ) { $dest_parts[] = $t['CmnaDest']; }
					if ( ! empty( $dest_parts ) ) { $parts[] = 'Destino: ' . implode( ', ', $dest_parts ); }
					$auto_glosa = implode( ' | ', $parts );
					$encabezado['IdDoc']['TermPagoGlosa'] = $auto_glosa;
				}

				if ( '' !== $fma_pago ) {
						$encabezado['IdDoc']['FmaPago'] = $fma_pago; }
				if ( '' !== $term_pago_glosa ) {
						$encabezado['IdDoc']['TermPagoGlosa'] = $term_pago_glosa; }
				if ( '' !== $fch_venc ) {
					$encabezado['IdDoc']['FchVenc'] = $fch_venc; }
				if ( '' !== $ind_servicio ) {
					$encabezado['IdDoc']['IndServicio'] = $ind_servicio; }
				if ( '' !== $periodo_desde ) {
					$encabezado['IdDoc']['PeriodoDesde'] = $periodo_desde; }
				if ( '' !== $periodo_hasta ) {
					$encabezado['IdDoc']['PeriodoHasta'] = $periodo_hasta; }

				$data['Encabezado'] = $encabezado;

				if ( '' !== $dsc_global_mov && '' !== $dsc_global_tipo && $dsc_global_val > 0 ) {
					$valor_dr        = ( '%' === $dsc_global_tipo ) ? $dsc_global_val : (int) round( $dsc_global_val );
					$global_discount = array(
						'TpoMov'   => $dsc_global_mov,
						'TpoValor' => $dsc_global_tipo,
						'ValorDR'  => $valor_dr,
					);
					if ( $dsc_global_ind_exe > 0 ) {
						$global_discount['IndExeDR'] = $dsc_global_ind_exe;
					}
					$data['DscRcgGlobal'] = $global_discount;
				}

				$references             = array();
				$nc_required_code       = ( 61 === $tipo && '' !== $nc_motive ) ? ( self::CREDIT_NOTE_MOTIVES[ $nc_motive ] ?? '' ) : '';
				$nc_has_reference       = false;
				$nc_missing_fields      = false;
				$nc_missing_text_reason = false;
				if ( isset( $post['references'] ) && is_array( $post['references'] ) ) {
					foreach ( $post['references'] as $ref_index => $reference ) {
						if ( ! is_array( $reference ) ) {
								continue;
						}
							$ref_tipo   = isset( $reference['tipo'] ) ? (int) $reference['tipo'] : 0;
							$ref_folio  = isset( $reference['folio'] ) ? trim( (string) $reference['folio'] ) : '';
							$ref_fecha  = isset( $reference['fecha'] ) ? trim( (string) $reference['fecha'] ) : '';
							$ref_razon  = isset( $reference['razon'] ) ? sanitize_text_field( (string) $reference['razon'] ) : '';
							$ref_codref = isset( $reference['codref'] ) ? trim( (string) $reference['codref'] ) : '';
						if ( ! in_array( $ref_codref, array( '1', '2', '3', '4', '5', '6', '7' ), true ) ) {
								$ref_codref = '';
						}
							$ref_global = ! empty( $reference['global'] );
							$has_values = ( 0 !== $ref_tipo ) || ( '' !== $ref_folio ) || ( '' !== $ref_fecha ) || ( '' !== $ref_codref ) || ( '' !== $ref_razon ) || $ref_global;
						if ( ! $has_values ) {
								continue;
						}
						if ( 61 === $tipo ) {
							if ( '' !== $nc_required_code ) {
									$ref_codref = $nc_required_code;
							}
								$ref_global = false;
						}
						if ( isset( $_POST['references'][ $ref_index ] ) && is_array( $_POST['references'][ $ref_index ] ) ) {
								$_POST['references'][ $ref_index ]['codref'] = $ref_codref; // phpcs:ignore WordPress.Security.NonceVerification.Missing
							if ( 61 === $tipo ) {
									unset( $_POST['references'][ $ref_index ]['global'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
							}
						}
						if ( 61 === $tipo ) {
								$nc_has_reference = true;
							if ( 0 === $ref_tipo || '' === $ref_folio || '' === $ref_fecha ) {
									$nc_missing_fields = true;
							}
							if ( 'texto' === $nc_motive && 0 !== $ref_tipo && '' !== $ref_folio && '' !== $ref_fecha && '' === $ref_razon ) {
									$nc_missing_text_reason = true;
							}
						}

							$entry = array();
						if ( 0 !== $ref_tipo ) {
								$entry['TpoDocRef'] = $ref_tipo;
						}
						if ( '' !== $ref_folio || '0' === $ref_folio ) {
								$entry['FolioRef'] = $ref_folio;
						}
						if ( '' !== $ref_fecha ) {
								$entry['FchRef'] = $ref_fecha;
						}
						if ( '' !== $ref_codref ) {
								$entry['CodRef'] = $ref_codref;
						}
						if ( '' !== $ref_razon ) {
								$entry['RazonRef'] = $ref_razon;
						}
						if ( $ref_global ) {
								$entry['IndGlobal'] = 1;
						}

						if ( ! empty( $entry ) ) {
								$references[] = $entry;
						}
					}
				}
				if ( ! empty( $references ) ) {
						$data['Referencias'] = $references; }
                                if ( 61 === $tipo ) {
                                        if ( ! $nc_has_reference ) {
                                                        return array( 'error' => __( 'Debes ingresar al menos una referencia con folio y fecha para la nota de crédito.', 'sii-boleta-dte' ) );
                                        }
                                        if ( $nc_missing_fields ) {
                                                        return array( 'error' => __( 'Completa el tipo, el folio y la fecha del documento referenciado antes de continuar.', 'sii-boleta-dte' ) );
                                        }
                                        if ( 'texto' === $nc_motive && $nc_missing_text_reason ) {
                                                        return array( 'error' => __( 'Describe la corrección en la glosa de la referencia para finalizar la nota de crédito.', 'sii-boleta-dte' ) );
                                        }
                                }

                                if ( ! $preview ) {
                                        $validation = $this->engine->generate_dte_xml( $data, $tipo, true );
                                        $error      = $engine_err( $validation );
                                        if ( null !== $error ) {
                                                return $error;
                                        }

                                        $next = $this->folio_manager->get_next_folio( $tipo, false );
                                        if ( function_exists( 'is_wp_error' ) && is_wp_error( $next ) ) {
                                                return array( 'error' => $next->get_error_message() );
                                        }
                                        if ( $next instanceof \WP_Error ) {
                                                return array( 'error' => $next->get_error_message() );
                                        }

                                        $folio = (int) $next;
                                        if ( $folio <= 0 ) {
                                                return array( 'error' => __( 'No hay folios disponibles para emitir este documento.', 'sii-boleta-dte' ) );
                                        }

                                        $data['Folio']                        = $folio;
                                        if ( isset( $data['Encabezado']['IdDoc'] ) && is_array( $data['Encabezado']['IdDoc'] ) ) {
                                                $data['Encabezado']['IdDoc']['Folio'] = $folio;
                                        }
                                }

                                $xml   = $this->engine->generate_dte_xml( $data, $tipo, $preview );
                                $error = $engine_err( $xml );
                                if ( null !== $error ) {
                                        return $error;
                                }
				$pdf       = $this->pdf->generate( (string) $xml );
				$pdf_label = $available[ $tipo ] ?? sprintf( 'DTE %d', $tipo );

				$pdf_context = array(
					'label'        => $pdf_label,
					'type'         => $tipo,
					'folio'        => $folio,
					'rut_emisor'   => (string) ( $settings_cfg['rut_emisor'] ?? '' ),
					'rut_receptor' => $rut,
				);

				if ( $preview ) {
					$pdf_url = $this->create_preview_pdf_link( $pdf, $pdf_context );

					// Hook: XML preview generado (sin folio definitivo / sin TED).
					if ( function_exists( 'do_action' ) ) {
						\do_action( 'sii_boleta_xml_preview_generated', (string) $xml, array(
							'tipo'  => $tipo,
							'folio' => $folio,
						) );
					}

					// Audit hash (preview) – almacenamos en memoria estática para comparar luego si se emite.
					if ( class_exists( '\\Sii\\BoletaDte\\Infrastructure\\Engine\\XmlAuditService' ) ) {
						$auditor      = new \Sii\BoletaDte\Infrastructure\Engine\XmlAuditService();
						$normalized   = $auditor->normalize( (string) $xml, 'preview' );
						$hash_preview = $auditor->hash( $normalized );
						self::$xml_preview_hash_cache[ $tipo ] = array( 'hash' => $hash_preview, 'normalized' => $normalized );
						if ( \defined( 'WP_DEBUG' ) && \constant( 'WP_DEBUG' ) ) {
							$this->debug_log( '[xml_audit] preview hash tipo=' . $tipo . ' hash=' . $hash_preview );
						}
					}

					return array(
						'preview' => true,
						'pdf'     => $pdf,      // keep raw for tests
						'pdf_url' => $pdf_url,  // public URL for iframe
						'xml'     => (string) $xml, // expose raw xml for AJAX preview
						'tipo'    => $tipo,
						'folio'   => $folio,
					);
				}

				if ( ! $this->folio_manager->mark_folio_used( $tipo, $folio ) ) {
						return array(
							'error' => __( 'No se pudo reservar un folio único. Por favor, inténtalo nuevamente.', 'sii-boleta-dte' ),
						);
				}

				$pdf_url = $this->store_persistent_pdf( $pdf, $pdf_context );

				// Hook: XML final generado (con folio + antes de envío SII ya calculado).
				if ( function_exists( 'do_action' ) ) {
					\do_action( 'sii_boleta_xml_final_generated', (string) $xml, array(
						'tipo'  => $tipo,
						'folio' => $folio,
					) );
				}

				// Comparar hash preview vs final si todavía existe en cache local.
				if ( class_exists( '\\Sii\\BoletaDte\\Infrastructure\\Engine\\XmlAuditService' ) ) {
					$auditor          = new \Sii\BoletaDte\Infrastructure\Engine\XmlAuditService();
					$normalized_final = $auditor->normalize( (string) $xml, 'final' );
					$hash_final       = $auditor->hash( $normalized_final );
					$preview_entry = self::$xml_preview_hash_cache[ $tipo ] ?? null;
					$hash_preview  = is_array( $preview_entry ) ? ( $preview_entry['hash'] ?? '' ) : '';
					$norm_preview  = is_array( $preview_entry ) ? ( $preview_entry['normalized'] ?? '' ) : '';
					if ( '' !== $hash_preview && \defined( 'WP_DEBUG' ) && \constant( 'WP_DEBUG' ) ) {
						if ( $hash_preview !== $hash_final && '' !== $norm_preview ) {
							$diffs      = $auditor->diff( $norm_preview, $normalized_final );
							$diff_count = count( $diffs );
							$this->debug_log( '[xml_audit] tipo=' . $tipo . ' folio=' . $folio . ' hash_preview=' . $hash_preview . ' hash_final=' . $hash_final . ' diffs=' . $diff_count );
						} else {
							$this->debug_log( '[xml_audit] tipo=' . $tipo . ' folio=' . $folio . ' hash unchanged=' . $hash_final );
						}
					}
				}

								$file = tempnam( sys_get_temp_dir(), 'dte' );
								file_put_contents( $file, (string) $xml );
								$env   = (string) ( $settings_cfg['environment'] ?? 'test' );
								$token = $this->token_manager->get_token( $env );
								$track = $this->api->send_dte_to_sii( $file, $env, $token );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $track ) ) {
				$code      = method_exists( $track, 'get_error_code' ) ? (string) $track->get_error_code() : '';
				$message   = method_exists( $track, 'get_error_message' ) ? (string) $track->get_error_message() : '';
				$trackData = null;
				if ( method_exists( $track, 'get_error_data' ) ) {
						$trackData = $track->get_error_data( $code );
						if ( null === $trackData ) {
							$trackData = $track->get_error_data();
						}
				}
				$trackId   = '';
				if ( is_array( $trackData ) && isset( $trackData['trackId'] ) ) {
						$trackId = (string) $trackData['trackId'];
				}
				$message = $this->normalize_api_error_message( $message );
					if ( $this->should_queue_for_error( $code ) ) {
                                        $context        = array(
                                                'label'        => $pdf_label,
                                                'type'         => $tipo,
                                                'folio'        => $folio,
                                                'rut_emisor'   => (string) ( $settings_cfg['rut_emisor'] ?? '' ),
                                                'rut_receptor' => $rut,
                                        );
                                        $queued_storage = $this->store_xml_for_queue( $file, $context );
                                        $storage_path   = (string) ( $queued_storage['path'] ?? '' );
                                        $storage_key    = (string) ( $queued_storage['key'] ?? '' );
                                        if ( '' !== $storage_path ) {
                                                $file = $storage_path;
                                        }
										$this->queue->enqueue_dte( $file, $env, $token, $storage_key );
										$queue_message = __( 'El SII no respondió. El documento fue puesto en cola para un reintento automático.', 'sii-boleta-dte' );
										if ( 'sii_boleta_dev_simulated_error' === $code ) {
												$queue_message = __( 'Envío simulado con error. El documento fue puesto en cola para un reintento automático.', 'sii-boleta-dte' );
										}
					$log_payload = $context;
					$log_payload['code']    = $code;
					$log_payload['message'] = $message;
					if ( '' !== $trackId ) {
						$log_payload['trackId'] = $trackId;
					}
					$log_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $log_payload ) : json_encode( $log_payload );
					LogDb::add_entry( $trackId, 'queued', is_string( $log_json ) ? $log_json : '', $env );
										return array(
											'queued'      => true,
											'pdf'         => $pdf,
											'pdf_url'     => $pdf_url,
											'message'     => $queue_message,
											'notice_type' => 'warning',
										);
					}

								return array(
									'error' => '' !== $message ? $message : __( 'Could not send the document. Please try again.', 'sii-boleta-dte' ),
								);
				}

                                if ( ! is_string( $track ) || '' === trim( (string) $track ) ) {
                                                                return array(
                                                                        'error' => __( 'La respuesta del SII no incluyó un track ID válido.', 'sii-boleta-dte' ),
                                                                );
                                }

                                if ( $this->is_certification_environment( $env ) ) {
                                        ProgressTracker::mark( ProgressTracker::OPTION_TEST_SEND );
                                }

                                                                $track_value     = trim( (string) $track );
                                                                $simulated_send = false !== strpos( $track_value, 'SIM-' );
                                                                $message        = '';
                                                                if ( $simulated_send ) {
                                                                        $message = sprintf( __( 'Envío simulado al SII. Track ID: %s.', 'sii-boleta-dte' ), $track_value );
                                                                }

                                                                return array(
                                                                        'track_id'    => $track_value,
                                                                        'pdf'         => $pdf,      // keep raw path for tests
                                                                        'pdf_url'     => $pdf_url,  // public URL for link
                                                                        'notice_type' => $simulated_send ? 'info' : 'success',
                                                                        'message'     => $message,
                                                                        'simulated'   => $simulated_send,
                                                                        'tipo'        => $tipo,
                                                                );
	}

		/**
		 * Stores the preview PDF path temporarily and returns a signed URL to view it.
		 *
		 * @param array<string,mixed> $context Metadata used to build a friendly filename.
		 */
    private function create_preview_pdf_link( string $path, array $context = array() ): string {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
						return '';
		}

			$filename  = $this->build_pdf_filename( $context, 'pdf' );
			$name_only = pathinfo( $filename, PATHINFO_FILENAME );
		if ( '' === (string) $name_only ) {
				$name_only = 'dte';
				$filename  = 'dte.pdf';
		}

			$key     = $filename;
			$counter = 2;
		while ( $this->preview_key_exists( $key ) ) {
				$key = $name_only . '-' . $counter . '.pdf';
				++$counter;
		}

			$this->set_preview_entry( $key, $path );

			return $this->build_viewer_url( $key, true );
	}

		/**
		 * Moves the generated PDF to uploads so it can be displayed via URL.
		 * Returns a public URL or empty string on failure.
		 *
		 * @param array<string,mixed> $context Metadata used to build a friendly filename.
		 */
    private function store_persistent_pdf( string $path, array $context = array() ): string {
        if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
            return '';
        }

        $stored = PdfStorage::store( $path );
        $dest   = isset( $stored['path'] ) ? (string) $stored['path'] : '';
        $key    = isset( $stored['key'] ) ? (string) $stored['key'] : '';
        $token  = isset( $stored['nonce'] ) ? (string) $stored['nonce'] : '';

        if ( '' === $dest || '' === $key || '' === $token ) {
            return '';
        }

        $download_name = $this->build_pdf_filename( $context, 'pdf' );
        $this->register_manual_pdf_entry( $key, $dest, $token, $download_name );

        return $this->build_viewer_url(
            $key,
            false,
            array(
                'manual' => '1',
                'token'  => $token,
            )
        );
    }

    private function build_viewer_url( string $key, bool $preview = false, array $extra = array() ): string {
        if ( ! function_exists( 'admin_url' ) ) {
            return '';
        }

        $args = array(
            'action'   => 'sii_boleta_dte_view_pdf',
            'key'      => $key,
            '_wpnonce' => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'sii_boleta_nonce' ) : '',
        );

        if ( $preview ) {
            $args['preview'] = '1';
        }

        foreach ( $extra as $arg_key => $value ) {
            if ( is_scalar( $value ) && '' !== (string) $value ) {
                $args[ $arg_key ] = (string) $value;
            }
        }

        return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
    }

	private function preview_key_exists( string $key ): bool {
			return null !== self::get_preview_entry( $key );
	}

	private function set_preview_entry( string $key, string $path ): void {
			$data = array(
				'path'    => $path,
				'expires' => time() + self::PREVIEW_TTL,
			);

			if ( function_exists( 'set_transient' ) ) {
					set_transient( self::PREVIEW_TRANSIENT_PREFIX . $key, $data, self::PREVIEW_TTL );
			}

			self::$preview_cache[ $key ] = $data;
	}

	private static function get_preview_entry( string $key ): ?array {
			$data = null;

		if ( function_exists( 'get_transient' ) ) {
				$value = get_transient( self::PREVIEW_TRANSIENT_PREFIX . $key );
			if ( false !== $value && is_array( $value ) ) {
				$data = $value;
			}
		}

		if ( null === $data && isset( self::$preview_cache[ $key ] ) ) {
				$data = self::$preview_cache[ $key ];
		}

		if ( null === $data ) {
				return null;
		}

			$expires = isset( $data['expires'] ) ? (int) $data['expires'] : 0;
		if ( $expires > 0 && $expires < time() ) {
				self::clear_preview_entry( $key );
				return null;
		}

			$path = isset( $data['path'] ) ? (string) $data['path'] : '';
		if ( '' === $path ) {
				self::clear_preview_entry( $key );
				return null;
		}

			return array(
				'path'    => $path,
				'expires' => $expires,
			);
	}

	private static function clear_preview_entry( string $key ): void {
		if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::PREVIEW_TRANSIENT_PREFIX . $key );
		}

			unset( self::$preview_cache[ $key ] );
	}

    public static function resolve_preview_path( string $key ): ?string {
                        $entry = self::get_preview_entry( $key );
                if ( null === $entry ) {
                                return null;
                }

                        $path = $entry['path'];
                if ( ! file_exists( $path ) ) {
                                self::clear_preview_entry( $key );
                                return null;
                }

                        return $path;
    }

    public static function clear_preview_path( string $key ): void {
                        self::clear_preview_entry( $key );
    }

    private function register_manual_pdf_entry( string $key, string $path, string $token, string $filename ): void {
        $key = strtolower( preg_replace( '/[^a-f0-9]/', '', $key ) );
        if ( '' === $key || '' === $path || '' === $token ) {
            return;
        }

        $data = array(
            'path'     => $path,
            'token'    => $token,
            'filename' => $filename,
            'expires'  => time() + self::MANUAL_TTL,
        );

        if ( function_exists( 'set_transient' ) ) {
            set_transient( self::MANUAL_TRANSIENT_PREFIX . $key, $data, self::MANUAL_TTL );
        }

        self::$manual_cache[ $key ] = $data;
    }

    public static function resolve_manual_pdf( string $key ): ?array {
        $key = strtolower( preg_replace( '/[^a-f0-9]/', '', $key ) );
        if ( '' === $key ) {
            return null;
        }

        $data = null;

        if ( function_exists( 'get_transient' ) ) {
            $value = get_transient( self::MANUAL_TRANSIENT_PREFIX . $key );
            if ( false !== $value && is_array( $value ) ) {
                $data = $value;
            }
        }

        if ( null === $data && isset( self::$manual_cache[ $key ] ) ) {
            $data = self::$manual_cache[ $key ];
        }

        if ( null === $data ) {
            return null;
        }

        $expires = isset( $data['expires'] ) ? (int) $data['expires'] : 0;
        if ( $expires > 0 && $expires < time() ) {
            self::clear_manual_pdf( $key );
            return null;
        }

        $path = isset( $data['path'] ) ? (string) $data['path'] : '';
        if ( '' === $path || ! file_exists( $path ) ) {
            self::clear_manual_pdf( $key );
            return null;
        }

        $token = isset( $data['token'] ) ? (string) $data['token'] : '';
        if ( '' === $token ) {
            self::clear_manual_pdf( $key );
            return null;
        }

        $filename = isset( $data['filename'] ) ? (string) $data['filename'] : basename( $path );

        return array(
            'path'     => $path,
            'token'    => $token,
            'filename' => $filename,
        );
    }

    public static function clear_manual_pdf( string $key ): void {
        $key = strtolower( preg_replace( '/[^a-f0-9]/', '', $key ) );
        if ( '' === $key ) {
            return;
        }

        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::MANUAL_TRANSIENT_PREFIX . $key );
        }

        unset( self::$manual_cache[ $key ] );
    }

                                /**
                                 * Stores the XML representation for queued retries in a persistent directory.
                                 *
                                 * @param array<string,mixed> $context Metadata reserved for future use.
                                 *
                                 * @return array{path:string,key:string}
                                 */
        private function store_xml_for_queue( string $path, array $context = array() ): array {
                if ( '' === $path || ! file_exists( $path ) ) {
                        return array(
                                'path' => '',
                                'key'  => '',
                        );
                }

                unset( $context );

                $stored = XmlStorage::store( $path );
                if ( '' !== $stored['path'] ) {
                        return $stored;
                }

                return array(
                        'path' => $path,
                        'key'  => '',
                );
        }

				/**
				 * Builds a descriptive filename for a generated PDF.
				 *
				 * @param array<string,mixed> $context
				 */
	private function build_pdf_filename( array $context, string $extension = 'pdf' ): string {
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

					$extension = preg_replace( '/[^A-Za-z0-9]+/', '', (string) $extension );
		if ( '' === $extension ) {
						$extension = 'pdf';
		}

					return $slug . '.' . $extension;
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
				 * Determines if a WP_Error code represents a temporary outage that warrants queuing.
				 */
	private function should_queue_for_error( string $code ): bool {
			$code = strtolower( trim( $code ) );
		if ( '' === $code ) {
						return false;
		}

					$temporary = array(
						'http_request_failed',
						'http_request_timeout',
						'sii_boleta_http_error',
						'sii_boleta_dev_simulated_error',
					);

					return in_array( $code, $temporary, true );
	}

				/**
				 * Attempts to extract a human readable message from the API error payload.
				 */
        private function normalize_api_error_message( string $message ): string {
                        $trimmed = trim( $message );
                if ( '' === $trimmed ) {
                                                return '';
                }

                                        $decoded = json_decode( $trimmed, true );
                if ( is_array( $decoded ) ) {
                                                $keys = array( 'mensaje', 'message', 'error', 'detail', 'descripcion' );
                        foreach ( $keys as $key ) {
                                if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) ) {
                                                                $value = (string) $decoded[ $key ];
                                        if ( '' !== trim( $value ) ) {
                                                return trim( $value );
                                        }
                                }
                        }
                }

                                        return $trimmed;
        }

                                /**
                                 * Determina si el ambiente corresponde a certificación.
                                 */
        private function is_certification_environment( string $environment ): bool {
                        $env = strtolower( trim( $environment ) );
                return in_array( $env, array( '0', 'test', 'certificacion', 'certification' ), true );
        }

    /**
     * Escribe mensajes de depuración en un directorio privado.
     */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && ! (bool) constant( 'WP_DEBUG' ) ) {
            return;
        }

        $sanitized = $this->sanitize_log_message( $message );
        if ( '' === $sanitized ) {
            return;
        }

        $dir = $this->resolve_secure_log_directory();
        if ( '' === $dir ) {
            error_log( $sanitized );
            return;
        }

        if ( function_exists( 'wp_mkdir_p' ) ) {
            wp_mkdir_p( $dir );
        } elseif ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0755, true );
        }

        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }

        $file = $dir . '/debug.log';
        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $sanitized . PHP_EOL;
        @file_put_contents( $file, $line, FILE_APPEND );
    }

    private function resolve_secure_log_directory(): string {
        if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
            $base = WP_CONTENT_DIR;
        } elseif ( function_exists( 'wp_upload_dir' ) ) {
            $uploads = wp_upload_dir();
            $base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';
        } else {
            $base = sys_get_temp_dir();
        }

        $base = rtrim( (string) $base, '/\\' );
        if ( '' === $base ) {
            return '';
        }

        return $base . '/sii-boleta-dte/private/logs';
    }

    private function sanitize_log_message( string $message ): string {
        $message = trim( preg_replace( '/[\r\n]+/', ' ', $message ) );
        if ( '' === $message ) {
            return '';
        }

        $limit = 600;
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $message, 'UTF-8' ) > $limit ) {
                $message = mb_substr( $message, 0, $limit, 'UTF-8' ) . '…';
            }
        } elseif ( strlen( $message ) > $limit ) {
            $message = substr( $message, 0, $limit ) . '…';
        }

        return $message;
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
			} elseif ( substr_count( $value, '.' ) > 1 ) {
						$value = str_replace( '.', '', $value );
			} elseif ( false !== ( $dot = strpos( $value, '.' ) ) ) {
						$fraction = substr( $value, $dot + 1 );
				if ( ctype_digit( $fraction ) && strlen( $fraction ) === 3 && $dot <= 3 ) {
					$value = str_replace( '.', '', $value );
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
			$dv                = strtoupper( $dv );

			$sum    = 0;
			$factor = 2;
		for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
				$sum           += (int) $body[ $i ] * $factor;
						$factor = ( 7 === $factor ) ? 2 : $factor + 1;
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
			$root  = dirname( __DIR__, 3 ) . '/resources/yaml/documentos_ok/';
			$codes = array();
		if ( is_dir( $root ) ) {
			foreach ( glob( $root . '*' ) as $dir ) {
				if ( is_dir( $dir ) ) {
					if ( preg_match( '/(\d{3})_/', basename( $dir ), $m ) ) {
						$codes[ (int) $m[1] ] = true;
					}
				}
			}
		}
			// Map to human labels, keep only codes found.
			$labels      = array(
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
			$out         = array();
			$environment = $this->settings->get_environment();
			foreach ( $labels as $code => $name ) {
				if ( ! isset( $codes[ $code ] ) ) {
					continue; }
				if ( FoliosDb::has_type( (int) $code, $environment ) ) {
						$out[ $code ] = $name;
				}
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
