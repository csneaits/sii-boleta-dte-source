<?php
/**
 * Implementación muy simple de la clase PDF417 utilizada por el plugin.
 * No genera un código de barras PDF417 real, pero produce una matriz de
 * bits compatible con el uso que hace la clase SII_Boleta_PDF para
 * dibujar rectángulos que representan el timbre electrónico.
 */
class PDF417 {
    /**
     * Codifica una cadena de texto en una matriz binaria.
     *
     * @param string $text Texto a codificar.
     * @param array  $options Opciones adicionales (no utilizadas).
     * @return array Estructura con claves 'bcode', 'cols' y 'rows'.
     */
    public function encode( $text, $options = [] ) {
        $binary = '';
        for ( $i = 0, $len = strlen( $text ); $i < $len; $i++ ) {
            $binary .= str_pad( decbin( ord( $text[ $i ] ) ), 8, '0', STR_PAD_LEFT );
        }
        $cols = isset( $options['cols'] ) ? max( 1, (int) $options['cols'] ) : 80;
        $rows = (int) ceil( strlen( $binary ) / $cols );
        $bcode = [];
        for ( $r = 0; $r < $rows; $r++ ) {
            $row = substr( $binary, $r * $cols, $cols );
            $row = str_pad( $row, $cols, '0', STR_PAD_RIGHT );
            $bcode[] = $row;
        }
        return [
            'bcode' => $bcode,
            'cols'  => $cols,
            'rows'  => $rows,
        ];
    }
}
