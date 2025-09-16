<?php
namespace Sii\BoletaDte\Infrastructure;

/**
 * Dummy signer returning XML unchanged.
 */
class Signer {
	public function sign_libro_xml( string $xml, string $cert_path = '', string $cert_pass = '' ) {
		return $xml;
	}

	public function sign_rvd_xml( string $xml, string $cert_path = '', string $cert_pass = '' ) {
		return $xml;
	}
}

class_alias( Signer::class, 'SII_Boleta_Signer' );
