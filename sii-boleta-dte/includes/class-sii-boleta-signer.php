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
     * @param string $algo      Algoritmo RSA a utilizar.
     * @return string|false     XML firmado o false si ocurre un error.
     */
    public function sign_dte_xml( $xml, $cert_path, $cert_pass, $algo = XMLSecurityKey::RSA_SHA1 ) {
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
        $objKey = new XMLSecurityKey( $algo );
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
     * Firma un XML del Libro de Boletas. El SII exige que se firme el nodo
     * <EnvioLibro> incluyendo el certificado utilizado.
     *
     * @param string $xml       Contenido XML del libro.
     * @param string $cert_path Ruta al archivo PFX.
     * @param string $cert_pass Contraseña del certificado.
     * @param string $algo      Algoritmo RSA a utilizar.
     * @return string|false     XML firmado o false si ocurre un error.
     */
    public function sign_libro_xml( $xml, $cert_path, $cert_pass, $algo = XMLSecurityKey::RSA_SHA1 ) {
        if ( ! $xml || ! file_exists( $cert_path ) ) {
            return false;
        }
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;
        if ( ! $doc->loadXML( $xml ) ) {
            return false;
        }
        $documento = $doc->getElementsByTagName( 'EnvioLibro' )->item(0);
        if ( ! $documento ) {
            return false;
        }
        // El nodo EnvioLibro debe tener un ID estable para la referencia de la firma.
        if ( ! $documento->hasAttribute( 'ID' ) ) {
            $documento->setAttribute( 'ID', 'EnvioLibro' );
        }
        $objDSig = new XMLSecurityDSig();
        $objDSig->addSignature( $doc );
        $objKey = new XMLSecurityKey( $algo );
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

    /**
     * Firma el XML correspondiente al Resumen de Ventas Diarias (RVD).
     * Inserta los datos del certificado en la sección KeyInfo/X509Data y
     * referencia el nodo <DocumentoConsumoFolios> con un ID fijo.
     *
     * @param string $xml       Contenido XML del RVD.
     * @param string $cert_path Ruta al certificado en formato PFX.
     * @param string $cert_pass Contraseña del certificado.
     * @param string $algo      Algoritmo RSA a utilizar.
     * @return string|false     XML firmado o false si ocurre un error.
     */
    public function sign_rvd_xml( $xml, $cert_path, $cert_pass, $algo = XMLSecurityKey::RSA_SHA1 ) {
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

        $objKey = new XMLSecurityKey( $algo );
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
