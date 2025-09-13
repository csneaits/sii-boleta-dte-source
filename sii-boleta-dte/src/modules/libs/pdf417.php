<?php
// Autoloader for BigFish\PDF417 classes bundled with the plugin.
spl_autoload_register(function ( $class ) {
    $prefix   = 'BigFish\\PDF417\\';
    $base_dir = __DIR__ . '/bigfish-pdf417/src/';
    $len      = strlen( $prefix );
    if ( 0 !== strncmp( $prefix, $class, $len ) ) {
        return;
    }
    $relative_class = substr( $class, $len );
    $file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

use BigFish\PDF417\PDF417 as BigFishPDF417;
use BigFish\PDF417\Renderers\JsonRenderer;

/**
 * Wrapper de la librería BigFish\PDF417 para mantener la compatibilidad
 * con el uso anterior en el plugin. Expone una clase global `PDF417`
 * con un método `encode` que devuelve una matriz de bits utilizable
 * para dibujar el código de barras.
 */
class PDF417 {
    /** @var BigFishPDF417 */
    private $generator;

    public function __construct() {
        $this->generator = new BigFishPDF417();
    }

    /**
     * Codifica el texto proporcionado en un arreglo de bits.
     *
     * @param string $text    Texto a codificar.
     * @param array  $options Opciones de generación.
     * @return array{'bcode':array<int,string>,'cols':int,'rows':int}
     */
    public function encode( $text, $options = [] ) {
        if ( isset( $options['columns'] ) ) {
            $this->generator->setColumns( (int) $options['columns'] );
        }
        if ( isset( $options['security_level'] ) ) {
            $this->generator->setSecurityLevel( (int) $options['security_level'] );
        }
        $data     = $this->generator->encode( $text );
        $renderer = new JsonRenderer();
        $grid     = json_decode( $renderer->render( $data ), true );
        $rows     = is_array( $grid ) ? count( $grid ) : 0;
        $cols     = $rows ? count( $grid[0] ) : 0;
        $bcode    = [];
        if ( is_array( $grid ) ) {
            foreach ( $grid as $row ) {
                $bcode[] = implode( '', $row );
            }
        }
        return [
            'bcode' => $bcode,
            'cols'  => $cols,
            'rows'  => $rows,
        ];
    }
}
