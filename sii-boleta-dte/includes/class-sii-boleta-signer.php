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
     * Intenta cargar credenciales (clave privada y certificado) desde un PFX/P12.
     * Primero usa openssl_pkcs12_read. Si falla con OpenSSL 3 por algoritmos legacy,
     * intenta convertir temporalmente el PFX a PEM usando el binario `openssl` y
     * extrae la clave/certificados desde allí.
     *
     * @param string $cert_path
     * @param string $cert_pass
     * @return array|false Array con claves ['pkey' => string, 'cert' => string] o false si falla.
     */
    public static function load_pkcs12_creds( $cert_path, $cert_pass ) {
        if ( ! is_string( $cert_path ) ) {
            return false;
        }
        $cert_path = realpath( $cert_path );
        if ( false === $cert_path || ! is_file( $cert_path ) || ! is_readable( $cert_path ) ) {
            return false;
        }
        if ( ! is_scalar( $cert_pass ) ) {
            return false;
        }
        $cert_pass = (string) $cert_pass;

        $pkcs12 = @file_get_contents( $cert_path );
        $creds  = [];
        if ( $pkcs12 && @openssl_pkcs12_read( $pkcs12, $creds, $cert_pass ) ) {
            return $creds;
        }

        // Fallback: usar binario openssl si está disponible y funciones no están deshabilitadas.
        $can_exec = function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true );
        if ( ! $can_exec ) {
            // Sin exec disponible no podemos convertir el PFX a PEM.
            if ( class_exists( 'SII_Logger' ) ) {
                SII_Logger::error( 'exec no disponible para convertir certificado PFX.' );
            }
            return false;
        }
        // Convertir a PEM en un archivo temporal y extraer clave/cert.
        $tmpDir = function_exists( 'wp_upload_dir' ) ? ( wp_upload_dir()['basedir'] ?? sys_get_temp_dir() ) : sys_get_temp_dir();
        $tmpPem = rtrim( $tmpDir, '/\\' ) . DIRECTORY_SEPARATOR . 'cert_bundle_' . uniqid() . '.pem';
        $cmd    = 'openssl pkcs12 -in %s -passin pass:%s -nodes -out %s 2>&1';
        $cmd    = sprintf( $cmd, escapeshellarg( $cert_path ), escapeshellarg( (string) $cert_pass ), escapeshellarg( $tmpPem ) );
        @exec( $cmd, $out, $ret );
        if ( $ret !== 0 || ! file_exists( $tmpPem ) ) {
            if ( file_exists( $tmpPem ) ) { @unlink( $tmpPem ); }
            return false;
        }
        $pem = @file_get_contents( $tmpPem );
        @unlink( $tmpPem );
        if ( ! $pem ) {
            return false;
        }
        // Extraer clave privada y primer certificado del bundle.
        $pkey = '';
        if ( preg_match( '/-----BEGIN (?:ENCRYPTED )?PRIVATE KEY-----(.*?)-----END (?:ENCRYPTED )?PRIVATE KEY-----/s', $pem, $m ) ) {
            $pkey = "-----BEGIN PRIVATE KEY-----" . trim( $m[1] ) . "-----END PRIVATE KEY-----";
            // Reconstituir saltos de línea si se perdieron
            $pkey = preg_replace( '/(-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----)/', "\n$1\n", $pkey );
            $pkey = preg_replace( '/([A-Za-z0-9+\/=]{64})/', "$1\n", $pkey );
        }
        $cert = '';
        if ( preg_match( '/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m ) ) {
            $cert = "-----BEGIN CERTIFICATE-----" . trim( $m[1] ) . "-----END CERTIFICATE-----";
            $cert = preg_replace( '/(-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----)/', "\n$1\n", $cert );
            $cert = preg_replace( '/([A-Za-z0-9+\/=]{64})/', "$1\n", $cert );
        }
        if ( $pkey && $cert ) {
            return [ 'pkey' => $pkey, 'cert' => $cert ];
        }
        return false;
    }

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
        // Extraer clave privada del PFX (con fallback a conversión)
        $creds = self::load_pkcs12_creds( $cert_path, $cert_pass );
        if ( ! is_array( $creds ) || empty( $creds['pkey'] ) ) {
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
        $creds = self::load_pkcs12_creds( $cert_path, $cert_pass );
        if ( ! is_array( $creds ) || empty( $creds['pkey'] ) || empty( $creds['cert'] ) ) {
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
        $creds = self::load_pkcs12_creds( $cert_path, $cert_pass );
        if ( ! is_array( $creds ) || empty( $creds['pkey'] ) || empty( $creds['cert'] ) ) {
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
