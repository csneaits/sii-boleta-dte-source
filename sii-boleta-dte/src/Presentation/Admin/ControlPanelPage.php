<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\LibroBoletas;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Application\RvdManager;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\TokenManager;

/**
 * Dashboard-like page summarising plugin status and queue management.
 */
class ControlPanelPage {
	private Settings $settings;
	private FolioManager $folio_manager;
	private QueueProcessor $processor;
	private RvdManager $rvd_manager;
	private LibroBoletas $libro_boletas;
	private Api $api;
	private TokenManager $token_manager;
	/** @var array<int, array{type:string,message:string}> */
	private array $notices = array();

	public function __construct( Settings $settings, FolioManager $folio_manager, QueueProcessor $processor, RvdManager $rvd_manager, LibroBoletas $libro_boletas, Api $api, TokenManager $token_manager ) {
			$this->settings      = $settings;
			$this->folio_manager = $folio_manager;
			$this->processor     = $processor;
			$this->rvd_manager   = $rvd_manager;
			$this->libro_boletas = $libro_boletas;
			$this->api           = $api;
			$this->token_manager = $token_manager;
	}

	/** Displays the control panel. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST['queue_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$this->handle_queue_action( sanitize_text_field( (string) ( $_POST['queue_action'] ?? '' ) ), (int) ( $_POST['job_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( isset( $_POST['rvd_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$this->handle_rvd_action( sanitize_text_field( (string) ( $_POST['rvd_action'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( isset( $_POST['libro_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$xml_input = (string) ( $_POST['libro_xml'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( function_exists( 'wp_unslash' ) ) {
					$xml_input = wp_unslash( $xml_input );
				} else {
					$xml_input = stripslashes( $xml_input );
				}
				$this->handle_libro_action( sanitize_text_field( (string) ( $_POST['libro_action'] ?? '' ) ), $xml_input ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		$tab = 'logs';
		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $_GET['tab'] ) : strtolower( (string) $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
				<div class="wrap sii-control-panel">
						<style>
								.sii-control-panel {
										position: relative;
										padding: 2.5rem 2.5rem 3rem;
										border-radius: 20px;
										background: linear-gradient(135deg, #f5f9ff 0%, #eef2ff 100%);
										box-shadow: 0 25px 50px rgba(15, 23, 42, 0.12);
										overflow: hidden;
								}
								.sii-control-panel::before {
										content: '';
										position: absolute;
										inset: -120px auto auto -140px;
										width: 360px;
										height: 360px;
										border-radius: 50%;
										background: radial-gradient(circle at center, rgba(58, 123, 213, 0.35), transparent 70%);
										pointer-events: none;
								}
								.sii-control-panel::after {
										content: '';
										position: absolute;
										inset: auto -120px -160px auto;
										width: 280px;
										height: 280px;
										border-radius: 50%;
										background: radial-gradient(circle at center, rgba(0, 210, 255, 0.2), transparent 75%);
										pointer-events: none;
								}
								.sii-control-panel h1 {
										margin-bottom: 1.2rem;
										position: relative;
										z-index: 1;
										font-size: 2.1rem;
										color: #0f172a;
								}
								.sii-control-panel h1 + p {
										color: #334155;
										font-size: 1.05rem;
										margin-top: -0.35rem;
										position: relative;
										z-index: 1;
								}
								.sii-control-panel .nav-tab-wrapper {
										position: relative;
										z-index: 1;
										background: rgba(255, 255, 255, 0.9);
										backdrop-filter: blur(6px);
										border-radius: 999px;
										padding: 0.5rem;
										display: inline-flex;
										gap: 0.3rem;
										border: 1px solid rgba(148, 163, 184, 0.4);
										box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
								}
								.sii-control-panel .nav-tab {
										border: none;
										border-radius: 999px;
										background: transparent;
										color: #475569;
										font-weight: 600;
										transition: all 0.25s ease;
										padding: 0.55rem 1.3rem;
								}
								.sii-control-panel .nav-tab:hover {
										background: rgba(58, 123, 213, 0.12);
										color: #2563eb;
								}
								.sii-control-panel .nav-tab.nav-tab-active {
										background: linear-gradient(135deg, #3a7bd5 0%, #00d2ff 100%);
										color: #fff;
										box-shadow: 0 10px 26px rgba(58, 123, 213, 0.35);
								}
								.sii-control-panel .sii-section {
										position: relative;
										z-index: 1;
										background: rgba(255, 255, 255, 0.95);
										border-radius: 18px;
										padding: 1.85rem 2rem;
										margin-top: 1.75rem;
										box-shadow: 0 22px 45px rgba(15, 23, 42, 0.1);
										border: 1px solid rgba(226, 232, 240, 0.7);
								}
								.sii-control-panel .sii-section > h2 {
										margin-top: 0;
										color: #1e3a8a;
										display: flex;
										align-items: center;
										gap: 0.6rem;
								}
								.sii-control-panel .sii-section > h2::after {
										content: '';
										flex: 1;
										height: 2px;
										border-radius: 999px;
										background: linear-gradient(90deg, rgba(58, 123, 213, 0.35), transparent);
								}
								.sii-control-panel table.widefat,
								.sii-control-panel table.wp-list-table {
										border-radius: 14px;
										overflow: hidden;
										border: none;
										box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
								}
								.sii-control-panel table.widefat thead th,
								.sii-control-panel table.wp-list-table thead th {
										background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(14, 165, 233, 0.15));
										color: #0f172a;
										font-weight: 600;
										padding: 0.9rem 1.1rem;
										border-bottom: 1px solid rgba(148, 163, 184, 0.35);
								}
								.sii-control-panel table.widefat tbody td,
								.sii-control-panel table.wp-list-table tbody td {
										padding: 0.85rem 1.1rem;
										background: rgba(255, 255, 255, 0.9);
										border-bottom: 1px solid rgba(226, 232, 240, 0.75);
								}
								.sii-control-panel table.widefat tbody tr:nth-child(even) td,
								.sii-control-panel table.wp-list-table tbody tr:nth-child(even) td {
										background: rgba(241, 245, 249, 0.9);
								}
								.sii-control-panel table.widefat tbody tr:last-child td,
								.sii-control-panel table.wp-list-table tbody tr:last-child td {
										border-bottom: none;
								}
								.sii-control-panel .button,
								.sii-control-panel .button-primary {
										border-radius: 999px;
										padding: 0.45rem 1.2rem;
										font-weight: 600;
										border: none;
										transition: transform 0.18s ease, box-shadow 0.18s ease;
										box-shadow: 0 10px 20px rgba(37, 99, 235, 0.15);
								}
								.sii-control-panel .button.button-primary {
										background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
										color: #fff;
								}
								.sii-control-panel .button:hover,
								.sii-control-panel .button-primary:hover {
										transform: translateY(-1px);
										box-shadow: 0 16px 25px rgba(37, 99, 235, 0.25);
								}
								.sii-control-panel .sii-inline-form {
										display: inline-flex;
										gap: 0.4rem;
										align-items: center;
								}
								.sii-control-panel .sii-inline-form .button {
										min-width: 90px;
								}
                                                                .sii-control-panel .sii-metric-filter {
                                                                                display: flex;
                                                                                flex-wrap: wrap;
                                                                                gap: 1rem;
                                                                                align-items: flex-end;
                                                                                margin-top: 1.5rem;
                                                                                margin-bottom: 1.3rem;
                                                                }
                                                                .sii-control-panel .sii-metric-filter label {
                                                                                display: flex;
                                                                                flex-direction: column;
                                                                                gap: 0.35rem;
                                                                                font-weight: 600;
                                                                                color: #1d4ed8;
                                                                }
                                                                .sii-control-panel .sii-metric-filter select {
                                                                                min-width: 160px;
                                                                                border-radius: 999px;
                                                                                border: 1px solid rgba(148, 163, 184, 0.35);
                                                                                padding: 0.45rem 1rem;
                                                                                background: rgba(255, 255, 255, 0.94);
                                                                                box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08);
                                                                }
                                                                .sii-control-panel .sii-metric-grid {
                                                                                display: grid;
                                                                                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                                                                                gap: 1.5rem;
                                                                                margin-top: 0.4rem;
                                                                }
                                                                .sii-control-panel .sii-metric-card {
                                                                                border-radius: 16px;
                                                                                padding: 1.3rem 1.5rem;
                                                                                background: linear-gradient(140deg, rgba(237, 242, 255, 0.95) 0%, rgba(248, 249, 255, 0.92) 100%);
                                                                                border: 1px solid rgba(219, 231, 255, 0.82);
                                                                                box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
                                                                }
                                                                .sii-control-panel .sii-metric-card h3 {
                                                                                margin-top: 0;
                                                                                margin-bottom: 0.65rem;
                                                                                font-size: 1.08rem;
                                                                                color: #1d4ed8;
                                                                }
                                                                .sii-control-panel .sii-metric-value {
                                                                                font-size: 2.1rem;
                                                                                font-weight: 700;
                                                                                color: #1e40af;
                                                                                margin: 0.35rem 0 0.9rem;
                                                                }
								.sii-control-panel .sii-metric-details {
										margin: 0;
										padding: 0;
										list-style: none;
										color: #1f2937;
										line-height: 1.5;
								}
								.sii-control-panel .sii-metric-details li {
										margin: 0.2rem 0;
								}
                                                                .sii-control-panel .sii-metric-charts {
                                                                                display: grid;
                                                                                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                                                                                gap: 1.5rem;
                                                                                margin-top: 2.1rem;
                                                                }
                                                                .sii-control-panel .sii-chart-card {
                                                                                border-radius: 18px;
                                                                                padding: 1.4rem 1.7rem;
                                                                                background: linear-gradient(140deg, rgba(237, 242, 255, 0.96) 0%, rgba(226, 232, 255, 0.9) 100%);
                                                                                border: 1px solid rgba(191, 219, 254, 0.7);
                                                                                box-shadow: 0 20px 40px rgba(15, 23, 42, 0.14);
                                                                                display: flex;
                                                                                flex-direction: column;
                                                                                gap: 1.1rem;
                                                                }
                                                                .sii-control-panel .sii-chart-card h3 {
                                                                                margin: 0;
                                                                                color: #1d4ed8;
                                                                                font-size: 1.12rem;
                                                                }
                                                                .sii-control-panel .sii-chart-card p {
                                                                                margin: 0;
                                                                                color: #475569;
                                                                                font-size: 0.85rem;
                                                                }
                                                                .sii-control-panel .sii-series-bars {
                                                                                display: grid;
                                                                                grid-template-columns: repeat(auto-fit, minmax(18px, 1fr));
                                                                                align-items: end;
                                                                                gap: 0.5rem;
                                                                                height: 180px;
                                                                                padding-bottom: 0.8rem;
                                                                }
                                                                .sii-control-panel .sii-series-bar {
                                                                                position: relative;
                                                                                background: linear-gradient(180deg, rgba(59, 130, 246, 0.9) 0%, rgba(14, 165, 233, 0.8) 100%);
                                                                                border-radius: 10px 10px 4px 4px;
                                                                                min-height: 4px;
                                                                                box-shadow: 0 12px 22px rgba(37, 99, 235, 0.28);
                                                                                transition: transform 0.2s ease;
                                                                }
                                                                .sii-control-panel .sii-series-bar::after {
                                                                                content: attr(data-value);
                                                                                position: absolute;
                                                                                bottom: calc(100% + 6px);
                                                                                left: 50%;
                                                                                transform: translateX(-50%);
                                                                                font-size: 0.75rem;
                                                                                color: #1e40af;
                                                                                font-weight: 600;
                                                                }
                                                                .sii-control-panel .sii-series-bar:hover {
                                                                                transform: translateY(-4px);
                                                                }
                                                                .sii-control-panel .sii-series-axis {
                                                                                display: grid;
                                                                                grid-template-columns: repeat(auto-fit, minmax(18px, 1fr));
                                                                                gap: 0.5rem;
                                                                                font-size: 0.75rem;
                                                                                color: #475569;
                                                                                text-align: center;
                                                                                font-weight: 600;
                                                                }
                                                                .sii-control-panel .sii-chart-empty {
                                                                                display: flex;
                                                                                align-items: center;
                                                                                justify-content: center;
                                                                                min-height: 140px;
                                                                                color: #64748b;
                                                                                background: rgba(226, 232, 240, 0.35);
                                                                                border-radius: 14px;
                                                                                border: 1px dashed rgba(148, 163, 184, 0.4);
                                                                }
                                                                .sii-control-panel .sii-chart-pie {
                                                                                width: 160px;
                                                                                height: 160px;
                                                                                margin: 0 auto;
                                                                                border-radius: 50%;
                                                                                background: conic-gradient(#cbd5f5 0deg 360deg);
                                                                                box-shadow: inset 0 0 0 12px rgba(255, 255, 255, 0.82), 0 20px 38px rgba(15, 23, 42, 0.15);
                                                                }
                                                                .sii-control-panel .sii-pie-legend {
                                                                                display: flex;
                                                                                flex-direction: column;
                                                                                gap: 0.6rem;
                                                                                font-size: 0.9rem;
                                                                }
                                                                .sii-control-panel .sii-pie-legend span {
                                                                                display: inline-flex;
                                                                                align-items: center;
                                                                                gap: 0.55rem;
                                                                                color: #334155;
                                                                }
                                                                .sii-control-panel .sii-pie-legend span::before {
                                                                                content: '';
                                                                                width: 14px;
                                                                                height: 14px;
                                                                                border-radius: 4px;
                                                                                background: currentColor;
                                                                                box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.7);
                                                                }
                                                                .sii-control-panel .sii-highlight {
                                                                                border-left: 4px solid rgba(37, 99, 235, 0.75);
                                                                                padding-left: 1.2rem;
                                                                                margin-top: 1.3rem;
                                                                                color: #0f172a;
                                                                                font-size: 0.98rem;
                                                                }
								.sii-control-panel .notice {
										border-radius: 14px;
										padding: 0.9rem 1rem;
										box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
								}
								.sii-control-panel textarea,
								.sii-control-panel select,
								.sii-control-panel input[type="text"],
								.sii-control-panel input[type="number"],
								.sii-control-panel input[type="date"] {
										border-radius: 12px;
										border: 1px solid rgba(148, 163, 184, 0.5);
										padding: 0.55rem 0.75rem;
										transition: border-color 0.2s ease, box-shadow 0.2s ease;
										box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
								}
								.sii-control-panel textarea:focus,
								.sii-control-panel select:focus,
								.sii-control-panel input:focus {
										outline: none;
										border-color: rgba(37, 99, 235, 0.75);
										box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
								}
								.sii-control-panel .sii-metric-card strong,
								.sii-control-panel .sii-highlight strong {
										color: inherit;
								}
								@media (max-width: 900px) {
										.sii-control-panel {
												padding: 2.25rem 1.6rem 2.5rem;
										}
								}
								@media (max-width: 782px) {
										.sii-control-panel {
												padding: 2rem 1.2rem 2.3rem;
										}
										.sii-control-panel .nav-tab-wrapper {
												width: 100%;
												justify-content: space-between;
										}
										.sii-control-panel .nav-tab {
												flex: 1;
												text-align: center;
										}
										.sii-control-panel table.widefat,
										.sii-control-panel table.wp-list-table {
												box-shadow: none;
										}
								}
								@media (prefers-color-scheme: dark) {
										.sii-control-panel {
												background: linear-gradient(135deg, rgba(15, 23, 42, 0.85) 0%, rgba(15, 118, 255, 0.18) 100%);
												box-shadow: 0 30px 55px rgba(2, 6, 23, 0.65);
										}
										.sii-control-panel::before {
												background: radial-gradient(circle at center, rgba(37, 99, 235, 0.35), transparent 70%);
										}
										.sii-control-panel::after {
												background: radial-gradient(circle at center, rgba(14, 165, 233, 0.25), transparent 75%);
										}
										.sii-control-panel h1,
										.sii-control-panel h1 + p {
												color: #e2e8f0;
										}
										.sii-control-panel .nav-tab-wrapper {
												background: rgba(15, 23, 42, 0.75);
												border-color: rgba(148, 163, 184, 0.4);
												box-shadow: inset 0 1px 0 rgba(148, 163, 184, 0.35);
										}
										.sii-control-panel .nav-tab {
												color: #cbd5f5;
										}
										.sii-control-panel .nav-tab:hover {
												color: #93c5fd;
												background: rgba(37, 99, 235, 0.2);
										}
										.sii-control-panel .nav-tab.nav-tab-active {
												box-shadow: 0 12px 24px rgba(14, 165, 233, 0.45);
										}
										.sii-control-panel .sii-section {
												background: rgba(15, 23, 42, 0.82);
												box-shadow: 0 28px 60px rgba(2, 6, 23, 0.7);
												border-color: rgba(51, 65, 85, 0.8);
										}
										.sii-control-panel .sii-section > h2,
										.sii-control-panel .sii-metric-value,
										.sii-control-panel .sii-highlight {
												color: #e2e8f0;
										}
										.sii-control-panel .sii-metric-filter select {
												background: rgba(30, 41, 59, 0.9);
												color: #e2e8f0;
												border-color: rgba(148, 163, 184, 0.45);
										}
										.sii-control-panel .sii-metric-card {
												background: linear-gradient(140deg, rgba(30, 41, 59, 0.92) 0%, rgba(30, 64, 175, 0.45) 100%);
												border-color: rgba(59, 130, 246, 0.35);
												box-shadow: 0 18px 48px rgba(15, 23, 42, 0.75);
										}
										.sii-control-panel .sii-chart-card {
												background: linear-gradient(140deg, rgba(30, 41, 59, 0.95) 0%, rgba(15, 118, 255, 0.28) 100%);
												border-color: rgba(59, 130, 246, 0.38);
												box-shadow: 0 24px 52px rgba(2, 6, 23, 0.75);
										}
										.sii-control-panel .sii-chart-card h3,
										.sii-control-panel .sii-chart-card p,
										.sii-control-panel .sii-series-axis,
										.sii-control-panel .sii-pie-legend span {
												color: #cbd5f5;
										}
										.sii-control-panel .sii-series-bar::after {
												color: #f8fafc;
										}
										.sii-control-panel .sii-chart-empty {
												background: rgba(30, 41, 59, 0.7);
												color: #cbd5f5;
												border-color: rgba(148, 163, 184, 0.4);
										}
										.sii-control-panel .sii-chart-pie {
												box-shadow: inset 0 0 0 12px rgba(15, 23, 42, 0.55), 0 20px 38px rgba(2, 6, 23, 0.65);
										}
										.sii-control-panel table.widefat,
										.sii-control-panel table.wp-list-table {
												box-shadow: 0 18px 36px rgba(2, 6, 23, 0.65);
										}
										.sii-control-panel table.widefat thead th,
										.sii-control-panel table.wp-list-table thead th {
												background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(14, 165, 233, 0.2));
												color: #f8fafc;
												border-bottom-color: rgba(148, 163, 184, 0.35);
										}
										.sii-control-panel table.widefat tbody td,
										.sii-control-panel table.wp-list-table tbody td {
												background: rgba(15, 23, 42, 0.85);
												color: #e2e8f0;
												border-bottom-color: rgba(30, 41, 59, 0.8);
										}
										.sii-control-panel table.widefat tbody tr:nth-child(even) td,
										.sii-control-panel table.wp-list-table tbody tr:nth-child(even) td {
												background: rgba(15, 23, 42, 0.75);
										}
										.sii-control-panel .button,
										.sii-control-panel .button-primary {
												box-shadow: 0 12px 24px rgba(14, 165, 233, 0.35);
										}
										.sii-control-panel textarea,
										.sii-control-panel select,
										.sii-control-panel input[type="text"],
										.sii-control-panel input[type="number"],
										.sii-control-panel input[type="date"] {
												background: rgba(15, 23, 42, 0.85);
												color: #e2e8f0;
												border-color: rgba(148, 163, 184, 0.5);
										}
										.sii-control-panel textarea:focus,
										.sii-control-panel select:focus,
										.sii-control-panel input:focus {
												box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.3);
										}
										.sii-control-panel .notice {
												background: rgba(15, 23, 42, 0.85);
												color: #e2e8f0;
										}
								}
						</style>
						<?php $this->render_notices(); ?>
						<h1><?php echo esc_html__( 'Control Panel', 'sii-boleta-dte' ); ?></h1>
						<p><?php echo esc_html__( 'Monitor your DTE operations, queues and scheduled tasks in an elegant unified dashboard.', 'sii-boleta-dte' ); ?></p>
						<h2 class="nav-tab-wrapper">
								<?php
								$base = function_exists( 'menu_page_url' ) ? menu_page_url( 'sii-boleta-dte', false ) : '?page=sii-boleta-dte';
								$tabs = array(
									'logs'    => __( 'Recent DTEs', 'sii-boleta-dte' ),
									'queue'   => __( 'Queue', 'sii-boleta-dte' ),
									'rvd'     => __( 'RVD', 'sii-boleta-dte' ),
									'libro'   => __( 'Libro validation', 'sii-boleta-dte' ),
									'metrics' => __( 'Metrics', 'sii-boleta-dte' ),
								);
								if ( ! isset( $tabs[ $tab ] ) ) {
										$tab = 'logs';
								}
								foreach ( $tabs as $key => $label ) {
									$active = $tab === $key ? ' nav-tab-active' : '';
									echo '<a href="' . esc_url( $base . '&tab=' . $key ) . '" class="nav-tab' . $active . '">' . esc_html( $label ) . '</a>';
								}
								?>
			</h2>
			<?php
			if ( 'queue' === $tab ) {
					$this->render_queue();
			} elseif ( 'rvd' === $tab ) {
					$this->render_rvd_tools();
			} elseif ( 'libro' === $tab ) {
					$this->render_libro_validation();
			} elseif ( 'metrics' === $tab ) {
					$this->render_metrics_dashboard();
			} else {
					$this->render_recent_logs();
			}
			?>
		</div>
		<?php
	}

	/** Renders folio availability table. */
	private function render_folios(): void {
		$cfg   = $this->settings->get_settings();
		$types = $cfg['enabled_types'] ?? array();
		?>
		<h2><?php echo esc_html__( 'Folio availability', 'sii-boleta-dte' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Type', 'sii-boleta-dte' ); ?></th>
					<th><?php echo esc_html__( 'Available', 'sii-boleta-dte' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$environment = $this->settings->get_environment();
				foreach ( $types as $type ) :
					$caf       = $this->folio_manager->get_caf_info( (int) $type );
					$last      = Settings::get_last_folio_value( (int) $type, $environment );
					$available = isset( $caf['H'] ) ? (int) $caf['H'] - $last : 0;
					?>
					<tr>
						<td><?php echo (int) $type; ?></td>
						<td><?php echo (int) $available; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Shows latest log entries. */
	private function render_recent_logs(): void {
			$logs = LogDb::get_logs( array( 'limit' => 5 ) );
		?>
				<div class="sii-section">
						<h2><?php echo esc_html__( 'Recent DTEs', 'sii-boleta-dte' ); ?></h2>
						<table class="widefat striped">
						<thead>
								<tr>
										<th><?php echo esc_html__( 'Track ID', 'sii-boleta-dte' ); ?></th>
										<th><?php echo esc_html__( 'Status', 'sii-boleta-dte' ); ?></th>
								</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['track_id'] ); ?></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
				</tr>
								<?php endforeach; ?>
						</tbody>
						</table>
				</div>
				<?php
	}

		/** Lists queue items with controls. */
	private function render_queue(): void {
			$jobs = QueueDb::get_pending_jobs();
		?>
				<div class="sii-section">
						<h2><?php echo esc_html__( 'Queue', 'sii-boleta-dte' ); ?></h2>
					<?php if ( empty( $jobs ) ) : ?>
						<p><?php echo esc_html__( 'No queued items.', 'sii-boleta-dte' ); ?></p>
						<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
				<thead>
				<tr>
						<th><?php echo esc_html__( 'ID', 'sii-boleta-dte' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'sii-boleta-dte' ); ?></th>
			<th><?php echo esc_html__( 'Attempts', 'sii-boleta-dte' ); ?></th>
			<th><?php echo esc_html__( 'Actions', 'sii-boleta-dte' ); ?></th>
		</tr>
		</thead>
		<tbody>
							<?php foreach ( $jobs as $job ) : ?>
		<tr>
			<td><?php echo (int) $job['id']; ?></td>
			<td><?php echo esc_html( $this->translate_type( $job['type'] ) ); ?></td>
			<td><?php echo (int) $job['attempts']; ?></td>
			<td>
						<form method="post" class="sii-inline-form">
				<input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>" />
								<?php $this->output_nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' ); ?>
				<button class="button" name="queue_action" value="process"><?php echo esc_html__( 'Process', 'sii-boleta-dte' ); ?></button>
				<button class="button" name="queue_action" value="requeue"><?php echo esc_html__( 'Retry', 'sii-boleta-dte' ); ?></button>
				<button class="button" name="queue_action" value="cancel"><?php echo esc_html__( 'Cancel', 'sii-boleta-dte' ); ?></button>
				</form>
			</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
						</table>
						<?php endif; ?>
				</div>
				<?php
	}

	private function render_rvd_tools(): void {
		?>
				<div class="sii-section">
						<h2><?php echo esc_html__( 'Generate and send RVD', 'sii-boleta-dte' ); ?></h2>
						<p><?php echo esc_html__( 'Creates the daily sales summary and sends it to the SII immediately.', 'sii-boleta-dte' ); ?></p>
						<form method="post">
						<input type="hidden" name="rvd_action" value="generate_send" />
					<?php $this->output_nonce_field( 'sii_boleta_rvd', 'sii_boleta_rvd_nonce' ); ?>
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Generate and send RVD', 'sii-boleta-dte' ); ?></button>
						</form>
					<?php $this->render_rvd_schedule(); ?>
				</div>
				<?php
	}

	private function render_libro_validation(): void {
		?>
				<div class="sii-section">
						<h2><?php echo esc_html__( 'Validate Libro XML', 'sii-boleta-dte' ); ?></h2>
						<p><?php echo esc_html__( 'Paste the Libro XML to verify it against the official schema.', 'sii-boleta-dte' ); ?></p>
					<?php $this->render_libro_schedule(); ?>
						<form method="post">
						<input type="hidden" name="libro_action" value="validate" />
					<?php $this->output_nonce_field( 'sii_boleta_libro', 'sii_boleta_libro_nonce' ); ?>
						<textarea name="libro_xml" rows="10" class="large-text" placeholder="&lt;LibroBoleta&gt;...&lt;/LibroBoleta&gt;"></textarea>
					<?php
					if ( function_exists( 'submit_button' ) ) {
							submit_button( __( 'Validate XML', 'sii-boleta-dte' ) );
					} else {
							echo '<button type="submit" class="button button-primary">' . esc_html__( 'Validate XML', 'sii-boleta-dte' ) . '</button>';
					}
					?>
						</form>
				</div>
				<?php
	}

        private function render_metrics_dashboard(): void {
                $logs  = LogDb::get_logs( array( 'limit' => 500 ) );
                $years = array();
                foreach ( $logs as $row ) {
                        $timestamp = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] ) : false;
                        if ( false === $timestamp ) {
                                continue;
                        }
                        $year = (int) gmdate( 'Y', $timestamp );
                        if ( ! in_array( $year, $years, true ) ) {
                                $years[] = $year;
                        }
                }
                rsort( $years );

                $raw_year = isset( $_GET['metrics_year'] ) ? (string) $_GET['metrics_year'] : '';
                if ( function_exists( 'sanitize_text_field' ) ) {
                        $raw_year = sanitize_text_field( $raw_year );
                } else {
                        $raw_year = preg_replace( '/[^0-9]/', '', $raw_year );
                }
                $selected_year = (int) $raw_year;
                if ( $selected_year <= 0 || ! in_array( $selected_year, $years, true ) ) {
                        $selected_year = 0;
                }

                $raw_month = isset( $_GET['metrics_month'] ) ? (string) $_GET['metrics_month'] : '';
                if ( function_exists( 'sanitize_text_field' ) ) {
                        $raw_month = sanitize_text_field( $raw_month );
                } else {
                        $raw_month = preg_replace( '/[^0-9]/', '', $raw_month );
                }
                $selected_month = (int) $raw_month;
                if ( $selected_month < 1 || $selected_month > 12 ) {
                        $selected_month = 0;
                }

                $filtered_logs = array();
                foreach ( $logs as $row ) {
                        $timestamp = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] ) : false;
                        if ( false === $timestamp ) {
                                continue;
                        }
                        $year  = (int) gmdate( 'Y', $timestamp );
                        $month = (int) gmdate( 'n', $timestamp );
                        if ( $selected_year > 0 && $year !== $selected_year ) {
                                continue;
                        }
                        if ( $selected_month > 0 && $month !== $selected_month ) {
                                continue;
                        }
                        $row['__timestamp'] = $timestamp;
                        $filtered_logs[]    = $row;
                }

                $status_counts = array();
                foreach ( $filtered_logs as $row ) {
                        $status = isset( $row['status'] ) ? (string) $row['status'] : '';
                        if ( '' === $status ) {
                                continue;
                        }
                        if ( ! isset( $status_counts[ $status ] ) ) {
                                $status_counts[ $status ] = 0;
                        }
                        ++$status_counts[ $status ];
                }

                $total_dtes    = count( $filtered_logs );
                $accepted_dtes = $status_counts['accepted'] ?? 0;
                $sent_dtes     = $status_counts['sent'] ?? 0;
                $rejected_dtes = $status_counts['rejected'] ?? 0;

                $reference_year = $selected_year > 0 ? $selected_year : (int) gmdate( 'Y' );
                $month_options  = array();
                for ( $m = 1; $m <= 12; ++$m ) {
                        $timestamp            = gmmktime( 0, 0, 0, $m, 1, $reference_year );
                        $month_options[ $m ] = function_exists( 'date_i18n' ) ? date_i18n( 'F', $timestamp ) : gmdate( 'F', $timestamp );
                }

                $page_slug = isset( $_GET['page'] ) ? (string) $_GET['page'] : 'sii-boleta-dte';
                if ( function_exists( 'sanitize_key' ) ) {
                        $page_slug = sanitize_key( $page_slug );
                } else {
                        $page_slug = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $page_slug ) );
                }
                if ( '' === $page_slug ) {
                        $page_slug = 'sii-boleta-dte';
                }
                $metrics_base = function_exists( 'menu_page_url' ) ? menu_page_url( 'sii-boleta-dte', false ) : '?page=' . rawurlencode( $page_slug );
                $metrics_url  = $metrics_base . '&tab=metrics';

                $series_points  = array();
                $series_caption = '';

                if ( $selected_year > 0 && $selected_month > 0 ) {
                        $days         = $this->days_in_month( $selected_year, $selected_month );
                        $daily_counts = array_fill( 1, $days, 0 );
                        foreach ( $filtered_logs as $row ) {
                                $day = (int) gmdate( 'j', $row['__timestamp'] );
                                if ( isset( $daily_counts[ $day ] ) ) {
                                        ++$daily_counts[ $day ];
                                }
                        }
                        for ( $day = 1; $day <= $days; ++$day ) {
                                $series_points[] = array(
                                        'label' => sprintf( '%02d', $day ),
                                        'value' => $daily_counts[ $day ] ?? 0,
                                );
                        }
                        $month_label   = $month_options[ $selected_month ] ?? (string) $selected_month;
                        $series_caption = sprintf( __( 'Daily activity for %1$s %2$d', 'sii-boleta-dte' ), $month_label, $selected_year );
                } elseif ( $selected_year > 0 ) {
                        $monthly_counts = array_fill( 1, 12, 0 );
                        foreach ( $filtered_logs as $row ) {
                                $month = (int) gmdate( 'n', $row['__timestamp'] );
                                ++$monthly_counts[ $month ];
                        }
                        for ( $month = 1; $month <= 12; ++$month ) {
                                $timestamp = gmmktime( 0, 0, 0, $month, 1, $selected_year );
                                $label     = function_exists( 'date_i18n' ) ? date_i18n( 'M', $timestamp ) : gmdate( 'M', $timestamp );
                                $series_points[] = array(
                                        'label' => $label,
                                        'value' => $monthly_counts[ $month ] ?? 0,
                                );
                        }
                        $series_caption = sprintf( __( 'Monthly totals for %d', 'sii-boleta-dte' ), $selected_year );
                } elseif ( $selected_month > 0 ) {
                        $year_counts = array();
                        foreach ( $filtered_logs as $row ) {
                                $year = (int) gmdate( 'Y', $row['__timestamp'] );
                                if ( ! isset( $year_counts[ $year ] ) ) {
                                        $year_counts[ $year ] = 0;
                                }
                                ++$year_counts[ $year ];
                        }
                        ksort( $year_counts );
                        foreach ( $year_counts as $year => $value ) {
                                $series_points[] = array(
                                        'label' => (string) $year,
                                        'value' => $value,
                                );
                        }
                        $month_label   = $month_options[ $selected_month ] ?? (string) $selected_month;
                        $series_caption = sprintf( __( 'Yearly comparison for %s', 'sii-boleta-dte' ), $month_label );
                } else {
                        $month_counts = array();
                        foreach ( $filtered_logs as $row ) {
                                $year  = (int) gmdate( 'Y', $row['__timestamp'] );
                                $month = (int) gmdate( 'n', $row['__timestamp'] );
                                $key   = sprintf( '%04d-%02d', $year, $month );
                                if ( ! isset( $month_counts[ $key ] ) ) {
                                        $month_counts[ $key ] = 0;
                                }
                                ++$month_counts[ $key ];
                        }
                        ksort( $month_counts );
                        if ( count( $month_counts ) > 6 ) {
                                $month_counts = array_slice( $month_counts, -6, null, true );
                        }
                        foreach ( $month_counts as $key => $value ) {
                                list( $year, $month ) = array_map( 'intval', explode( '-', $key ) );
                                $timestamp = gmmktime( 0, 0, 0, $month, 1, $year );
                                $label     = function_exists( 'date_i18n' ) ? date_i18n( 'M Y', $timestamp ) : gmdate( 'M Y', $timestamp );
                                $series_points[] = array(
                                        'label' => $label,
                                        'value' => $value,
                                );
                        }
                        $series_caption = __( 'Most recent six months of activity', 'sii-boleta-dte' );
                }

                if ( '' === $series_caption ) {
                        $series_caption = __( 'Timeline for the latest submissions', 'sii-boleta-dte' );
                }

                $series_total = 0;
                foreach ( $series_points as $point ) {
                        $series_total += (int) $point['value'];
                }
                $series_max = 0;
                if ( $series_total > 0 ) {
                        $series_max = max( array_map( static fn( $point ) => (int) $point['value'], $series_points ) );
                }

                $legend_items = array(
                        array(
                                'label' => __( 'Accepted', 'sii-boleta-dte' ),
                                'value' => $accepted_dtes,
                                'color' => '#22c55e',
                        ),
                        array(
                                'label' => __( 'Sent (awaiting result)', 'sii-boleta-dte' ),
                                'value' => $sent_dtes,
                                'color' => '#38bdf8',
                        ),
                        array(
                                'label' => __( 'Rejected', 'sii-boleta-dte' ),
                                'value' => $rejected_dtes,
                                'color' => '#ef4444',
                        ),
                );

                $pie_total   = 0;
                $gradients   = array();
                $start_angle = 0.0;
                foreach ( $legend_items as &$item ) {
                        $pie_total += (int) $item['value'];
                }
                if ( $pie_total > 0 ) {
                        foreach ( $legend_items as &$item ) {
                                $item_value        = (int) $item['value'];
                                $item['percentage'] = (int) round( ( $item_value / $pie_total ) * 100 );
                                $angle              = ( $item_value / $pie_total ) * 360;
                                $end_angle          = $start_angle + $angle;
                                if ( $angle > 0 ) {
                                        $gradients[] = sprintf( '%s %.2fdeg %.2fdeg', $item['color'], $start_angle, $end_angle );
                                }
                                $start_angle = $end_angle;
                        }
                } else {
                        foreach ( $legend_items as &$item ) {
                                $item['percentage'] = 0;
                        }
                }
                unset( $item );

                $pie_style = '';
                if ( ! empty( $gradients ) ) {
                        $pie_style = 'background: conic-gradient(' . implode( ', ', $gradients ) . ');';
                }

                $cfg         = $this->settings->get_settings();
                $environment = $this->settings->get_environment();
                $rvd_enabled = ! empty( $cfg['rvd_auto_enabled'] );
                $rvd_time    = isset( $cfg['rvd_auto_time'] ) ? (string) $cfg['rvd_auto_time'] : '02:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $rvd_time ) ) {
                        $rvd_time = '02:00';
                }
                $rvd_last_run = Settings::get_schedule_last_run( 'rvd', $environment );
                $rvd_next     = $rvd_enabled ? $this->next_daily_run_timestamp( $rvd_time ) : 0;

                $libro_enabled = ! empty( $cfg['libro_auto_enabled'] );
                $libro_day     = isset( $cfg['libro_auto_day'] ) ? (int) $cfg['libro_auto_day'] : 1;
                if ( $libro_day < 1 || $libro_day > 31 ) {
                        $libro_day = 1;
                }
                $libro_time = isset( $cfg['libro_auto_time'] ) ? (string) $cfg['libro_auto_time'] : '03:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $libro_time ) ) {
                        $libro_time = '03:00';
                }
                $libro_last_run = Settings::get_schedule_last_run( 'libro', $environment );
                $libro_next     = $libro_enabled ? $this->next_monthly_run_timestamp( $libro_day, $libro_time ) : 0;
                $libro_period   = $this->previous_month_period( $this->current_timestamp() );

                $queue_jobs   = QueueDb::get_pending_jobs( 50 );
                $queue_counts = array(
                        'dte'   => 0,
                        'rvd'   => 0,
                        'libro' => 0,
                );
                foreach ( $queue_jobs as $job ) {
                        $type = isset( $job['type'] ) ? (string) $job['type'] : '';
                        if ( isset( $queue_counts[ $type ] ) ) {
                                ++$queue_counts[ $type ];
                        }
                }

                $dominant_status = __( 'Accepted', 'sii-boleta-dte' );
                $dominant_value  = $accepted_dtes;
                if ( $sent_dtes > $dominant_value ) {
                        $dominant_status = __( 'Sent (awaiting result)', 'sii-boleta-dte' );
                        $dominant_value  = $sent_dtes;
                }
                if ( $rejected_dtes > $dominant_value ) {
                        $dominant_status = __( 'Rejected', 'sii-boleta-dte' );
                        $dominant_value  = $rejected_dtes;
                }
                ?>
                <div class="sii-section">
                        <h2><?php echo esc_html__( 'Operational metrics', 'sii-boleta-dte' ); ?></h2>
                        <form method="get" class="sii-metric-filter">
                                <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
                                <input type="hidden" name="tab" value="metrics" />
                                <label>
                                        <span><?php echo esc_html__( 'Year', 'sii-boleta-dte' ); ?></span>
                                        <select name="metrics_year">
                                                <option value=""><?php echo esc_html__( 'All years', 'sii-boleta-dte' ); ?></option>
                                                <?php foreach ( $years as $year_option ) : ?>
                                                        <?php $selected_attr = ( (int) $year_option === $selected_year ) ? ' selected="selected"' : ''; ?>
                                                        <option value="<?php echo (int) $year_option; ?>"<?php echo $selected_attr; ?>><?php echo (int) $year_option; ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </label>
                                <label>
                                        <span><?php echo esc_html__( 'Month', 'sii-boleta-dte' ); ?></span>
                                        <select name="metrics_month">
                                                <option value=""><?php echo esc_html__( 'All months', 'sii-boleta-dte' ); ?></option>
                                                <?php foreach ( $month_options as $month_number => $month_label ) : ?>
                                                        <?php $selected_attr = ( (int) $month_number === $selected_month ) ? ' selected="selected"' : ''; ?>
                                                        <option value="<?php echo (int) $month_number; ?>"<?php echo $selected_attr; ?>><?php echo esc_html( $month_label ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </label>
                                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Apply filters', 'sii-boleta-dte' ); ?></button>
                                <?php if ( $selected_year || $selected_month ) : ?>
                                        <a class="button" href="<?php echo esc_url( $metrics_url ); ?>"><?php echo esc_html__( 'Reset', 'sii-boleta-dte' ); ?></a>
                                <?php endif; ?>
                        </form>
                        <div class="sii-metric-grid">
                                <div class="sii-metric-card">
                                        <h3><?php echo esc_html__( 'DTE performance', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo (int) $total_dtes; ?></p>
                                        <ul class="sii-metric-details">
                                                <li><?php echo esc_html__( 'Accepted', 'sii-boleta-dte' ) . ': ' . (int) $accepted_dtes; ?></li>
                                                <li><?php echo esc_html__( 'Sent (awaiting result)', 'sii-boleta-dte' ) . ': ' . (int) $sent_dtes; ?></li>
                                                <li><?php echo esc_html__( 'Rejected', 'sii-boleta-dte' ) . ': ' . (int) $rejected_dtes; ?></li>
                                        </ul>
                                </div>
                                <div class="sii-metric-card">
                                        <h3><?php echo esc_html__( 'RVD automation', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo esc_html( $rvd_enabled ? __( 'Active', 'sii-boleta-dte' ) : __( 'Paused', 'sii-boleta-dte' ) ); ?></p>
                                        <ul class="sii-metric-details">
                                                <li><?php echo esc_html__( 'Last submission', 'sii-boleta-dte' ) . ': ' . esc_html( '' !== $rvd_last_run ? $rvd_last_run : __( 'Never', 'sii-boleta-dte' ) ); ?></li>
                                                <li><?php echo esc_html__( 'Next run', 'sii-boleta-dte' ) . ': ' . esc_html( $this->format_datetime( $rvd_next ) ); ?></li>
                                                <li><?php echo esc_html__( 'Pending jobs', 'sii-boleta-dte' ) . ': ' . (int) $queue_counts['rvd']; ?></li>
                                        </ul>
                                </div>
                                <div class="sii-metric-card">
                                        <h3><?php echo esc_html__( 'Libro de boletas', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo esc_html( $libro_enabled ? __( 'Scheduled', 'sii-boleta-dte' ) : __( 'Manual', 'sii-boleta-dte' ) ); ?></p>
                                        <ul class="sii-metric-details">
                                                <li><?php echo esc_html__( 'Last report', 'sii-boleta-dte' ) . ': ' . esc_html( '' !== $libro_last_run ? $libro_last_run : __( 'Never', 'sii-boleta-dte' ) ); ?></li>
                                                <li><?php echo esc_html__( 'Next reporting window', 'sii-boleta-dte' ) . ': ' . esc_html( $this->format_datetime( $libro_next ) ); ?></li>
                                                <li><?php echo esc_html__( 'Period under preparation', 'sii-boleta-dte' ) . ': ' . esc_html( $libro_period ); ?></li>
                                                <li><?php echo esc_html__( 'Pending jobs', 'sii-boleta-dte' ) . ': ' . (int) $queue_counts['libro']; ?></li>
                                        </ul>
                                </div>
                        </div>
                        <div class="sii-metric-charts">
                                <div class="sii-chart-card">
                                        <h3><?php echo esc_html__( 'Time series', 'sii-boleta-dte' ); ?></h3>
                                        <p><?php echo esc_html( $series_caption ); ?></p>
                                        <?php if ( $series_total > 0 && $series_max > 0 ) : ?>
                                                <div class="sii-series-bars">
                                                        <?php foreach ( $series_points as $point ) : ?>
                                                                <?php
                                                                $value      = (int) $point['value'];
                                                                $height     = max( 6, (int) round( ( $value / $series_max ) * 100 ) );
                                                                $bar_style  = 'height: ' . $height . '%;';
                                                                ?>
                                                                <div class="sii-series-bar" style="<?php echo esc_attr( $bar_style ); ?>" data-value="<?php echo esc_attr( (string) $value ); ?>"></div>
                                                        <?php endforeach; ?>
                                                </div>
                                                <div class="sii-series-axis">
                                                        <?php foreach ( $series_points as $point ) : ?>
                                                                <span><?php echo esc_html( (string) $point['label'] ); ?></span>
                                                        <?php endforeach; ?>
                                                </div>
                                        <?php else : ?>
                                                <div class="sii-chart-empty"><?php echo esc_html__( 'No data available for the selected filters.', 'sii-boleta-dte' ); ?></div>
                                        <?php endif; ?>
                                </div>
                                <div class="sii-chart-card">
                                        <h3><?php echo esc_html__( 'Status distribution', 'sii-boleta-dte' ); ?></h3>
                                        <p><?php echo esc_html__( 'Accepted vs pending vs rejected', 'sii-boleta-dte' ); ?></p>
                                        <?php if ( $pie_total > 0 ) : ?>
                                                <div class="sii-chart-pie"<?php echo '' !== $pie_style ? ' style="' . esc_attr( $pie_style ) . '"' : ''; ?>></div>
                                        <?php else : ?>
                                                <div class="sii-chart-empty"><?php echo esc_html__( 'No DTE activity to display.', 'sii-boleta-dte' ); ?></div>
                                        <?php endif; ?>
                                        <div class="sii-pie-legend">
                                                <?php foreach ( $legend_items as $item ) : ?>
                                                        <span style="color: <?php echo esc_attr( $item['color'] ); ?>;">
                                                                <?php echo esc_html( $item['label'] ); ?> · <?php echo (int) $item['value']; ?> (<?php echo (int) $item['percentage']; ?>%)
                                                        </span>
                                                <?php endforeach; ?>
                                        </div>
                                </div>
                        </div>
                        <div class="sii-highlight">
                                <strong><?php echo esc_html__( 'Key takeaway', 'sii-boleta-dte' ); ?></strong>
                                <?php if ( $series_total > 0 ) : ?>
                                        <span><?php echo esc_html( sprintf( __( 'Most documents are currently classified as %1$s (%2$d records).', 'sii-boleta-dte' ), $dominant_status, $dominant_value ) ); ?></span>
                                <?php else : ?>
                                        <span><?php echo esc_html__( 'We have not recorded activity for this period yet — use the filters to explore other dates.', 'sii-boleta-dte' ); ?></span>
                                <?php endif; ?>
                        </div>
                </div>
                <?php
        }

	/** Handles queue actions. */
	public function handle_queue_action( string $action, int $id ): void {
		if ( empty( $action ) ) {
			return;
		}

		if ( ! $this->verify_nonce( 'sii_boleta_queue_nonce', 'sii_boleta_queue' ) ) {
			$this->add_notice( __( 'Security verification failed. Please try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( 'process' === $action ) {
			$this->processor->process( $id );
		} elseif ( 'cancel' === $action ) {
			$this->processor->cancel( $id );
		} elseif ( 'requeue' === $action ) {
			$this->processor->retry( $id );
		}

		$this->add_notice( __( 'Queue action executed.', 'sii-boleta-dte' ) );
	}

	private function handle_rvd_action( string $action ): void {
		if ( 'generate_send' !== $action ) {
			return;
		}

		if ( ! $this->verify_nonce( 'sii_boleta_rvd_nonce', 'sii_boleta_rvd' ) ) {
			$this->add_notice( __( 'Security verification failed. Please try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		$xml = $this->rvd_manager->generate_xml();
		if ( '' === $xml ) {
			$this->add_notice( __( 'Unable to generate the RVD XML. Check your configuration and try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( ! $this->rvd_manager->validate_rvd_xml( $xml ) ) {
			$this->add_notice( __( 'The generated RVD XML is not valid.', 'sii-boleta-dte' ), 'error' );
			return;
		}

				$environment = $this->settings->get_environment();
				$token       = $this->token_manager->get_token( $environment );
				$response    = $this->api->send_libro_to_sii( $xml, $environment, $token );
		$is_error            = false;
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$is_error = true;
		} elseif ( class_exists( '\WP_Error' ) && $response instanceof \WP_Error ) {
			$is_error = true;
		}

		if ( $is_error ) {
			$error_message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : __( 'Unknown error', 'sii-boleta-dte' );
			$this->add_notice( sprintf( __( 'RVD sending failed: %s', 'sii-boleta-dte' ), $error_message ), 'error' );
			return;
		}

		$track = '';
		if ( is_array( $response ) && isset( $response['trackId'] ) ) {
			$track = (string) $response['trackId'];
		}

		$message = __( 'RVD sent successfully.', 'sii-boleta-dte' );
		if ( '' !== $track ) {
			$message .= ' ' . sprintf( __( 'Track ID: %s', 'sii-boleta-dte' ), $track );
		}

		$this->add_notice( $message );
	}

	private function handle_libro_action( string $action, string $xml ): void {
		if ( 'validate' !== $action ) {
				return;
		}

		if ( ! $this->verify_nonce( 'sii_boleta_libro_nonce', 'sii_boleta_libro' ) ) {
			$this->add_notice( __( 'Security verification failed. Please try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

			$xml = trim( $xml );
		if ( '' === $xml ) {
			$this->add_notice( __( 'Please paste the Libro XML before validating.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( $this->libro_boletas->validate_libro_xml( $xml ) ) {
			$this->add_notice( __( 'Libro XML is valid.', 'sii-boleta-dte' ) );
		} else {
			$this->add_notice( __( 'Libro XML did not pass validation. Review the structure and try again.', 'sii-boleta-dte' ), 'error' );
		}
	}

	private function output_nonce_field( string $action, string $name ): void {
		if ( function_exists( 'wp_nonce_field' ) ) {
			\wp_nonce_field( $action, $name );
			return;
		}

			$action_attr = htmlspecialchars( $action, ENT_QUOTES, 'UTF-8' );
			$name_attr   = htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' );
			echo '<input type="hidden" name="' . $name_attr . '" value="' . $action_attr . '" />';
	}

	private function verify_nonce( string $field, string $action ): bool {
		if ( empty( $_POST[ $field ] ) ) {
			return false;
		}

		if ( function_exists( 'wp_verify_nonce' ) ) {
			return (bool) \wp_verify_nonce( (string) $_POST[ $field ], $action );
		}

				return true;
	}

	private function add_notice( string $message, string $type = 'success' ): void {
			$this->notices[] = array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => $message,
			);
	}

	private function render_notices(): void {
		if ( empty( $this->notices ) ) {
			return;
		}

		foreach ( $this->notices as $notice ) {
			$type    = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			$message = $notice['message'];
			echo '<div class="notice ' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		$this->notices = array();
	}

	/** Returns a translated label for a queue job type. */
	private function translate_type( string $type ): string {
			$map = array(
				'dte'   => __( 'DTE', 'sii-boleta-dte' ),
				'libro' => __( 'Libro', 'sii-boleta-dte' ),
				'rvd'   => __( 'RVD', 'sii-boleta-dte' ),
			);
			return $map[ $type ] ?? $type;
	}

	private function render_rvd_schedule(): void {
			$cfg     = $this->settings->get_settings();
			$enabled = ! empty( $cfg['rvd_auto_enabled'] );
			$time    = isset( $cfg['rvd_auto_time'] ) ? (string) $cfg['rvd_auto_time'] : '02:00';
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
				$time = '02:00';
		}
			$environment = $this->settings->get_environment();
			$last        = Settings::get_schedule_last_run( 'rvd', $environment );
			$next_ts     = $enabled ? $this->next_daily_run_timestamp( $time ) : 0;
		?>
				<h3><?php echo esc_html__( 'Automatic schedule', 'sii-boleta-dte' ); ?></h3>
				<p><?php echo esc_html__( 'Status:', 'sii-boleta-dte' ) . ' ' . esc_html( $enabled ? __( 'Enabled', 'sii-boleta-dte' ) : __( 'Disabled', 'sii-boleta-dte' ) ); ?></p>
				<?php if ( $enabled ) : ?>
						<p><?php echo esc_html__( 'Daily time:', 'sii-boleta-dte' ) . ' ' . esc_html( $time ); ?></p>
						<p><?php echo esc_html__( 'Next run:', 'sii-boleta-dte' ) . ' ' . esc_html( $this->format_datetime( $next_ts ) ); ?></p>
				<?php endif; ?>
				<p><?php echo esc_html__( 'Last run:', 'sii-boleta-dte' ) . ' ' . esc_html( '' !== $last ? $last : __( 'Never', 'sii-boleta-dte' ) ); ?></p>
				<?php
	}

	private function render_libro_schedule(): void {
			$cfg     = $this->settings->get_settings();
			$enabled = ! empty( $cfg['libro_auto_enabled'] );
			$day     = isset( $cfg['libro_auto_day'] ) ? (int) $cfg['libro_auto_day'] : 1;
		if ( $day < 1 || $day > 31 ) {
				$day = 1;
		}
			$time = isset( $cfg['libro_auto_time'] ) ? (string) $cfg['libro_auto_time'] : '03:00';
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
				$time = '03:00';
		}
			$environment = $this->settings->get_environment();
			$last        = Settings::get_schedule_last_run( 'libro', $environment );
			$next_ts     = $enabled ? $this->next_monthly_run_timestamp( $day, $time ) : 0;
			$period      = $this->previous_month_period( $this->current_timestamp() );
		?>
				<h3><?php echo esc_html__( 'Monthly Libro schedule', 'sii-boleta-dte' ); ?></h3>
				<p><?php echo esc_html__( 'Status:', 'sii-boleta-dte' ) . ' ' . esc_html( $enabled ? __( 'Enabled', 'sii-boleta-dte' ) : __( 'Disabled', 'sii-boleta-dte' ) ); ?></p>
				<?php if ( $enabled ) : ?>
						<p><?php echo esc_html__( 'Scheduled day:', 'sii-boleta-dte' ) . ' ' . esc_html( (string) $day ); ?></p>
						<p><?php echo esc_html__( 'Send time:', 'sii-boleta-dte' ) . ' ' . esc_html( $time ); ?></p>
						<p><?php echo esc_html__( 'Next run:', 'sii-boleta-dte' ) . ' ' . esc_html( $this->format_datetime( $next_ts ) ); ?></p>
						<p><?php echo esc_html__( 'Period to be reported:', 'sii-boleta-dte' ) . ' ' . esc_html( $period ); ?></p>
				<?php endif; ?>
				<p><?php echo esc_html__( 'Last run:', 'sii-boleta-dte' ) . ' ' . esc_html( '' !== $last ? $last : __( 'Never', 'sii-boleta-dte' ) ); ?></p>
				<?php
	}

	private function current_timestamp(): int {
		if ( function_exists( 'current_time' ) ) {
				return (int) current_time( 'timestamp' );
		}
			return time();
	}

	private function get_timezone(): \DateTimeZone {
		try {
			if ( function_exists( 'wp_timezone' ) ) {
				return wp_timezone();
			}
		} catch ( \Throwable $e ) {
				// Ignore and fallback.
		}
			return new \DateTimeZone( 'UTC' );
	}

	private function timestamp_for_time( string $time, int $reference ): int {
			$timezone              = $this->get_timezone();
			$date                  = new \DateTimeImmutable( '@' . $reference );
			$date                  = $date->setTimezone( $timezone );
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$date                  = $date->setTime( $hour, $minute, 0 );
			return $date->getTimestamp();
	}

	private function next_daily_run_timestamp( string $time ): int {
			$now    = $this->current_timestamp();
			$target = $this->timestamp_for_time( $time, $now );
		if ( $now >= $target ) {
				$target = $this->timestamp_for_time( $time, $now + 86400 );
		}
			return $target;
	}

	private function timestamp_for_month_day_time( int $day, string $time, int $reference ): int {
			$timezone              = $this->get_timezone();
			$date                  = new \DateTimeImmutable( '@' . $reference );
			$date                  = $date->setTimezone( $timezone );
			$year                  = (int) $date->format( 'Y' );
			$month                 = (int) $date->format( 'm' );
			$base                  = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );
			$days                  = (int) $base->format( 't' );
			$day                   = min( max( 1, $day ), $days );
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$target                = $base->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 );
			return $target->getTimestamp();
	}

        private function next_monthly_run_timestamp( int $day, string $time ): int {
                        $now    = $this->current_timestamp();
                        $target = $this->timestamp_for_month_day_time( $day, $time, $now );
                if ( $now >= $target ) {
                                $timezone              = $this->get_timezone();
                                $date                  = new \DateTimeImmutable( '@' . $now );
                                $date                  = $date->setTimezone( $timezone )->modify( 'first day of next month' );
                                $year                  = (int) $date->format( 'Y' );
                                $month                 = (int) $date->format( 'm' );
                                $days                  = (int) $date->format( 't' );
                                $day                   = min( max( 1, $day ), $days );
                                list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
                                $target                = $date->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 )->getTimestamp();
                }
                        return $target;
        }

        private function days_in_month( int $year, int $month ): int {
                if ( $month < 1 || $month > 12 ) {
                                return 30;
                }
                $timestamp = gmmktime( 0, 0, 0, $month, 1, max( 1, $year ) );
                return (int) gmdate( 't', $timestamp );
        }

        private function previous_month_period( int $timestamp ): string {
                        $timezone = $this->get_timezone();
                        $date     = new \DateTimeImmutable( '@' . $timestamp );
                        $date     = $date->setTimezone( $timezone )->modify( 'first day of last month' );
                        return $date->format( 'Y-m' );
	}

	private function format_datetime( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
				return __( 'Not scheduled', 'sii-boleta-dte' );
		}
		if ( function_exists( 'wp_date' ) ) {
				return wp_date( 'Y-m-d H:i', $timestamp );
		}
			return gmdate( 'Y-m-d H:i', $timestamp );
	}
}

class_alias( ControlPanelPage::class, 'SII_Boleta_Control_Panel_Page' );
