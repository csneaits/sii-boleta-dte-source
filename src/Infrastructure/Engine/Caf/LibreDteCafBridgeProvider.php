<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Caf;

use libredte\lib\Core\Package\Billing\Component\Document\Contract\TipoDocumentoInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafBagInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafFakerWorkerInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafLoaderWorkerInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Contract\CafProviderInterface;
use libredte\lib\Core\Package\Billing\Component\Identifier\Support\CafBag;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorInterface;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Bridge CafProvider that delegates to FoliosDb for real CAFs and uses
 * LibreDTE's faker in development. It also assigns a next folio when not
 * provided, persisting the last used value per environment/type.
 */
class LibreDteCafBridgeProvider implements CafProviderInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CafLoaderWorkerInterface $cafLoader,
        private readonly CafFakerWorkerInterface $cafFaker
    ) {
    }

    public function retrieve(
        EmisorInterface $emisor,
        TipoDocumentoInterface $tipoDocumento,
        ?int $folio = null
    ): CafBagInterface {
        $env = $this->settings->get_environment();
        $tipo = (int) $tipoDocumento->getCodigo();

        // Development: always fake CAF/folio.
        if ($env === '2') {
            // En desarrollo, el faker recibe como parámetro el primer folio del rango,
            // por lo tanto calculamos el siguiente disponible a partir del último folio usado.
            $last = Settings::get_last_folio_value($tipo, $env);
            // Si se entrega folio explícito, trátalo como el siguiente a usar ⇒ base = folio-1.
            $base = ($folio !== null && $folio > 0) ? ($folio - 1) : ($last > 0 ? $last : 0);
            $next = $base + 1;

            // Genera un CAF falso cuyo rango comience en el folio calculado.
            $bag = $this->cafFaker->create($emisor, $tipo, $next, $next + 10);
            // Entregar una ventana de folios empezando en el siguiente calculado.
            $folios = range($next, $next + 10);
            if ($bag instanceof CafBag) { $bag = $bag->setFoliosDisponibles($folios); }
            elseif (method_exists($bag, 'setFoliosDisponibles')) { $bag->setFoliosDisponibles($folios); }
            // Persistimos el primer folio disponible para mantener un avance +1 estable entre llamadas.
            $observed = (int) ($folios[0] ?? $next);
            Settings::update_last_folio_value($tipo, $env, $observed);
            return $bag;
        }

        // Certification/Production: use FoliosDb to pick CAF and a valid folio.
        $ranges = FoliosDb::for_type($tipo, $env);
        $cafXml = '';
        $finalFolio = $folio ?? 0;

        // Determine folio if not provided.
        if ($finalFolio <= 0) {
            $last = Settings::get_last_folio_value($tipo, $env);
            $finalFolio = $this->nextFolioFromRanges($ranges, $last);
        }

        // Ensure folio belongs to a known range; if not, pick first valid.
        $range = FoliosDb::find_for_folio($tipo, $finalFolio, $env);
        if (!$range) {
            // pick start of first range
            if (!empty($ranges)) {
                $first = $ranges[0];
                $finalFolio = (int) ($first['desde'] ?? 1);
                $range = $first;
            }
        }

        if ($range) {
            // Accept either array key 'caf' (memory) or 'caf_xml' (DB)
            $cafXml = (string) ($range['caf'] ?? ($range['caf_xml'] ?? ''));
        }

    if ($cafXml === '') {
            // Fallback to first range that has CAF
            foreach ($ranges as $r) {
                $cafXml = (string) ($r['caf'] ?? ($r['caf_xml'] ?? ''));
                if ($cafXml !== '') {
                    // Clamp folio into this range if needed
                    $desde = (int) ($r['desde'] ?? 1);
                    $finalFolio = max($finalFolio, $desde);
                    break;
                }
            }
        }

    if ($cafXml === '') {
            // As a last resort, fake a CAF to avoid hard failures (mainly for cert without CAF uploaded).
            // Ensure advancement between consecutive calls without consuming here.
            $last = Settings::get_last_folio_value($tipo, $env);
            // Para el faker, el parámetro es la base (último usado). Si llega un folio, úsalo como siguiente ⇒ base = folio-1.
            $base = ($finalFolio > 0) ? ($finalFolio - 1) : ($last > 0 ? $last : 0);
            $next = $base + 1;
            $bag = $this->cafFaker->create($emisor, $tipo, $next, $next + 10);
            $folios = range($next, $next + 10);
            if ($bag instanceof CafBag) { $bag = $bag->setFoliosDisponibles($folios); }
            elseif (method_exists($bag, 'setFoliosDisponibles')) { $bag->setFoliosDisponibles($folios); }
            $observed = (int) ($folios[0] ?? $next);
            Settings::update_last_folio_value($tipo, $env, $observed);
            return $bag;
        }

        // Load CAF and build bag; set available folios to control next selection.
        $bag = $this->cafLoader->load($cafXml);
        $desde = (int) (($range['desde'] ?? null) ?? 1);
        $hasta = (int) (($range['hasta'] ?? null) ?? max($desde, $finalFolio + 1));

        // Folios in LibreDTE are inclusive; FoliosDb find_for_folio used < hasta, so include $hasta too.
        $available = range($finalFolio, $hasta);
        if ($finalFolio < $desde) {
            $available = range($desde, $hasta);
        }
        $bag = $this->decorateBagWithContext($bag, $emisor, $tipoDocumento, $available);

        // Persist advancement when folio was auto selected.
        Settings::update_last_folio_value($tipo, $env, $finalFolio);

        return $bag;
    }

    private function nextFolioFromRanges(array $ranges, int $last): int
    {
        if (empty($ranges)) {
            return max(1, $last + 1);
        }

        if ($last <= 0) {
            return (int) ($ranges[0]['desde'] ?? 1);
        }

        // If last belongs to a range, use next; else, jump to next range start.
        foreach ($ranges as $r) {
            $desde = (int) ($r['desde'] ?? 1);
            $hasta = (int) ($r['hasta'] ?? ($desde + 1));
            if ($last >= $desde && $last < $hasta) {
                $next = $last + 1;
                if ($next < $hasta) {
                    return $next;
                }
            }
            if ($last < $desde) {
                return $desde;
            }
        }

        // After last range, start at last known 'hasta' to avoid 0.
        $end = (int) (end($ranges)['hasta'] ?? 1);
        return max(1, $end);
    }

    private function decorateBagWithContext(CafBagInterface $bag, EmisorInterface $emisor, TipoDocumentoInterface $tipo, array $folios): CafBagInterface
    {
        // Ensure the resulting bag is a Support\CafBag when possible, else set folios if the interface supports it.
        if ($bag instanceof CafBag) {
            return $bag->setFoliosDisponibles($folios);
        }
        if (method_exists($bag, 'setFoliosDisponibles')) {
            $bag->setFoliosDisponibles($folios);
        }
        return $bag;
    }
}
