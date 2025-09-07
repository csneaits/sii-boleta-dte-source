<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Implementación nativa del motor DTE que delega en las clases existentes
 * del plugin (XML_Generator, Signer, API, PDF, RVD/Libro/CDF managers).
 */
class SII_Native_Engine implements SII_DTE_Engine {
    /** @var SII_Boleta_Settings */
    private $settings;
    /** @var SII_Boleta_XML_Generator */
    private $xml_generator;
    /** @var SII_Boleta_Signer */
    private $signer;
    /** @var SII_Boleta_API */
    private $api;
    /** @var SII_Boleta_PDF */
    private $pdf;
    /** @var SII_Boleta_RVD_Manager */
    private $rvd_manager;
    /** @var SII_Boleta_Consumo_Folios */
    private $consumo_folios;
    /** @var SII_Libro_Boletas */
    private $libro_boletas;

    public function __construct(
        SII_Boleta_Settings $settings,
        SII_Boleta_XML_Generator $xml_generator,
        SII_Boleta_Signer $signer,
        SII_Boleta_API $api,
        SII_Boleta_PDF $pdf,
        SII_Boleta_RVD_Manager $rvd_manager,
        SII_Boleta_Consumo_Folios $consumo_folios,
        SII_Libro_Boletas $libro_boletas
    ) {
        $this->settings       = $settings;
        $this->xml_generator  = $xml_generator;
        $this->signer         = $signer;
        $this->api            = $api;
        $this->pdf            = $pdf;
        $this->rvd_manager    = $rvd_manager;
        $this->consumo_folios = $consumo_folios;
        $this->libro_boletas  = $libro_boletas;
    }

    public function generate_dte_xml( array $data, $tipo_dte, $preview = false ) {
        return $this->xml_generator->generate_dte_xml( $data, $tipo_dte, $preview );
    }

    public function sign_dte_xml( $xml ) {
        $opts = $this->settings->get_settings();
        return $this->signer->sign_dte_xml( $xml, $opts['cert_path'] ?? '', $opts['cert_pass'] ?? '' );
    }

    public function send_dte_file( $file_path, $environment, $token, $cert_path, $cert_pass ) {
        return $this->api->send_dte_to_sii( $file_path, $environment, $token, $cert_path, $cert_pass );
    }

    public function render_pdf( $xml_or_signed_xml, array $settings ) {
        return $this->pdf->generate_pdf_representation( $xml_or_signed_xml, $settings );
    }

    public function build_rvd_xml( $date = null ) {
        return $this->rvd_manager->generate_rvd_xml( $date );
    }

    public function send_rvd( $xml_signed, $environment, $token ) {
        return $this->api->send_rvd_to_sii( $xml_signed, $environment, $token );
    }

    public function generate_cdf_xml( $date ) {
        return $this->consumo_folios->generate_cdf_xml( $date );
    }

    public function send_cdf( $xml_content, $environment, $token, $cert_path, $cert_pass ) {
        return $this->consumo_folios->send_cdf_to_sii( $xml_content, $environment, $token, $cert_path, $cert_pass );
    }

    public function generate_libro( $fecha_inicio, $fecha_fin ) {
        return $this->libro_boletas->generate_libro_xml( $fecha_inicio, $fecha_fin );
    }

    public function send_libro( $xml, $environment, $token, $cert_path, $cert_pass ) {
        return $this->libro_boletas->send_libro_to_sii( $xml, $environment, $token, $cert_path, $cert_pass );
    }
}

