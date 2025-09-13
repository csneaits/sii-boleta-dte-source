<?php
namespace Sii\BoletaDte\Domain;

interface DteEngine {
    /**
     * Generates DTE XML from data.
     *
     * @param array<string,mixed> $data
     * @param int|string $tipo_dte
     * @param bool $preview
     * @return string|false
     */
    public function generate_dte_xml( array $data, $tipo_dte, bool $preview = false );

    /**
     * Renders a PDF from a DTE XML string.
     *
     * @param string $xml     DTE document XML.
     * @param array<string,mixed> $options Rendering options.
     * @return string|false Path to generated PDF or false on failure.
     */
    public function render_pdf( string $xml, array $options = [] );
}

class_alias( DteEngine::class, 'SII_DTE_Engine' );
