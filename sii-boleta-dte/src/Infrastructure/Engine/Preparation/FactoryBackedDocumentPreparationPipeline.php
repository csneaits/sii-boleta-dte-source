<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Preparation;

use Sii\BoletaDte\Infrastructure\Engine\Builder\DocumentPayloadBuilder;
use Sii\BoletaDte\Infrastructure\Engine\Factory\Components\SectionSanitizer;
use Sii\BoletaDte\Infrastructure\Engine\Factory\DteDocumentFactory;

class FactoryBackedDocumentPreparationPipeline implements DocumentPreparationPipelineInterface {
    private SectionSanitizer $sectionSanitizer;

    public function __construct(?SectionSanitizer $sectionSanitizer = null) {
        $this->sectionSanitizer = $sectionSanitizer ?? new SectionSanitizer();
    }

    public function prepare(
        DteDocumentFactory $factory,
        int $tipo,
        array $data,
        array $settings,
        bool $preview
    ): DocumentPreparationResult {
        $template = $factory->createTemplateLoader()->load($tipo);
        if (isset($template['Detalle'])) {
            $template['Detalle'] = array();
        }
        if (isset($template['Encabezado']['Totales'])) {
            $template['Encabezado']['Totales'] = array();
        }
        if (isset($template['DscRcgGlobal'])) {
            unset($template['DscRcgGlobal']);
        }

        $global_discount_data = array();
        $global_discount_items = array();
        if (isset($data['DscRcgGlobal']) && is_array($data['DscRcgGlobal'])) {
            list($global_discount_data, $global_discount_items) = $this->normalizeGlobalDiscountData(
                $data['DscRcgGlobal']
            );
        }

        $detalles = is_array($data['Detalles'] ?? null) ? $data['Detalles'] : array();
        $detalle = $factory->createDetailNormalizer()->normalize($detalles, $tipo);

        $emisorBuilder = $factory->createEmisorDataBuilder();
        $emisor = $emisorBuilder->build($data, $settings);

        $payloadBuilder = new DocumentPayloadBuilder($template);
        $payloadBuilder
            ->withDocumentIdentification($tipo, $data)
            ->withEmisor($emisor)
            ->withDetalle($detalle)
            ->withGlobalDiscount($global_discount_data);

        $rawReceptor = array();
        if (isset($data['Receptor']) && is_array($data['Receptor'])) {
            $rawReceptor = $data['Receptor'];
        } elseif (isset($data['Encabezado']['Receptor']) && is_array($data['Encabezado']['Receptor'])) {
            $rawReceptor = $data['Encabezado']['Receptor'];
        }

        $receptorSanitizer = $factory->createReceptorSanitizer();
        if (!empty($rawReceptor)) {
            $sanitizedReceptor = $receptorSanitizer->sanitize($rawReceptor);
        } elseif (isset($template['Encabezado']['Receptor']) && is_array($template['Encabezado']['Receptor'])) {
            $sanitizedReceptor = $receptorSanitizer->sanitize($template['Encabezado']['Receptor']);
        } else {
            $sanitizedReceptor = $receptorSanitizer->sanitize(array());
        }

        $payloadBuilder->withReceptor($sanitizedReceptor, $rawReceptor);

        $tasa_iva = null;
        if (isset($data['Encabezado']['Totales']['TasaIVA'])) {
            $tasa_iva = (float) $data['Encabezado']['Totales']['TasaIVA'];
        } elseif (isset($template['Encabezado']['Totales']['TasaIVA'])) {
            $tasa_iva = (float) $template['Encabezado']['Totales']['TasaIVA'];
        }
        if (null === $tasa_iva && in_array($tipo, array(33, 39, 43, 46), true)) {
            $tasa_iva = 19.0;
        }

        $payloadBuilder->withTotalsSkeleton($tasa_iva);

        if (isset($data['Referencias']) && is_array($data['Referencias'])) {
            $payloadBuilder->withReferences($data['Referencias']);
        } else {
            $payloadBuilder->withReferences(array());
        }

        $payload = $payloadBuilder->build();

        return new DocumentPreparationResult(
            $payload,
            $detalle,
            $global_discount_items,
            $tasa_iva,
            $emisor
        );
    }

    /**
     * @param array<string|int,mixed> $raw
     *
     * @return array{0:array<mixed>,1:array<int,array<string,mixed>>}
     */
    private function normalizeGlobalDiscountData( array $raw ): array {
        if ($this->isList($raw)) {
            $normalized = array();
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $clean = $this->sectionSanitizer->sanitize($entry);
                if (!empty($clean)) {
                    $normalized[] = $clean;
                }
            }

            return array($normalized, $normalized);
        }

        $clean = $this->sectionSanitizer->sanitize($raw);
        if (empty($clean)) {
            return array(array(), array());
        }

        return array($clean, array($clean));
    }

    private function isList( array $array ): bool {
        if (array() === $array) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
