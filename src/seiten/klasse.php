<?php

declare(strict_types=1);

/**
 * Eine Klasse: Angaben, geheimer Link, Zustaendigkeit, Archivieren.
 */

use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlasseExistiert;
use Klassenziel\Support\Anfrage;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$klassen = $kern->klassen();
$id = (int) $kern->parameter('id');

$klasse = $klassen->fuer($konto, $id);
if ($klasse === null) {
    // Fremde und erfundene Klassen sehen gleich aus.
    Kern::nichtGefunden();
}

$daten = Klassendaten::ausKlasse($klasse);

if (Kern::istPost()) {
    $kern->verlangeFormular();

    if ($klasse->istArchiviert()) {
        Sitzung::meldung('fehler', 'Eine archivierte Klasse lässt sich nicht mehr ändern.');
        Kern::weiterleiten('/klassen/' . $id);
    }

    switch ((string) ($_POST['aktion'] ?? '')) {
        case 'speichern':
            $daten = Klassendaten::ausEingabe($_POST);
            if (!$daten->istGueltig()) {
                break;
            }

            try {
                $klassen->speichern($id, $daten);
                Sitzung::meldung('erfolg', 'Gespeichert.');
                Kern::weiterleiten('/klassen/' . $id);
            } catch (KlasseExistiert $e) {
                $daten->fehler['export_kuerzel'] = sprintf(
                    'Für %s gibt es im Schuljahr %s schon eine andere Klasse.',
                    $e->exportKuerzel,
                    $e->schuljahr
                );
            }
            break;

        case 'link_neu':
            $klassen->neuerLink($id);
            Sitzung::meldung('erfolg', 'Neuer Link erzeugt. Der bisherige gilt ab sofort nicht mehr.');
            Kern::weiterleiten('/klassen/' . $id . '#link');

        case 'zustaendig_hinzu':
            if (!$konto->istVerwaltung) {
                Kern::nichtGefunden();
            }
            $neu = $kern->konten()->nachId((int) ($_POST['konto_id'] ?? 0));
            if ($neu !== null) {
                $klassen->setzeZustaendig($id, $neu->id);
                Sitzung::meldung('erfolg', $neu->name . ' ist jetzt zuständig.');
            }
            Kern::weiterleiten('/klassen/' . $id . '#zustaendig');

        case 'zustaendig_weg':
            if (!$konto->istVerwaltung) {
                Kern::nichtGefunden();
            }
            if (!$klassen->entferneZustaendig($id, (int) ($_POST['konto_id'] ?? 0))) {
                Sitzung::meldung('fehler', 'Mindestens eine Lehrkraft muss zuständig bleiben.');
            }
            Kern::weiterleiten('/klassen/' . $id . '#zustaendig');

        case 'namensliste':
            $anzahl = $kern->namensliste()->ersetze($id, is_string($_POST['namen'] ?? null) ? $_POST['namen'] : '');
            Sitzung::meldung('erfolg', $anzahl === 0
                ? 'Die Namensliste ist leer.'
                : $anzahl . ' Namensteile hinterlegt – nur als Prüfsumme, die Namen selbst sind nicht gespeichert.');
            Kern::weiterleiten('/klassen/' . $id . '#namensliste');

        case 'archivieren':
            if (($_POST['bestaetigt'] ?? '') !== $klasse->exportKuerzel) {
                Sitzung::meldung('fehler', 'Zum Archivieren bitte das Exportkürzel der Klasse eintippen.');
                Kern::weiterleiten('/klassen/' . $id . '#archivieren');
            }
            $klassen->archivieren($id);
            Sitzung::meldung('erfolg', $klasse->anzeigename . ' ist archiviert. Der Link gilt nicht mehr, die Originaltexte sind gelöscht.');
            Kern::weiterleiten('/');

        default:
            $kern->fehlerseite('Unbekannte Aktion', 'Diese Aktion gibt es nicht.', 400, '/klassen/' . $id);
    }
}

$link = $klasse->istArchiviert() ? null : $klassen->link($klasse);
$zustaendige = $klassen->zustaendige($id);
$zustaendigeIds = array_column($zustaendige, 'id');

$kern->zeichne('klasse.twig', [
    'konto' => $konto,
    'klasse' => $klasse,
    'daten' => $daten,
    'wertung' => $kern->standdienst()->wertung($klasse, new DateTimeImmutable('today')),
    'offen' => $kern->vorfaelle()->offeneAnzahl($id),
    'namensliste' => $kern->namensliste()->anzahl($id),
    'link' => $link !== null ? Anfrage::basis() . '/klassenziel/' . $link : null,
    'zustaendige' => $zustaendige,
    'weitere_konten' => $konto->istVerwaltung
        ? array_values(array_filter(
            $kern->konten()->alle(),
            static fn ($k): bool => !in_array($k->id, $zustaendigeIds, true)
        ))
        : [],
]);
