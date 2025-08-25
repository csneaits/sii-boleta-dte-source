<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generador de representación PDF/HTML de la boleta. En esta versión
 * simplificada, el método `generate_pdf_representation` construye un
 * archivo HTML que contiene los datos del DTE, el logo y el timbre
 * electrónico. En un proyecto real, se recomendaría utilizar una
 * biblioteca como FPDF, TCPDF o DOMPDF para generar un PDF verdadero,
 * así como una librería de códigos de barras para el PDF417.
 */
class SII_Boleta_PDF {

    /**
     * Genera una representación visual del DTE en un archivo HTML.
     * Devuelve la ruta del archivo generado o false si falla.
     *
     * @param string $signed_xml El XML firmado del DTE.
     * @param array  $settings   Configuración del plugin (emisor, logo, etc.).
     * @return string|false
     */
    public function generate_pdf_representation( $signed_xml, array $settings ) {
        if ( ! $signed_xml ) {
            return false;
        }
        try {
            $xml = new SimpleXMLElement( $signed_xml );
            // Extraer datos básicos
            $documento  = $xml->Documento;
            $folio      = (string) $documento->Encabezado->IdDoc->Folio;
            $tipo_dte   = (string) $documento->Encabezado->IdDoc->TipoDTE;
            $fecha      = (string) $documento->Encabezado->IdDoc->FchEmis;
            $emisor     = $settings['razon_social'];
            $rut_emisor = $settings['rut_emisor'];
            $receptor   = (string) $documento->Encabezado->Receptor->RznSocRecep;
            $rut_rece   = (string) $documento->Encabezado->Receptor->RUTRecep;

            $totals = $documento->Encabezado->Totales;
            $total  = (string) $totals->MntTotal;
            $neto   = isset( $totals->MntNeto ) ? (string) $totals->MntNeto : '';
            $iva    = isset( $totals->IVA ) ? (string) $totals->IVA : '';
            $exento = isset( $totals->MntExe ) ? (string) $totals->MntExe : '';
            // Nodo TED
            $ted_node  = $documento->TED;
            $ted_xml   = $ted_node ? $ted_node->asXML() : '';

            $upload_dir = wp_upload_dir();
            // Prefijo de nombre de archivo por tipo
            $timestamp  = time();
            $file_base  = 'DTE_' . $tipo_dte . '_' . $folio . '_' . $timestamp;

            // Intentar generar PDF si existe FPDF y la clase PDF417
            $pdf_path = false;
            if ( class_exists( 'FPDF', false ) && class_exists( 'PDF417', false ) ) {
                $pdf_path = $this->generate_pdf( $documento, $settings, $ted_node, $file_base );
            }

            // Si se generó PDF, retornar la ruta
            if ( $pdf_path ) {
                return $pdf_path;
            }

            // Fallback a HTML si no se puede generar el PDF
            $file_name  = $file_base . '.html';
            $file_path  = trailingslashit( $upload_dir['basedir'] ) . $file_name;
            // Construir HTML simple
            ob_start();
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="utf-8">
                <title><?php echo esc_html( 'DTE ' . $folio ); ?></title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .logo { max-height: 80px; }
                    .details, .items, .totals { margin-bottom: 15px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #ccc; padding: 5px; }
                    .totals td { font-weight: bold; }
                    .ted { font-size: 8px; word-wrap: break-word; }
                    .barcode { margin-top: 10px; }
                </style>
            </head>
            <body>
                <div class="header">
                    <?php if ( ! empty( $settings['logo_id'] ) ) : ?>
                        <?php echo wp_get_attachment_image( $settings['logo_id'], 'medium', false, [ 'class' => 'logo' ] ); ?>
                    <?php endif; ?>
                    <h2><?php echo esc_html( $emisor ); ?></h2>
                    <p><?php esc_html_e( 'RUT:', 'sii-boleta-dte' ); ?> <?php echo esc_html( $rut_emisor ); ?></p>
                    <p><?php echo esc_html( $settings['direccion'] ); ?>, <?php echo esc_html( $settings['comuna'] ); ?></p>
                    <h3><?php echo esc_html( 'DTE ' . $tipo_dte . ' Folio ' . $folio ); ?></h3>
                </div>
                <div class="details">
                    <h4><?php esc_html_e( 'Receptor', 'sii-boleta-dte' ); ?></h4>
                    <p><?php esc_html_e( 'Nombre:', 'sii-boleta-dte' ); ?> <?php echo esc_html( $receptor ); ?><br/><?php esc_html_e( 'RUT:', 'sii-boleta-dte' ); ?> <?php echo esc_html( $rut_rece ); ?></p>
                    <p><?php esc_html_e( 'Fecha:', 'sii-boleta-dte' ); ?> <?php echo esc_html( $fecha ); ?></p>
                </div>
                <div class="items">
                    <h4><?php esc_html_e( 'Ítems', 'sii-boleta-dte' ); ?></h4>
                    <table>
                        <thead><tr><th>#</th><th><?php esc_html_e( 'Descripción', 'sii-boleta-dte' ); ?></th><th><?php esc_html_e( 'Cantidad', 'sii-boleta-dte' ); ?></th><th><?php esc_html_e( 'Precio', 'sii-boleta-dte' ); ?></th><th><?php esc_html_e( 'Subtotal', 'sii-boleta-dte' ); ?></th></tr></thead>
                        <tbody>
                        <?php $i = 1; foreach ( $documento->Detalle as $det ) : ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo esc_html( $det->NmbItem ); ?></td>
                                <td style="text-align: right;"><?php echo esc_html( $det->QtyItem ); ?></td>
                                <td style="text-align: right;"><?php echo number_format( (float) $det->PrcItem, 0, ',', '.' ); ?></td>
                                <td style="text-align: right;"><?php echo number_format( (float) $det->MontoItem, 0, ',', '.' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="totals">
                    <table>
                        <?php if ( '' !== $neto && '' !== $iva ) : ?>
                            <tr><td><?php esc_html_e( 'Neto', 'sii-boleta-dte' ); ?></td><td style="text-align: right;"><?php echo number_format( (float) $neto, 0, ',', '.' ); ?></td></tr>
                            <tr><td><?php esc_html_e( 'IVA', 'sii-boleta-dte' ); ?></td><td style="text-align: right;"><?php echo number_format( (float) $iva, 0, ',', '.' ); ?></td></tr>
                        <?php elseif ( '' !== $exento ) : ?>
                            <tr><td><?php esc_html_e( 'Exento', 'sii-boleta-dte' ); ?></td><td style="text-align: right;"><?php echo number_format( (float) $exento, 0, ',', '.' ); ?></td></tr>
                        <?php endif; ?>
                        <tr><td><?php esc_html_e( 'Total', 'sii-boleta-dte' ); ?></td><td style="text-align: right;"><?php echo number_format( (float) $total, 0, ',', '.' ); ?></td></tr>
                    </table>
                </div>
                <div class="ted">
                    <h4><?php esc_html_e( 'Timbre Electrónico (TED)', 'sii-boleta-dte' ); ?></h4>
                    <pre><?php echo esc_html( $ted_xml ); ?></pre>
                    <div class="barcode">
                        <?php
                        $barcode_url = '';
                        if ( $ted_node ) {
                            $dd_xml = '';
                            if ( isset( $ted_node->DD ) ) {
                                $dd_xml = $ted_node->DD->asXML();
                            } else {
                                $dd_xml = $ted_node->asXML();
                            }
                            $barcode_data = base64_encode( $dd_xml );
                            $barcode_url  = 'https://barcode.tec-it.com/barcode.ashx?data=' . rawurlencode( $barcode_data ) . '&code=PDF417&multiple=4&quiet=1';
                        }
                        if ( $barcode_url ) :
                        ?>
                            <img src="<?php echo esc_url( $barcode_url ); ?>" alt="PDF417" />
                        <?php else : ?>
                            <p><?php esc_html_e( 'No se pudo generar el código PDF417.', 'sii-boleta-dte' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </body>
            </html>
            <?php
            $html = ob_get_clean();
            file_put_contents( $file_path, $html );
            return $file_path;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Genera un archivo PDF de representación del DTE usando FPDF y PDF417. Este método
     * se ejecuta únicamente si las clases FPDF y PDF417 están cargadas. Dibuja un
     * código PDF417 en la parte inferior con el contenido del TED.
     *
     * @param SimpleXMLElement $documento Nodo Documento del DTE firmado.
     * @param array            $settings  Configuración del plugin (emisor, logo, etc.).
     * @param SimpleXMLElement $ted_node  Nodo TED utilizado para el código de barras.
     * @param string           $file_base Nombre base para el archivo (sin extensión).
     * @return string|false Ruta del PDF generado o false en caso de falla.
     */
    private function generate_pdf( $documento, array $settings, $ted_node, $file_base ) {
        try {
            $upload_dir = wp_upload_dir();
            $file_path  = trailingslashit( $upload_dir['basedir'] ) . $file_base . '.pdf';
            // Incluir librerías
            if ( ! class_exists( 'FPDF', false ) ) {
                return false;
            }
            if ( ! class_exists( 'PDF417', false ) ) {
                return false;
            }
            $pdf = new FPDF( 'P', 'mm', 'A4' );
            $pdf->AddPage();
            // Logo
            if ( ! empty( $settings['logo_id'] ) ) {
                $img = wp_get_attachment_image_src( $settings['logo_id'], 'medium' );
                if ( $img && ! empty( $img[0] ) ) {
                    $pdf->Image( $img[0], 10, 10, 40 );
                }
            }
            // Encabezado
            $pdf->SetFont( 'Arial', 'B', 12 );
            $pdf->SetXY( 10, 20 );
            $pdf->Cell( 0, 7, $settings['razon_social'], 0, 1, 'L' );
            $pdf->SetFont( 'Arial', '', 10 );
            $pdf->Cell( 0, 5, 'RUT: ' . $settings['rut_emisor'], 0, 1, 'L' );
            $pdf->Cell( 0, 5, $settings['direccion'] . ', ' . $settings['comuna'], 0, 1, 'L' );
            $pdf->SetFont( 'Arial', 'B', 12 );
            $pdf->Cell( 0, 7, 'DTE ' . $documento->Encabezado->IdDoc->TipoDTE . ' Folio ' . $documento->Encabezado->IdDoc->Folio, 0, 1, 'L' );
            $pdf->Ln( 4 );
            // Receptor
            $pdf->SetFont( 'Arial', 'B', 11 );
            $pdf->Cell( 0, 6, 'Receptor', 0, 1, 'L' );
            $pdf->SetFont( 'Arial', '', 10 );
            $pdf->Cell( 0, 5, 'Nombre: ' . $documento->Encabezado->Receptor->RznSocRecep, 0, 1, 'L' );
            $pdf->Cell( 0, 5, 'RUT: ' . $documento->Encabezado->Receptor->RUTRecep, 0, 1, 'L' );
            if ( isset( $documento->Encabezado->Receptor->DirRecep ) ) {
                $pdf->Cell( 0, 5, 'Dirección: ' . $documento->Encabezado->Receptor->DirRecep . ', ' . $documento->Encabezado->Receptor->CmnaRecep, 0, 1, 'L' );
            }
            $pdf->Cell( 0, 5, 'Fecha: ' . $documento->Encabezado->IdDoc->FchEmis, 0, 1, 'L' );
            $pdf->Ln( 2 );
            // Tabla de ítems
            $pdf->SetFont( 'Arial', 'B', 10 );
            $pdf->SetFillColor( 230, 230, 230 );
            $pdf->Cell( 10, 6, '#', 1, 0, 'C', true );
            $pdf->Cell( 90, 6, 'Descripción', 1, 0, 'L', true );
            $pdf->Cell( 20, 6, 'Cant.', 1, 0, 'R', true );
            $pdf->Cell( 30, 6, 'Precio', 1, 0, 'R', true );
            $pdf->Cell( 30, 6, 'Subtotal', 1, 1, 'R', true );
            $pdf->SetFont( 'Arial', '', 10 );
            foreach ( $documento->Detalle as $idx => $det ) {
                $pdf->Cell( 10, 5, strval( $idx + 1 ), 1, 0, 'C' );
                $pdf->Cell( 90, 5, strval( $det->NmbItem ), 1, 0, 'L' );
                $pdf->Cell( 20, 5, number_format( (float) $det->QtyItem, 0, ',', '.' ), 1, 0, 'R' );
                $pdf->Cell( 30, 5, number_format( (float) $det->PrcItem, 0, ',', '.' ), 1, 0, 'R' );
                $pdf->Cell( 30, 5, number_format( (float) $det->MontoItem, 0, ',', '.' ), 1, 1, 'R' );
            }
            // Totales
            $totals = $documento->Encabezado->Totales;
            $neto   = isset( $totals->MntNeto ) ? (float) $totals->MntNeto : null;
            $iva    = isset( $totals->IVA ) ? (float) $totals->IVA : null;
            $exento = isset( $totals->MntExe ) ? (float) $totals->MntExe : null;
            $total  = (float) $totals->MntTotal;
            $pdf->SetFont( 'Arial', 'B', 10 );
            if ( null !== $neto && null !== $iva ) {
                $pdf->Cell( 150, 6, 'Neto', 1, 0, 'R' );
                $pdf->Cell( 30, 6, number_format( $neto, 0, ',', '.' ), 1, 1, 'R' );
                $pdf->Cell( 150, 6, 'IVA', 1, 0, 'R' );
                $pdf->Cell( 30, 6, number_format( $iva, 0, ',', '.' ), 1, 1, 'R' );
            } elseif ( null !== $exento ) {
                $pdf->Cell( 150, 6, 'Exento', 1, 0, 'R' );
                $pdf->Cell( 30, 6, number_format( $exento, 0, ',', '.' ), 1, 1, 'R' );
            }
            $pdf->Cell( 150, 6, 'Total', 1, 0, 'R' );
            $pdf->Cell( 30, 6, number_format( $total, 0, ',', '.' ), 1, 1, 'R' );

            // TED texto
            $pdf->Ln( 4 );
            $pdf->SetFont( 'Arial', 'B', 8 );
            $pdf->Cell( 0, 4, 'Timbre Electrónico (TED)', 0, 1, 'L' );
            $pdf->SetFont( 'Arial', '', 6 );
            $ted_text = $ted_node ? $ted_node->asXML() : '';
            // Dividir el TED en líneas para que no exceda ancho de página
            $ted_lines = explode( '\n', wordwrap( $ted_text, 100, '\n', true ) );
            foreach ( $ted_lines as $line ) {
                $pdf->Cell( 0, 3, $line, 0, 1, 'L' );
            }

            // Generar código de barras PDF417
            if ( $ted_node ) {
                $dd_xml = '';
                if ( isset( $ted_node->DD ) ) {
                    $dd_xml = $ted_node->DD->asXML();
                } else {
                    $dd_xml = $ted_node->asXML();
                }
                $pdf417 = new PDF417();
                $barcode = $pdf417->encode( $dd_xml );
                if ( isset( $barcode['bcode'] ) && isset( $barcode['cols'] ) && isset( $barcode['rows'] ) ) {
                    $module_w  = 0.5; // ancho de módulo en mm
                    $module_h  = 0.75; // alto de módulo en mm
                    // Posicionar el código de barras al final de la página (20 mm del margen inferior)
                    $x = 10;
                    $y = $pdf->GetPageHeight() - 20 - ( $barcode['rows'] * $module_h );
                    $pdf->SetFillColor( 0, 0, 0 );
                    foreach ( $barcode['bcode'] as $row => $rowbits ) {
                        $col = 0;
                        // Cada char es un 0 o 1 indicando espacio/barra
                        for ( $i = 0; $i < strlen( $rowbits ); $i++ ) {
                            if ( $rowbits[$i] === '1' ) {
                                $pdf->Rect( $x + $col * $module_w, $y + $row * $module_h, $module_w, $module_h, 'F' );
                            }
                            $col++;
                        }
                    }
                }
            }
            $pdf->Output( 'F', $file_path );
            return $file_path;
        } catch ( Exception $e ) {
            return false;
        }
    }
}