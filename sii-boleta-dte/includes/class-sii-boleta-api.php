<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Encapsula las llamadas a la API del SII. Proporciona métodos para enviar
 * documentos electrónicos (boletas, notas de crédito/debito) y obtener
 * respuestas como el track ID. Permite además consultar el estado de un
 * envío según el track ID.
 */
class SII_Boleta_API {

    /**
     * Envía un DTE (archivo XML) al servicio de boletas del SII.
     *
     * @param string $file_path   Ruta del archivo XML a enviar.
     * @param string $environment 'test' o 'production'.
     * @return string|false Track ID devuelto por el SII o false en caso de error.
     */
    public function send_dte_to_sii( $file_path, $environment = 'test' ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }
        $base_url = ( 'production' === $environment )
            ? 'https://api.sii.cl/bolcoreinternetui/api'
            : 'https://maullin.sii.cl/bolcoreinternetui/api';
        $endpoint = $base_url . '/envioBoleta';
        $xml_content = file_get_contents( $file_path );
        // Construir headers. En implementaciones reales se debe incluir el token de la API.
        $args = [
            'body'        => $xml_content,
            'headers'     => [
                'Content-Type' => 'application/xml',
            ],
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 60,
        ];
        $response = wp_remote_post( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $response );
        // El SII puede devolver JSON o XML; intentamos decodificar JSON primero.
        $data = json_decode( $body, true );
        if ( isset( $data['trackId'] ) ) {
            return $data['trackId'];
        }
        // Si es XML, intentar parsear el nodo trackId
        if ( strpos( $body, '<trackId>' ) !== false ) {
            $xml = simplexml_load_string( $body );
            if ( $xml && isset( $xml->trackId ) ) {
                return (string) $xml->trackId;
            }
        }
        return false;
    }

    /**
     * Consulta el estado de un envío mediante su track ID. Útil para saber si
     * el SII aceptó o rechazó la boleta.
     *
     * @param string $track_id
     * @param string $environment 'test' o 'production'.
     * @return array|false Array con datos de estado o false si falla.
     */
    public function get_dte_status( $track_id, $environment = 'test' ) {
        $base_url = ( 'production' === $environment )
            ? 'https://api.sii.cl/bolcoreinternetui/api'
            : 'https://maullin.sii.cl/bolcoreinternetui/api';
        $endpoint = $base_url . '/boleta/trackid/' . urlencode( $track_id );
        $response = wp_remote_get( $endpoint, [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( is_array( $data ) ) {
            return $data;
        }
        return false;
    }
}