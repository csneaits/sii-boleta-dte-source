<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Presentation\Admin\SettingsPage;
use Sii\BoletaDte\Presentation\Admin\LogsPage;
use Sii\BoletaDte\Presentation\Admin\DiagnosticsPage;
use Sii\BoletaDte\Presentation\Admin\Help;
use Sii\BoletaDte\Presentation\Admin\ControlPanelPage;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Presentation\Admin\CafPage;

class Pages {
	private Plugin $core;
	private SettingsPage $settings_page;
	private LogsPage $logs_page;
	private DiagnosticsPage $diagnostics_page;
	private Help $help_page;
	private ControlPanelPage $control_panel_page;
	private GenerateDtePage $generate_dte_page;
	private CafPage $caf_page;

	public function __construct( Plugin $core, SettingsPage $settings_page = null, LogsPage $logs_page = null, DiagnosticsPage $diagnostics_page = null, Help $help_page = null, ControlPanelPage $control_panel_page = null, GenerateDtePage $generate_dte_page = null, CafPage $caf_page = null ) {
				$this->core                                  = $core;
				$this->settings_page                         = $settings_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( SettingsPage::class );
				$this->logs_page                             = $logs_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( LogsPage::class );
				$this->diagnostics_page                      = $diagnostics_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( DiagnosticsPage::class );
				$this->help_page                             = $help_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( Help::class );
				$this->control_panel_page                    = $control_panel_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( ControlPanelPage::class );
									$this->generate_dte_page = $generate_dte_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( GenerateDtePage::class );
									$this->caf_page          = $caf_page ?? \Sii\BoletaDte\Infrastructure\Factory\Container::get( CafPage::class );
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

																// CAF management page.
																\add_submenu_page(
																	'sii-boleta-dte',
																	\__( 'Folios / CAFs', 'sii-boleta-dte' ),
																	\__( 'Folios / CAFs', 'sii-boleta-dte' ),
																	'manage_options',
																	'sii-boleta-dte-cafs',
																	array( $this->caf_page, 'render_page' )
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
        if ( 'sii-boleta-dte_page_sii-boleta-dte-generate' === $hook || false !== strpos( $hook, 'sii-boleta-dte-generate' ) ) {
																		\wp_enqueue_script(
																			'sii-boleta-generate-dte',
																			SII_BOLETA_DTE_URL . 'src/Presentation/assets/js/generate-dte.js',
																			array(),
																			SII_BOLETA_DTE_VERSION,
																			true
																		);
                    \wp_localize_script(
                        'sii-boleta-generate-dte',
                        'siiBoletaGenerate',
                        array(
                            'nonce' => \wp_create_nonce( 'sii_boleta_nonce' ),
                            'ajax'  => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : ( ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'wp-admin/admin-ajax.php' ),
                            'previewAction' => 'sii_boleta_dte_generate_preview',
                            'texts' => array(
                                'previewReady' => __( 'Preview generated. Review the document below.', 'sii-boleta-dte' ),
                                'previewError' => __( 'Could not generate preview. Please try again.', 'sii-boleta-dte' ),
                                'openNewTab'   => __( 'Open preview in a new tab', 'sii-boleta-dte' ),
                                'loading'      => __( 'Generating preview…', 'sii-boleta-dte' ),
                            ),
                        )
                    );
        }

		if ( 'sii-boleta-dte_page_sii-boleta-dte-settings' === $hook ) {
										\wp_enqueue_media();
										\wp_enqueue_style(
											'sii-boleta-admin-settings',
											SII_BOLETA_DTE_URL . 'src/Presentation/assets/css/admin-settings.css',
											array(),
											SII_BOLETA_DTE_VERSION
										);
										\wp_enqueue_script(
											'sii-boleta-admin-settings',
											SII_BOLETA_DTE_URL . 'src/Presentation/assets/js/admin-settings.js',
											array( 'jquery' ),
											SII_BOLETA_DTE_VERSION,
											true
										);
										\wp_localize_script(
											'sii-boleta-admin-settings',
											'siiBoletaSettings',
											array(
												'optionKey' => Settings::OPTION_NAME,
												'cafOptions' => array(
													33 => \__( 'Factura', 'sii-boleta-dte' ),
													39 => \__( 'Boleta', 'sii-boleta-dte' ),
												),
												'texts' => array(
													'selectDocument' => \__( 'Select document type', 'sii-boleta-dte' ),
													'selectLogo'     => \__( 'Select logo', 'sii-boleta-dte' ),
													'useLogo'        => \__( 'Use logo', 'sii-boleta-dte' ),
													'sending'        => \__( 'Sending…', 'sii-boleta-dte' ),
													'sendFail'       => \__( 'Failed to send', 'sii-boleta-dte' ),
												),
												'nonce' => \wp_create_nonce( 'sii_boleta_nonce' ),
											)
										);
		}
	}
}

class_alias( Pages::class, 'Sii\\BoletaDte\\Admin\\Pages' );
