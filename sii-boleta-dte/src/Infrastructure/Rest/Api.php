<?php
namespace Sii\BoletaDte\Infrastructure\Rest;

use Sii\BoletaDte\Shared\SharedLogger;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Certification\ProgressTracker;
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
        $url  = $this->build_url( $environment, 'boleta/' . rawurlencode( $environment ) );
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
                $this->maybe_mark_certification_progress( $environment );
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
        $url  = $this->build_url( $environment, 'libro/' . rawurlencode( $environment ) );
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

            $json = json_decode( $body, true );
            if ( is_array( $json ) && isset( $json['trackId'] ) ) {
                LogDb::add_entry( (string) $json['trackId'], 'sent', $body );
                return array( 'trackId' => (string) $json['trackId'] );
            }

            return new WP_Error( 'sii_boleta_libro_http_error', $body );
        }
        return new WP_Error( 'sii_boleta_libro_http_error', 'HTTP error' );
    }

    /**
     * Queries the status of a previously sent DTE.
     */
    public function get_dte_status( string $track_id, string $environment, string $token ) {
        $url  = $this->build_url( $environment, 'status/' . rawurlencode( $track_id ) . '/' . rawurlencode( $environment ) );
        $args = array( 'headers' => array( 'Authorization' => $token ) );
        $res  = wp_remote_get( $url, $args );
        if ( is_wp_error( $res ) ) {
            $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
            return $res;
        }
        $code = wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );
        if ( 200 !== $code ) {
            $this->logger->error( 'HTTP ' . $code . ' ' . $body );
            return new WP_Error(
                'sii_boleta_http_error',
                'HTTP ' . $code,
                array(
                    'body' => $body,
                    'url'  => $url,
                )
            );
        }
        $data = json_decode( $body, true );
        if ( isset( $data['status'] ) ) {
            LogDb::add_entry( $track_id, (string) $data['status'], $body );
            return $data['status'];
        }
        return new WP_Error(
            'sii_boleta_http_error',
            'Invalid response',
            array(
                'body' => $body,
                'url'  => $url,
            )
        );
    }

    private function maybe_mark_certification_progress( string $environment ): void {
        if ( $this->is_certification_environment( $environment ) ) {
            ProgressTracker::mark( ProgressTracker::OPTION_TEST_SEND );
        }
    }

    private function is_certification_environment( string $environment ): bool {
        $env = strtolower( trim( (string) $environment ) );
        return in_array( $env, array( '0', 'test', 'certificacion', 'certification' ), true );
    }

    private function build_url( string $environment, string $path = '' ): string {
        $base = $this->get_base_host( $environment );
        if ( '' === $path ) {
            return $base;
        }
        return $base . '/' . ltrim( $path, '/' );
    }

    private function get_base_host( string $environment ): string {
        $env = strtolower( trim( (string) $environment ) );
        $hosts = array(
            '1'            => 'https://maullin.sii.cl',
            'prod'         => 'https://maullin.sii.cl',
            'production'   => 'https://maullin.sii.cl',
            '0'            => 'https://palena.sii.cl',
            'test'         => 'https://palena.sii.cl',
            'certificacion'=> 'https://palena.sii.cl',
            'certification'=> 'https://palena.sii.cl',
        );
        $default = $hosts[$env] ?? 'https://palena.sii.cl';
        $base = apply_filters( 'sii_boleta_api_base_host', $default, $environment );
        return rtrim( (string) $base, '/' );
    }
}

class_alias( Api::class, 'SII_Boleta_API' );
