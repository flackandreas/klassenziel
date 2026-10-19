<?php

declare(strict_types=1);

/**
 * Ein Vorfall: einstufen, Kurztext, freigeben, ohne Text zaehlen, ignorieren -
 * oder teilen und zusammenfuehren, wenn die Gruppierung danebenlag.
 *
 * Der Originaltext aus WebUntis steht nur hier, nur fuer die zustaendige
 * Lehrkraft, als Grundlage fuer einen neutralen Kurztext.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;
use Klassenziel\Vorfaelle\Namenspruefung;
use Klassenziel\Vorfaelle\VorfallRepository;
use Klassenziel\Wertung\Bewertung;
use Klassenziel\Wertung\Kategoriezuordnung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$repo = $kern->vorfaelle();

$vorfall = $repo->eines((int) $kern->parameter('id'));
$klasse = $vorfall !== null ? $kern->klassen()->fuer($konto, (int) $vorfall['klasse_id']) : null;

if ($vorfall === null || $klasse === null
    || ($vorfall['status'] === 'ignoriert' && $vorfall['entschieden_von'] === null)) {
    // Fremde, erfundene und von der Regel ignorierte neutrale Vorfaelle
    // sehen gleich aus.
    Kern::nichtGefunden();
}

$id = (int) $vorfall['id'];
$zuordnung = new Kategoriezuordnung($kern->db());
$ausKategorie = $zuordnung->fuer($klasse->id, (string) $vorfall['webuntis_kategorie'], (string) $vorfall['kategoriegruppe']);
$wirksam = Bewertung::wirksam($vorfall, $zuordnung);

// Was im Formular steht: die bisherige Entscheidung oder die Kategorie.
$eingabe = [
    'richtung' => in_array($wirksam['richtung'], ['positiv', 'negativ', 'neutral'], true) ? $wirksam['richtung'] : '',
    'stufe' => (string) ($wirksam['stufe'] ?? ''),
    'kurztext' => (string) ($vorfall['kurztext'] ?? ''),
];
$fehler = [];
$namenstreffer = [];

if (Kern::istPost() && !$klasse->istArchiviert()) {
    $kern->verlangeFormular();
    $aktion = (string) ($_POST['aktion'] ?? '');

    switch ($aktion) {
        case 'freigegeben':
        case 'ohne_text':
        case 'ignoriert':
            $eingabe = [
                'richtung' => is_string($_POST['richtung'] ?? null) ? $_POST['richtung'] : '',
                'stufe' => is_string($_POST['stufe'] ?? null) ? $_POST['stufe'] : '',
                'kurztext' => is_string($_POST['kurztext'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $_POST['kurztext']) ?? '') : '',
            ];

            $richtung = null;
            $stufe = null;

            if ($aktion !== 'ignoriert') {
                if (!in_array($eingabe['richtung'], ['positiv', 'negativ', 'neutral'], true)) {
                    $fehler['richtung'] = 'Bitte einstufen: positiv, negativ oder neutral.';
                } else {
                    $richtung = $eingabe['richtung'];
                }

                if (in_array($richtung, ['positiv', 'negativ'], true)) {
                    if (!in_array($eingabe['stufe'], ['klein', 'mittel', 'gross'], true)) {
                        $fehler['stufe'] = 'Bitte eine Stufe wählen.';
                    } else {
                        $stufe = $eingabe['stufe'];
                    }
                }
            }

            if ($aktion === 'freigegeben') {
                if ($eingabe['kurztext'] === '') {
                    $fehler['kurztext'] = 'Zum Freigeben braucht es einen Kurztext – oder „Ohne Text zählen“.';
                } elseif (mb_strlen($eingabe['kurztext']) > VorfallRepository::KURZTEXT_LAENGE) {
                    $fehler['kurztext'] = 'Höchstens ' . VorfallRepository::KURZTEXT_LAENGE . ' Zeichen.';
                } elseif ($richtung === 'neutral') {
                    $fehler['kurztext'] = 'Neutrale Einträge erscheinen nie öffentlich. Bitte „Ohne Text zählen“ wählen.';
                }
            }

            if ($fehler === [] && $aktion === 'freigegeben' && ($_POST['trotzdem'] ?? '') !== '1') {
                $namenstreffer = Namenspruefung::treffer(
                    $kern->schluessel(),
                    $eingabe['kurztext'],
                    array_values(array_unique([...$repo->namensKennungen($vorfall), ...$kern->namensliste()->kennungen($klasse->id)]))
                );
            }

            if ($fehler === [] && $namenstreffer === []) {
                // Folgt die Einstufung der Kategorie, bleibt der Vorfall an
                // die Zuordnung gebunden - auch an eine spaeter geaenderte.
                $manuell = $aktion !== 'ignoriert'
                    && !($ausKategorie['richtung'] === $richtung && $ausKategorie['stufe'] === $stufe);

                $repo->entscheide($klasse->id, $id, $aktion, $richtung, $stufe, $manuell, $eingabe['kurztext'], $konto);

                Sitzung::meldung('erfolg', match ($aktion) {
                    'freigegeben' => 'Freigegeben – der Kurztext steht auf der Klassenseite.',
                    'ohne_text' => 'Gespeichert – öffentlich steht nur die Kategorie.',
                    default => 'Ignoriert – zählt nicht und erscheint nicht öffentlich.',
                });

                $naechster = isset($_POST['weiter']) ? $repo->naechsterOffener($klasse->id, $id) : null;
                Kern::weiterleiten($naechster !== null ? '/vorfaelle/' . $naechster : '/klassen/' . $klasse->id . '/pruefliste');
            }
            break;

        case 'eigener':
            $repo->alsEigenerVorfall($klasse->id, $id);
            Sitzung::meldung('erfolg', 'Als eigener Vorfall übernommen. Er zählt ab jetzt.');
            Kern::weiterleiten('/vorfaelle/' . $id);

        case 'zusammen':
            $ziel = (int) ($_POST['ziel'] ?? 0);
            try {
                $repo->fuehreZusammen($klasse->id, $id, $ziel);
                Sitzung::meldung('erfolg', 'Zusammengeführt. Der Vorfall zählt einmal, mit allen Beteiligten.');
                Kern::weiterleiten('/vorfaelle/' . $ziel);
            } catch (\InvalidArgumentException $e) {
                $fehler['zusammen'] = $e->getMessage();
            }
            break;

        case 'teilen':
            try {
                $neu = $repo->teile($klasse->id, $id, (int) ($_POST['anzahl'] ?? 0));
                Sitzung::meldung('erfolg', 'Abgetrennt. Hier ist der neue Vorfall – beide zählen jetzt einzeln.');
                Kern::weiterleiten('/vorfaelle/' . $neu);
            } catch (\InvalidArgumentException $e) {
                $fehler['teilen'] = $e->getMessage();
            }
            break;

        default:
            $kern->fehlerseite('Unbekannte Aktion', 'Diese Aktion gibt es nicht.', 400, '/vorfaelle/' . $id);
    }
}

$moeglichZu = $vorfall['moeglich_zu'] !== null ? $repo->eines((int) $vorfall['moeglich_zu']) : null;

$kern->zeichne('vorfall.twig', [
    'konto' => $konto,
    'klasse' => $klasse,
    'vorfall' => $vorfall,
    'originaltext' => $repo->originaltext($vorfall),
    'wirksam' => $wirksam,
    'aus_kategorie' => $ausKategorie,
    'gewichtung' => $kern->einstellungen()->gewichtung($klasse->id),
    'eingabe' => $eingabe,
    'fehler' => $fehler,
    'namenstreffer' => $namenstreffer,
    'textbausteine' => $kern->stammdaten()->textbausteine(),
    'am_selben_tag' => $repo->amSelbenTag($klasse->id, $id, (string) $vorfall['datum']),
    'moeglich_zu' => $moeglichZu,
    'offen' => $repo->offeneAnzahl($klasse->id),
]);
