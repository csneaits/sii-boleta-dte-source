<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Infrastructure\Engine;

/**
 * Servicio para auditar diferencias entre XML preview y final.
 */
class XmlAuditService {
    /** @var string[] */
    private array $defaultIgnoreXPaths = array(
        '//Documento//IdDoc/Folio',
        '//Documento//TED',
        '//Documento//IdDoc/TmstFirma',
        '//EnvioDTE/TmstFirmaEnv',
        '//*[local-name()="Signature" and namespace-uri()="http://www.w3.org/2000/09/xmldsig#"]',
    );

    /**
     * Normaliza un XML removiendo nodos volátiles y compactando espacios.
     */
    public function normalize(string $xml, string $phase = 'preview'): string {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOENT)) { // phpcs:ignore
            return ''; // XML inválido, no normalizamos.
        }
        $xpaths = \apply_filters('sii_boleta_xml_diff_ignore_paths', $this->defaultIgnoreXPaths, $phase);
        $xp = new \DOMXPath($dom);
        foreach ($xpaths as $expr) {
            if (!is_string($expr) || '' === trim($expr)) { continue; }
            foreach ($xp->query($expr) ?: array() as $node) {
                if ($node instanceof \DOMNode && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        // Ordenar atributos (estabilidad hash).
        $this->sortAttributes($dom);
        $xmlOut = $dom->saveXML() ?: '';
        // Colapsar espacios múltiples entre tags y textos.
        $xmlOut = preg_replace('/>\s+</', '><', $xmlOut) ?? $xmlOut;
        $xmlOut = preg_replace('/\s+/', ' ', $xmlOut) ?? $xmlOut;
        return trim($xmlOut);
    }

    /**
     * Calcula hash sha256 del XML normalizado.
     */
    public function hash(string $normalizedXml): string {
        if ($normalizedXml === '') { return ''; }
        return hash('sha256', $normalizedXml);
    }

    /**
     * Diff básico entre dos XML ya normalizados.
     * Devuelve una lista de cambios (attr-changed, text-changed, node-missing, node-extra).
     * Limitado a $limit elementos.
     * @return array<int,array<string,mixed>>
     */
    public function diff(string $normalizedA, string $normalizedB, int $limit = 200): array {
        if ($normalizedA === $normalizedB) { return array(); }
        $a = $this->xmlToArray($normalizedA);
        $b = $this->xmlToArray($normalizedB);
        $diffs = array();
        $this->compareNode($a, $b, '/', $diffs, $limit);
        return $diffs;
    }

    private function xmlToArray(string $xml): array {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOENT)) { // phpcs:ignore
            return array();
        }
        return $this->nodeToArray($dom->documentElement);
    }

    private function nodeToArray(?\DOMNode $node): array {
        if (!$node || $node->nodeType !== XML_ELEMENT_NODE) {
            return array();
        }
        $out = array(
            'name' => $node->nodeName,
            'attrs' => array(),
            'children' => array(),
        );
        if ($node->attributes) {
            foreach ($node->attributes as $attr) {
                $out['attrs'][$attr->nodeName] = (string)$attr->nodeValue;
            }
            ksort($out['attrs']);
        }
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = trim((string)$child->nodeValue);
                if ($text !== '') {
                    $out['children'][] = array('text' => $text);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $out['children'][] = $this->nodeToArray($child);
            }
        }
        return $out;
    }

    private function compareNode(array $a, array $b, string $path, array &$diffs, int $limit): void {
        if (count($diffs) >= $limit) { return; }
        if (($a['name'] ?? null) !== ($b['name'] ?? null)) {
            $diffs[] = array('type' => 'node-mismatch', 'path' => $path, 'from' => ($a['name'] ?? null), 'to' => ($b['name'] ?? null));
            return;
        }
        $name = $a['name'] ?? 'UNKNOWN';
        $path = rtrim($path, '/') . '/' . $name;
        // Atributos.
        $attrsA = $a['attrs'] ?? array();
        $attrsB = $b['attrs'] ?? array();
        foreach ($attrsA as $k => $v) {
            if (!array_key_exists($k, $attrsB)) {
                $diffs[] = array('type' => 'attr-missing-in-B', 'path' => $path, 'attr' => $k, 'from' => $v);
                if (count($diffs) >= $limit) { return; }
            } elseif ($attrsB[$k] !== $v) {
                $diffs[] = array('type' => 'attr-changed', 'path' => $path, 'attr' => $k, 'from' => $v, 'to' => $attrsB[$k]);
                if (count($diffs) >= $limit) { return; }
            }
        }
        foreach ($attrsB as $k => $v) {
            if (!array_key_exists($k, $attrsA)) {
                $diffs[] = array('type' => 'attr-extra-in-B', 'path' => $path, 'attr' => $k, 'to' => $v);
                if (count($diffs) >= $limit) { return; }
            }
        }
        // Hijos (orden importa en este diff básico).
        $childrenA = $a['children'] ?? array();
        $childrenB = $b['children'] ?? array();
        $len = max(count($childrenA), count($childrenB));
        for ($i = 0; $i < $len; $i++) {
            if (count($diffs) >= $limit) { return; }
            $ca = $childrenA[$i] ?? null;
            $cb = $childrenB[$i] ?? null;
            if ($ca === null) {
                $diffs[] = array('type' => 'node-extra-in-B', 'path' => $path, 'index' => $i, 'node' => $cb);
                continue;
            }
            if ($cb === null) {
                $diffs[] = array('type' => 'node-missing-in-B', 'path' => $path, 'index' => $i, 'node' => $ca);
                continue;
            }
            // Texto vs texto.
            if (isset($ca['text']) || isset($cb['text'])) {
                $ta = $ca['text'] ?? '';
                $tb = $cb['text'] ?? '';
                if ($ta !== $tb) {
                    $diffs[] = array('type' => 'text-changed', 'path' => $path, 'index' => $i, 'from' => $ta, 'to' => $tb);
                }
                continue;
            }
            // Elementos recursivos.
            $this->compareNode($ca, $cb, $path, $diffs, $limit);
        }
    }

    private function sortAttributes(\DOMDocument $dom): void {
        $xp = new \DOMXPath($dom);
        foreach ($xp->query('//*') as $node) {
            if ($node instanceof \DOMElement && $node->hasAttributes()) {
                $attrs = array();
                foreach ($node->attributes as $attr) {
                    $attrs[$attr->nodeName] = $attr->nodeValue;
                }
                ksort($attrs);
                while ($node->attributes->length) {
                    $node->removeAttributeNode($node->attributes->item(0));
                }
                foreach ($attrs as $k => $v) {
                    $node->setAttribute($k, (string)$v);
                }
            }
        }
    }
}
