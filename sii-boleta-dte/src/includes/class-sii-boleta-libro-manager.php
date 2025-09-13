<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase puente que permite a las tareas cron utilizar la clase
 * SII_Libro_Boletas con una interfaz basada en meses.
 */
class SII_Boleta_Libro_Manager {
    /**
     * @var SII_Boleta_Settings
     */
    private $settings;

    /**
     * @var SII_Libro_Boletas
     */
    private $libro;

    public function __construct( SII_Boleta_Settings $settings ) {
        $this->settings = $settings;
        $this->libro    = new SII_Libro_Boletas( $settings );
    }

    /**
     * Genera el XML del Libro para un mes dado en formato YYYY-MM.
     *
     * @param string $month Mes a procesar.
     * @return string|false XML generado o false en caso de error.
     */
    public function generate_libro_xml( $month ) {
        $start = $month . '-01';
        $end   = date( 'Y-m-t', strtotime( $start ) );
        return $this->libro->generate_libro_xml( $start, $end );
    }

    /**
     * Envía el XML del Libro al SII reutilizando la clase subyacente.
     *
     * @param string $xml         Contenido XML del libro.
     * @param string $environment Ambiente de envío (test|production).
     * @param string $token       Token de la API.
     * @param string $cert_path   Ruta al certificado.
     * @param string $cert_pass   Contraseña del certificado.
     * @return string|\WP_Error|false Track ID o false/\WP_Error si falla.
     */
    public function send_libro_to_sii( $xml, $environment = 'test', $token = '', $cert_path = '', $cert_pass = '' ) {
        return $this->libro->send_libro_to_sii( $xml, $environment, $token, $cert_path, $cert_pass );
    }
}
