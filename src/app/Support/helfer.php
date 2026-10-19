<?php

declare(strict_types=1);

/**
 * Globale Hilfsfunktionen, von Composer geladen. Dieselben Namen wie im
 * Portal, damit niemand zwischen den Repositories umdenken muss.
 */

if (!function_exists('umgebung')) {
    /**
     * Liest einen Konfigurationswert. Ein leerer Wert gilt als nicht gesetzt,
     * damit ein leeres Feld in der .env den Vorgabewert nicht verdraengt.
     */
    function umgebung(string $name, ?string $vorgabe = null): ?string
    {
        $wert = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_scalar($wert) || $wert === false || $wert === '') {
            return $vorgabe;
        }

        return trim((string) $wert);
    }
}

if (!function_exists('umgebung_schalter')) {
    /** Wie umgebung(), liefert aber true/false fuer 1/0, ja/nein, true/false. */
    function umgebung_schalter(string $name, bool $vorgabe): bool
    {
        $wert = umgebung($name);
        if ($wert === null) {
            return $vorgabe;
        }

        return in_array(mb_strtolower($wert), ['1', 'true', 'ja', 'an', 'yes', 'on'], true);
    }
}
