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
					__( 'Help', 'sii-boleta-dte' ),
					__( 'Help', 'sii-boleta-dte' ),
					'manage_options',
					'sii-boleta-dte-help',
					array( $this, 'render_page' )
				);
		}
	}

	public function render_page(): void {
			echo '<div class="wrap"><h1>' . esc_html__( 'Help', 'sii-boleta-dte' ) . '</h1>';
			echo '<p>' . esc_html__( 'For full documentation visit the project repository.', 'sii-boleta-dte' ) . '</p>';
			echo '<p><a href="https://github.com/fullLibreDte" target="_blank" rel="noopener">' . esc_html__( 'View documentation', 'sii-boleta-dte' ) . '</a></p>';
			echo '</div>';
	}
}

class_alias( Help::class, 'SII_Boleta_Help' );
class_alias( Help::class, 'Sii\\BoletaDte\\Admin\\Help' );
