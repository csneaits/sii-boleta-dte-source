<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Página pública para consulta de boletas.
 * Registra una regla de reescritura /boleta/{folio} y muestra la representación
 * guardada (PDF o HTML) del DTE si existe.
 */
class SII_Boleta_Public {

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render_boleta' ] );
    }

    public function add_rewrite() {
        add_rewrite_rule( '^boleta/([0-9]+)/?$', 'index.php?sii_boleta_folio=$matches[1]', 'top' );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'sii_boleta_folio';
        return $vars;
    }

    public function maybe_render_boleta() {
        $folio = get_query_var( 'sii_boleta_folio' );
        if ( ! $folio ) {
            return;
        }
        $upload_dir = wp_upload_dir();
        $pattern    = trailingslashit( $upload_dir['basedir'] ) . '*_' . $folio . '_*.{pdf,html}';
        $files      = glob( $pattern, GLOB_BRACE );
        if ( empty( $files ) ) {
            status_header( 404 );
            wp_die( esc_html__( 'Boleta no encontrada', 'sii-boleta-dte' ) );
        }
        $file = $files[0];
        $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        if ( 'pdf' === $ext ) {
            header( 'Content-Type: application/pdf' );
        } else {
            header( 'Content-Type: text/html; charset=utf-8' );
        }
        readfile( $file );
        exit;
    }
}
