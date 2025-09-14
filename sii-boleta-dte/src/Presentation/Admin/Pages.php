<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Presentation\Admin\SettingsPage;
use Sii\BoletaDte\Presentation\Admin\LogsPage;
use Sii\BoletaDte\Presentation\Admin\DiagnosticsPage;
use Sii\BoletaDte\Presentation\Admin\Help;

class Pages {
	private Plugin $core;
	private SettingsPage $settings_page;
	private LogsPage $logs_page;
	private DiagnosticsPage $diagnostics_page;
	private Help $help_page;

	public function __construct( Plugin $core, SettingsPage $settings_page = null, LogsPage $logs_page = null, DiagnosticsPage $diagnostics_page = null, Help $help_page = null ) {
			$this->core             = $core;
			$this->settings_page    = $settings_page ?? new SettingsPage( $core->get_settings() );
			$this->logs_page        = $logs_page ?? new LogsPage();
			$this->diagnostics_page = $diagnostics_page ?? new DiagnosticsPage( $core->get_settings(), new \Sii\BoletaDte\Infrastructure\TokenManager( $core->get_api(), $core->get_settings() ), $core->get_api() );
			$this->help_page        = $help_page ?? new Help();
	}

	public function register(): void {
		\add_menu_page(
			\__( 'SII Boletas', 'sii-boleta-dte' ),
			\__( 'SII Boletas', 'sii-boleta-dte' ),
			'manage_options',
			'sii-boleta-dte',
			array( $this->settings_page, 'render_page' ),
			'dashicons-media-document'
		);

				// Register settings sections and fields.
				$this->settings_page->register();

				\add_submenu_page(
					'sii-boleta-dte',
					\__( 'Panel de Control', 'sii-boleta-dte' ),
					\__( 'Panel de Control', 'sii-boleta-dte' ),
					'manage_options',
					'sii-boleta-dte-panel',
					array( $this, 'render_control_panel_page' )
				);

				// Logs page.
				$this->logs_page->register();

				// Diagnostics and help pages.
				$this->diagnostics_page->register();
				$this->help_page->register();
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'sii-boleta-dte_page_sii-boleta-dte-panel' === $hook ) {
			\wp_enqueue_style(
				'sii-boleta-control-panel',
				SII_BOLETA_DTE_URL . 'assets/css/control-panel.css',
				array(),
				SII_BOLETA_DTE_VERSION
			);
		}
	}

	public function render_control_panel_page(): void {
		echo '<div class="wrap"><h1>' . \esc_html__( 'Panel de Control', 'sii-boleta-dte' ) . '</h1></div>';
	}
}

class_alias( Pages::class, 'Sii\\BoletaDte\\Admin\\Pages' );
