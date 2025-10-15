<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Xml;

class ReceptorPlaceholderCleaner implements XmlPlaceholderCleaner {
        public function clean( string $xml, array $rawReceptor, bool $hasReferences ): string {
                $previous = libxml_use_internal_errors( true );
                $document = new \DOMDocument();

                if ( ! $document->loadXML( $xml ) ) {
                        libxml_clear_errors();
                        libxml_use_internal_errors( $previous );
                        return $xml;
                }

                $xpath = new \DOMXPath( $document );
                $xpath->registerNamespace( 'dte', 'http://www.sii.cl/SiiDte' );

                $providedKeys = array();
                foreach ( $rawReceptor as $key => $value ) {
                        if ( is_string( $value ) ) {
                                $value = trim( $value );
                        }
                        if ( '' === $value || null === $value ) {
                                continue;
                        }
                        $providedKeys[ $key ] = true;
                }

                $optionalFields = array(
                        'DirRecep',
                        'CmnaRecep',
                        'CiudadRecep',
                        'Contacto',
                        'CorreoRecep',
                        'DirPostal',
                        'CmnaPostal',
                        'CiudadPostal',
                        'CdgIntRecep',
                        'Telefono',
                        'TelRecep',
                );

                $receptorNodes = $xpath->query( '/dte:DTE/dte:Documento/dte:Encabezado/dte:Receptor' );
                if ( $receptorNodes instanceof \DOMNodeList && $receptorNodes->length > 0 ) {
                        $receptor = $receptorNodes->item( 0 );
                        foreach ( $optionalFields as $field ) {
                                if ( isset( $providedKeys[ $field ] ) ) {
                                        continue;
                                }
                                $fieldNodes = $xpath->query( 'dte:' . $field, $receptor );
                                if ( ! ( $fieldNodes instanceof \DOMNodeList ) ) {
                                        continue;
                                }
                                for ( $i = $fieldNodes->length - 1; $i >= 0; --$i ) {
                                        $node = $fieldNodes->item( $i );
                                        if ( $node instanceof \DOMNode && $node->parentNode === $receptor ) {
                                                $receptor->removeChild( $node );
                                        }
                                }
                        }
                }

                if ( ! $hasReferences ) {
                        $refNodes = $xpath->query( '/dte:DTE/dte:Documento/dte:Referencia' );
                        if ( $refNodes instanceof \DOMNodeList ) {
                                for ( $i = $refNodes->length - 1; $i >= 0; --$i ) {
                                        $node = $refNodes->item( $i );
                                        if ( $node instanceof \DOMNode && $node->parentNode instanceof \DOMNode ) {
                                                $node->parentNode->removeChild( $node );
                                        }
                                }
                        }
                }

                $result = $document->saveXML() ?: $xml;
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
                return $result;
        }
}
