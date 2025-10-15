<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Certificate;

use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;

interface CertificateProviderInterface {
    /**
     * @param array<string,mixed> $settings
     */
    public function resolve(array $settings, Emisor $emisor): object;
}
