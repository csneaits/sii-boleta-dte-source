<?php
/**
 * Implementación mínima de la clase FPDF necesaria para generar PDFs
 * básicos dentro de este plugin. No es una sustitución completa de la
 * librería original, pero provee los métodos esenciales usados por el
 * generador de boletas.
 *
 * Esta clase construye un documento PDF muy sencillo con soporte para:
 * - Páginas en tamaño A4.
 * - Escritura de texto con la fuente Helvetica.
 * - Dibujar rectángulos rellenos.
 * - Posicionamiento básico mediante coordenadas en milímetros.
 *
 * Para funcionalidades avanzadas (imágenes, fuentes personalizadas,
 * tablas complejas, etc.) se recomienda utilizar la librería oficial
 * FPDF disponible en http://www.fpdf.org/.
 */
class FPDF {
    /** @var array $pages Contenido crudo de cada página */
    private $pages = [];
    /** @var string $current Página actual en construcción */
    private $current = '';
    /** @var float $x Posición X actual en mm */
    private $x = 10;
    /** @var float $y Posición Y actual desde la parte superior en mm */
    private $y = 10;
    /** @var float $w Ancho de la página en mm */
    private $w = 210;
    /** @var float $h Alto de la página en mm */
    private $h = 297;
    /** @var float $k Factor de conversión de mm a puntos */
    private $k;
    /** @var int $font_size Tamaño de la fuente actual */
    private $font_size = 12;

    public function __construct( $orientation = 'P', $unit = 'mm', $size = 'A4' ) {
        $this->k = 72 / 25.4; // mm a puntos
        if ( 'A4' === $size ) {
            $this->w = 210;
            $this->h = 297;
        }
    }

    /**
     * Añade una nueva página al documento.
     */
    public function AddPage() {
        $this->current = '';
        $this->pages[] = &$this->current;
        $this->x = 10;
        $this->y = 10;
    }

    /**
     * Define la fuente actual. Solo se soporta Helvetica.
     */
    public function SetFont( $family, $style = '', $size = 12 ) {
        $this->font_size = (int) $size;
    }

    /**
     * Sitúa el cursor en coordenadas específicas.
     */
    public function SetXY( $x, $y ) {
        $this->x = $x;
        $this->y = $y;
    }

    /**
     * Salto de línea vertical.
     */
    public function Ln( $h = 0 ) {
        $this->x = 10;
        $this->y += $h;
    }

    /**
     * Color de relleno para futuros rectángulos.
     */
    public function SetFillColor( $r, $g = null, $b = null ) {
        $r = $r / 255;
        $g = $g / 255;
        $b = $b / 255;
        $this->current .= sprintf( "%.3f %.3f %.3f rg\n", $r, $g, $b );
    }

    /**
     * Dibuja un rectángulo.
     */
    public function Rect( $x, $y, $w, $h, $style = '' ) {
        $x *= $this->k;
        $y = ( $this->h - $y - $h ) * $this->k;
        $w *= $this->k;
        $h *= $this->k;
        $op = ( 'F' === $style ) ? 'f' : 'S';
        $this->current .= sprintf( "%.2f %.2f %.2f %.2f re %s\n", $x, $y, $w, $h, $op );
    }

    /**
     * Escribe una celda de texto.
     */
    public function Cell( $w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false ) {
        $x = $this->x * $this->k;
        $y = ( $this->h - $this->y ) * $this->k;
        if ( $fill ) {
            $this->Rect( $this->x, $this->y - $h + 1, $w, $h, 'F' );
        }
        $txt = $this->escape( $txt );
        $this->current .= sprintf( "BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $this->font_size, $x, $y, $txt );
        if ( $ln > 0 ) {
            $this->x = 10;
            $this->y += $h;
        } else {
            $this->x += $w;
        }
    }

    /**
     * Devuelve la altura de la página en milímetros.
     */
    public function GetPageHeight() {
        return $this->h;
    }

    /**
     * Método de salida del PDF a fichero.
     */
    public function Output( $dest, $file ) {
        $buffer = "%PDF-1.3\n";
        $objects = [];
        $offsets = [];
        $n = 1;
        // Fuente
        $objects[$n] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $fontObj = $n;
        $n++;
        $kids = [];
        foreach ( $this->pages as $content ) {
            $len = strlen( $content );
            $objects[$n] = "<< /Length $len >>\nstream\n$content" . "endstream";
            $contentObj = $n;
            $n++;
            $objects[$n] = "<< /Type /Page /Parent 1 0 R /MediaBox [0 0 " . ( $this->w * $this->k ) . " " . ( $this->h * $this->k ) . "] /Resources << /Font << /F1 $fontObj 0 R >> >> /Contents $contentObj 0 R >>";
            $kids[] = "$n 0 R";
            $n++;
        }
        // Páginas
        $objects[1] = "<< /Type /Pages /Kids [" . implode( ' ', $kids ) . "] /Count " . count( $kids ) . " >>";
        // Catálogo
        $objects[$n] = "<< /Type /Catalog /Pages 1 0 R >>";
        $catalog = $n;
        foreach ( $objects as $num => $obj ) {
            $offsets[$num] = strlen( $buffer );
            $buffer .= "$num 0 obj\n$obj\nendobj\n";
        }
        $xref = strlen( $buffer );
        $buffer .= "xref\n0 " . ( $catalog + 1 ) . "\n0000000000 65535 f \n";
        for ( $i = 1; $i <= $catalog; $i++ ) {
            $off = isset( $offsets[$i] ) ? $offsets[$i] : 0;
            $buffer .= sprintf( "%010d 00000 n \n", $off );
        }
        $buffer .= "trailer << /Size " . ( $catalog + 1 ) . " /Root $catalog 0 R >>\nstartxref\n$xref\n%%EOF";
        if ( 'F' === $dest ) {
            file_put_contents( $file, $buffer );
        } else {
            echo $buffer;
        }
    }

    /**
     * Escapa caracteres especiales del texto.
     */
    private function escape( $txt ) {
        return str_replace( ['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $txt );
    }

    // Métodos no implementados en esta versión mínima.
    public function Image( $file, $x, $y, $w ) { /* no-op */ }
}
