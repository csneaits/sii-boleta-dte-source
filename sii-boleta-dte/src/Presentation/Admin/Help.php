<?php
namespace Sii\BoletaDte\Presentation\Admin;

/**
 * Help/about page with basic instructions.
 */
class Help {
        public function register(): void {
                if ( function_exists( 'add_submenu_page' ) ) {
                                add_submenu_page(
                                        'sii-boleta-dte',
                                        __( 'Ayuda', 'sii-boleta-dte' ),
                                        __( 'Ayuda', 'sii-boleta-dte' ),
                                        'manage_options',
                                        'sii-boleta-dte-help',
                                        array( $this, 'render_page' )
                                );
                }
        }

        public function render_page(): void {
                                AdminStyles::open_container( 'sii-help-page' );
                                echo '<h1>' . esc_html__( 'Ayuda', 'sii-boleta-dte' ) . '</h1>';
                                echo '<div class="sii-admin-card sii-admin-card--compact">';
                                echo '<h2>' . esc_html__( 'Integración con el SII', 'sii-boleta-dte' ) . '</h2>';
                                echo '<ol>';
                                echo '<li>' . esc_html__( 'Obtén tu certificado digital del SII e ingresa su ruta y contraseña en los ajustes.', 'sii-boleta-dte' ) . '</li>';
                                echo '<li>' . esc_html__( 'Completa los datos del emisor como RUT, razón social y giro.', 'sii-boleta-dte' ) . '</li>';
                                echo '<li>' . esc_html__( 'Carga los archivos CAF correspondientes mediante el mantenedor de folios.', 'sii-boleta-dte' ) . '</li>';
                                echo '<li>' . esc_html__( 'Selecciona el ambiente de pruebas o producción y habilita los tipos de DTE necesarios.', 'sii-boleta-dte' ) . '</li>';
                                echo '<li>' . esc_html__( 'Genera un DTE de prueba y envíalo al SII para verificar la configuración.', 'sii-boleta-dte' ) . '</li>';
                                echo '</ol>';
                                echo '<p>' . esc_html__( 'Para la documentación completa visita el repositorio del proyecto.', 'sii-boleta-dte' ) . '</p>';
                                echo '<p><a href="https://github.com/fullLibreDte" target="_blank" rel="noopener">' . esc_html__( 'Ver documentación', 'sii-boleta-dte' ) . '</a></p>';
                                echo '</div>';
                                AdminStyles::close_container();
        }
}

class_alias( Help::class, 'SII_Boleta_Help' );
class_alias( Help::class, 'Sii\\BoletaDte\\Admin\\Help' );
