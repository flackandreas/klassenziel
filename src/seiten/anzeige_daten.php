<?php

declare(strict_types=1);

/**
 * Die Daten der Klassenseite als JSON - fuer die Aktualisierung alle fuenf
 * Minuten und fuer "Weitere laden". Dieselben Felder wie die Seite, aus
 * demselben Ansichtsmodell.
 */

use Klassenziel\Anzeige\Anzeigedaten;
use Klassenziel\Anzeige\Zugang;
use Klassenziel\Support\Kern;

/** @var Kern $kern */

$klasse = Zugang::klasse($kern);

$daten = $kern->anzeigedienst()->fuer(
    $klasse,
    new DateTimeImmutable('today'),
    Zugang::zahl('anzahl', Anzeigedaten::EINTRAEGE_HANDY, 1, Anzeigedaten::EINTRAEGE_HOECHSTENS),
    Zugang::zahl('versatz', 0, 0, 10000)
);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
