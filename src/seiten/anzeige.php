<?php

declare(strict_types=1);

/**
 * Die oeffentliche Klassenseite hinter dem geheimen Link: der Punktestand als
 * Linie ueber das Schuljahr.
 *
 * ?ansicht=beamer erzwingt das Beamer-Layout (1280x720 bis 1920x1080, ohne
 * Scrollen). Ohne Parameter waehlt das Stylesheet nach der Bildschirmgroesse.
 * ?eintraege=40 zeigt mehr Eintraege - der Weg fuer "Weitere laden" ohne
 * JavaScript.
 */

use Klassenziel\Anzeige\Anzeigedaten;
use Klassenziel\Anzeige\Verlaufsbild;
use Klassenziel\Anzeige\Zugang;
use Klassenziel\Support\Kern;

/** @var Kern $kern */

$klasse = Zugang::klasse($kern);

$beamer = ($_GET['ansicht'] ?? '') === 'beamer';
$anzahl = $beamer
    ? Anzeigedaten::EINTRAEGE_BEAMER
    : Zugang::zahl('eintraege', Anzeigedaten::EINTRAEGE_HANDY, 1, Anzeigedaten::EINTRAEGE_HOECHSTENS);

$anzeige = $kern->anzeigedienst()->fuer($klasse, new DateTimeImmutable('today'), $anzahl);

$kern->zeichneOeffentlich('anzeige.twig', [
    'anzeige' => $anzeige,
    'verlaufsbild' => [
        'beamer' => Verlaufsbild::fuer($anzeige['verlauf'], $anzeige['ziel'], $anzeige['stimmung']['etappen'], 'beamer'),
        'handy' => Verlaufsbild::fuer($anzeige['verlauf'], $anzeige['ziel'], $anzeige['stimmung']['etappen'], 'handy'),
    ],
    'beamer' => $beamer,
    'anzahl' => $anzahl,
    // Unter diesem Merker legt das Geraet den zuletzt gesehenen Stand ab -
    // daran erkennt das Skript, welche Smiley-Etappe seitdem neu erreicht
    // ist. Die Kennung der Klasse ist kein Geheimnis; der Link waere eines.
    'merker' => 'klassenziel-' . $klasse->id,
]);
