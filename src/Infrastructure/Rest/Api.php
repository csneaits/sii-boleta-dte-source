<?php
namespace Sii\BoletaDte\Infrastructure\Rest;

use Sii\BoletaDte\Shared\SharedLogger;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Certification\ProgressTracker;
use Sii\BoletaDte\Infrastructure\Settings;
use libredte\lib\Core\Application;
use Sii\BoletaDte\Infrastructure\LibredteBridge;
use WP_Error;

trait LibreDteWsSupport {
    /**
     * Resolve LibreDTE SII WS worker if available. Overridable in tests.
     * @return object|null A worker exposing consumeWebservice(...)
     */
    protected function resolve_sii_ws() {
        try {
            // Prefer centralized libredte access if Settings are available
            if ( isset( $this->settings ) && $this->settings instanceof Settings ) {
                $app = LibredteBridge::getApp( $this->settings );
            } else {
                $app = Application::getInstance();
            }
            if ( ! $app || ! method_exists( $app, 'getPackageRegistry' ) ) { return null; }
            $registry = \call_user_func( array( $app, 'getPackageRegistry' ) );
            if ( ! $registry ) { return null; }
            $billing = null;
            if ( method_exists( $registry, 'getBillingPackage' ) ) {
                $billing = \call_user_func( array( $registry, 'getBillingPackage' ) );
            } elseif ( property_exists( $registry, 'billing' ) ) {
                $billing = $registry->billing;
            }
            if ( ! $billing || ! method_exists( $billing, 'getIntegrationComponent' ) ) { return null; }
            $integration = \call_user_func( array( $billing, 'getIntegrationComponent' ) );
            if ( ! $integration ) { return null; }
            if ( method_exists( $integration, 'getSiiLazyWorker' ) ) {
                return $integration->getSiiLazyWorker();
            }
            return null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }
    private function is_ws_enabled(): bool {
        if ( ! isset( $this->settings ) || ! $this->settings instanceof Settings ) { return false; }
        $cfg = $this->settings->get_settings();
        return ! empty( $cfg['use_libredte_ws'] );
    }

    /**
     * @param 'dte'|'libro'|'recibos' $kind
     * @param string $token opaque token; if SiiLazy needs different auth, it can derive or ignore
     * @param string $payload file path (dte) or xml string (libro/recibos)
     * @return mixed|null Return value compatible with existing methods or null to continue HTTP path
     */
    private function maybe_send_with_libredte_ws( string $kind, string $environment, string $token, string $payload ) {
        if ( ! $this->is_ws_enabled() ) { return null; }
        try {
            $sii = $this->resolve_sii_ws();
            if ( ! $sii ) { return null; }

            // Build a minimal request DTO via array; specific versions can require concrete classes.
            $request = array(
                'environment' => $environment,
                'token' => $token,
            );

            $service = '';
            $function = '';
            $args = array();
            switch ( $kind ) {
                case 'dte':
                    $service = 'RecepcionDTE';
                    $function = 'upload';
                    $body = file_exists( $payload ) ? file_get_contents( $payload ) : '';
                    $args = array( 'xml' => $body );
                    break;
                case 'libro':
                    $service = 'RecepcionLibros';
                    $function = 'upload';
                    $args = array( 'xml' => $payload );
                    break;
                case 'recibos':
                    $service = 'RecepcionRecibos';
                    $function = 'upload';
                    $args = array( 'xml' => $payload );
                    break;
            }

            if ( '' === $service ) { return null; }

            $retry = $this->retries;
            $resp = \call_user_func_array( array( $sii, 'consumeWebservice' ), array( $request, $service, $function, $args, $retry ) );

            // Map response into our existing return contracts
            if ( 'dte' === $kind ) {
                $trackId = is_array( $resp ) && isset( $resp['trackId'] ) ? (string) $resp['trackId'] : '';
                if ( '' !== $trackId ) { LogDb::add_entry( $trackId, 'sent', json_encode( $resp ), $environment ); return $trackId; }
                return new WP_Error( 'sii_boleta_http_error', 'WS error', $resp );
            }
            if ( in_array( $kind, array( 'libro', 'recibos' ), true ) ) {
                $trackId = is_array( $resp ) && isset( $resp['trackId'] ) ? (string) $resp['trackId'] : '';
                if ( '' !== $trackId ) { LogDb::add_entry( $trackId, 'sent', json_encode( $resp ), $environment ); return array( 'trackId' => $trackId ); }
                return new WP_Error( 'sii_boleta_http_error', 'WS error', $resp );
            }
        } catch ( \Throwable $e ) {
            // Silent fallback to HTTP path on any WS issues
        }
        return null;
    }

    private function maybe_query_with_libredte_ws( string $trackId, string $environment, string $token ) {
        if ( ! $this->is_ws_enabled() ) { return null; }
        try {
            $sii = $this->resolve_sii_ws();
            if ( ! $sii ) { return null; }

            $request = array(
                'environment' => $environment,
                'token' => $token,
            );

            $resp = \call_user_func_array( array( $sii, 'consumeWebservice' ), array( $request, 'EstadoDTE', 'query', array( 'trackId' => $trackId ), $this->retries ) );
            if ( is_array( $resp ) && isset( $resp['status'] ) ) {
                LogDb::add_entry( $trackId, (string) $resp['status'], json_encode( $resp ), $environment );
                return $resp['status'];
            }
            return new WP_Error( 'sii_boleta_http_error', 'WS error', $resp );
        } catch ( \Throwable $e ) {
        }
        return null;
    }
}

/**
 * HTTP client wrapper for communication with SII services.
 */
class Api {
    use LibreDteWsSupport;
    private SharedLogger $logger;

