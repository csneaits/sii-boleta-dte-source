<?php
namespace SiiBoletaDte\Infrastructure\WooCommerce;

/**
 * WooCommerce integration helper to generate and send DTEs.
 */
class Woo
{
    /** @var \SII_Boleta_Settings */
    private $settings;
    /** @var mixed */
    private $engine;
    /** @var \SiiBoletaDte\Infrastructure\TokenManager */
    private $token_manager;
    /** @var \SII_Boleta_API */
    private $api;

    public function __construct($settings, $engine, $token_manager, $api)
    {
        $this->settings      = $settings;
        $this->engine        = $engine;
        $this->token_manager = $token_manager;
        $this->api           = $api;
    }

    /**
     * Generate and send a DTE when an order is completed.
     */
    public function handle_order_completed($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $env   = $this->get_environment();
        $token = $this->token_manager->get_token($env);

        $data = [
            'Detalles' => [],
        ];

        foreach ($order->get_items() as $item) {
            $qty        = $item->get_quantity();
            $line_total = (float) $item->get_total();
            $price      = $qty > 0 ? $line_total / $qty : 0;
            $data['Detalles'][] = [
                'NmbItem'   => $item->get_name(),
                'QtyItem'   => $qty,
                'PrcItem'   => $price,
                'MontoItem' => round($line_total),
            ];
        }

        $xml = $this->engine->generate_dte_xml($data, 39, false);

        $this->api->send_dte_to_sii($env, $token, $xml);
    }

    private function get_environment(): string
    {
        $opts = (array) $this->settings->get_settings();
        return $opts['environment'] ?? 'test';
    }
}
