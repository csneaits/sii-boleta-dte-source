<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Caf;

use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafLoaderWorkerInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Support\CafBag;
use libredte\lib\Core\Package\Billing\Component\Identifier\Worker\CafFakerWorker;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

class LibreDteCafProvider implements CafProviderInterface {
    public function __construct(
        private readonly CafLoaderWorkerInterface $cafLoader,
        private readonly CafFakerWorker $cafFaker
    ) {
    }

    public function resolve(
        int $tipo,
        int $folio,
        bool $preview,
        Emisor $emisor,
        string $environment
    ): CafBag {
        $cafXml = '';
        if (!$preview) {
            if ($folio > 0) {
                $range = FoliosDb::find_for_folio($tipo, $folio, $environment);
                if ($range && !empty($range['caf'])) {
                    $cafXml = (string) $range['caf'];
                }
            }

            if ('' === $cafXml) {
                foreach (FoliosDb::for_type($tipo, $environment) as $row) {
                    if (!empty($row['caf'])) {
                        $cafXml = (string) $row['caf'];
                        break;
                    }
                }
            }
        }

        if ($preview || '' === trim($cafXml)) {
            return $this->cafFaker->create($emisor, $tipo, $folio);
        }

        $hadProvidedCaf = '' !== trim($cafXml);

        try {
            return $this->cafLoader->load($cafXml);
        } catch (\Throwable $e) {
            throw new CafResolutionException('Invalid CAF', $hadProvidedCaf, $cafXml, $e);
        }
    }
}
