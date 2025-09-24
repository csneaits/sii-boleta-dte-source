<?php
namespace Sii\BoletaDte\Presentation\Admin;

/**
 * Shared admin UI helper that wraps plugin pages in the styled container.
 */
class AdminStyles {
    /**
     * Opens a styled container with the shared azure background classes.
     */
    public static function open_container( string $extra_classes = '' ): void {
        $classes = trim( 'wrap sii-admin-surface ' . $extra_classes );
        $escaped = self::escape_attr( $classes );
        echo '<div class="' . $escaped . '">';
    }

    /**
     * Closes the styled container.
     */
    public static function close_container(): void {
        echo '</div>';
    }

    private static function escape_attr( string $value ): string {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }

        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }
}
