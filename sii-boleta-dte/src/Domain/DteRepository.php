<?php

namespace Sii\BoletaDte\Domain;

/**
 * Puerto de persistencia para los DTE.
 */
interface DteRepository {
    /**
     * Guarda un DTE en el almacenamiento subyacente.
     */
    public function save( Dte $dte ): void;
}
