<?php

declare(strict_types=1);

/**
 * Fachkuerzel aus dem Export und ihr Klartext. Neue Kuerzel traegt der Import
 * von selbst ein; ohne Klartext zeigt die Klassenseite das Kuerzel.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$stammdaten = $kern->stammdaten();

if (Kern::istPost()) {
    $kern->verlangeFormular();

    $klartexte = [];
    $kuerzel = array_column($stammdaten->faecher(), 'kuerzel');
    foreach (is_array($_POST['klartext'] ?? null) ? $_POST['klartext'] : [] as $schluessel => $wert) {
        if (in_array((string) $schluessel, $kuerzel, true) && is_string($wert)) {
            $klartexte[(string) $schluessel] = $wert;
        }
    }

    $stammdaten->setzeFaecher($klartexte);
    Sitzung::meldung('erfolg', 'Gespeichert.');
    Kern::weiterleiten('/verwaltung/faecher');
}

$kern->zeichne('verwaltung_faecher.twig', [
    'konto' => $konto,
    'faecher' => $stammdaten->faecher(),
]);
