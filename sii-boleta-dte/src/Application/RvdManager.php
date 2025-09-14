<?php
namespace SiiBoletaDte\Application;

use SiiBoletaDte\Infrastructure\TokenManager;

/**
 * Manage generation and transmission of the daily sales report (RVD).
 */
class RvdManager
{
    /** @var \SII_Boleta_Settings */
    private $settings;
    /** @var \SII_Boleta_API */
    private $api;
    /** @var TokenManager */
    private $token_manager;

    public function __construct($settings, $api, TokenManager $token_manager)
    {
        $this->settings      = $settings;
        $this->api           = $api;
        $this->token_manager = $token_manager;
    }

    /**
     * Entry point for the cron hook to submit the RVD to SII.
     */
    public function maybe_run(): void
    {
        $opts = (array) $this->settings->get_settings();
        $env  = $opts['environment'] ?? 'test';

        $token = $this->token_manager->get_token();
        $xml   = $this->generate_xml();

        $this->api->send_rvd_to_sii($env, $token, $xml);
    }

    /**
     * Generate RVD XML using totals from today's WooCommerce orders.
     */
    public function generate_xml(): string
    {
        $start = new \WC_DateTime('today', new \DateTimeZone('UTC'));
        $end   = new \WC_DateTime('tomorrow', new \DateTimeZone('UTC'));

        $orders = wc_get_orders([
            'limit'        => -1,
            'status'       => ['completed'],
            'date_created' => $start->format('Y-m-d H:i:s') . '...' . $end->format('Y-m-d H:i:s'),
        ]);

        $total = 0.0;
        foreach ($orders as $order) {
            $total += (float) $order->get_total();
        }

        return '<RVD><MntTotal>' . (int) round($total) . '</MntTotal></RVD>';
    }
}
