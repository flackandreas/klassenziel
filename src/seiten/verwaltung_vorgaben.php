<?php

declare(strict_types=1);

/**
 * Vorgaben der Schule - nur fuer die Verwaltung.
 *
 * Die Punkte gelten fuer Klassen, die danach angelegt werden; laufende
 * Klassen behalten ihre. Die Kategorie-Vorgaben gelten fuer jede Klasse ohne
 * eigene Zuordnung, auch rueckwirkend - etwa wenn die Schule in WebUntis
 * eine Kategorie "Lob" einfuehrt.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;
use Klassenziel\Wertung\Gewichtung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
if (!$konto->istVerwaltung) {
    Kern::nichtGefunden();
}

$stammdaten = $kern->stammdaten();
$vorgaben = $stammdaten->vorgaben();
$fehler = [];
$eingabe = [
    'klein' => (string) $vorgaben->klein,
    'mittel' => (string) $vorgaben->mittel,
    'gross' => (string) $vorgaben->gross,
    'wochenbonus' => (string) $vorgaben->wochenbonus,
];

if (Kern::istPost()) {
    $kern->verlangeFormular();

    if (($_POST['aktion'] ?? '') === 'kategorien') {
        $erlaubt = array_column($stammdaten->kategorieVorgaben(), 'webuntis_kategorie');
        $namen = is_array($_POST['kategorie_name'] ?? null) ? $_POST['kategorie_name'] : [];
        $werte = is_array($_POST['kategorie'] ?? null) ? $_POST['kategorie'] : [];

        foreach ($namen as $index => $name) {
            if (!is_string($name) || !in_array($name, $erlaubt, true) || !is_string($werte[$index] ?? null)) {
                continue;
            }
            [$richtung, $stufe] = array_pad(explode(':', $werte[$index], 2), 2, null);
            $stammdaten->setzeKategorieVorgabe($name, (string) $richtung, $stufe);
        }

        Sitzung::meldung('erfolg', 'Gespeichert. Klassen ohne eigene Zuordnung sind neu berechnet.');
        Kern::weiterleiten('/verwaltung/vorgaben');
    }

    [$gewichtung, $fehler] = Gewichtung::ausEingabe($_POST);
    foreach (array_keys($eingabe) as $feld) {
        $eingabe[$feld] = is_string($_POST[$feld] ?? null) ? trim($_POST[$feld]) : '';
    }

    if ($gewichtung !== null) {
        $stammdaten->setzeVorgaben($gewichtung);
        Sitzung::meldung('erfolg', 'Gespeichert. Gilt für Klassen, die ab jetzt angelegt werden.');
        Kern::weiterleiten('/verwaltung/vorgaben');
    }
}

$kern->zeichne('verwaltung_vorgaben.twig', [
    'konto' => $konto,
    'eingabe' => $eingabe,
    'fehler' => $fehler,
    'kategorien' => $stammdaten->kategorieVorgaben(),
]);
