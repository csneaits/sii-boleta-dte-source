<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Caf;

use libredte\lib\Core\Package\Billing\Component\Identifier\Support\CafBag;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;

interface CafProviderInterface {
    /**
     * @throws CafResolutionException
     */
    public function resolve(
        int $tipo,
        int $folio,
        bool $preview,
        Emisor $emisor,
        string $environment
    ): CafBag;
}
