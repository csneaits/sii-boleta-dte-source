<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registro de endpoints públicos para consulta de DTE.
 *
 * Provee la URL /boleta/{folio} para permitir la consulta pública de un
 * documento previamente generado. Busca el XML firmado y muestra una vista
 * simple con los datos más relevantes. Si existe una representación PDF,
 * se ofrece un enlace de descarga.
 */
class SII_Boleta_Endpoints {

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'render_boleta' ] );
    }

    /**
     * Registra la regla de reescritura /boleta/{folio}.
     */
    public function add_rewrite() {
        add_rewrite_rule( '^boleta/([0-9]+)/?$', 'index.php?sii_boleta_folio=$matches[1]', 'top' );
    }

    /**
     * Expone la variable de consulta para capturar el folio.
     *
     * @param array $vars Variables existentes.
     * @return array
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'sii_boleta_folio';
        return $vars;
    }

    /**
     * Si la solicitud corresponde al endpoint, genera una salida HTML con
     * los datos del DTE o retorna 404 si no se encuentra.
     */
    public function render_boleta() {
        $folio = get_query_var( 'sii_boleta_folio' );
        if ( ! $folio ) {
            return;
        }

        $html = $this->get_boleta_html( $folio );
        if ( false === $html ) {
            status_header( 404 );
            wp_die( esc_html__( 'Boleta no encontrada', 'sii-boleta-dte' ) );
        } elseif ( is_wp_error( $html ) ) {
            status_header( 500 );
            wp_die( esc_html__( 'Error al cargar el DTE.', 'sii-boleta-dte' ) );
        }

        status_header( 200 );
        nocache_headers();
        echo $html;
        exit;
    }

    /**
     * Genera el HTML del DTE para un folio determinado.
     *
     * @param int $folio Folio a consultar.
     * @return string|false|\WP_Error HTML generado o false/\WP_Error si falla.
     */
    public function get_boleta_html( $folio ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        // Buscar recursivamente por nombre de archivo que termine con _<folio>_*.xml (DTE o Woo_DTE)
        $xml_file = '';
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() ) {
                $name = $file->getFilename();
                if ( preg_match( '/^(?:Woo_)?DTE_\d+_' . preg_quote( (string) $folio, '/' ) . '_\d+\.xml$/', $name ) ) {
                    $xml_file = $file->getPathname();
                    break;
                }
            }
        }
        if ( ! $xml_file ) {
            return false;
        }
        $xml      = @simplexml_load_file( $xml_file );
        if ( ! $xml ) {
            return new \WP_Error( 'sii_boleta_invalid_xml' );
        }

        $documento  = $xml->Documento;
        $encabezado = $documento->Encabezado;
        $emisor     = $encabezado->Emisor;
        $receptor   = $encabezado->Receptor;
        $totales    = $encabezado->Totales;

        // Buscar PDF recursivamente en misma base
        $pdf_url = '';
        $pdf_path = '';
        $it2 = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it2 as $file ) {
            if ( $file->isFile() ) {
                $name = $file->getFilename();
                if ( preg_match( '/^(?:Woo_)?DTE_\d+_' . preg_quote( (string) $folio, '/' ) . '_\d+\.pdf$/', $name ) ) {
                    $pdf_path = $file->getPathname();
                    break;
                }
            }
        }
        if ( $pdf_path ) {
            $pdf_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path );
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="utf-8" />
            <title><?php echo esc_html( 'Boleta ' . $folio ); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin:20px; }
                table { border-collapse: collapse; width:100%; margin-top:20px; }
                th, td { border:1px solid #ccc; padding:5px; text-align:left; }
            </style>
        </head>
        <body>
            <h1><?php esc_html_e( 'Consulta de Boleta', 'sii-boleta-dte' ); ?></h1>
            <p><strong><?php esc_html_e( 'Folio', 'sii-boleta-dte' ); ?>:</strong> <?php echo esc_html( $folio ); ?></p>
            <p><strong><?php esc_html_e( 'Emisor', 'sii-boleta-dte' ); ?>:</strong> <?php echo esc_html( $emisor->RznSoc ); ?> (<?php echo esc_html( $emisor->RUTEmisor ); ?>)</p>
            <p><strong><?php esc_html_e( 'Receptor', 'sii-boleta-dte' ); ?>:</strong> <?php echo esc_html( $receptor->RznSocRecep ); ?> (<?php echo esc_html( $receptor->RUTRecep ); ?>)</p>
            <p><strong><?php esc_html_e( 'Fecha', 'sii-boleta-dte' ); ?>:</strong> <?php echo esc_html( $encabezado->IdDoc->FchEmis ); ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Descripción', 'sii-boleta-dte' ); ?></th>
                        <th><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></th>
                        <th><?php esc_html_e( 'Precio', 'sii-boleta-dte' ); ?></th>
                        <th><?php esc_html_e( 'Monto', 'sii-boleta-dte' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $documento->Detalle as $det ) : ?>
                    <tr>
                        <td><?php echo esc_html( $det->NmbItem ); ?></td>
                        <td style="text-align:right;">&nbsp;<?php echo esc_html( $det->QtyItem ); ?></td>
                        <td style="text-align:right;">&nbsp;<?php echo esc_html( $det->PrcItem ); ?></td>
                        <td style="text-align:right;">&nbsp;<?php echo esc_html( $det->MontoItem ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><strong><?php esc_html_e( 'Total', 'sii-boleta-dte' ); ?>:</strong> <?php echo esc_html( $totales->MntTotal ); ?></p>
            <?php if ( $pdf_url ) : ?>
                <p><a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Descargar PDF', 'sii-boleta-dte' ); ?></a></p>
            <?php endif; ?>
            <details>
                <summary><?php esc_html_e( 'Ver XML', 'sii-boleta-dte' ); ?></summary>
                <pre><?php echo esc_html( file_get_contents( $xml_file ) ); ?></pre>
            </details>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

