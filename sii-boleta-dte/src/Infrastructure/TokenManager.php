<?php
namespace SiiBoletaDte\Infrastructure;

/**
 * Handle authentication token retrieval using configured certificate credentials.
 */
class TokenManager
{
    /** @var \SII_Boleta_Settings */
    private $settings;
    /** @var \SII_Boleta_API */
    private $api;

    public function __construct($settings, $api)
    {
        $this->settings = $settings;
        $this->api      = $api;
    }

    /**
     * Request a token from SII using stored certificate credentials.
     */
    public function get_token(): string
    {
        $opts     = (array) $this->settings->get_settings();
        $env      = $opts['environment'] ?? 'test';
        $certPath = $opts['cert_path'] ?? '';
        $certPass = $opts['cert_pass'] ?? '';

        if ('' !== $certPass) {
            $decoded = base64_decode((string) $certPass, true);
            if (false !== $decoded) {
                $certPass = $decoded;
            }
        }

        return $this->api->generate_token($env, $certPath, $certPass);
    }
}
