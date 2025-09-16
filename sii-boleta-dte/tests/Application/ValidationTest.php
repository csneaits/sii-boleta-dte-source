<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\RvdManager;
use Sii\BoletaDte\Application\LibroBoletas;

if ( ! class_exists( 'Dummy_Settings' ) ) {
    class Dummy_Settings extends Settings {
        private array $data;
        public function __construct( array $data ) { $this->data = $data; }
        public function get_settings(): array { return $this->data; }
    }
}

class ValidationTest extends TestCase {
    private $signature = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/><SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/><Reference URI=""><DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/><DigestValue>ABC</DigestValue></Reference></SignedInfo><SignatureValue>ABC</SignatureValue><KeyInfo><KeyValue><RSAKeyValue><Modulus>ABC</Modulus><Exponent>AQAB</Exponent></RSAKeyValue></KeyValue></KeyInfo></Signature>';

    public function test_rvd_xml_validation() {
        $xml = '<ConsumoFolios version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><DocumentoConsumoFolios ID="RVD"><Caratula version="1.0"><RutEmisor>11111111-1</RutEmisor><RutEnvia>11111111-1</RutEnvia><FchResol>2024-01-01</FchResol><NroResol>0</NroResol><FchInicio>2024-05-01</FchInicio><FchFinal>2024-05-01</FchFinal><Correlativo>1</Correlativo><SecEnvio>1</SecEnvio><TmstFirmaEnv>2024-05-01T00:00:00</TmstFirmaEnv></Caratula><Resumen><TipoDocumento>39</TipoDocumento><MntNeto>1000</MntNeto><MntExento>0</MntExento><MntIVA>190</MntIVA><MntTotal>1190</MntTotal><FoliosEmitidos>1</FoliosEmitidos><FoliosAnulados>0</FoliosAnulados><FoliosUtilizados>1</FoliosUtilizados><RangoUtilizados><Inicial>1</Inicial><Final>1</Final></RangoUtilizados><FoliosNoUtilizados>0</FoliosNoUtilizados></Resumen></DocumentoConsumoFolios>'.$this->signature.'</ConsumoFolios>';
        $manager = new RvdManager( new Dummy_Settings([]) );
        $this->assertFalse( $manager->validate_rvd_xml( $xml ) );
    }

    public function test_libro_xml_validation() {
        $xml = '<LibroBoleta version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><EnvioLibro ID="EnvioLibro"><Caratula><RutEmisorLibro>11111111-1</RutEmisorLibro><RutEnvia>11111111-1</RutEnvia><PeriodoTributario>2024-05</PeriodoTributario><FchResol>2024-01-01</FchResol><NroResol>0</NroResol><TipoLibro>ESPECIAL</TipoLibro><TipoEnvio>TOTAL</TipoEnvio></Caratula><ResumenSegmento><TotalesSegmento><TpoDoc>39</TpoDoc><TotalesServicio><TpoServ>3</TpoServ><TotDoc>1</TotDoc><TotMntNeto>1000</TotMntNeto><TasaIVA>19.00</TasaIVA><TotMntIVA>190</TotMntIVA><TotMntTotal>1190</TotMntTotal></TotalesServicio></TotalesSegmento></ResumenSegmento><Detalle><TpoDoc>39</TpoDoc><FolioDoc>1</FolioDoc><FchEmiDoc>2024-05-01</FchEmiDoc><MntTotal>1190</MntTotal></Detalle><TmstFirma>2024-05-01T00:00:00</TmstFirma></EnvioLibro>'.$this->signature.'</LibroBoleta>';
        $libro = new LibroBoletas( new Dummy_Settings([]) );
        $this->assertFalse( $libro->validate_libro_xml( $xml ) );
    }
}
