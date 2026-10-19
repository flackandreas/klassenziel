<?php

declare(strict_types=1);

/**
 * Einstellungen fuer die ganze Schule - ein Wegweiser.
 */

use Klassenziel\Support\Kern;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$stammdaten = $kern->stammdaten();

$kern->zeichne('verwaltung.twig', [
    'konto' => $konto,
    'ferien' => count($stammdaten->ferien()),
    'faecher_offen' => count(array_filter($stammdaten->faecher(), static fn (array $f): bool => $f['klartext'] === null)),
    'kategorien_offen' => count(array_filter($stammdaten->kategorieVorgaben(), static fn (array $k): bool => $k['richtung'] === 'pruefen')),
]);
