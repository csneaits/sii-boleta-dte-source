<?php
namespace SiiBoletaDte\Application;

use SII_Boleta_Settings;

/**
 * Handles generation and scheduled sending of the Resumen de Ventas Diarias (RVD).
 */
class RvdManager
{
    public const CRON_HOOK = 'sii_boleta_dte_daily_rvd';

    /** @var object */
    private $api;

    /** @var object */
    private $token_manager;

    /** @var SII_Boleta_Settings */
    private SII_Boleta_Settings $settings;

    /**
     * @param object              $api            API client with send_rvd_to_sii method.
     * @param object              $token_manager  Token manager with get_token method.
     * @param SII_Boleta_Settings $settings       Plugin settings provider.
     */
    public function __construct($api, $token_manager, SII_Boleta_Settings $settings)
    {
        $this->api           = $api;
        $this->token_manager = $token_manager;
        $this->settings      = $settings;

        if (function_exists('add_action')) {
            \add_action(self::CRON_HOOK, [ $this, 'maybe_run' ]);
        }
    }

    /**
     * Cron callback that generates the RVD XML and sends it to SII.
     */
    public function maybe_run(): void
    {
        $config      = $this->settings->get_settings();
        $environment = $config['environment'] ?? 'test';

        $token = $this->token_manager->get_token($environment);
        $xml   = $this->generate_xml();

        if ($xml && !\is_wp_error($token)) {
            $this->api->send_rvd_to_sii($xml, $environment, $token);
        }
    }

    /**
     * Generate RVD XML for the current day orders.
     */
    public function generate_xml(): string
    {
        $tz    = \function_exists('wp_timezone') ? \wp_timezone() : new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable('today', $tz))->setTime(0, 0, 0);
        $end   = $start->setTime(23, 59, 59);

        $orders = \wc_get_orders([
            'limit'        => -1,
            'status'       => [ 'completed', 'processing' ],
            'date_created' => $start->format('Y-m-d H:i:s') . '...' . $end->format('Y-m-d H:i:s'),
        ]);

        $total = 0;
        foreach ($orders as $order) {
            $total += (int) $order->get_total();
        }

        $doc  = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('RVD');
        $root->appendChild($doc->createElement('Total', (string) $total));
        $doc->appendChild($root);

        return $doc->saveXML();
    }
}
