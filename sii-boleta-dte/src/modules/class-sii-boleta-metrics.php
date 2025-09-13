<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reúne métricas básicas sobre los DTE generados por el plugin.
 */
class SII_Boleta_Metrics {

    /**
     * Obtiene métricas de documentos y errores a partir de los archivos
     * generados y el archivo de log del plugin.
     *
     * @return array {
     *     @type int   $total          Total de DTE encontrados.
     *     @type array $by_type        Conteo por tipo de DTE.
     *     @type int   $sent           Número de DTE enviados al SII.
     *     @type int   $errors         Total de líneas de error en el log.
     *     @type array $error_reasons  Conteo de errores agrupados por mensaje.
     * }
     */
    public function gather_metrics() {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );

        $metrics = [
            'total'         => 0,
            'by_type'       => [],
            'sent'          => 0,
            'errors'        => 0,
            'error_reasons' => [],
        ];

        // Buscar archivos DTE generados (recursivo en subcarpetas)
        $files = [];
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() ) {
                $name = $file->getFilename();
                if ( preg_match( '/^(?:Woo_)?DTE_\d+_\d+_\d+\.xml$/', $name ) ) {
                    $files[] = $file->getPathname();
                }
            }
        }
        if ( $files ) {
            foreach ( $files as $file ) {
                $metrics['total']++;
                if ( preg_match( '/DTE_(\d+)_\d+_\d+\.xml$/', basename( $file ), $m ) ) {
                    $type = $m[1];
                    if ( ! isset( $metrics['by_type'][ $type ] ) ) {
                        $metrics['by_type'][ $type ] = 0;
                    }
                    $metrics['by_type'][ $type ]++;
                }
            }
        }

        // Analizar el archivo de log para contadores de envío y errores.
        $log_file = $base_dir . 'sii-boleta-logs/sii-boleta.log';
        if ( file_exists( $log_file ) ) {
            $lines = file( $log_file );
            foreach ( $lines as $line ) {
                if ( stripos( $line, 'enviado al sii' ) !== false ) {
                    $metrics['sent']++;
                }
                if ( stripos( $line, 'error' ) !== false ) {
                    $metrics['errors']++;
                    if ( preg_match( '/error\s*:\s*(.+)$/i', $line, $m ) ) {
                        $reason = trim( $m[1] );
                        if ( ! isset( $metrics['error_reasons'][ $reason ] ) ) {
                            $metrics['error_reasons'][ $reason ] = 0;
                        }
                        $metrics['error_reasons'][ $reason ]++;
                    }
                }
            }
        }

        return $metrics;
    }
}
