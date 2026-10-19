<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

use DateTimeImmutable;
use Klassenziel\Klassen\Klasse;
use Klassenziel\Wertung\Bewertung;
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
