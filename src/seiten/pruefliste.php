<?php

declare(strict_types=1);

/**
 * Pruefliste einer Klasse: alles, worueber die Lehrkraft noch entscheiden
 * muss - oder mit ?alle=1 jeder Vorfall, um eine Entscheidung zu aendern.
 *
 * Was schon zaehlt (offen, mit bekannter Kategorie), laesst sich hier in einem
 * Schritt "ohne Text zaehlen". Einzeln bleibt, was einen Kurztext bekommen
 * soll oder noch einzustufen ist.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;
use Klassenziel\Vorfaelle\VorfallRepository;
use Klassenziel\Wertung\Bewertung;
use Klassenziel\Wertung\Kategoriezuordnung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$klasse = $kern->klasseAusPfad($konto);
$auswahl = isset($_GET['alle']) ? 'alle' : 'offen';

$repo = $kern->vorfaelle();
$zuordnung = new Kategoriezuordnung($kern->db());
$gewichtung = $kern->einstellungen()->gewichtung($klasse->id);
$ziel = '/klassen/' . $klasse->id . '/pruefliste';

if (Kern::istPost()) {
    $kern->verlangeFormular();

    if ($klasse->istArchiviert() || ($_POST['aktion'] ?? '') !== 'ohne_text') {
        $kern->fehlerseite('Unbekannte Aktion', 'Diese Aktion gibt es hier nicht.', 400, $ziel);
    }

    $gewaehlt = is_array($_POST['vorfall'] ?? null) ? $_POST['vorfall'] : [];
    $ids = array_values(array_filter(
        array_map(static fn (mixed $id): int => is_string($id) && ctype_digit($id) ? (int) $id : 0, $gewaehlt),
        static fn (int $id): bool => $id > 0
    ));

    $anzahl = $repo->ohneTextUebernehmen($klasse->id, $ids, $zuordnung, $konto);

    if ($anzahl > 0) {
        Sitzung::meldung('erfolg', $anzahl === 1
            ? '1 Vorfall ohne Text übernommen. Er zählt wie bisher; öffentlich steht nur seine Einstufung.'
            : $anzahl . ' Vorfälle ohne Text übernommen. Sie zählen wie bisher; öffentlich steht nur ihre Einstufung.');
    } else {
        Sitzung::meldung('fehler', 'Nichts übernommen – es war kein Vorfall ausgewählt, der schon zählt.');
    }

    Kern::weiterleiten($ziel);
}

$vorfaelle = [];
foreach ($repo->fuerKlasse($klasse->id, $auswahl) as $vorfall) {
    $wirksam = Bewertung::wirksam($vorfall, $zuordnung);
    $original = $repo->originaltext($vorfall);

    $vorfaelle[] = $vorfall + [
        'wirksam' => $wirksam,
        'punkte' => $wirksam['zaehlt'] ? $gewichtung->fuer($wirksam['richtung'], (string) $wirksam['stufe']) : 0,
        // Nur in der Pruefliste: hier entscheidet die Lehrkraft in einem Schritt.
        'sammel' => $auswahl === 'offen' && !$klasse->istArchiviert() && VorfallRepository::zaehltSchon($vorfall, $zuordnung),
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
    'sammel' => count(array_filter($vorfaelle, static fn (array $v): bool => $v['sammel'])),
]);
