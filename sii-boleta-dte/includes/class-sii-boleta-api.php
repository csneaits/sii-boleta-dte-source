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
     * Devuelve la URL base de la API según el ambiente.
     * Permite ser modificada mediante el filtro 'sii_boleta_api_base_url'.
     *
     * @param string $environment 'test' o 'production'.
     * @return string
     */
    private function get_base_url( $environment ) {
        $url = ( 'production' === $environment )
            ? 'https://api.sii.cl/bolcoreinternetui/api'
            : 'https://maullin.sii.cl/bolcoreinternetui/api';
        /**
         * Filtro para modificar la URL base de la API del SII.
         *
         * @param string $url         URL base calculada.
         * @param string $environment Ambiente solicitado.
         */
        return apply_filters( 'sii_boleta_api_base_url', $url, $environment );
    }

    /**
     * Obtiene un token válido desde la configuración o lo genera si expiró.
     */
    private function get_or_generate_token( $environment, $cert_path, $cert_pass ) {
        $settings = get_option( SII_Boleta_Settings::OPTION_NAME, [] );
        $token    = $settings['api_token'] ?? '';
        $expires  = isset( $settings['api_token_expires'] ) ? intval( $settings['api_token_expires'] ) : 0;

        if ( empty( $token ) || time() >= $expires ) {
            $token = $this->generate_token( $environment, $cert_path, $cert_pass );
        }

        return $token;
    }

    /**
     * Envía un DTE (archivo XML) al servicio de boletas del SII.
     *
     * @param string $file_path   Ruta del archivo XML a enviar.
     * @param string $environment 'test' o 'production'.
     * @param string $token       Token de autenticación de la API.
     * @param string $cert_path   Ruta del certificado PFX para generar el token si falta.
     * @param string $cert_pass   Contraseña del certificado.
     * @return string|\WP_Error|false Track ID devuelto por el SII, WP_Error si falta el token o false en caso de error.
     */
    public function send_dte_to_sii( $file_path, $environment = 'test', $token = '', $cert_path = '', $cert_pass = '' ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }
        if ( empty( $token ) ) {
            $token = $this->get_or_generate_token( $environment, $cert_path, $cert_pass );
        }
        if ( empty( $token ) ) {
            return new \WP_Error( 'sii_boleta_missing_token', __( 'El token de la API no está configurado.', 'sii-boleta-dte' ) );
        }
        $endpoint = $this->get_base_url( $environment ) . '/envioBoleta';
        $xml_content = file_get_contents( $file_path );
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
     * Envía el XML del Resumen de Ventas Diarias (RVD) al SII.
     * Implementa reintentos exponenciales frente a errores de red o
     * respuestas del servidor en la serie 5xx.
     *
     * @param string $xml_signed  XML ya firmado del RVD.
     * @param string $environment Ambiente de destino ('test' o 'production').
     * @param string $token       Token de autenticación de la API.
     * @return array|\WP_Error   Datos de la respuesta (incluyendo trackId) o WP_Error en caso de falla.
     */
    public function send_rvd_to_sii( $xml_signed, $environment = 'test', $token = '' ) {
        if ( empty( $xml_signed ) ) {
            return new \WP_Error( 'sii_boleta_rvd_empty', __( 'El XML del RVD está vacío.', 'sii-boleta-dte' ) );
        }
        if ( empty( $token ) ) {
            return new \WP_Error( 'sii_boleta_missing_token', __( 'El token de la API no está configurado.', 'sii-boleta-dte' ) );
        }

        $endpoint = $this->get_base_url( $environment ) . '/envioRVD';

        $args = [
            'body'        => $xml_signed,
            'headers'     => [
                'Content-Type'  => 'application/xml',
                'Authorization' => 'Bearer ' . $token,
            ],
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 60,
        ];

        $attempts = 0;
        $max_attempts = 3;
        $delay = 1;
        do {
            $attempts++;
            $response = wp_remote_post( $endpoint, $args );
            $retry    = false;

            if ( is_wp_error( $response ) ) {
                $retry = ( $attempts < $max_attempts );
                $error_message = $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $retry = ( $code >= 500 && $attempts < $max_attempts );
            }

            if ( $retry ) {
                sleep( $delay );
                $delay *= 2;
            }
        } while ( $retry );

        if ( is_wp_error( $response ) ) {
            $this->log_rvd_message( 'Error de conexión al enviar RVD: ' . $error_message );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        update_option( 'sii_boleta_rvd_last_response', $body );

        if ( 200 !== $code ) {
            $message = 'HTTP ' . $code;
            $data    = json_decode( $body, true );
            if ( is_array( $data ) ) {
                if ( isset( $data['codigo'] ) && isset( $data['mensaje'] ) ) {
                    $message .= ' - ' . $data['codigo'] . ': ' . $data['mensaje'];
                } elseif ( isset( $data['error'] ) ) {
                    $message .= ' - ' . $data['error'];
                }
            } else {
                $xml = simplexml_load_string( $body );
                if ( $xml ) {
                    if ( isset( $xml->codigo ) && isset( $xml->causa ) ) {
                        $message .= ' - ' . $xml->codigo . ': ' . $xml->causa;
                    } elseif ( isset( $xml->Codigo ) && isset( $xml->Causa ) ) {
                        $message .= ' - ' . $xml->Codigo . ': ' . $xml->Causa;
                    }
                }
            }
            $this->log_rvd_message( 'Error al enviar RVD: ' . $message );
            return new \WP_Error( 'sii_boleta_rvd_http_error', $message, [ 'status' => $code, 'body' => $body ] );
        }

        $track_id = '';
        $data     = json_decode( $body, true );
        if ( is_array( $data ) && isset( $data['trackId'] ) ) {
            $track_id = $data['trackId'];
        } else {
            $xml = simplexml_load_string( $body );
            if ( $xml && isset( $xml->trackId ) ) {
                $track_id = (string) $xml->trackId;
            }
        }

        if ( $track_id ) {
            update_option( 'sii_boleta_rvd_last_trackid', $track_id );
            $this->log_rvd_message( 'RVD enviado correctamente. Track ID: ' . $track_id );
        } else {
            $this->log_rvd_message( 'RVD enviado sin trackId en la respuesta.' );
        }

        return [
            'status'  => $code,
            'trackId' => $track_id,
            'body'    => $body,
        ];
    }

    /**
     * Registra mensajes relacionados con el envío del RVD en un archivo de
     * log diario ubicado en el directorio de cargas de WordPress.
     *
     * @param string $message Mensaje a registrar.
     */
    private function log_rvd_message( $message ) {
        $upload_dir = wp_upload_dir();
        $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sii-boleta-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $file = trailingslashit( $log_dir ) . 'rvd-' . date( 'Y-m-d' ) . '.log';
        $time = date( 'H:i:s' );
        $line = sprintf( "[%s] %s\n", $time, $message );
        file_put_contents( $file, $line, FILE_APPEND );
    }

    /**
     * Consulta el estado de un envío mediante su track ID. Útil para saber si
     * el SII aceptó o rechazó la boleta.
     *
     * @param string $track_id
     * @param string $environment 'test' o 'production'.
     * @param string $token       Token de autenticación de la API.
     * @param string $cert_path   Ruta del certificado PFX para generar el token si falta.
     * @param string $cert_pass   Contraseña del certificado.
     * @return array|\WP_Error|false Array con datos de estado, WP_Error si falta el token o false si falla.
     */
    public function get_dte_status( $track_id, $environment = 'test', $token = '', $cert_path = '', $cert_pass = '' ) {
        if ( empty( $token ) ) {
            $token = $this->get_or_generate_token( $environment, $cert_path, $cert_pass );
        }
        if ( empty( $token ) ) {
            return new \WP_Error( 'sii_boleta_missing_token', __( 'El token de la API no está configurado.', 'sii-boleta-dte' ) );
        }
        $endpoint = $this->get_base_url( $environment ) . '/boleta/trackid/' . urlencode( $track_id );
        $response = wp_remote_get( $endpoint, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new \WP_Error( 'sii_boleta_http_error', sprintf( __( 'Error HTTP %d al llamar al servicio del SII.', 'sii-boleta-dte' ), $code ) );
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( is_array( $data ) ) {
            return $data;
        }
        return false;
    }

    /**
     * Obtiene automáticamente el token de autenticación desde el SII.
     *
     * @param string $environment 'test' o 'production'.
     * @param string $cert_path   Ruta al certificado PFX.
     * @param string $cert_pass   Contraseña del certificado.
     * @return string|false       Token obtenido o false en caso de error.
     */
    public function generate_token( $environment = 'test', $cert_path = '', $cert_pass = '' ) {
        if ( ! file_exists( $cert_path ) ) {
            return false;
        }

        // 1. Solicitar semilla
        $seed_url = ( 'production' === $environment )
            ? 'https://palena.sii.cl/DTEWS/CrSeed.jws'
            : 'https://maullin.sii.cl/DTEWS/CrSeed.jws';
        $soap_body = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><getSeed/></soapenv:Body></soapenv:Envelope>';
        $response = wp_remote_post( $seed_url, [
            'body'    => $soap_body,
            'headers' => [ 'Content-Type' => 'text/xml; charset=utf-8' ],
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $response );
        $body = html_entity_decode( $body );
        $seed = '';
        if ( preg_match( '/<SEMILLA>([^<]+)<\/SEMILLA>/i', $body, $m ) ) {
            $seed = trim( $m[1] );
        }
        if ( ! $seed ) {
            return false;
        }

        // 2. Firmar semilla
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;
        $doc->loadXML( '<getToken ID="GetToken"><item><Semilla>' . $seed . '</Semilla></item></getToken>' );
        $objDSig = new XMLSecurityDSig();
        $objDSig->addSignature( $doc );
        $pkcs12 = file_get_contents( $cert_path );
        if ( ! openssl_pkcs12_read( $pkcs12, $creds, $cert_pass ) ) {
            return false;
        }
        $objKey = new XMLSecurityKey( XMLSecurityKey::RSA_SHA1 );
        $objKey->loadKey( $creds['pkey'] );
        $objDSig->addReference( $doc->documentElement );
        $objDSig->sign( $objKey );
        // Añadir certificado al KeyInfo
        $cert = str_replace( [ '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r" ], '', $creds['cert'] );
        $keyInfo  = $doc->createElementNS( XMLSecurityDSig::XMLDSIG_NS, 'ds:KeyInfo' );
        $x509Data = $doc->createElementNS( XMLSecurityDSig::XMLDSIG_NS, 'ds:X509Data' );
        $x509Cert = $doc->createElementNS( XMLSecurityDSig::XMLDSIG_NS, 'ds:X509Certificate', $cert );
        $x509Data->appendChild( $x509Cert );
        $keyInfo->appendChild( $x509Data );
        $objDSig->sigNode->appendChild( $keyInfo );
        $doc->documentElement->appendChild( $objDSig->sigNode );
        $signed_seed = $doc->saveXML();

        // 3. Solicitar token
        $token_url = ( 'production' === $environment )
            ? 'https://palena.sii.cl/DTEWS/GetTokenFromSeed.jws'
            : 'https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws';
        $response = wp_remote_post( $token_url, [
            'body'    => $signed_seed,
            'headers' => [ 'Content-Type' => 'text/xml; charset=utf-8' ],
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body  = wp_remote_retrieve_body( $response );
        $body  = html_entity_decode( $body );
        $token = '';
        if ( preg_match( '/<TOKEN>([^<]+)<\/TOKEN>/i', $body, $m ) ) {
            $token = trim( $m[1] );
        }

        if ( $token ) {
            // Guardar el token y su expiración en las opciones para reutilizarlo.
            $settings                     = get_option( SII_Boleta_Settings::OPTION_NAME, array() );
            $settings['api_token']        = $token;
            $settings['api_token_expires'] = time() + 50 * 60; // 50 minutos.
            update_option( SII_Boleta_Settings::OPTION_NAME, $settings );
        }

        return $token ? $token : false;
    }
}
