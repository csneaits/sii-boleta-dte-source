<?php
namespace SiiBoletaDte\Application;

/**
 * Persistent queue processor for DTE and Libro jobs.
 */
class Queue
{
    public const OPTION_NAME = 'sii_boleta_queue';
    public const CRON_HOOK   = 'sii_boleta_dte_process_queue';

    /** @var object */
    private $api;

    /**
     * Constructor.
     *
     * @param object $api API client with send_dte_to_sii and send_libro_to_sii methods.
     */
    public function __construct($api)
    {
        $this->api = $api;
        if (function_exists('add_action')) {
            \add_action(self::CRON_HOOK, [ $this, 'process' ]);
        }
    }

    /**
     * Enqueue a job for later processing.
     *
     * @param array{type:string,args:array} $job Job definition.
     */
    public function enqueue(array $job): void
    {
        $queue   = \get_option(self::OPTION_NAME, []);
        $queue[] = $job;
        \update_option(self::OPTION_NAME, $queue);
    }

    /**
     * Process queued jobs.
     */
    public function process(): void
    {
        $queue  = \get_option(self::OPTION_NAME, []);

        while ($job = \array_shift($queue)) {
            $result = null;

            if ('dte' === ($job['type'] ?? '')) {
                $result = $this->api->send_dte_to_sii(...($job['args'] ?? []));
            } elseif ('libro' === ($job['type'] ?? '')) {
                $result = $this->api->send_libro_to_sii(...($job['args'] ?? []));
            }

            if (\is_wp_error($result)) {
                // Requeue failed job for retry on next run.
                $queue[] = $job;
            }
        }

        \update_option(self::OPTION_NAME, $queue);
    }
}
