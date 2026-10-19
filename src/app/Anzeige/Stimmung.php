<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

/**
 * Smiley-Stufen auf dem Weg zum Ziel.
 *
 * Bei einem, zwei, drei Vierteln des Ziels und beim Ziel selbst sitzt je ein
 * Smiley im Balken. Wer eine Etappe erreicht, schaltet den naechsten,
 * froehlicheren frei - und sieht immer, wie weit es bis dahin noch ist. Unter
 * der ersten Etappe gilt Stufe 0.
 *
 * Gerechnet wird am Ziel, nicht an den 100 Punkten: Bei einem Ziel von 70
 * liegen die Etappen bei 18, 35, 53 und 70. Kleine Ziele haben weniger
 * Etappen, weil zwei Viertel auf dieselbe Zahl fielen.
 */
final class Stimmung
{
    /** Ein Spruch je Stufe, von 0 bis 4. */
    public const SPRUECHE = [
        0 => 'Zusammen schaffen wir das!',
        1 => 'Der Anfang ist gemacht.',
        2 => 'Halbzeit – weiter so!',
        3 => 'Auf der Zielgeraden!',
        4 => 'Ziel erreicht – jetzt halten!',
    ];

    /**
     * Die Etappen: ab wie vielen Punkten welche Stufe gilt.
     *
     * @return list<array{stufe: int, bei: int}>
     */
    public static function etappen(int $ziel): array
    {
        $ziel = max(1, min(100, $ziel));

        $etappen = [];
        foreach ([1, 2, 3, 4] as $viertel) {
            $bei = $viertel === 4 ? $ziel : (int) round($ziel * $viertel / 4);
            if ($bei < 1) {
                continue;
            }
            // Fallen zwei Viertel auf dieselbe Zahl, zaehlt das hoehere.
            if ($etappen !== [] && $etappen[count($etappen) - 1]['bei'] === $bei) {
                array_pop($etappen);
            }
            $etappen[] = ['stufe' => $viertel, 'bei' => $bei];
        }

        return $etappen;
    }

    /**
     * @return array{stufe: int, spruch: string, status: string, naechste: ?int, fehlt_naechste: int, naechste_ist_ziel: bool, etappen: list<array{stufe: int, bei: int, erreicht: bool}>}
     */
    public static function fuer(int $stand, int $ziel): array
    {
        $stufe = 0;
        $naechste = null;
        $etappen = [];

        foreach (self::etappen($ziel) as $etappe) {
            $erreicht = $stand >= $etappe['bei'];
            if ($erreicht) {
                $stufe = $etappe['stufe'];
            } elseif ($naechste === null) {
                $naechste = $etappe;
            }
            $etappen[] = $etappe + ['erreicht' => $erreicht];
        }

        return [
            'stufe' => $stufe,
            'spruch' => self::SPRUECHE[$stufe],
            'status' => self::status($stand, max(1, min(100, $ziel)), $naechste),
            'naechste' => $naechste['bei'] ?? null,
            'fehlt_naechste' => $naechste !== null ? $naechste['bei'] - $stand : 0,
            'naechste_ist_ziel' => $naechste !== null && $naechste['stufe'] === 4,
            'etappen' => $etappen,
        ];
    }

    /**
     * Was als Naechstes zu tun ist - in Punkten, nicht in Prozent.
     *
     * @param array{stufe: int, bei: int}|null $naechste
     */
    private static function status(int $stand, int $ziel, ?array $naechste): string
    {
        if ($naechste === null) {
            $ueber = $stand - $ziel;

            return $ueber > 0 ? $ueber . ' ' . self::punkte($ueber) . ' über dem Ziel' : 'Genau am Ziel';
        }

        $bisZiel = $ziel - $stand;
        if ($naechste['stufe'] === 4) {
            return 'Noch ' . $bisZiel . ' ' . self::punkte($bisZiel) . ' bis zum Ziel';
        }

        $bisEtappe = $naechste['bei'] - $stand;

        return 'Noch ' . $bisEtappe . ' ' . self::punkte($bisEtappe) . ' bis zum nächsten Smiley · ' . $bisZiel . ' bis zum Ziel';
    }

    private static function punkte(int $anzahl): string
    {
        return $anzahl === 1 ? 'Punkt' : 'Punkte';
    }
}
