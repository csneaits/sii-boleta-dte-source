<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\TokenManager;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Domain\DteEngine;
use Sii\BoletaDte\Infrastructure\PdfGenerator;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

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

        private const PREVIEW_TRANSIENT_PREFIX = 'sii_boleta_preview_';
        private const PREVIEW_TTL              = 900;

        /**
         * @var array<string,array{path:string,expires:int}>
         */
        private static array $preview_cache = array();

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
						$result = $this->process_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
				// Persist selected type and basic fields on reload
				$available    = $this->get_available_types();
				$default_tipo = (int) ( array_key_first( $available ) ?? 39 );
				$sel_tipo     = isset( $_POST['tipo'] ) ? (int) $_POST['tipo'] : $default_tipo; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $available[ $sel_tipo ] ) ) {
			$sel_tipo = $default_tipo; }
				$val                  = static function ( string $k ): string {
						return isset( $_POST[ $k ] ) ? esc_attr( (string) $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				};
				$current_emitter_giro = isset( $_POST['giro_emisor'] )
						? (string) $_POST['giro_emisor'] // phpcs:ignore WordPress.Security.NonceVerification.Missing
						: $default_emitter_giro;
				$item0                = isset( $_POST['items'][0] ) && is_array( $_POST['items'][0] ) ? (array) $_POST['items'][0] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$i0d                  = isset( $item0['desc'] ) ? esc_attr( (string) $item0['desc'] ) : '';
				$i0q                  = isset( $item0['qty'] ) ? esc_attr( (string) $item0['qty'] ) : '1';
				$i0p                  = isset( $item0['price'] ) ? esc_attr( (string) $item0['price'] ) : '0';
				$modal_preview_url    = '';
		if ( is_array( $result ) && ! empty( $result['preview'] ) ) {
				$modal_preview_url = (string) ( $result['pdf_url'] ?? $result['pdf'] ?? '' );
		}
				$environment       = $this->settings->get_environment();
				$environment_label = '1' === $environment ? __( 'Production', 'sii-boleta-dte' ) : __( 'Certification', 'sii-boleta-dte' );
				$rvd_automation    = ! empty( $settings_cfg['rvd_auto_enabled'] );
				$libro_automation  = ! empty( $settings_cfg['libro_auto_enabled'] );
                ?>
                                <?php AdminStyles::open_container( 'sii-generate-dte' ); ?>
                                                <style>
                                                .sii-generate-dte {
								position: relative;
								padding: 2.5rem 2.2rem 3rem;
								border-radius: 24px;
								background: linear-gradient(140deg, #f5f7ff 0%, #ecfdfd 100%);
								box-shadow: 0 30px 60px rgba(15, 23, 42, 0.15);
								overflow: hidden;
						}
						.sii-generate-dte::before {
								content: '';
								position: absolute;
								inset: -150px auto auto -180px;
								width: 420px;
								height: 420px;
								border-radius: 50%;
								background: radial-gradient(circle, rgba(37, 99, 235, 0.2) 0%, transparent 70%);
								pointer-events: none;
						}
						.sii-generate-dte::after {
								content: '';
								position: absolute;
								inset: auto -160px -200px auto;
								width: 360px;
								height: 360px;
								border-radius: 50%;
								background: radial-gradient(circle, rgba(14, 165, 233, 0.18) 0%, transparent 75%);
								pointer-events: none;
						}
						.sii-generate-dte h1 {
								margin: 0 0 0.75rem;
								position: relative;
								z-index: 1;
								font-size: 2.15rem;
								color: #0f172a;
						}
						.sii-generate-dte-subtitle {
								margin: 0 0 1.8rem;
								font-size: 1.05rem;
								color: #334155;
								position: relative;
								z-index: 1;
								max-width: 720px;
						}
						.sii-generate-dte-layout {
								position: relative;
								z-index: 1;
								display: grid;
								grid-template-columns: minmax(0, 1fr) 320px;
								gap: 1.5rem;
								align-items: start;
						}
						.sii-generate-dte-card {
								background: rgba(255, 255, 255, 0.96);
								border-radius: 20px;
								padding: 2rem;
								box-shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
								border: 1px solid rgba(226, 232, 240, 0.65);
								backdrop-filter: blur(6px);
						}
						.sii-generate-dte-card--accent {
								background: linear-gradient(150deg, rgba(37, 99, 235, 0.14), rgba(56, 189, 248, 0.12));
								border-color: rgba(96, 165, 250, 0.4);
						}
						.sii-generate-dte-card--tips {
								background: linear-gradient(145deg, rgba(129, 140, 248, 0.12), rgba(236, 72, 153, 0.08));
								border-color: rgba(165, 180, 252, 0.35);
						}
						#sii-generate-dte-notices {
								margin-bottom: 1.2rem;
						}
						#sii-generate-dte-notices .notice {
								border-radius: 14px;
								padding: 1rem 1.25rem;
								box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
						}
						.sii-generate-dte-form table.form-table {
								width: 100%;
								border-spacing: 0;
						}
						.sii-generate-dte-form table.form-table th {
								padding: 0 1rem 1.1rem 0;
								text-align: left;
								color: #1e293b;
								width: 30%;
						}
						.sii-generate-dte-form table.form-table td {
								padding: 0 0 1.1rem;
						}
						.sii-generate-dte-form input[type="text"],
						.sii-generate-dte-form input[type="number"],
						.sii-generate-dte-form input[type="date"],
						.sii-generate-dte-form select,
						.sii-generate-dte-form textarea {
								width: 100%;
								border-radius: 12px;
								border: 1px solid rgba(148, 163, 184, 0.55);
								padding: 0.6rem 0.75rem;
								transition: border-color 0.2s ease, box-shadow 0.2s ease;
								box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08);
								background: rgba(255, 255, 255, 0.92);
						}
						.sii-generate-dte-form input:focus,
						.sii-generate-dte-form select:focus,
						.sii-generate-dte-form textarea:focus {
								outline: none;
								border-color: rgba(37, 99, 235, 0.8);
								box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
						}
						.sii-generate-dte-form label {
								font-weight: 600;
								color: #1f2937;
						}
                                                #sii-items-table {
                                                                width: 100%;
                                                                border-radius: 18px;
                                                                overflow: hidden;
                                                                border: none;
                                                                box-shadow: 0 24px 48px rgba(15, 23, 42, 0.14);
                                                                margin-top: 0.75rem;
                                                                border-spacing: 0;
                                                                table-layout: fixed;
                                                }
                                                #sii-items-table thead th {
                                                                background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(14, 165, 233, 0.12));
                                                                padding: 0.9rem 1.1rem;
                                                                color: #0f172a;
                                                                font-size: 0.78rem;
                                                                letter-spacing: 0.08em;
                                                                text-transform: uppercase;
                                                                border-bottom: 1px solid rgba(148, 163, 184, 0.28);
                                                }
                                                #sii-items-table thead th:first-child,
                                                #sii-items-table tbody td:first-child {
                                                                width: 46%;
                                                }
                                                #sii-items-table thead th:nth-child(2),
                                                #sii-items-table tbody td:nth-child(2) {
                                                                width: 18%;
                                                                text-align: center;
                                                }
                                                #sii-items-table thead th:nth-child(3),
                                                #sii-items-table tbody td:nth-child(3) {
                                                                width: 22%;
                                                                text-align: center;
                                                }
                                                #sii-items-table thead th:last-child,
                                                #sii-items-table tbody td:last-child {
                                                                width: 14%;
                                                                text-align: right;
                                                }
                                                #sii-items-table tbody tr {
                                                                background: rgba(255, 255, 255, 0.96);
                                                                transition: transform 0.2s ease, box-shadow 0.2s ease;
                                                }
                                                #sii-items-table tbody tr:hover {
                                                                transform: translateY(-2px);
                                                                box-shadow: 0 26px 50px rgba(15, 23, 42, 0.16);
                                                }
                                                #sii-items-table tbody td {
                                                                padding: 0.85rem 1.1rem;
                                                                border-bottom: 1px solid rgba(226, 232, 240, 0.65);
                                                                background: transparent;
                                                        }
                                                #sii-items-table tbody tr:last-child td {
                                                                border-bottom: none;
                                                }
                                                #sii-items-table tbody td:nth-child(2) input,
                                                #sii-items-table tbody td:nth-child(3) input {
                                                                max-width: 140px;
                                                                margin: 0 auto;
                                                                text-align: center;
                                                }
                                                #sii-items-table tbody td:last-child {
                                                                padding-right: 1.5rem;
                                                }
                                                #sii-items-table .remove-item {
                                                                border-radius: 12px;
                                                                background: rgba(248, 113, 113, 0.15);
                                                                color: #b91c1c;
                                                                border: none;
                                                                width: 38px;
                                                                height: 38px;
                                                                display: inline-flex;
                                                                align-items: center;
                                                                justify-content: center;
                                                                font-size: 1.2rem;
                                                                line-height: 1;
                                                                padding: 0;
                                                                transition: background 0.2s ease, transform 0.2s ease;
                                                }
                                                #sii-items-table .remove-item:hover,
                                                #sii-items-table .remove-item:focus {
                                                                background: rgba(239, 68, 68, 0.28);
                                                                color: #991b1b;
                                                                transform: translateY(-1px);
                                                                outline: none;
                                                }
                                                #sii-items-table .remove-item:focus-visible {
                                                                box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.35);
                                                }
						.sii-generate-actions {
								display: flex;
								gap: 0.75rem;
								flex-wrap: wrap;
								align-items: center;
								margin-top: 1.4rem;
						}
						.sii-generate-dte .button,
						.sii-generate-dte .button-primary {
								border-radius: 999px;
								border: none;
								padding: 0.55rem 1.45rem;
								font-weight: 600;
								transition: transform 0.18s ease, box-shadow 0.18s ease;
								box-shadow: 0 15px 28px rgba(37, 99, 235, 0.2);
						}
						.sii-generate-dte .button.button-primary {
								background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
								color: #fff;
						}
						.sii-generate-dte .button:hover,
						.sii-generate-dte .button-primary:hover {
								transform: translateY(-1px);
								box-shadow: 0 20px 32px rgba(37, 99, 235, 0.28);
						}
						.sii-generate-dte-aside {
								display: grid;
								gap: 1.2rem;
						}
						.sii-generate-dte-summary,
						.sii-generate-dte-tips {
								margin: 0.6rem 0 0;
								padding-left: 1.1rem;
								color: #1f2937;
								line-height: 1.55;
						}
						.sii-generate-dte-summary {
								list-style: disc;
						}
						.sii-generate-dte-tips {
								list-style: decimal;
						}
						.sii-generate-dte-card h2 {
								margin-top: 0;
								color: #1e3a8a;
								font-size: 1.1rem;
						}
						.sii-generate-dte-card--tips h2 {
								color: #5b21b6;
						}
						.sii-dte-modal {
								position: fixed;
								inset: 0;
								z-index: 100000;
						}
						.sii-dte-modal-backdrop {
								position: absolute;
								inset: 0;
								background: rgba(15, 23, 42, 0.55);
								backdrop-filter: blur(3px);
						}
						.sii-dte-modal-content {
								position: absolute;
								top: 50%;
								left: 50%;
								transform: translate(-50%, -50%);
								width: min(92vw, 1100px);
								height: min(88vh, 780px);
								background: #ffffff;
								box-shadow: 0 28px 65px rgba(15, 23, 42, 0.35);
								border-radius: 20px;
								overflow: hidden;
								display: flex;
								flex-direction: column;
						}
						.sii-dte-modal-close {
								position: absolute;
								top: 16px;
								right: 18px;
								border: none;
								background: rgba(15, 23, 42, 0.08);
								color: #0f172a;
								border-radius: 999px;
								padding: 0.35rem 0.85rem;
								cursor: pointer;
								font-size: 1.3rem;
								transition: background 0.2s ease;
						}
						.sii-dte-modal-close:hover {
								background: rgba(37, 99, 235, 0.18);
						}
						.sii-dte-modal-frame {
								flex: 1;
								width: 100%;
								border: 0;
								background: #f8fafc;
						}
						body.sii-dte-modal-open {
								overflow: hidden;
						}
						.sii-rut-invalid {
								border-color: #d63638 !important;
								box-shadow: 0 0 0 1px rgba(214, 54, 56, 0.35) !important;
						}
						@media (max-width: 1100px) {
								.sii-generate-dte-layout {
										grid-template-columns: 1fr;
								}
								.sii-generate-dte-aside {
										grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
								}
						}
                                                @media (max-width: 782px) {
                                                                .sii-generate-dte {
                                                                                padding: 2rem 1.2rem 2.4rem;
                                                                }
                                                                .sii-generate-dte-card {
                                                                                padding: 1.6rem 1.35rem;
                                                                }
                                                                .sii-generate-dte-form table.form-table th {
                                                                                width: 100%;
                                                                                display: block;
                                                                                padding-bottom: 0.4rem;
                                                                }
                                                                .sii-generate-dte-form table.form-table td {
                                                                                display: block;
                                                                                padding-bottom: 1.3rem;
                                                                }
                                                }
                                                @media (max-width: 880px) {
                                                                #sii-items-table thead {
                                                                                display: none;
                                                                }
                                                                #sii-items-table,
                                                                #sii-items-table tbody,
                                                                #sii-items-table tr,
                                                                #sii-items-table td {
                                                                                display: block;
                                                                                width: 100%;
                                                                }
                                                                #sii-items-table tbody tr {
                                                                                margin-bottom: 1.1rem;
                                                                                padding: 1rem 1.2rem;
                                                                                border-radius: 18px;
                                                                                box-shadow: 0 22px 44px rgba(15, 23, 42, 0.16);
                                                                }
                                                                #sii-items-table tbody td {
                                                                                padding: 0.4rem 0;
                                                                                border: none;
                                                                                display: flex;
                                                                                gap: 0.75rem;
                                                                                align-items: center;
                                                                                justify-content: space-between;
                                                                                font-size: 0.95rem;
                                                                }
                                                                #sii-items-table tbody td::before {
                                                                                content: attr(data-label);
                                                                                font-weight: 600;
                                                                                color: #1f2937;
                                                                }
                                                                #sii-items-table tbody td:nth-child(2) input,
                                                                #sii-items-table tbody td:nth-child(3) input {
                                                                                margin: 0;
                                                                                max-width: 160px;
                                                                }
                                                                #sii-items-table tbody td:last-child {
                                                                                justify-content: flex-end;
                                                                                padding-right: 0;
                                                                }
                                                }
                                                @media (prefers-color-scheme: dark) {
                                                                .sii-generate-dte {
                                                                                background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(14, 116, 144, 0.45) 100%);
                                                                                box-shadow: 0 32px 70px rgba(2, 6, 23, 0.7);
                                                                }
								.sii-generate-dte::before {
										background: radial-gradient(circle, rgba(37, 99, 235, 0.35) 0%, transparent 70%);
								}
								.sii-generate-dte::after {
										background: radial-gradient(circle, rgba(14, 165, 233, 0.3) 0%, transparent 75%);
								}
								.sii-generate-dte h1,
								.sii-generate-dte-subtitle {
										color: #e2e8f0;
								}
								.sii-generate-dte-card {
										background: rgba(15, 23, 42, 0.85);
										border-color: rgba(51, 65, 85, 0.8);
										box-shadow: 0 28px 60px rgba(2, 6, 23, 0.75);
								}
								.sii-generate-dte-card h2,
								.sii-generate-dte-summary,
								.sii-generate-dte-tips,
								.sii-generate-dte-form table.form-table th,
								.sii-generate-dte-form table.form-table td label,
								.sii-generate-dte-subtitle {
										color: #e2e8f0;
								}
								.sii-generate-dte-form input,
								.sii-generate-dte-form select,
								.sii-generate-dte-form textarea {
										background: rgba(15, 23, 42, 0.75);
										color: #f8fafc;
										border-color: rgba(148, 163, 184, 0.55);
								}
                                                                #sii-items-table {
                                                                                box-shadow: 0 26px 52px rgba(2, 6, 23, 0.65);
                                                                }
                                                                #sii-items-table thead th {
                                                                                color: #e2e8f0;
                                                                                background: linear-gradient(135deg, rgba(59, 130, 246, 0.35), rgba(14, 165, 233, 0.28));
                                                                                border-bottom-color: rgba(51, 65, 85, 0.75);
                                                                }
                                                                #sii-items-table tbody tr {
                                                                                background: rgba(15, 23, 42, 0.82);
                                                                                border-bottom-color: rgba(51, 65, 85, 0.75);
                                                                }
                                                                #sii-items-table tbody tr:hover {
                                                                                box-shadow: 0 30px 58px rgba(2, 6, 23, 0.7);
                                                                }
                                                                #sii-items-table tbody td {
                                                                                color: #f1f5f9;
                                                                                border-bottom-color: rgba(71, 85, 105, 0.7);
                                                                }
                                                                #sii-items-table tbody tr:last-child td {
                                                                                border-bottom-color: transparent;
                                                                }
                                                                #sii-items-table tbody td::before {
                                                                                color: #cbd5f5;
                                                                }
                                                                #sii-items-table .remove-item {
                                                                                background: rgba(248, 113, 113, 0.28);
                                                                                color: #fecaca;
                                                                }
                                                                #sii-items-table .remove-item:hover,
                                                                #sii-items-table .remove-item:focus {
                                                                                background: rgba(248, 113, 113, 0.4);
                                                                                color: #fee2e2;
                                                                }
								.sii-generate-dte .button,
								.sii-generate-dte .button-primary {
										box-shadow: 0 18px 32px rgba(14, 165, 233, 0.4);
								}
								.sii-dte-modal-content {
										background: rgba(15, 23, 42, 0.95);
										color: #f8fafc;
								}
								.sii-dte-modal-frame {
										background: rgba(15, 23, 42, 0.85);
								}
								.sii-dte-modal-close {
										background: rgba(148, 163, 184, 0.18);
										color: #f8fafc;
								}
								.sii-dte-modal-close:hover {
										background: rgba(59, 130, 246, 0.35);
								}
								#sii-generate-dte-notices .notice {
										background: rgba(15, 23, 42, 0.85);
										color: #e2e8f0;
								}
						}
						</style>
						<h1><?php esc_html_e( 'Generate DTE', 'sii-boleta-dte' ); ?></h1>
						<p class="sii-generate-dte-subtitle"><?php esc_html_e( 'Craft and submit digital tax documents with a refined interface designed to guide each step of the process.', 'sii-boleta-dte' ); ?></p>
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
																<div class="notice notice-info"><p><?php esc_html_e( 'Preview generated. Review the document below.', 'sii-boleta-dte' ); ?>
																<?php
																if ( ! empty( $modal_preview_url ) ) :
																	?>
																	- <a target="_blank" rel="noopener" href="<?php echo esc_url( $modal_preview_url ); ?>"><?php esc_html_e( 'Open preview in a new tab', 'sii-boleta-dte' ); ?></a><?php endif; ?></p></div>
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
																				if ( ! empty( $dl_url ) ) :
																					?>
																								- <a href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download PDF', 'sii-boleta-dte' ); ?></a>
																				<?php endif; ?>
																</p></div>
												<?php elseif ( is_array( $result ) && ! empty( $result['error'] ) ) : ?>
																<div class="error notice"><p><?php echo esc_html( (string) $result['error'] ); ?></p></div>
												<?php endif; ?>
										</div>
										<form method="post" id="sii-generate-dte-form" class="sii-generate-dte-form">
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
													<tr class="dte-section" data-types="33,34,43,46,52,110" style="display:none">
														<th scope="row"><label for="sii-dir-recep"><?php esc_html_e( 'Dirección Receptor', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-dir-recep" name="dir_recep" class="regular-text" value="<?php echo $val( 'dir_recep' ); ?>" /></td>
													</tr>
													<tr class="dte-section" data-types="33,34,43,46,52,110" style="display:none">
														<th scope="row"><label for="sii-cmna-recep"><?php esc_html_e( 'Comuna Receptor', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-cmna-recep" name="cmna_recep" class="regular-text" value="<?php echo $val( 'cmna_recep' ); ?>" /></td>
													</tr>
													<tr class="dte-section" data-types="33,34,43,46,52,110" style="display:none">
														<th scope="row"><label for="sii-ciudad-recep"><?php esc_html_e( 'Ciudad Receptor', 'sii-boleta-dte' ); ?></label></th>
														<td><input type="text" id="sii-ciudad-recep" name="ciudad_recep" class="regular-text" value="<?php echo $val( 'ciudad_recep' ); ?>" /></td>
													</tr>
													<tr>
                                                <th scope="row"><label for="sii-items"><?php esc_html_e( 'Ítems', 'sii-boleta-dte' ); ?></label></th>
														<td>
																<table id="sii-items-table" class="widefat">
																		<thead>
																				<tr>
                                                               <th><?php esc_html_e( 'Descripción', 'sii-boleta-dte' ); ?></th>
                                                               <th><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></th>
                                                               <th><?php esc_html_e( 'Precio unitario', 'sii-boleta-dte' ); ?></th>
																						<th></th>
																				</tr>
																		</thead>
																<tbody>
                                                                                                                               <tr>
                                                               <td data-label="<?php esc_attr_e( 'Descripción', 'sii-boleta-dte' ); ?>"><input type="text" name="items[0][desc]" data-field="desc" class="regular-text" value="<?php echo $i0d; ?>" /></td>
                                                               <td data-label="<?php esc_attr_e( 'Cantidad', 'sii-boleta-dte' ); ?>"><input type="number" name="items[0][qty]" data-field="qty" value="<?php echo $i0q; ?>" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></td>
                                                               <td data-label="<?php esc_attr_e( 'Precio unitario', 'sii-boleta-dte' ); ?>"><input type="number" name="items[0][price]" data-field="price" value="<?php echo $i0p; ?>" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></td>
                                                               <td data-label="<?php esc_attr_e( 'Acciones', 'sii-boleta-dte' ); ?>"><button type="button" class="button remove-item" aria-label="<?php esc_attr_e( 'Eliminar ítem', 'sii-boleta-dte' ); ?>">×</button></td>
                                                                                                                               </tr>
																</tbody>
															</table>
                                                        <p><button type="button" class="button" id="sii-add-item"><?php esc_html_e( 'Agregar ítem', 'sii-boleta-dte' ); ?></button></p>
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
																<input type="number" name="ref_folio" id="sii-ref-folio" min="1" step="1" value="<?php echo $val( 'ref_folio' ); ?>" />
															</label>
															&nbsp; 
															<label><?php esc_html_e( 'Fecha', 'sii-boleta-dte' ); ?>
																<input type="date" name="ref_fecha" value="<?php echo $val( 'ref_fecha' ); ?>" />
															</label>
															&nbsp; 
															<label><?php esc_html_e( 'Razón', 'sii-boleta-dte' ); ?>
																<input type="text" name="ref_razon" class="regular-text" placeholder="Anula/rebaja, etc." value="<?php echo $val( 'ref_razon' ); ?>" />
															</label>
														</td>
													</tr>
																</tbody>
																</table>
																<div class="sii-generate-actions">
                                                               <?php submit_button( __( 'Previsualizar', 'sii-boleta-dte' ), 'secondary', 'preview', false ); ?>
                                                               <?php submit_button( __( 'Enviar al SII', 'sii-boleta-dte' ) ); ?>
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
                                                                                                                <li><?php esc_html_e( 'Agrega descripciones claras a los ítems y mantén precios unitarios en pesos enteros para totales precisos.', 'sii-boleta-dte' ); ?></li>
                                                                                                                <li><?php esc_html_e( 'Al emitir facturas o guías, completa los campos de dirección del receptor para agilizar la logística.', 'sii-boleta-dte' ); ?></li>
                                                                                                                <li><?php esc_html_e( 'Si tu cliente no tiene RUT para boletas, el sistema aplicará automáticamente el identificador genérico del SII.', 'sii-boleta-dte' ); ?></li>
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
				$dir_recep    = sanitize_text_field( (string) ( $post['dir_recep'] ?? '' ) );
				$cmna_recep   = sanitize_text_field( (string) ( $post['cmna_recep'] ?? '' ) );
				$ciudad_recep = sanitize_text_field( (string) ( $post['ciudad_recep'] ?? '' ) );
				$items        = array();
				$n            = 1;
		$raw                  = $post['items'] ?? array();
		if ( $preview ) {
			$this->debug_log( '[preview] raw items=' . print_r( $raw, true ) );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
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

		$folio = 0;
		if ( ! $preview ) {
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
		}

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
		if ( '' !== $giro_emisor ) {
				$data['Encabezado'] = array(
					'Emisor' => array(
						'GiroEmisor' => $giro_emisor,
						'GiroEmis'   => $giro_emisor,
					),
				);
		}
		if ( '' !== $dir_recep ) {
			$data['Receptor']['DirRecep'] = $dir_recep; }
		if ( '' !== $cmna_recep ) {
			$data['Receptor']['CmnaRecep'] = $cmna_recep; }
		if ( '' !== $ciudad_recep ) {
			$data['Receptor']['CiudadRecep'] = $ciudad_recep; }

		// Reference data for credit/debit notes
		if ( in_array( $tipo, array( 56, 61, 111, 112 ), true ) ) {
				$ref_tipo  = (int) ( $post['ref_tipo'] ?? 0 );
				$ref_folio = trim( (string) ( $post['ref_folio'] ?? '' ) );
				$ref_fecha = trim( (string) ( $post['ref_fecha'] ?? '' ) );
				$ref_razon = sanitize_text_field( (string) ( $post['ref_razon'] ?? '' ) );
			if ( '' !== $ref_folio ) {
							$ref = array(
								'TpoDocRef' => 0 !== $ref_tipo ? $ref_tipo : 39,
								'FolioRef'  => $ref_folio,
							);
							if ( '' !== $ref_fecha ) {
								$ref['FchRef'] = $ref_fecha; }
							if ( '' !== $ref_razon ) {
								$ref['RazonRef'] = $ref_razon; }
							$data['Referencias'] = array( $ref );
			}
		}
				$xml = $this->engine->generate_dte_xml( $data, $tipo, $preview );
		if ( is_wp_error( $xml ) ) {
				$code = method_exists( $xml, 'get_error_code' ) ? $xml->get_error_code() : '';
				$msg  = $xml->get_error_message();
			if ( 'sii_boleta_missing_caf' === $code ) {
						// Mensaje más claro para el usuario final
						$labels     = $this->get_available_types();
						$tipo_label = $labels[ $tipo ] ?? (string) $tipo;
						$msg        = sprintf( __( 'No hay un CAF configurado para el tipo %s. Sube un CAF en “Folios / CAFs”.', 'sii-boleta-dte' ), $tipo_label );
			}
				return array( 'error' => $msg );
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
                return array(
                        'preview' => true,
                        'pdf'     => $pdf,      // keep raw for tests
                        'pdf_url' => $pdf_url,  // public URL for iframe
                );
        }

        $this->folio_manager->mark_folio_used( $tipo, $folio );

        $pdf_url = $this->store_persistent_pdf( $pdf, $pdf_context );

                                $file = tempnam( sys_get_temp_dir(), 'dte' );
                                file_put_contents( $file, (string) $xml );
                                $env   = (string) ( $settings_cfg['environment'] ?? 'test' );
                                $token = $this->token_manager->get_token( $env );
                                $track = $this->api->send_dte_to_sii( $file, $env, $token );
                                if ( function_exists( 'is_wp_error' ) && is_wp_error( $track ) ) {
                                                $code    = method_exists( $track, 'get_error_code' ) ? (string) $track->get_error_code() : '';
                                                $message = method_exists( $track, 'get_error_message' ) ? (string) $track->get_error_message() : '';
                                                $message = $this->normalize_api_error_message( $message );
                                                if ( $this->should_queue_for_error( $code ) ) {
                                                                $context    = array(
                                                                        'label'        => $pdf_label,
                                                                        'type'         => $tipo,
                                                                        'folio'        => $folio,
                                                                        'rut_emisor'   => (string) ( $settings_cfg['rut_emisor'] ?? '' ),
                                                                        'rut_receptor' => $rut,
                                                                );
                                                                $queued_file = $this->store_xml_for_queue( $file, $context );
                                                                if ( is_string( $queued_file ) && '' !== $queued_file ) {
                                                                                $file = $queued_file;
                                                                }
                                                                $this->queue->enqueue_dte( $file, $env, $token );
                                                                return array(
                                                                        'queued'      => true,
                                                                        'pdf'         => $pdf,
                                                                        'pdf_url'     => $pdf_url,
                                                                        'message'     => __( 'El SII no respondió. El documento fue puesto en cola para un reintento automático.', 'sii-boleta-dte' ),
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

                                return array(
                                        'track_id'    => trim( (string) $track ),
                                        'pdf'         => $pdf,      // keep raw path for tests
                                        'pdf_url'     => $pdf_url,  // public URL for link
                                        'notice_type' => 'success',
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
                        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array(
                                'basedir' => sys_get_temp_dir(),
                                'baseurl' => '',
                        );
                        $base    = rtrim( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ), '/\\' );
                        $dir     = $base . '/sii-boleta-dte/previews';
                        if ( function_exists( 'wp_mkdir_p' ) ) {
                                        wp_mkdir_p( $dir );
                        } elseif ( ! is_dir( $dir ) ) {
                                        @mkdir( $dir, 0755, true );
                        }
                        $filename  = $this->build_pdf_filename( $context, 'pdf' );
                        $name_only = pathinfo( $filename, PATHINFO_FILENAME );
                        if ( '' === (string) $name_only ) {
                                        $name_only = 'dte';
                        }
                        $dest    = $dir . '/' . $filename;
                        $counter = 2;
                        while ( file_exists( $dest ) ) {
                                        $dest = $dir . '/' . $name_only . '-' . $counter . '.pdf';
                                        ++$counter;
                        }
                        if ( ! @copy( $path, $dest ) ) {
                                        return '';
                        }
                        @chmod( $dest, 0644 );

                return $this->build_viewer_url( basename( $dest ) );
        }

        private function build_viewer_url( string $key, bool $preview = false ): string {
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

                /**
                 * Stores the XML representation for queued retries in a persistent directory.
                 *
                 * @param array<string,mixed> $context Metadata used to build the filename.
                 */
        private function store_xml_for_queue( string $path, array $context = array() ): string {
                if ( '' === $path || ! file_exists( $path ) ) {
                                return '';
                }

                        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array(
                                'basedir' => sys_get_temp_dir(),
                        );
                        $base = rtrim( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ), '/\\' );
                        $dir  = $base . '/sii-boleta-dte/queue';
                        if ( function_exists( 'wp_mkdir_p' ) ) {
                                        wp_mkdir_p( $dir );
                        } elseif ( ! is_dir( $dir ) ) {
                                        @mkdir( $dir, 0755, true );
                        }
                        $filename  = $this->build_pdf_filename( $context, 'xml' );
                        $name_only = pathinfo( $filename, PATHINFO_FILENAME );
                        if ( '' === $name_only ) {
                                        $name_only = 'dte';
                        }
                        $dest    = $dir . '/' . $filename;
                        $counter = 2;
                        while ( file_exists( $dest ) ) {
                                        $dest = $dir . '/' . $name_only . '-' . $counter . '.xml';
                                        ++$counter;
                        }
                        if ( ! @copy( $path, $dest ) ) {
                                        return '';
                        }
                        @chmod( $dest, 0644 );

                        return $dest;
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
         * Escribe mensajes de depuración en uploads/sii-boleta-logs/.
         */
        private function debug_log( string $message ): void {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
			? $uploads['basedir']
			: ( defined( 'ABSPATH' ) ? ABSPATH : sys_get_temp_dir() );
		$dir     = rtrim( (string) $base, '/\\' ) . '/sii-boleta-logs';
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
