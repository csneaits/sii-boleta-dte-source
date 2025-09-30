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
                if ( false !== strpos( $hook, 'sii-boleta-dte' ) ) {
                        $style_relative = 'src/Presentation/assets/css/admin-shared.css';
                        $style_path     = SII_BOLETA_DTE_PATH . $style_relative;
                        $style_version  = SII_BOLETA_DTE_VERSION;
                        if ( file_exists( $style_path ) ) {
                                $style_version .= '-' . filemtime( $style_path );
                        }
                        \wp_enqueue_style(
                                'sii-boleta-admin-shared',
                                SII_BOLETA_DTE_URL . $style_relative,
                                array(),
                                $style_version
                        );
                }

if ( in_array( $hook, array( 'toplevel_page_sii-boleta-dte', 'sii-boleta-dte_page_sii-boleta-dte' ), true ) ) {
		\wp_enqueue_style(
			'sii-boleta-control-panel',
			SII_BOLETA_DTE_URL . 'src/Presentation/assets/css/control-panel.css',
			array(),
			SII_BOLETA_DTE_VERSION
		);
		$script_relative = 'src/Presentation/assets/js/control-panel.js';
		$script_path     = SII_BOLETA_DTE_PATH . $script_relative;
		$script_version  = SII_BOLETA_DTE_VERSION;
		if ( file_exists( $script_path ) ) {
			$script_version .= '-' . filemtime( $script_path );
		}
		\wp_enqueue_script(
			'sii-boleta-control-panel',
			SII_BOLETA_DTE_URL . $script_relative,
			array(),
			$script_version,
			true
		);
		\wp_localize_script(
			'sii-boleta-control-panel',
			'siiBoletaControlPanel',
			array(
				'ajax'            => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : ( ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'wp-admin/admin-ajax.php' ),
				'action'          => 'sii_boleta_dte_control_panel_data',
				'nonce'           => function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'sii_boleta_control_panel' ) : '',
				'refreshInterval' => 30,
				'texts'           => array(
					'noLogs'  => __( 'Sin DTE recientes.', 'sii-boleta-dte' ),
					'noQueue' => __( 'No hay elementos en la cola.', 'sii-boleta-dte' ),
				),
			)
		);
}
        if ( 'sii-boleta-dte_page_sii-boleta-dte-generate' === $hook || false !== strpos( $hook, 'sii-boleta-dte-generate' ) ) {
                        $script_relative = 'src/Presentation/assets/js/generate-dte.js';
                        $style_relative  = 'src/Presentation/assets/css/generate-dte.css';
                        $script_path     = SII_BOLETA_DTE_PATH . $script_relative;
                        $style_path      = SII_BOLETA_DTE_PATH . $style_relative;
                        $script_version  = SII_BOLETA_DTE_VERSION;
                        if ( file_exists( $script_path ) ) {
                                $script_version .= '-' . filemtime( $script_path );
                        }
                        $style_version = SII_BOLETA_DTE_VERSION;
                        if ( file_exists( $style_path ) ) {
                                $style_version .= '-' . filemtime( $style_path );
                        }
                        \wp_enqueue_style(
                                'sii-boleta-generate-dte',
                                SII_BOLETA_DTE_URL . $style_relative,
                                array( 'sii-boleta-admin-shared' ),
                                $style_version
                        );
                                                                                                                               \wp_enqueue_script(
                                                                                                                               'sii-boleta-generate-dte',
                                                                                                                               SII_BOLETA_DTE_URL . $script_relative,
                                                                                                                               array(),
                                                                                                                               $script_version,
																	true
																);
                    \wp_localize_script(
                        'sii-boleta-generate-dte',
                        'siiBoletaGenerate',
                        array(
                            'nonce' => \wp_create_nonce( 'sii_boleta_nonce' ),
                            'ajax'  => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : ( ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'wp-admin/admin-ajax.php' ),
                            'previewAction' => 'sii_boleta_dte_generate_preview',
                            'sendAction'    => 'sii_boleta_dte_send_document',
                            'texts' => array(
                                'previewReady' => __( 'Preview generated. Review the document below.', 'sii-boleta-dte' ),
                                'previewError' => __( 'Could not generate preview. Please try again.', 'sii-boleta-dte' ),
                                'openNewTab'   => __( 'Open preview in a new tab', 'sii-boleta-dte' ),
                                'loading'      => __( 'Generating preview…', 'sii-boleta-dte' ),
                                'rutInvalid'   => __( 'El RUT ingresado no es válido.', 'sii-boleta-dte' ),
                                'rutRequired'  => __( 'El RUT del receptor es obligatorio para este tipo de documento.', 'sii-boleta-dte' ),
                                'sendError'    => __( 'Could not send the document. Please try again.', 'sii-boleta-dte' ),
                                'sendSuccess'  => __( 'Document sent to SII. Tracking ID: %s.', 'sii-boleta-dte' ),
                                'sending'      => __( 'Sending…', 'sii-boleta-dte' ),
                                'viewPdf'      => __( 'Download PDF', 'sii-boleta-dte' ),
                                'stepIncomplete' => __( 'Completa los campos obligatorios antes de continuar.', 'sii-boleta-dte' ),
                                'requiredBadge'  => __( 'Obligatorio', 'sii-boleta-dte' ),
                                'optionalBadge'  => __( 'Opcional', 'sii-boleta-dte' ),
                                'itemsDescLabel'   => __( 'Description', 'sii-boleta-dte' ),
                                'itemsQtyLabel'    => __( 'Quantity', 'sii-boleta-dte' ),
                                'itemsPriceLabel'  => __( 'Unit Price', 'sii-boleta-dte' ),
                                'itemsActionsLabel' => __( 'Actions', 'sii-boleta-dte' ),
                                'itemsRemoveLabel' => __( 'Remove item', 'sii-boleta-dte' ),
                                'referenceCodeLabel' => __( 'Reference code', 'sii-boleta-dte' ),
                            ),
                        )
                    );
        }

        if ( false !== strpos( $hook, 'sii-boleta-dte-settings' ) ) {
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
                array(),
                SII_BOLETA_DTE_VERSION,
                true
            );
            \wp_localize_script(
                'sii-boleta-admin-settings',
                'siiBoletaSettings',
                array(
                    'optionKey' => Settings::OPTION_NAME,
                    'texts'     => array(
                        'selectLogo' => \__( 'Select logo', 'sii-boleta-dte' ),
                        'useLogo'    => \__( 'Use logo', 'sii-boleta-dte' ),
                        'sending'    => \__( 'Sending…', 'sii-boleta-dte' ),
                        'sendFail'   => \__( 'Failed to send', 'sii-boleta-dte' ),
                    ),
                    'nonce' => \wp_create_nonce( 'sii_boleta_nonce' ),
                )
            );
        }

        if ( false !== strpos( $hook, 'sii-boleta-dte-cafs' ) ) {
            $script_relative = 'src/Presentation/assets/js/caf-manager.js';
            $style_relative  = 'src/Presentation/assets/css/caf-manager.css';
            $script_path     = SII_BOLETA_DTE_PATH . $script_relative;
            $style_path      = SII_BOLETA_DTE_PATH . $style_relative;
            $base_version    = SII_BOLETA_DTE_VERSION;
            if ( file_exists( $script_path ) ) {
                $base_version .= '-' . filemtime( $script_path );
            }
            \wp_enqueue_style(
                'sii-boleta-caf-manager',
                SII_BOLETA_DTE_URL . $style_relative,
                array(),
                file_exists( $style_path ) ? $base_version : SII_BOLETA_DTE_VERSION
            );
            \wp_enqueue_script(
                'sii-boleta-caf-manager',
                SII_BOLETA_DTE_URL . $script_relative,
                array(),
                $base_version,
                true
            );
            \wp_localize_script(
                'sii-boleta-caf-manager',
                'siiBoletaCaf',
                array(
                    'ajax'  => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : ( ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'wp-admin/admin-ajax.php' ),
                    'nonce' => \wp_create_nonce( 'sii_boleta_caf' ),
                    'texts' => array(
                        'addTitle'      => \__( 'Agregar rango de folios', 'sii-boleta-dte' ),
                        'editTitle'     => \__( 'Editar rango de folios', 'sii-boleta-dte' ),
                        'deleteConfirm' => \__( '¿Eliminar este rango de folios?', 'sii-boleta-dte' ),
                        'genericError'  => \__( 'Ha ocurrido un error. Inténtalo nuevamente.', 'sii-boleta-dte' ),
                        'saving'        => \__( 'Guardando…', 'sii-boleta-dte' ),
                        'noCaf'         => \__( 'Aún no se ha cargado un CAF.', 'sii-boleta-dte' ),
                        'currentCaf'    => \__( 'CAF actual: %s', 'sii-boleta-dte' ),
                        'uploadedOn'    => \__( '(última carga: %s)', 'sii-boleta-dte' ),
                    ),
                )
            );
        }
        }
}

class_alias( Pages::class, 'Sii\\BoletaDte\\Admin\\Pages' );
