<?php
namespace Sii\BoletaDte\Infrastructure;

use WP_Error;

/**
 * Minimal HTTP client for SII API used in tests.
 */
class Api {
    /**
     * Sends a DTE XML file to SII and returns track ID or WP_Error.
     * Retries up to 3 times on WP_Error responses.
     */
    public function send_dte_to_sii( string $file, string $environment, string $token ) {
        $url  = 'https://sii.example/' . $environment;
        $args = [ 'headers' => [ 'Authorization' => $token ], 'body' => file_exists( $file ) ? file_get_contents( $file ) : '' ];
        for ( $i = 0; $i < 3; $i++ ) {
            $res = wp_remote_post( $url, $args );
            if ( is_wp_error( $res ) ) {
                continue;
            }
            $code = wp_remote_retrieve_response_code( $res );
            if ( 200 !== $code ) {
                return new WP_Error( 'sii_boleta_http_error', 'HTTP ' . $code );
            }
            $body = wp_remote_retrieve_body( $res );
            $data = json_decode( $body, true );
            if ( isset( $data['trackId'] ) ) {
                \Sii\BoletaDte\Infrastructure\Persistence\LogDb::add_entry( $data['trackId'], 'sent', $body );
                return (string) $data['trackId'];
            }
            return new WP_Error( 'sii_boleta_rechazo', $body );
        }
        return new WP_Error( 'sii_boleta_http_error', 'HTTP error' );
    }

    /**
     * Sends a Libro XML and returns array response or WP_Error.
     */
    public function send_libro_to_sii( string $xml, string $environment, string $token ) {
        $url  = 'https://sii.example/libro/' . $environment;
        for ( $i = 0; $i < 3; $i++ ) {
            $res = wp_remote_post( $url, [ 'headers' => [ 'Authorization' => $token ], 'body' => $xml ] );
            if ( is_wp_error( $res ) ) {
                continue;
            }
            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            if ( 200 !== $code ) {
                continue;
            }
            if ( false !== ( $sx = @simplexml_load_string( $body ) ) && isset( $sx->trackId ) ) {
                \Sii\BoletaDte\Infrastructure\Persistence\LogDb::add_entry( (string) $sx->trackId, 'sent', $body );
                return [ 'trackId' => (string) $sx->trackId ];
            }
            return new WP_Error( 'sii_boleta_libro_http_error', $body );
        }
        return new WP_Error( 'sii_boleta_libro_http_error', 'HTTP error' );
    }

    public function generate_token( string $environment, string $cert_path = '', string $cert_pass = '' ): string {
        return 'token';
    }
}

class_alias( Api::class, 'SII_Boleta_API' );
