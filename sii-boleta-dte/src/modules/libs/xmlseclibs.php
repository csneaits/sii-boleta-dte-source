<?php
/**
 * xmlseclibs.php - biblioteca recortada para firmar documentos XML.
 *
 * Esta versión incluye solo los métodos necesarios para firmar los
 * documentos DTE en este plugin. Se ha simplificado para mantener el
 * tamaño y la complejidad al mínimo. Para una implementación completa
 * consulte el proyecto original en GitHub: https://github.com/robrichards/xmlseclibs
 */

use DOMDocument;
use DOMElement;

class XMLSecurityKey {
    const RSA_SHA1   = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    public $type = null;
    public $privateKey = null;
    public $publicKey  = null;

    public function __construct( $type ) {
        $this->type = $type;
    }

    /**
     * Carga una clave privada desde un archivo o una cadena.
     *
     * @param string $key Clave privada.
     * @param bool   $isFile Indica si $key es una ruta a archivo o la clave en sí.
     * @param string|null $pass Frase de contraseña para la clave, si aplica.
     */
    public function loadKey( $key, $isFile = false, $pass = null ) {
        if ( $isFile ) {
            $key = file_get_contents( $key );
        }
        $this->privateKey = openssl_pkey_get_private( $key, $pass );
        $this->publicKey  = openssl_pkey_get_public( $key );
    }

    /**
     * Firma datos utilizando la clave privada. Devuelve la firma binaria.
     *
     * @param string $data
     * @param int    $alg   Constante de OpenSSL.
     * @return string|false
     */
    public function signData( $data, $alg = OPENSSL_ALGO_SHA1 ) {
        $signature = '';
        if ( openssl_sign( $data, $signature, $this->privateKey, $alg ) ) {
            return $signature;
        }
        return false;
    }
}

class XMLSecurityDSig {
    const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
    public $sigNode;
    public $idKeys = array();
    public $signedInfo;
    public $xPathCtx;

    public function __construct() {
    }

    /**
     * Firma un documento utilizando la clave proporcionada. La firma se
     * adjunta al nodo raíz.
     *
     * @param XMLSecurityKey $objKey
     * @param array          $options
     */
    public function sign( $objKey, $options = array() ) {
        $prefix       = 'ds';
        $doc          = $this->sigNode->ownerDocument;
        $canonicalData= $this->canonicalizeData( $this->signedInfo );
        $algo         = OPENSSL_ALGO_SHA1;
        if ( isset( $options['alg'] ) && XMLSecurityKey::RSA_SHA256 === $options['alg'] ) {
            $algo = OPENSSL_ALGO_SHA256;
        }
        $signature = $objKey->signData( $canonicalData, $algo );
        $sigValue  = base64_encode( $signature );
        $signatureNode = $doc->createElementNS( self::XMLDSIG_NS, $prefix . ':SignatureValue', $sigValue );
        $this->sigNode->appendChild( $signatureNode );
    }

    /**
     * Agrega referencia al nodo raíz del documento. Se calcula el digest
     * usando SHA1.
     *
     * @param DOMNode $node
     */
    public function addReference( $node ) {
        $doc = $this->sigNode->ownerDocument;
        $digestMethod = $doc->createElementNS( self::XMLDSIG_NS, 'ds:DigestMethod' );
        $digestMethod->setAttribute( 'Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1' );
        $canonicalData = $node->C14N( true, true );
        $digestValue = base64_encode( sha1( $canonicalData, true ) );
        $digestValueNode = $doc->createElementNS( self::XMLDSIG_NS, 'ds:DigestValue', $digestValue );
        $reference = $doc->createElementNS( self::XMLDSIG_NS, 'ds:Reference' );
        $reference->setAttribute( 'URI', '#' . $node->getAttribute( 'ID' ) );
        $reference->appendChild( $digestMethod );
        $reference->appendChild( $digestValueNode );
        $signedInfo = $this->sigNode->getElementsByTagNameNS( self::XMLDSIG_NS, 'SignedInfo' )->item(0);
        $signedInfo->appendChild( $reference );
    }

    /**
     * Inicializa la estructura de la firma dentro del documento.
     *
     * @param DOMDocument $doc
     */
    public function addSignature( $doc ) {
        $prefix = 'ds';
        $this->sigNode = $doc->createElementNS( self::XMLDSIG_NS, $prefix . ':Signature' );
        $doc->documentElement->appendChild( $this->sigNode );
        // SignedInfo
        $this->signedInfo = $doc->createElementNS( self::XMLDSIG_NS, $prefix . ':SignedInfo' );
        $this->sigNode->appendChild( $this->signedInfo );
        // CanonicalizationMethod
        $canonMethod = $doc->createElementNS( self::XMLDSIG_NS, $prefix . ':CanonicalizationMethod' );
        $canonMethod->setAttribute( 'Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315' );
        $this->signedInfo->appendChild( $canonMethod );
        // SignatureMethod
        $sigMethod = $doc->createElementNS( self::XMLDSIG_NS, $prefix . ':SignatureMethod' );
        $sigMethod->setAttribute( 'Algorithm', XMLSecurityKey::RSA_SHA1 );
        $this->signedInfo->appendChild( $sigMethod );
    }

    /**
     * Canonicaliza datos XML mediante C14N exclusivo.
     *
     * @param DOMElement $node
     * @return string
     */
    private function canonicalizeData( $node ) {
        return $node->C14N( true, false );
    }
}