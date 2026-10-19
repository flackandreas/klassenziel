<?php

declare(strict_types=1);

/**
 * Eine Klasse anlegen.
 *
 * Jede Lehrkraft darf das. Wer anlegt, ist zustaendig; die Verwaltung sieht
 * alle Klassen und vergibt weitere Zustaendigkeiten.
 */

use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlasseExistiert;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$daten = Klassendaten::neu(new DateTimeImmutable('today'));

if (Kern::istPost()) {
    $kern->verlangeFormular();
    $daten = Klassendaten::ausEingabe($_POST);

    if ($daten->istGueltig()) {
        try {
            $id = $kern->klassen()->anlegen($daten, $konto);
            Sitzung::meldung('erfolg', 'Die Klasse ist angelegt. Den Link für Klasse und Eltern finden Sie unten.');
            Kern::weiterleiten('/klassen/' . $id);
        } catch (KlasseExistiert $e) {
            $daten->fehler['export_kuerzel'] = sprintf(
                'Für %s gibt es im Schuljahr %s schon eine Klasse. Die Verwaltung kann Sie dort als zuständig eintragen.',
                $e->exportKuerzel,
                $e->schuljahr
            );
        }
    }
}

$kern->zeichne('klasse_neu.twig', [
    'konto' => $konto,
    'daten' => $daten,
]);
