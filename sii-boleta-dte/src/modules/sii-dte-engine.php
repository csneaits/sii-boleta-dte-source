<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Interfaz para motores DTE intercambiables (Strategy).
 * Permite reemplazar la implementación nativa por otra (p.ej. LibreDTE)
 * mediante el filtro 'sii_boleta_dte_engine'.
 */
interface SII_DTE_Engine {
    /**
     * Genera el XML del DTE.
     *
     * @param array $data
     * @param int   $tipo_dte
     * @param bool  $preview
     * @return string|\WP_Error|false
     */
    public function generate_dte_xml( array $data, $tipo_dte, $preview = false );

    /**
     * Firma el XML del DTE con el certificado configurado.
     *
     * @param string $xml
     * @return string|false
     */
    public function sign_dte_xml( $xml );

    /**
     * Envía un archivo XML de DTE al SII y devuelve trackId.
     *
     * @param string $file_path
     * @param string $environment
     * @param string $token
     * @param string $cert_path
     * @param string $cert_pass
     * @return string|\WP_Error|false
     */
    public function send_dte_file( $file_path, $environment, $token, $cert_path, $cert_pass );

    /**
     * Genera representación PDF/HTML del DTE.
     *
     * @param string $xml_or_signed_xml
     * @param array  $settings
     * @return string|false Ruta del archivo generado.
     */
    public function render_pdf( $xml_or_signed_xml, array $settings );

    /** RVD: Construcción y envío */
    public function build_rvd_xml( $date = null );
    public function send_rvd( $xml_signed, $environment, $token );

    /** CDF: Generación y envío */
    public function generate_cdf_xml( $date );
    public function send_cdf( $xml_content, $environment, $token, $cert_path, $cert_pass );

    /** Libro de Boletas: Generación y envío */
    public function generate_libro( $fecha_inicio, $fecha_fin );
    public function send_libro( $xml, $environment, $token, $cert_path, $cert_pass );
}

