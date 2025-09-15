<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Presentation\Admin\SettingsPage;
use Sii\BoletaDte\Presentation\Admin\LogsPage;
use Sii\BoletaDte\Presentation\Admin\DiagnosticsPage;
use Sii\BoletaDte\Presentation\Admin\Help;
use Sii\BoletaDte\Presentation\Admin\ControlPanelPage;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;

class Pages {
	private Plugin $core;
	private SettingsPage $settings_page;
	private LogsPage $logs_page;
	private DiagnosticsPage $diagnostics_page;
	private Help $help_page;
	private ControlPanelPage $control_panel_page;
	private GenerateDtePage $generate_dte_page;

	public function __construct( Plugin $core, SettingsPage $settings_page = null, LogsPage $logs_page = null, DiagnosticsPage $diagnostics_page = null, Help $help_page = null, ControlPanelPage $control_panel_page = null, GenerateDtePage $generate_dte_page = null ) {
					$this->core               = $core;
					$this->settings_page      = $settings_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( SettingsPage::class );
					$this->logs_page          = $logs_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( LogsPage::class );
					$this->diagnostics_page   = $diagnostics_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( DiagnosticsPage::class );
					$this->help_page          = $help_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( Help::class );
					$this->control_panel_page = $control_panel_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( ControlPanelPage::class );
					$this->generate_dte_page  = $generate_dte_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( GenerateDtePage::class );
	}

	public function register(): void {
				\add_menu_page(
					\__( 'SII Boletas', 'sii-boleta-dte' ),
					\__( 'SII Boletas', 'sii-boleta-dte' ),
					'manage_options',
					'sii-boleta-dte',
					array( $this->control_panel_page, 'render_page' ),
					'dashicons-media-document'
				);

								// Register settings sections and fields.
								$this->settings_page->register();

								// Default submenu linking to control panel.
								\add_submenu_page(
									'sii-boleta-dte',
									\__( 'Control Panel', 'sii-boleta-dte' ),
									\__( 'Control Panel', 'sii-boleta-dte' ),
									'manage_options',
									'sii-boleta-dte',
									array( $this->control_panel_page, 'render_page' )
								);

								// Manual generator page.
								$this->generate_dte_page->register();

								// Settings page.
								\add_submenu_page(
									'sii-boleta-dte',
									\__( 'Settings', 'sii-boleta-dte' ),
									\__( 'Settings', 'sii-boleta-dte' ),
									'manage_options',
									'sii-boleta-dte-settings',
									array( $this->settings_page, 'render_page' )
								);

								// Logs page.
								$this->logs_page->register();

								// Diagnostics and help pages.
								$this->diagnostics_page->register();
								$this->help_page->register();
	}

	public function enqueue_assets( string $hook ): void {
		if ( in_array( $hook, array( 'toplevel_page_sii-boleta-dte', 'sii-boleta-dte_page_sii-boleta-dte' ), true ) ) {
						\wp_enqueue_style(
							'sii-boleta-control-panel',
							SII_BOLETA_DTE_URL . 'src/Presentation/assets/css/control-panel.css',
							array(),
							SII_BOLETA_DTE_VERSION
						);
		}
		if ( 'sii-boleta-dte_page_sii-boleta-dte-generate' === $hook ) {
						\wp_enqueue_script(
							'sii-boleta-generate-dte',
							SII_BOLETA_DTE_URL . 'src/Presentation/assets/js/generate-dte.js',
							array(),
							SII_BOLETA_DTE_VERSION,
							true
						);
		}
	}
}

class_alias( Pages::class, 'Sii\\BoletaDte\\Admin\\Pages' );
