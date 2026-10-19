<?php

declare(strict_types=1);

/**
 * Die oeffentliche Klassenseite hinter dem geheimen Link.
 *
 * Phase 1: Zugang, Ratenbremse, Schutzkoepfe und das gemeinsame 404 stehen;
 * der Health-Balken folgt in Phase 4.
 *
 * Keine Sitzung, kein Cookie. Wer die Seite oeffnet, hinterlaesst nur einen
 * Zaehler in der Ratenbremse - unter dem Hash seiner Adresse.
 */

use Klassenziel\Support\Anfrage;
use Klassenziel\Support\Kern;

/** @var Kern $kern */

header('X-Robots-Tag: noindex, nofollow');
// Der Link soll nicht als Referrer an eine andere Seite gehen, auch nicht an
// eine eigene: im Zugriffsprotokoll stuende er dann im Klartext.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$bremse = $kern->ratenbremse();
$absender = Anfrage::absender();

// Die Zaehler sollen nach einem Tag weg sein (so steht es in den
// Datenschutzhinweisen). Ohne eigenen Zeitplan: gelegentlich hier.
if (random_int(1, 200) === 1) {
    $bremse->raeumeAuf();
}

// Grosszuegig: im Schul-WLAN teilt sich eine ganze Klasse eine Adresse.
$anzeigeGrenze = 600;
$anzeigeFenster = 600;
// Fehlversuche mit unbekanntem Link: wer hier dreissigmal danebenliegt, sucht.
$fehlversuchGrenze = 30;
$fehlversuchFenster = 3600;

if ($bremse->ueberschritten('fehlversuch', $absender, $fehlversuchGrenze, $fehlversuchFenster)
    || !$bremse->erlaubt('anzeige', $absender, $anzeigeGrenze, $anzeigeFenster)) {
    http_response_code(429);
    header('Retry-After: 600');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Zu viele Anfragen. Bitte in einigen Minuten noch einmal versuchen.\n";
    exit;
}

$klasse = $kern->klassen()->nachLink($kern->parameter('token'));

if ($klasse === null) {
    $bremse->erlaubt('fehlversuch', $absender, $fehlversuchGrenze, $fehlversuchFenster);
    Kern::nichtGefunden();
}

$kern->zeichneOeffentlich('anzeige_vorlaeufig.twig', [
    'klasse' => $klasse,
]);
