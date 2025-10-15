<?php
namespace Sii\BoletaDte\Infrastructure\Engine;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * XML signer utilities.
 *
 * Nota: LibreDTE firma los DTE y EnvioDTE internamente. Aquí nos enfocamos en
 * EnvioRecibos, donde proveemos una firma robusta con xmlseclibs.
 */
class Signer {
	public function sign_libro_xml( string $xml, string $cert_path = '', string $cert_pass = '' ) {
		return $xml;
	}

	public function sign_rvd_xml( string $xml, string $cert_path = '', string $cert_pass = '' ) {
		return $xml;
	}

	/**
	 * Firma un EnvioRecibos utilizando xmlseclibs (RSA-SHA256) referenciando SetRecibos@ID.
	 */
	public function sign_recibos_xml( string $xml, string $cert_path = '', string $cert_pass = '' ) {
		if ( '' === $cert_path || ! file_exists( $cert_path ) ) { return $xml; }
		try {
			$pkcs12 = @file_get_contents( $cert_path );
			if ( false === $pkcs12 ) { return $xml; }
			$certs = array();
			if ( ! \openssl_pkcs12_read( $pkcs12, $certs, (string) $cert_pass ) ) { return $xml; }
			$privKey = $certs['pkey'] ?? null; // PEM private key
			$x509    = $certs['cert'] ?? '';   // PEM certificate
			if ( ! $privKey || '' === (string) $x509 ) { return $xml; }

			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = false;
			if ( ! $dom->loadXML( $xml, LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NONET ) ) { return $xml; }

			$xp = new DOMXPath( $dom );
			$xp->registerNamespace( 's', 'http://www.sii.cl/SiiDte' );
			/** @var DOMElement|null $set */
			$set = $xp->query( '//s:EnvioRecibos/s:SetRecibos' )->item( 0 );
			if ( ! $set instanceof DOMElement ) { return $xml; }
			$id = (string) $set->getAttribute( 'ID' );
			if ( '' === $id ) {
				$id = 'SR' . substr( sha1( microtime( true ) ), 0, 10 );
				$set->setAttribute( 'ID', $id );
			}

			// Preparar xmlseclibs
			$dsig = new XMLSecurityDSig();
			$dsig->setCanonicalMethod( XMLSecurityDSig::C14N );
			$dsig->addReference(
				$set,
				XMLSecurityDSig::SHA256,
				array( XMLSecurityDSig::C14N ),
				array( 'id_name' => 'ID', 'overwrite' => false )
			);

			$key = new XMLSecurityKey( XMLSecurityKey::RSA_SHA256, array( 'type' => 'private' ) );
			$key->loadKey( (string) $privKey );
			$dsig->sign( $key );
			$dsig->add509Cert( (string) $x509, true );

			/** @var DOMElement|null $envio */
			$envio = $xp->query( '//s:EnvioRecibos' )->item( 0 );
			if ( $envio instanceof DOMElement ) {
				$dsig->appendSignature( $envio );
				return $dom->saveXML() ?: $xml;
			}
		} catch ( \Throwable $e ) {
			// Fallback silencioso en producción para no bloquear flujo
		}
		return $xml;
	}
}

class_alias( Signer::class, 'SII_Boleta_Signer' );
