<?php
namespace Sii\BoletaDte\Infrastructure\Admin;

/**
 * Minimal help page stub.
 */
class Help {
    public function register_page(): void {
        // no-op for tests
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>Ayuda Boleta SII</h1></div>';
    }
}

class_alias( Help::class, 'SII_Boleta_Help' );
class_alias( Help::class, 'Sii\\BoletaDte\\Admin\\Help' );
