<?php
namespace SiiBoletaDte\Application;

/**
 * Responsible for generating and sending the daily RVD summary.
 */
class RvdManager
{
    /** @var \SiiBoletaDte\Infrastructure\TokenManager */
    private $token_manager;
    /** @var \SII_Boleta_Settings */
    private $settings;
    /** @var \SII_Boleta_API */
    private $api;

    public function __construct($token_manager, $settings, $api)
    {
        $this->token_manager = $token_manager;
        $this->settings      = $settings;
        $this->api           = $api;
    }

    /**
     * Trigger the RVD process if needed.
     */
    public function maybe_run(): void
    {
        $env   = $this->get_environment();
        $token = $this->token_manager->get_token($env);
        $xml   = $this->generate_xml();

        $this->api->send_rvd_to_sii($env, $token, $xml);
    }

    /**
     * Build the RVD XML for the current day orders.
     */
    public function generate_xml(): string
    {
        $start  = strtotime('today');
        $end    = strtotime('tomorrow', $start) - 1;
        $orders = wc_get_orders([
            'limit'        => -1,
            'date_created' => [
                gmdate('Y-m-d H:i:s', $start),
                gmdate('Y-m-d H:i:s', $end),
            ],
        ]);

        $total = 0;
        foreach ($orders as $order) {
            $total += (float) $order->get_total();
        }

        return '<RVD><Total>' . round($total) . '</Total></RVD>';
    }

    private function get_environment(): string
    {
        $opts = (array) $this->settings->get_settings();
        return $opts['environment'] ?? 'test';
    }
}
