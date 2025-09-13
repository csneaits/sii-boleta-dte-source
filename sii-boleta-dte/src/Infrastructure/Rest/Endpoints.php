<?php
namespace Sii\BoletaDte\Infrastructure\Rest;

use Exception;

/**
 * Public endpoints to view DTE information.
 */
class Endpoints {
    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'render_boleta' ] );
    }

    public function add_rewrite() {
        add_rewrite_rule( '^boleta/([0-9]+)/?$', 'index.php?sii_boleta_folio=$matches[1]', 'top' );
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'sii_boleta_folio';
        return $vars;
    }

    /**
     * Renders the DTE when hitting /boleta/{folio}.
     */
    public function render_boleta(): void {
        $folio = get_query_var( 'sii_boleta_folio' );
        if ( ! $folio ) {
            return;
        }
        $html = $this->get_boleta_html( (int) $folio );
        if ( false === $html ) {
            status_header( 404 );
            wp_die( 'Boleta no encontrada' );
        } elseif ( is_wp_error( $html ) ) {
            status_header( 500 );
            wp_die( 'Error al cargar el DTE.' );
        }
        status_header( 200 );
        nocache_headers();
        echo $html;
        exit;
    }

    /**
     * Builds HTML for a given folio searching in uploads directory.
     *
     * @return string|false
     */
    public function get_boleta_html( int $folio ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $xml_file   = '';
        $it         = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() && preg_match( '/^(?:Woo_)?DTE_\d+_' . $folio . '_\d+\.xml$/', $file->getFilename() ) ) {
                $xml_file = $file->getPathname();
                break;
            }
        }
        if ( ! $xml_file ) {
            return false;
        }
        $xml = @simplexml_load_file( $xml_file );
        if ( ! $xml ) {
            return new \WP_Error( 'sii_boleta_invalid_xml' );
        }
        $doc  = $xml->Documento;
        $enc  = $doc->Encabezado;
        $emis = $enc->Emisor;
        $rec  = $enc->Receptor;
        $tot  = $enc->Totales;
        ob_start();
        ?>
        <html><body>
        <h1>Consulta de Boleta</h1>
        <p><strong>Folio:</strong> <?php echo htmlspecialchars( (string) $folio, ENT_QUOTES ); ?></p>
        <p><strong>Emisor:</strong> <?php echo htmlspecialchars( (string) $emis->RznSoc, ENT_QUOTES ); ?> (<?php echo htmlspecialchars( (string) $emis->RUTEmisor, ENT_QUOTES ); ?>)</p>
        <p><strong>Receptor:</strong> <?php echo htmlspecialchars( (string) $rec->RznSocRecep, ENT_QUOTES ); ?> (<?php echo htmlspecialchars( (string) $rec->RUTRecep, ENT_QUOTES ); ?>)</p>
        <p><strong>Fecha:</strong> <?php echo htmlspecialchars( (string) $enc->IdDoc->FchEmis, ENT_QUOTES ); ?></p>
        <table><tbody>
        <?php foreach ( $doc->Detalle as $det ) : ?>
            <tr>
                <td><?php echo htmlspecialchars( (string) $det->NmbItem, ENT_QUOTES ); ?></td>
                <td><?php echo htmlspecialchars( (string) $det->QtyItem, ENT_QUOTES ); ?></td>
                <td><?php echo htmlspecialchars( (string) $det->PrcItem, ENT_QUOTES ); ?></td>
                <td><?php echo htmlspecialchars( (string) $det->MontoItem, ENT_QUOTES ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><strong>Total:</strong> <?php echo htmlspecialchars( (string) $tot->MntTotal, ENT_QUOTES ); ?></p>
        </body></html>
        <?php
        return ob_get_clean();
    }
}

class_alias( Endpoints::class, 'SII_Boleta_Endpoints' );
