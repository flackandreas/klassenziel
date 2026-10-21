<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

use DateTimeImmutable;
use Klassenziel\Klassen\Klasse;
use Klassenziel\Wertung\Bewertung;
use Klassenziel\Wertung\Ferienkalender;
use Klassenziel\Wertung\Gewichtung;
use Klassenziel\Wertung\Wertung;

/**
 * Das einzige Ansichtsmodell der oeffentlichen Klassenseite.
 *
 * Alles, was Schuelerinnen, Schueler und Eltern sehen, geht hier durch - und
 * hier steht abschliessend, welche Felder das sind (Planung, Abschnitt 2):
 * Datum, Fach, Kategorie als Richtung und Stufe, Punkte und ein freigegebener
 * Kurztext. Kein Originaltext, keine Lehrkraft, keine WebUntis-Kategorie im
 * Wortlaut, keine Kennung eines Vorfalls. Ein Test prueft die Feldliste.
 *
 * Ohne Freigabe steht statt des Kurztexts die Bezeichnung der Kategorie,
 * etwa "Kleiner negativer Eintrag".
 *
 * Dazu der Verlauf fuer die Linie: der Stand am Ende jeder Woche als Datum
 * und Zahl, die Achse von Start bis Stichtag und die Ferien der Schule. Das
 * sind Summen, kein einzelner Eintrag - was sie veraendert hat, steht
 * ohnehin in den Eintraegen darunter.
 */
final class Anzeigedaten
{
    public const EINTRAEGE_BEAMER = 5;
    public const EINTRAEGE_HANDY = 20;
    public const EINTRAEGE_HOECHSTENS = 200;
    public const WOCHENBONUS_TEXT = 'Wochenbonus: Woche ohne großen Eintrag';

    /** Die Felder je Eintrag - und keine anderen. */
    public const EINTRAG_FELDER = ['art', 'datum', 'datum_text', 'fach', 'text', 'punkte', 'punkte_text', 'stufe', 'richtung'];

    /**
     * @param array<int, array{status: string, kurztext: ?string, fach: string}> $vorfaelle
     *        oeffentliche Angaben zu den Vorfaellen der Wertung, nach Kennung
     * @return array<string,mixed>
     */
    public static function baue(
        Klasse $klasse,
        Wertung $wertung,
        Gewichtung $gewichtung,
        array $vorfaelle,
        DateTimeImmutable $heute,
        int $anzahl = self::EINTRAEGE_HANDY,
        int $versatz = 0,
        Ferienkalender $ferien = new Ferienkalender(),
    ): array {
        $anzahl = max(1, min(self::EINTRAEGE_HOECHSTENS, $anzahl));
        $versatz = max(0, $versatz);

        $neuesteZuerst = array_reverse($wertung->ereignisse);
        $auszug = array_slice($neuesteZuerst, $versatz, $anzahl);

        $eintraege = [];
        foreach ($auszug as $ereignis) {
            $eintraege[] = self::eintrag($ereignis, $vorfaelle);
        }

        $fehlt = max(0, $klasse->zielwert - $wertung->stand);

        return [
            'klasse' => [
                'name' => $klasse->anzeigename,
                'schuljahr' => $klasse->schuljahr,
                'belohnung' => $klasse->belohnung,
            ],
            'stand' => $wertung->stand,
            'ziel' => $klasse->zielwert,
            'fehlt' => $fehlt,
            'ziel_erreicht' => $wertung->stand >= $klasse->zielwert,
            'stimmung' => Stimmung::fuer($wertung->stand, $klasse->zielwert),
            'countdown' => Countdown::fuer($klasse->stichtag, $heute),
            'woche' => [
                'bilanz' => $wertung->wochenbilanz,
                'bilanz_text' => self::punkteText($wertung->wochenbilanz),
                'positiv' => $wertung->wochePositiv,
                'negativ' => $wertung->wocheNegativ,
            ],
            'wertung' => [
                'klein' => $gewichtung->klein,
                'mittel' => $gewichtung->mittel,
                'gross' => $gewichtung->gross,
                'wochenbonus' => $gewichtung->wochenbonus,
            ],
            'quelle' => $klasse->datenstand?->format('d.m.Y'),
            'eintraege' => $eintraege,
            'weitere' => count($neuesteZuerst) > $versatz + $anzahl,
            'verlauf' => self::verlauf($klasse, $wertung, $ferien),
        ];
    }

