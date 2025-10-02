<?php
namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Infrastructure\Rest\Api;

/**
 * Simple token manager that requests a new token from the SII API and caches it
 * for the duration of the request.
 */
class TokenManager {
    private Api $api;
    private Settings $settings;
    private array $cache = array();

    public function __construct( Api $api, Settings $settings ) {
        $this->api      = $api;
        $this->settings = $settings;
    }

    /**
     * Obtains a token for the given environment.
     */
    public function get_token( string $environment ): string {
        if ( isset( $this->cache[ $environment ] ) ) {
            return $this->cache[ $environment ];
        }
        // Prefer LibreDTE authentication when available and enabled; fallback to pseudo-token
        $token = '';
        if ( method_exists( $this->api, 'libredte_authenticate' ) ) {
            try {
                $token = (string) $this->api->libredte_authenticate( $environment );
            } catch ( \Throwable $e ) {
                $token = '';
            }
        }
        if ( '' === $token ) {
            $config    = $this->settings->get_settings();
            $cert_path = $config['cert_path'] ?? '';
            $cert_pass = $config['cert_pass'] ?? '';
            $token     = $this->api->generate_token( $environment, $cert_path, $cert_pass );
        }
        $this->cache[ $environment ] = $token;
        return $token;
    }
}

class_alias( TokenManager::class, 'SII_Boleta_Token_Manager' );
