<?php
namespace Sii\BoletaDte\Infrastructure\Rest;

use Sii\BoletaDte\Shared\SharedLogger;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use WP_Error;

/**
 * HTTP client wrapper for communication with SII services.
 */
class Api {
    private SharedLogger $logger;

    /** Number of times to retry failed HTTP requests. */
    private int $retries;

    public function __construct( SharedLogger $logger = null, int $retries = 3 ) {
        $this->logger  = $logger ?? new SharedLogger();
        $this->retries = max( 1, $retries );
    }

    /**
     * Generates an authentication token using a certificate and password.
     * This simplified implementation only simulates token creation.
     */
    public function generate_token( string $environment, string $cert_path = '', string $cert_pass = '' ): string {
        return hash( 'sha256', $environment . $cert_path . $cert_pass . microtime( true ) );
    }

    /**
     * Sends a DTE XML file to SII and returns the track ID or WP_Error.
     */
    public function send_dte_to_sii( string $file, string $environment, string $token ) {
        $url  = 'https://sii.example/' . $environment;
        $args = array(
            'headers' => array( 'Authorization' => $token ),
            'body'    => file_exists( $file ) ? file_get_contents( $file ) : '',
        );
        for ( $i = 0; $i < $this->retries; $i++ ) {
            $res = wp_remote_post( $url, $args );
            if ( is_wp_error( $res ) ) {
                $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
                continue;
            }
            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            if ( 200 !== $code ) {
                $this->logger->error( 'HTTP ' . $code );
                return new WP_Error( 'sii_boleta_http_error', 'HTTP ' . $code );
            }
            $data = json_decode( $body, true );
            if ( isset( $data['trackId'] ) ) {
                LogDb::add_entry( (string) $data['trackId'], 'sent', $body );
                return (string) $data['trackId'];
            }
            $this->logger->error( 'Invalid response: ' . $body );
            return new WP_Error( 'sii_boleta_rechazo', $body );
        }
        return new WP_Error( 'sii_boleta_http_error', 'HTTP error' );
    }

    /**
     * Sends a Libro or RVD XML and returns the response array or WP_Error.
     */
    public function send_libro_to_sii( string $xml, string $environment, string $token ) {
        $url  = 'https://sii.example/libro/' . $environment;
        $args = array(
            'headers' => array( 'Authorization' => $token ),
            'body'    => $xml,
        );
        for ( $i = 0; $i < $this->retries; $i++ ) {
            $res = wp_remote_post( $url, $args );
            if ( is_wp_error( $res ) ) {
                $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
                continue;
            }
            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            if ( 200 !== $code ) {
                continue;
            }
            \libxml_use_internal_errors( true );
            $sx = simplexml_load_string( $body );
            \libxml_clear_errors();
            if ( false !== $sx && isset( $sx->trackId ) ) {
                LogDb::add_entry( (string) $sx->trackId, 'sent', $body );
                return array( 'trackId' => (string) $sx->trackId );
            }
            return new WP_Error( 'sii_boleta_libro_http_error', $body );
        }
        return new WP_Error( 'sii_boleta_libro_http_error', 'HTTP error' );
    }

    /**
     * Queries the status of a previously sent DTE.
     */
    public function get_dte_status( string $track_id, string $environment, string $token ) {
        $url  = 'https://sii.example/status/' . rawurlencode( $track_id ) . '/' . $environment;
        $args = array( 'headers' => array( 'Authorization' => $token ) );
        $res  = wp_remote_get( $url, $args );
        if ( is_wp_error( $res ) ) {
            $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
            return $res;
        }
        $code = wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );
        if ( 200 !== $code ) {
            $this->logger->error( 'HTTP ' . $code );
            return new WP_Error( 'sii_boleta_http_error', 'HTTP ' . $code );
        }
        $data = json_decode( $body, true );
        if ( isset( $data['status'] ) ) {
            LogDb::add_entry( $track_id, (string) $data['status'], $body );
            return $data['status'];
        }
        return new WP_Error( 'sii_boleta_http_error', 'Invalid response' );
    }
}

class_alias( Api::class, 'SII_Boleta_API' );