    /**
     * Die Daten der Linie. Die Achse endet am Stichtag; ohne Stichtag am Ende
     * des Schuljahres (31. Juli), damit die Linie nicht die ganze Breite fuellt
     * und so tut, als waere das Jahr schon um.
     *
     * @return array{start: string, ende: string, stichtag: ?string, punkte: list<array{datum: string, stand: int}>, ferien: list<array{von: string, bis: string}>}
     */
    private static function verlauf(Klasse $klasse, Wertung $wertung, Ferienkalender $ferien): array
    {
        $start = $klasse->startdatum->setTime(0, 0);
        $ende = $klasse->stichtag?->setTime(0, 0)
            ?? $start->setDate((int) $start->format('Y') + ((int) $start->format('n') >= 8 ? 1 : 0), 7, 31);

        $punkte = array_map(static fn (array $p): array => [
            'datum' => (string) $p['datum'],
            'stand' => (int) $p['stand'],
        ], $wertung->verlauf);

        // Liegt der letzte Punkt hinter dem Achsenende (Stichtag spaeter
        // vorverlegt), waechst die Achse mit, statt ihn abzuschneiden.
        if ($punkte !== [] && $punkte[count($punkte) - 1]['datum'] > $ende->format('Y-m-d')) {
            $ende = new DateTimeImmutable($punkte[count($punkte) - 1]['datum']);
        }
        if ($ende <= $start) {
            $ende = $start->modify('+7 days');
        }

        $vonBis = [$start->format('Y-m-d'), $ende->format('Y-m-d')];
        $zeitraeume = array_values(array_filter(
            $ferien->zeitraeume(),
            static fn (array $z): bool => $z['bis'] >= $vonBis[0] && $z['von'] <= $vonBis[1]
        ));

        return [
            'start' => $vonBis[0],
            'ende' => $vonBis[1],
            'stichtag' => $klasse->stichtag?->format('Y-m-d'),
            'punkte' => $punkte,
            'ferien' => array_map(static fn (array $z): array => ['von' => $z['von'], 'bis' => $z['bis']], $zeitraeume),
        ];
    }

    /**
     * Punkte mit Vorzeichen; das Minus typografisch (U+2212), nicht der
     * Bindestrich - am Beamer ist der Unterschied zu sehen.
     */
    public static function punkteText(int $punkte): string
    {
        return $punkte > 0 ? '+' . $punkte : ($punkte < 0 ? "\u{2212}" . abs($punkte) : '0');
    }

    /**
     * @param array{art: string, id: ?int, datum: string, richtung: string, stufe: string, punkte: int, stand: int} $ereignis
     * @param array<int, array{status: string, kurztext: ?string, fach: string}> $vorfaelle
     * @return array<string,mixed>
     */
    private static function eintrag(array $ereignis, array $vorfaelle): array
    {
        $datum = new DateTimeImmutable($ereignis['datum']);

        if ($ereignis['art'] === 'wochenbonus') {
            return [
                'art' => 'wochenbonus',
                'datum' => $datum->format('Y-m-d'),
                'datum_text' => $datum->format('d.m.'),
                'fach' => '',
                'text' => self::WOCHENBONUS_TEXT,
                'punkte' => $ereignis['punkte'],
                'punkte_text' => self::punkteText($ereignis['punkte']),
                'stufe' => '',
                'richtung' => 'positiv',
            ];
        }

        $info = $vorfaelle[(int) $ereignis['id']] ?? ['status' => '', 'kurztext' => null, 'fach' => ''];
        $kurztext = $info['status'] === 'freigegeben' && is_string($info['kurztext']) && trim($info['kurztext']) !== ''
            ? trim($info['kurztext'])
            : null;

        return [
            'art' => 'vorfall',
            'datum' => $datum->format('Y-m-d'),
            'datum_text' => $datum->format('d.m.'),
            'fach' => $info['fach'],
            'text' => $kurztext ?? Bewertung::bezeichnung($ereignis['richtung'], $ereignis['stufe']),
            'punkte' => $ereignis['punkte'],
            'punkte_text' => self::punkteText($ereignis['punkte']),
            'stufe' => Bewertung::stufenname($ereignis['stufe']),
            'richtung' => $ereignis['richtung'],
        ];
    }
}
