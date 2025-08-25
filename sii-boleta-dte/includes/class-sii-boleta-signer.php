<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Clase encargada de firmar el XML de los DTE utilizando la librería
 * xmlseclibs incluida en este plugin. Aunque la implementación se ha
 * simplificado, se adecua a la firma básica requerida por los servicios del
 * SII. Para firmar correctamente según las especificaciones completas, se
 * recomienda ampliar este código con los detalles de firma para cada nodo.
 */
class SII_Boleta_Signer {

    /**
     * Firma un XML de DTE. Carga el certificado en formato PFX y aplica
     * digitalmente la firma al nodo Documento.
     *
     * @param string $xml       Contenido XML del DTE.
     * @param string $cert_path Ruta al archivo PFX.
     * @param string $cert_pass Contraseña del certificado.
     * @return string|false     XML firmado o false si ocurre un error.
     */
    public function sign_dte_xml( $xml, $cert_path, $cert_pass ) {
        if ( ! $xml || ! file_exists( $cert_path ) ) {
            return false;
        }
        // Cargar el documento
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        // Encontrar el nodo Documento para añadir la firma
        $documento = $doc->getElementsByTagName( 'Documento' )->item(0);
        if ( ! $documento ) {
            return false;
        }
        // Añadir ID al nodo Documento si no lo tiene (necesario para la referencia)
        if ( ! $documento->hasAttribute( 'ID' ) ) {
            $documento->setAttribute( 'ID', 'DTE-doc' );
        }
        // Crear el objeto de firma
        $objDSig = new XMLSecurityDSig();
        $objDSig->addSignature( $doc );
        // Crear clave para firmar
        $objKey = new XMLSecurityKey( XMLSecurityKey::RSA_SHA1 );
        // Extraer clave privada del PFX
        $pkcs12 = file_get_contents( $cert_path );
        if ( ! openssl_pkcs12_read( $pkcs12, $creds, $cert_pass ) ) {
            return false;
        }
        $objKey->loadKey( $creds['pkey'] );
        // Crear referencia al nodo Documento
        $objDSig->addReference( $documento );
        // Firmar
        $objDSig->sign( $objKey );
        // Insertar la firma en el documento
        $doc->documentElement->appendChild( $objDSig->sigNode );
        return $doc->saveXML();
    }

    /**
     * Firma un XML del Libro de Boletas. Aplica la firma al nodo raíz
     * "LibroBoletas" para cumplir con los requisitos del SII.
     *
     * @param string $xml       Contenido XML del libro.
     * @param string $cert_path Ruta al archivo PFX.
     * @param string $cert_pass Contraseña del certificado.
     * @return string|false     XML firmado o false si ocurre un error.
     */
    public function sign_libro_xml( $xml, $cert_path, $cert_pass ) {
        if ( ! $xml || ! file_exists( $cert_path ) ) {
            return false;
        }
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        $documento = $doc->getElementsByTagName( 'DocumentoConsumoFolios' )->item(0);
        if ( ! $documento ) {
            return false;
        }
        if ( ! $documento->hasAttribute( 'ID' ) ) {
            $documento->setAttribute( 'ID', 'RVD' );
        }
        $objDSig = new XMLSecurityDSig();
        $objDSig->addSignature( $doc );
        $objKey = new XMLSecurityKey( XMLSecurityKey::RSA_SHA1 );
        $pkcs12 = file_get_contents( $cert_path );
        if ( ! openssl_pkcs12_read( $pkcs12, $creds, $cert_pass ) ) {
            return false;
        }
        $objKey->loadKey( $creds['pkey'] );
        $objDSig->addReference( $documento );
        $objDSig->sign( $objKey );
        // Agregar certificado al KeyInfo
        $cert    = str_replace( [ '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r" ], '', $creds['cert'] );
        $keyInfo = $doc->createElementNS( XMLSecurityDSig::XMLDSIG_NS, 'ds:KeyInfo' );
        $x509Data = $doc->createElementNS( XMLSecurityDSig::XMLDSIG_NS, 'ds:X509Data' );
        $x509Cert = $doc->createElementNS( XMLSecurityDSig::XMLDSIG_NS, 'ds:X509Certificate', $cert );
        $x509Data->appendChild( $x509Cert );
        $keyInfo->appendChild( $x509Data );
        $objDSig->sigNode->appendChild( $keyInfo );
        $doc->documentElement->appendChild( $objDSig->sigNode );
        return $doc->saveXML();
    }
}