    /** Number of times to retry failed HTTP requests. */
    private int $retries;
    private ?Settings $settings = null;

    public function __construct( SharedLogger $logger = null, int $retries = 3 ) {
        $this->logger  = $logger ?? new SharedLogger();
        $this->retries = max( 1, $retries );
    }

    /** Injects Settings to read feature flags (e.g., use_libredte_ws). */
    public function setSettings( Settings $settings ): void {
        $this->settings = $settings;
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
        $simulated = $this->maybe_simulate_send( 'dte', $environment );
        if ( null !== $simulated ) {
            return $simulated;
        }
        // Try LibreDTE WS client when enabled; fallback to HTTP
        $wsResult = $this->maybe_send_with_libredte_ws( 'dte', $environment, $token, $file );
        if ( null !== $wsResult ) { return $wsResult; }
        $url  = $this->build_url( $environment, 'boleta/' . rawurlencode( $environment ) );
        $args = array(
            'headers' => array( 'Authorization' => $token ),
            'body'    => file_exists( $file ) ? file_get_contents( $file ) : '',
            'timeout' => 15,
        );
        for ( $i = 0; $i < $this->retries; $i++ ) {
            $res = wp_remote_post( $url, $args );
            if ( is_wp_error( $res ) ) {
                $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
                continue; // retry on transport error
            }
            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            if ( 200 !== $code ) {
                // Abort on 4xx, retry on 5xx
                $this->logger->error( 'HTTP ' . $code );
                if ( $code >= 500 ) { continue; }
                return new WP_Error( 'sii_boleta_http_error', 'HTTP ' . $code );
            }
            $data = json_decode( $body, true );
            if ( isset( $data['trackId'] ) ) {
                LogDb::add_entry( (string) $data['trackId'], 'sent', $body, $environment );
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
        $simulated = $this->maybe_simulate_send( 'libro', $environment );
        if ( null !== $simulated ) {
            return $simulated;
        }
        $wsResult = $this->maybe_send_with_libredte_ws( 'libro', $environment, $token, $xml );
        if ( null !== $wsResult ) { return $wsResult; }
        $url  = $this->build_url( $environment, 'libro/' . rawurlencode( $environment ) );
        $args = array(
            'headers' => array( 'Authorization' => $token ),
            'body'    => $xml,
            'timeout' => 15,
        );
        for ( $i = 0; $i < $this->retries; $i++ ) {
            $res = wp_remote_post( $url, $args );
            if ( is_wp_error( $res ) ) {
                $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
                continue; // retry on transport error
            }
            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            if ( 200 !== $code ) {
                if ( $code >= 500 ) { continue; }
                return new WP_Error( 'sii_boleta_libro_http_error', 'HTTP ' . $code );
            }
            \libxml_use_internal_errors( true );
            $sx = simplexml_load_string( $body );
            \libxml_clear_errors();
            if ( false !== $sx && isset( $sx->trackId ) ) {
                LogDb::add_entry( (string) $sx->trackId, 'sent', $body, $environment );
                return array( 'trackId' => (string) $sx->trackId );
            }

            $json = json_decode( $body, true );
            if ( is_array( $json ) && isset( $json['trackId'] ) ) {
                LogDb::add_entry( (string) $json['trackId'], 'sent', $body, $environment );
                return array( 'trackId' => (string) $json['trackId'] );
            }

            return new WP_Error( 'sii_boleta_libro_http_error', $body );
        }
        return new WP_Error( 'sii_boleta_libro_http_error', 'HTTP error' );
    }

    /**
     * Sends an EnvioRecibos XML and returns the response array or WP_Error.
     */
    public function send_recibos_to_sii( string $xml, string $environment, string $token ) {
        $simulated = $this->maybe_simulate_send( 'recibos', $environment );
        if ( null !== $simulated ) {
            return $simulated;
        }
        $wsResult = $this->maybe_send_with_libredte_ws( 'recibos', $environment, $token, $xml );
        if ( null !== $wsResult ) { return $wsResult; }
        $url  = $this->build_url( $environment, 'recibos/' . rawurlencode( $environment ) );
        $args = array(
            'headers' => array( 'Authorization' => $token ),
            'body'    => $xml,
            'timeout' => 15,
        );
        for ( $i = 0; $i < $this->retries; $i++ ) {
            $res = wp_remote_post( $url, $args );
            if ( is_wp_error( $res ) ) {
                $this->logger->error( 'HTTP error: ' . $res->get_error_message() );
                continue; // retry on transport error
            }
            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            if ( 200 !== $code ) {
                if ( $code >= 500 ) { continue; }
                return new WP_Error( 'sii_boleta_recibos_http_error', 'HTTP ' . $code );
            }

            \libxml_use_internal_errors( true );
            $sx = simplexml_load_string( $body );
            \libxml_clear_errors();
            if ( false !== $sx && isset( $sx->trackId ) ) {
                LogDb::add_entry( (string) $sx->trackId, 'sent', $body, $environment );
                return array( 'trackId' => (string) $sx->trackId );
            }

            $json = json_decode( $body, true );
            if ( is_array( $json ) && isset( $json['trackId'] ) ) {
                LogDb::add_entry( (string) $json['trackId'], 'sent', $body, $environment );
                return array( 'trackId' => (string) $json['trackId'] );
            }

            return new WP_Error( 'sii_boleta_recibos_http_error', $body );
        }
        return new WP_Error( 'sii_boleta_recibos_http_error', 'HTTP error' );
    }

    /**
     * Queries the status of a previously sent DTE.
     */
    public function get_dte_status( string $track_id, string $environment, string $token ) {
        $simulated = $this->maybe_simulate_status( $track_id, $environment );
        if ( null !== $simulated ) {
            return $simulated;
        }
        $wsResult = $this->maybe_query_with_libredte_ws( $track_id, $environment, $token );
        if ( null !== $wsResult ) { return $wsResult; }
        $url  = $this->build_url( $environment, 'status/' . rawurlencode( $track_id ) . '/' . rawurlencode( $environment ) );
    $args = array( 'headers' => array( 'Authorization' => $token ), 'timeout' => 15 );
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
            LogDb::add_entry( $track_id, (string) $data['status'], $body, $environment );
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

    private function get_simulation_mode( string $environment ): string {
        if ( ! $this->settings instanceof Settings ) {
            return 'disabled';
        }
        $env = Settings::normalize_environment( $environment );
        if ( '2' !== $env ) {
            return 'disabled';
        }
        $cfg  = $this->settings->get_settings();
        $mode = isset( $cfg['dev_sii_simulation_mode'] ) ? (string) $cfg['dev_sii_simulation_mode'] : '';
        if ( '' === $mode ) {
            return 'success';
        }
        if ( in_array( $mode, array( 'success', 'error' ), true ) ) {
            return $mode;
        }
        return 'disabled' === $mode ? 'disabled' : 'success';
    }

    private function maybe_simulate_send( string $kind, string $environment ) {
        $mode = $this->get_simulation_mode( $environment );
        if ( 'disabled' === $mode ) {
            return null;
        }

        $track = $this->generate_simulation_track_id( $kind );
        if ( 'success' === $mode ) {
            $payload = json_encode(
                array(
                    'simulated' => true,
                    'mode'      => 'success',
                    'kind'      => $kind,
                )
            );
            LogDb::add_entry( $track, 'sent', is_string( $payload ) ? $payload : '', $environment );
            if ( 'dte' === $kind ) {
                $this->maybe_mark_certification_progress( $environment );
                return $track;
            }
            return array( 'trackId' => $track );
        }

        $message = __( 'Envío simulado con error desde ajustes de desarrollo.', 'sii-boleta-dte' );
        LogDb::add_entry( $track, 'error', $message, $environment );
        return new WP_Error(
            'sii_boleta_dev_simulated_error',
            $message,
            array(
                'kind'    => $kind,
                'trackId' => $track,
            )
        );
    }

    private function maybe_simulate_status( string $track_id, string $environment ) {
        $mode = $this->get_simulation_mode( $environment );
        if ( 'disabled' === $mode ) {
            return null;
        }
        if ( 'success' === $mode ) {
            $payload = json_encode(
                array(
                    'simulated' => true,
                    'mode'      => 'success',
                    'trackId'   => $track_id,
                )
            );
            LogDb::add_entry( $track_id, 'accepted', is_string( $payload ) ? $payload : '', $environment );
            return 'accepted';
        }

        return new WP_Error(
            'sii_boleta_dev_simulated_error',
            __( 'Estado simulado no disponible: el último envío fue forzado a error.', 'sii-boleta-dte' ),
            array(
                'trackId' => $track_id,
            )
        );
    }

    private function generate_simulation_track_id( string $kind ): string {
        $prefix = strtoupper( substr( $kind, 0, 3 ) );
        try {
            $random = bin2hex( random_bytes( 4 ) );
        } catch ( \Throwable $e ) {
            $random = (string) mt_rand( 1000, 9999 );
        }
        return $prefix . '-SIM-' . $random;
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

    /**
     * Attempts to authenticate via LibreDTE SiiLazy when enabled.
     * Returns an opaque token string on success or empty string on failure.
     */
    public function libredte_authenticate( string $environment ): string {
        if ( ! $this->is_ws_enabled() ) { return ''; }
        try {
            $sii = $this->resolve_sii_ws();
            if ( ! $sii || ! \method_exists( $sii, 'authenticate' ) ) { return ''; }
            $request = array( 'environment' => $environment );
            $token   = \call_user_func( array( $sii, 'authenticate' ), $request );
            return is_string( $token ) ? $token : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Validates a DTE signature against SII using LibreDTE when available.
     * Returns array-like structured result or WP_Error on failure/unavailable.
     *
     * @param array<string,mixed> $params Keys: company,rut,document,number,date,total,recipient,signature
     * @return array<string,mixed>|WP_Error
     */
    public function validate_document_signature( string $environment, array $params ) {
        if ( ! $this->is_ws_enabled() ) {
            return new WP_Error( 'sii_boleta_ws_unavailable', 'LibreDTE WS disabled' );
        }
        try {
            $sii = $this->resolve_sii_ws();
            if ( ! $sii || ! \method_exists( $sii, 'validateDocumentSignature' ) ) {
                return new WP_Error( 'sii_boleta_ws_unavailable', 'validateDocumentSignature not supported' );
            }
            $request = array( 'environment' => $environment );
            $args = array(
                $request,
                $params['company']   ?? '',
                $params['document']  ?? '',
                $params['number']    ?? 0,
                $params['date']      ?? '',
                $params['total']     ?? 0,
                $params['recipient'] ?? '',
                $params['signature'] ?? '',
            );
            $resp = \call_user_func_array( array( $sii, 'validateDocumentSignature' ), $args );
            return is_array( $resp ) ? $resp : array( 'raw' => $resp );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'sii_boleta_ws_error', $e->getMessage() );
        }
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
