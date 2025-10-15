<?php
// Script to generate sample DTE PDFs for different document types.
// Run from repository root: php scripts/generate_sample_pdfs.php

require __DIR__ . '/../vendor/autoload.php';

use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Settings;

// Minimal dummy settings to avoid WP dependency in CLI environment
class DummyCliSettings extends Settings {
    public function get_settings(): array {
        return [
            'rut_emisor' => '11111111-1',
            'razon_social' => 'ACME S.A.',
            'giro' => 'Comercio',
            'direccion' => 'Calle Falsa 123',
            'comuna' => 'Santiago',
        ];
    }

    public function get_environment(): string {
        return '0';
    }
}

$engine = new LibreDteEngine(new DummyCliSettings());

$targetDir = __DIR__ . '/../var/output_pdfs';
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

$today = date('Y-m-d');

$samples = [
    33 => 'Factura Afecta',
    39 => 'Boleta',
    52 => 'Guia de despacho',
    56 => 'Nota de debito',
    61 => 'Nota de credito',
];

foreach ($samples as $tipo => $label) {
    echo "Generating DTE type $tipo ($label)\n";

    $data = [
        'Folio' => 1,
        'FchEmis' => $today,
        'Encabezado' => [
            'Totales' => [ 'TasaIVA' => 19 ],
        ],
        'Receptor' => [
            'RUTRecep' => '22222222-2',
            'RznSocRecep' => 'Cliente de Prueba',
            'DirRecep' => 'Av. Demo 100',
            'CmnaRecep' => 'Providencia',
        ],
        'Detalles' => [
            [
                'NroLinDet' => 1,
                'NmbItem' => 'Servicio de prueba',
                'QtyItem' => 1,
                // Use a gross price example (1190) and mark MntBruto to test adjuster
                'PrcItem' => 1190,
                'MontoItem' => 1190,
                'MntBruto' => 1,
            ],
        ],
    ];

    try {
        $xml = $engine->generate_dte_xml($data, $tipo, true);
        if (!is_string($xml) || '' === trim($xml)) {
            echo "  Failed to generate XML for tipo $tipo\n";
            continue;
        }

        $pdfPath = $engine->render_pdf($xml);
        if (!is_string($pdfPath) || !file_exists($pdfPath)) {
            echo "  PDF render failed for tipo $tipo\n";
            continue;
        }

        $dest = $targetDir . "/dte_{$tipo}.pdf";
        if (@copy($pdfPath, $dest)) {
            echo "  Saved PDF to: $dest\n";
        } else {
            echo "  Could not copy PDF to output folder; temp file: $pdfPath\n";
        }

        // cleanup temp file
        @unlink($pdfPath);

    } catch (Throwable $e) {
        echo "  Exception generating DTE tipo $tipo: " . $e->getMessage() . "\n";
    }
}

echo "Done. Generated PDFs (if any) can be found in var/output_pdfs/\n";
