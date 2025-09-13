<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Motor DTE nulo utilizado cuando LibreDTE no está disponible.
 * Todas las operaciones retornan WP_Error para evitar errores fatales.
 */
class SII_Null_Engine implements SII_DTE_Engine {
    /**
     * Retorna error genérico cuando se intenta utilizar el motor.
     *
     * @return \WP_Error
     */
    private function missing_error() {
        return new \WP_Error( 'sii_boleta_engine_missing', __( 'LibreDTE no está disponible. Instala la librería requerida.', 'sii-boleta-dte' ) );
    }

    public function generate_dte_xml( array $data, $tipo_dte, $preview = false ) {
        return $this->missing_error();
    }

    public function sign_dte_xml( $xml ) {
        return $this->missing_error();
    }

    public function send_dte_file( $file_path, $environment, $token, $cert_path, $cert_pass ) {
        return $this->missing_error();
    }

    public function render_pdf( $xml_or_signed_xml, array $settings ) {
        return $this->missing_error();
    }

    public function build_rvd_xml( $date = null ) {
        return $this->missing_error();
    }

    public function send_rvd( $xml_signed, $environment, $token ) {
        return $this->missing_error();
    }

    public function generate_cdf_xml( $date ) {
        return $this->missing_error();
    }

    public function send_cdf( $xml_content, $environment, $token, $cert_path, $cert_pass ) {
        return $this->missing_error();
    }

    public function generate_libro( $fecha_inicio, $fecha_fin ) {
        return $this->missing_error();
    }

    public function send_libro( $xml, $environment, $token, $cert_path, $cert_pass ) {
        return $this->missing_error();
    }
}
