<?php

declare(strict_types=1);

/**
 * Gewichtung einer Klasse: Stufenpunkte, Wochenbonus, Kategorie-Zuordnung -
 * mit Simulation vor dem Speichern.
 *
 * Die Simulation ist Pflicht, nicht Kuer: Mit den Startwerten ergab der erste
 * echte Export der 9d rund -46 Punkte aus 30 Vorfaellen in fuenf Wochen. Ohne
 * Anpassung stuende die Klasse kurz nach dem Start bei 0. Deshalb gibt es
 * "Speichern" erst, nachdem der Verlauf mit den neuen Werten zu sehen war.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;
use Klassenziel\Wertung\Gewichtung;
use Klassenziel\Wertung\Verlaufsgrafik;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$klasse = $kern->klasseAusPfad($konto);
$einstellungen = $kern->einstellungen();
$standdienst = $kern->standdienst();
$heute = new DateTimeImmutable('today');

$bisherGewichtung = $einstellungen->gewichtung($klasse->id);
$bisherZuordnungen = $einstellungen->zuordnungen($klasse->id);
$kategorien = $einstellungen->kategorien($klasse->id);
$bisher = $standdienst->wertung($klasse, $heute);

$auswahl = static fn (?array $z): string => $z === null ? ''
    : (in_array($z['richtung'], ['positiv', 'negativ'], true) ? $z['richtung'] . ':' . $z['stufe'] : $z['richtung']);

$eingabe = [
    'klein' => (string) $bisherGewichtung->klein,
    'mittel' => (string) $bisherGewichtung->mittel,
    'gross' => (string) $bisherGewichtung->gross,
    'wochenbonus' => (string) $bisherGewichtung->wochenbonus,
    'kategorien' => [],
];
foreach ($kategorien as $k) {
    $eingabe['kategorien'][$k['kategorie']] = $auswahl($bisherZuordnungen[$k['kategorie']] ?? null);
}

$fehler = [];
$simulation = null;

if (Kern::istPost() && !$klasse->istArchiviert()) {
    $kern->verlangeFormular();

    [$gewichtung, $fehler] = Gewichtung::ausEingabe($_POST);
    foreach (['klein', 'mittel', 'gross', 'wochenbonus'] as $feld) {
        $eingabe[$feld] = is_string($_POST[$feld] ?? null) ? trim($_POST[$feld]) : '';
    }

    // Nur Kategorien, die es fuer diese Klasse gibt - ein Formular kann
    // sonst beliebige Schluessel mitschicken.
    $gesendet = is_array($_POST['kategorie'] ?? null) ? $_POST['kategorie'] : [];
    $namen = is_array($_POST['kategorie_name'] ?? null) ? $_POST['kategorie_name'] : [];
    $erlaubt = array_column($kategorien, 'kategorie');
    $zuordnungen = [];

    foreach ($namen as $index => $name) {
        if (!is_string($name) || !in_array($name, $erlaubt, true)) {
            continue;
        }
        $wert = is_string($gesendet[$index] ?? null) ? $gesendet[$index] : '';
        $eingabe['kategorien'][$name] = $wert;

        if ($wert === '') {
            continue;
        }
        if (preg_match('/^(positiv|negativ):(klein|mittel|gross)$/', $wert, $teile) === 1) {
            $zuordnungen[$name] = ['richtung' => $teile[1], 'stufe' => $teile[2]];
        } elseif (in_array($wert, ['neutral', 'pruefen'], true)) {
            $zuordnungen[$name] = ['richtung' => $wert, 'stufe' => null];
        } else {
            $fehler['kategorie'] = 'Eine Kategorie hat einen unbekannten Wert.';
        }
    }

    // Gespeichert wird nur, was in der Vorschau zu sehen war: der Knopf traegt
    // eine Pruefsumme der simulierten Werte. Hat jemand danach noch etwas
    // geaendert, gibt es zuerst die Vorschau dazu.
    $pruefsumme = $gewichtung !== null
        ? hash('sha256', json_encode([$gewichtung->klein, $gewichtung->mittel, $gewichtung->gross, $gewichtung->wochenbonus, $zuordnungen]) ?: '')
        : '';
    $eingabe['pruefsumme'] = $pruefsumme;

    if ($gewichtung !== null && $fehler === []) {
        $gesehen = is_string($_POST['gesehen'] ?? null) && hash_equals($pruefsumme, $_POST['gesehen']);

        if (($_POST['aktion'] ?? '') === 'speichern' && !$gesehen) {
            $fehler['vorschau'] = 'Die Werte haben sich seit der Vorschau geändert. Hier ist die Vorschau dazu – bitte noch einmal prüfen.';
        }

        if (($_POST['aktion'] ?? '') === 'speichern' && $gesehen) {
            $einstellungen->speichereGewichtung($klasse->id, $gewichtung);
            foreach ($erlaubt as $name) {
                $neu = $zuordnungen[$name] ?? null;
                if ($auswahl($neu) !== $auswahl($bisherZuordnungen[$name] ?? null)) {
                    $einstellungen->setzeZuordnung($klasse->id, $name, $neu['richtung'] ?? null, $neu['stufe'] ?? null);
                }
            }

            Sitzung::meldung('erfolg', 'Gespeichert. Der Punktestand ist neu berechnet – auch rückwirkend.');
            Kern::weiterleiten('/klassen/' . $klasse->id . '/gewichtung');
        }

        $simulation = $standdienst->simuliere($klasse, $heute, $gewichtung, $zuordnungen);
    }
}

$reihen = [];
if ($simulation !== null) {
    $reihen[] = ['name' => 'bisher', 'farbe' => Verlaufsgrafik::FARBE_BISHER, 'punkte' => $bisher->verlauf];
    $reihen[] = ['name' => 'neu', 'farbe' => Verlaufsgrafik::FARBE_NEU, 'punkte' => $simulation->verlauf];
} else {
    $reihen[] = ['name' => 'aktuell', 'farbe' => Verlaufsgrafik::FARBE_NEU, 'punkte' => $bisher->verlauf];
}

$kern->zeichne('gewichtung.twig', [
    'konto' => $konto,
    'klasse' => $klasse,
    'eingabe' => $eingabe,
    'fehler' => $fehler,
    'kategorien' => $kategorien,
    'bisher' => $bisher,
    'simulation' => $simulation,
    'reihen' => $reihen,
    'grafik' => Verlaufsgrafik::svg(
        $reihen,
        $klasse->zielwert,
        'Verlauf des Punktestands ' . ($simulation !== null ? 'bisher und mit den neuen Werten' : 'mit den aktuellen Werten')
    ),
]);
