<?php
/**
 * Maneja la generación y envío del Consumo de Folios (CDF) al SII.
 *
 * @package SII_Boleta_DTE
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Esta clase toma los rangos de folios autorizados por cada CAF activo
 * y determina la cantidad de folios utilizados y anulados hasta una fecha
 * específica, construyendo el XML requerido por el SII.
 */
class SII_Boleta_Consumo_Folios {

	/**
	 * Instancia de configuraciones del plugin.
	 *
	 * @var SII_Boleta_Settings
	 */
	private $settings;

	/**
	 * Manejador de folios para acceder a los rangos autorizados.
	 *
	 * @var SII_Boleta_Folio_Manager
	 */
	private $folio_manager;

	/**
	 * Instancia de la API para reutilizar generación de token.
	 *
	 * @var \Sii\BoletaDte\Infrastructure\Api\SiiBoletaApi
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param SII_Boleta_Settings     $settings      Instancia de configuraciones.
	 * @param SII_Boleta_Folio_Manager $folio_manager Manejador de folios.
	 * @param \Sii\BoletaDte\Infrastructure\Api\SiiBoletaApi          $api           Instancia de la API del SII.
	 */
	public function __construct( SII_Boleta_Settings $settings, SII_Boleta_Folio_Manager $folio_manager, \Sii\BoletaDte\Infrastructure\Api\SiiBoletaApi $api ) {
		$this->settings      = $settings;
		$this->folio_manager = $folio_manager;
		$this->api           = $api;
	}

	/**
	 * Genera el XML de Consumo de Folios para la fecha indicada.
	 *
	 * Se considera cada CAF activo configurado en el sistema y se calculan
	 * los folios utilizados desde el inicio del rango hasta el último folio
	 * usado según los registros guardados en la base de datos.
	 *
	 * @param string $fecha Fecha del consumo en formato YYYY-MM-DD.
	 * @return string|false XML generado o false si ocurre un error.
	 */
	public function generate_cdf_xml( $fecha ) {
		$settings  = $this->settings->get_settings();
		$caf_paths = $settings['caf_path'] ?? [];
		$rut       = $settings['rut_emisor'] ?? '';
		if ( empty( $caf_paths ) || empty( $rut ) ) {
			return false;
		}

		try {
			$xml = new SimpleXMLElement( '<ConsumoFolios xmlns="http://www.sii.cl/SiiDte"></ConsumoFolios>' );
			$caratula = $xml->addChild( 'Caratula' );
			$caratula->addAttribute( 'version', '1.0' );
			$caratula->addChild( 'RutEmisor', $rut );
			$caratula->addChild( 'RutEnvia', $rut );
			$caratula->addChild( 'FchInicio', $fecha );
			$caratula->addChild( 'FchFinal', $fecha );

			foreach ( $caf_paths as $tipo => $path ) {
				if ( ! file_exists( $path ) ) {
					continue;
				}
				$range = $this->get_caf_range( $path );
				if ( ! $range ) {
					continue;
				}
				$option_key = SII_Boleta_Folio_Manager::OPTION_LAST_FOLIO_PREFIX . intval( $tipo );
				$last_folio = intval( get_option( $option_key, $range['D'] - 1 ) );
				if ( $last_folio < $range['D'] ) {
					continue; // No se ha utilizado ningún folio.
				}
				$emitidos = $last_folio - $range['D'] + 1;
				$resumen  = $xml->addChild( 'Resumen' );
				$resumen->addAttribute( 'TipoDTE', intval( $tipo ) );
				$resumen->addChild( 'FoliosEmitidos', $emitidos );
				$resumen->addChild( 'FoliosAnulados', 0 );
				$resumen->addChild( 'FoliosUtilizados', $emitidos );
				$rango = $resumen->addChild( 'RangoUtilizados' );
				$rango->addChild( 'Inicial', $range['D'] );
				$rango->addChild( 'Final', $last_folio );
			}
			return $xml->asXML();
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Envía el XML de Consumo de Folios al SII.
	 *
	 * @param string $xml_content Contenido XML a enviar.
	 * @param string $environment Ambiente de envío (test|production).
	 * @param string $token       Token de la API si ya existe.
	 * @param string $cert_path   Ruta al certificado PFX.
	 * @param string $cert_pass   Contraseña del certificado.
	 * @return string|\WP_Error|false Track ID entregado por el SII o false/WP_Error si falla.
	 */
	public function send_cdf_to_sii( $xml_content, $environment = 'test', $token = '', $cert_path = '', $cert_pass = '' ) {
		if ( empty( $xml_content ) ) {
			return false;
		}
		if ( empty( $token ) ) {
			$token = $this->get_or_generate_token( $environment, $cert_path, $cert_pass );
		}
		if ( empty( $token ) ) {
			return new \WP_Error( 'sii_boleta_missing_token', __( 'El token de la API no está configurado.', 'sii-boleta-dte' ) );
		}
		$base_url = ( 'production' === $environment )
			? 'https://api.sii.cl/bolcoreinternetui/api'
			: 'https://maullin.sii.cl/bolcoreinternetui/api';
		$endpoint = $base_url . '/consumoFolios';
		$args = [
			'body'        => $xml_content,
			'headers'     => [
				'Content-Type'  => 'application/xml',
				'Authorization' => 'Bearer ' . $token,
			],
			'method'      => 'POST',
			'data_format' => 'body',
			'timeout'     => 60,
		];
		$response = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'sii_boleta_http_error', sprintf( __( 'Error HTTP %d al llamar al servicio del SII.', 'sii-boleta-dte' ), $code ) );
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( isset( $data['trackId'] ) ) {
			return $data['trackId'];
		}
		if ( strpos( $body, '<trackId>' ) !== false ) {
			$xml = simplexml_load_string( $body );
			if ( $xml && isset( $xml->trackId ) ) {
				return (string) $xml->trackId;
			}
		}
		return false;
	}

	/**
	 * Obtiene un token válido desde la configuración o lo genera si expiró.
	 *
	 * @param string $environment Ambiente configurado.
	 * @param string $cert_path   Ruta al certificado.
	 * @param string $cert_pass   Contraseña del certificado.
	 * @return string Token válido o cadena vacía.
	 */
	private function get_or_generate_token( $environment, $cert_path, $cert_pass ) {
		$settings = get_option( SII_Boleta_Settings::OPTION_NAME, [] );
		$token    = $settings['api_token'] ?? '';
		$expires  = isset( $settings['api_token_expires'] ) ? intval( $settings['api_token_expires'] ) : 0;
		if ( empty( $token ) || time() >= $expires ) {
			$token = $this->api->generate_token( $environment, $cert_path, $cert_pass );
		}
		return $token;
	}

	/**
	 * Extrae el rango de folios (D y H) desde un CAF.
	 *
	 * @param string $caf_path Ruta al archivo CAF.
	 * @return array|false     Array con claves 'D' y 'H' o false si falla.
	 */
	private function get_caf_range( $caf_path ) {
		try {
			$xml = new SimpleXMLElement( file_get_contents( $caf_path ) );
			$caf = isset( $xml->CAF ) ? $xml->CAF : $xml;
			if ( ! isset( $caf->DA->RNG->D ) || ! isset( $caf->DA->RNG->H ) ) {
				return false;
			}
			$d = max( 0, (int) $caf->DA->RNG->D - 1 );
			$h = (int) $caf->DA->RNG->H;
			return [ 'D' => $d, 'H' => $h ];
		} catch ( Exception $e ) {
			return false;
		}
	}
}
