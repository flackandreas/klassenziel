<?php

declare(strict_types=1);

/**
 * Pruefliste einer Klasse: alles, worueber die Lehrkraft noch entscheiden
 * muss - oder mit ?alle=1 jeder Vorfall, um eine Entscheidung zu aendern.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Wertung\Bewertung;
use Klassenziel\Wertung\Kategoriezuordnung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$klasse = $kern->klasseAusPfad($konto);
$auswahl = isset($_GET['alle']) ? 'alle' : 'offen';

$repo = $kern->vorfaelle();
$zuordnung = new Kategoriezuordnung($kern->db());
$gewichtung = $kern->einstellungen()->gewichtung($klasse->id);

$vorfaelle = [];
foreach ($repo->fuerKlasse($klasse->id, $auswahl) as $vorfall) {
    $wirksam = Bewertung::wirksam($vorfall, $zuordnung);
    $original = $repo->originaltext($vorfall);

    $vorfaelle[] = $vorfall + [
        'wirksam' => $wirksam,
        'punkte' => $wirksam['zaehlt'] ? $gewichtung->fuer($wirksam['richtung'], (string) $wirksam['stufe']) : 0,
        // Nur hier, nur fuer die Lehrkraft, und auch hier nur gekuerzt.
        'original_kurz' => $original !== null ? mb_strimwidth((string) preg_replace('/\s+/u', ' ', $original), 0, 140, '…') : null,
    ];
}

$kern->zeichne('pruefliste.twig', [
    'konto' => $konto,
    'klasse' => $klasse,
    'vorfaelle' => $vorfaelle,
    'auswahl' => $auswahl,
    'offen' => $repo->offeneAnzahl($klasse->id),
]);
