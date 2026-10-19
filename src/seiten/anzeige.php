<?php

declare(strict_types=1);

/**
 * Die oeffentliche Klassenseite hinter dem geheimen Link: der Health-Balken.
 *
 * ?ansicht=beamer erzwingt das Beamer-Layout (1280x720 bis 1920x1080, ohne
 * Scrollen). Ohne Parameter waehlt das Stylesheet nach der Bildschirmgroesse.
 * ?eintraege=40 zeigt mehr Eintraege - der Weg fuer "Weitere laden" ohne
 * JavaScript.
 */

use Klassenziel\Anzeige\Anzeigedaten;
use Klassenziel\Anzeige\Zugang;
use Klassenziel\Support\Kern;

/** @var Kern $kern */

$klasse = Zugang::klasse($kern);

$beamer = ($_GET['ansicht'] ?? '') === 'beamer';
$anzahl = $beamer
    ? Anzeigedaten::EINTRAEGE_BEAMER
    : Zugang::zahl('eintraege', Anzeigedaten::EINTRAEGE_HANDY, 1, Anzeigedaten::EINTRAEGE_HOECHSTENS);

$kern->zeichneOeffentlich('anzeige.twig', [
    'anzeige' => $kern->anzeigedienst()->fuer($klasse, new DateTimeImmutable('today'), $anzahl),
    'beamer' => $beamer,
    'anzahl' => $anzahl,
    // Fuer die Animation "vom alten zum neuen Wert": unter diesem Merker
    // legt das Geraet den zuletzt gesehenen Stand ab. Die Kennung der
    // Klasse ist kein Geheimnis; der Link waere eines.
    'merker' => 'klassenziel-' . $klasse->id,
]);
