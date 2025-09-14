<?php
namespace SiiBoletaDte\Infrastructure\WooCommerce;

use SiiBoletaDte\Infrastructure\TokenManager;

/**
 * Handle WooCommerce order events and send DTEs to SII.
 */
class Woo
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
     * Generate a DTE for a completed WooCommerce order.
     *
     * @param int $order_id WooCommerce order identifier.
     */
    public function handle_order_completed(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $data = ['Detalle' => []];

        foreach ($order->get_items() as $item) {
            $qty   = (int) $item->get_quantity();
            $total = (float) $item->get_total();

            $data['Detalle'][] = [
                'NmbItem'   => $item->get_name(),
                'QtyItem'   => $qty,
                'PrcItem'   => (int) round($qty > 0 ? $total / $qty : 0),
                'MontoItem' => (int) round($total),
            ];
        }

        $data['Detalles'] = $data['Detalle'];
        $data['Totales']  = [
            'MntTotal' => array_reduce(
                $data['Detalle'],
                static fn ($carry, $i) => $carry + (int) $i['MontoItem'],
                0
            ),
        ];

        $xml = $this->generate_dte_xml($data);

        $opts = (array) $this->settings->get_settings();
        $env  = $opts['environment'] ?? 'test';
        $token = $this->token_manager->get_token();

        $this->api->send_dte_to_sii($env, $token, $xml);
    }

    /**
     * Build XML representation of the DTE payload.
     */
    private function generate_dte_xml(array $data): string
    {
        // Placeholder; actual implementation would build valid DTE XML.
        return '<DTE></DTE>';
    }
}
