<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

/**
 * Der Verlauf des Punktestands als SVG - fuer die Simulation im Admin.
 *
 * Vom Server gezeichnet, nicht im Browser: die Content-Security-Policy
 * erlaubt keine style-Attribute, Praesentationsattribute im SVG dagegen
 * schon, und die Grafik steht auch ohne JavaScript. Das Skript in
 * public/js/verwaltung.js legt nur das Fadenkreuz mit dem Tooltip darueber;
 * die Werte stehen ausserdem in der Tabelle darunter.
 *
 * Linien 2 px, Endpunkte r = 4 mit 2 px Ring in der Flaechenfarbe, Raster als
 * Haarlinie. Farben gegen die Flaeche geprueft (scripts/validate_palette.js
 * der dataviz-Richtlinie): #1C64A8 und #15896A bestehen alle Pruefungen; weil
 * der Abstand fuer Tritanopie im Grenzbereich liegt, tragen beide Reihen
 * zusaetzlich Legende und Endbeschriftung.
 */
final class Verlaufsgrafik
{
    public const FARBE_NEU = '#1C64A8';
    public const FARBE_BISHER = '#15896A';

    private const BREITE = 720;
    private const HOEHE = 260;
    private const LINKS = 40;
    private const RECHTS = 104;
    private const OBEN = 12;
    private const UNTEN = 34;

    private const RASTER = '#E3E8EE';
    private const TEXT_LEISE = '#4B5966';
    private const TEXT = '#13222E';
    private const FLAECHE = '#FFFFFF';

    /**
     * @param list<array{name: string, farbe: string, punkte: list<array{datum: string, stand: int}>}> $reihen
     *        alle Reihen mit denselben Datumswerten
     */
    public static function svg(array $reihen, int $ziel, string $titel): string
    {
        $reihen = array_values(array_filter($reihen, static fn (array $r): bool => $r['punkte'] !== []));
        if ($reihen === []) {
            return '';
        }

        $anzahl = count($reihen[0]['punkte']);
        $innenBreite = self::BREITE - self::LINKS - self::RECHTS;
        $innenHoehe = self::HOEHE - self::OBEN - self::UNTEN;

        $x = static fn (int $i): float => self::LINKS + ($anzahl > 1 ? $i * $innenBreite / ($anzahl - 1) : $innenBreite / 2);
        $y = static fn (int $wert): float => self::OBEN + (100 - $wert) * $innenHoehe / 100;
        $zahl = static fn (float $wert): string => rtrim(rtrim(number_format($wert, 1, '.', ''), '0'), '.');

        $daten = [
            'x' => array_map(static fn (int $i): float => round($x($i), 1), range(0, $anzahl - 1)),
            'datum' => array_map(static fn (array $p): string => self::kurzdatum($p['datum']), $reihen[0]['punkte']),
            'reihen' => array_map(static fn (array $r): array => [
                'name' => $r['name'],
                'farbe' => $r['farbe'],
                'werte' => array_column($r['punkte'], 'stand'),
            ], $reihen),
        ];

        $svg = [];
        $svg[] = sprintf(
            '<svg class="verlauf" viewBox="0 0 %d %d" role="img" aria-labelledby="verlauf-titel" data-verlauf="%s" data-oben="%d" data-unten="%d">',
            self::BREITE,
            self::HOEHE,
            self::e((string) json_encode($daten)),
            self::OBEN,
            self::HOEHE - self::UNTEN
        );
        $svg[] = '<title id="verlauf-titel">' . self::e($titel) . '</title>';

        // Raster und Achse
        foreach ([0, 25, 50, 75, 100] as $stufe) {
            $svg[] = sprintf(
                '<line x1="%s" x2="%s" y1="%s" y2="%s" stroke="%s" stroke-width="1"/>',
                self::LINKS,
                self::BREITE - self::RECHTS,
                $zahl($y($stufe)),
                $zahl($y($stufe)),
                self::RASTER
            );
            $svg[] = sprintf(
                '<text x="%d" y="%s" text-anchor="end" font-size="15" fill="%s" dominant-baseline="middle">%d</text>',
                self::LINKS - 6,
                $zahl($y($stufe)),
                self::TEXT_LEISE,
                $stufe
            );
        }

        // Zielmarke
        $svg[] = sprintf(
            '<line x1="%s" x2="%s" y1="%s" y2="%s" stroke="%s" stroke-width="1"/>',
            self::LINKS,
            self::BREITE - self::RECHTS,
            $zahl($y($ziel)),
            $zahl($y($ziel)),
            self::TEXT
        );
        $svg[] = sprintf(
            '<text x="%d" y="%s" font-size="15" fill="%s" dominant-baseline="middle">Ziel %d</text>',
            self::BREITE - self::RECHTS + 8,
            $zahl($y($ziel)),
            self::TEXT,
            $ziel
        );

        // Datumsbeschriftung: Anfang, Ende und etwa jede vierte Woche dazwischen.
        $schritt = max(1, (int) ceil($anzahl / 6));
        for ($i = 0; $i < $anzahl; $i++) {
            if ($i !== 0 && $i !== $anzahl - 1 && $i % $schritt !== 0) {
                continue;
            }
            if ($i !== $anzahl - 1 && $anzahl - 1 - $i < $schritt / 2 && $i !== 0) {
                continue;
            }
            $svg[] = sprintf(
                '<text x="%s" y="%d" text-anchor="middle" font-size="15" fill="%s">%s</text>',
                $zahl($x($i)),
                self::HOEHE - 10,
                self::TEXT_LEISE,
                self::e(self::kurzdatum($reihen[0]['punkte'][$i]['datum']))
            );
        }

        // Linien
        $enden = [];
        foreach ($reihen as $reihe) {
            $punkte = [];
            foreach ($reihe['punkte'] as $i => $punkt) {
                $punkte[] = $zahl($x($i)) . ',' . $zahl($y($punkt['stand']));
            }

            $svg[] = sprintf(
                '<polyline points="%s" fill="none" stroke="%s" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>',
                implode(' ', $punkte),
                self::e($reihe['farbe'])
            );

            $letzter = $reihe['punkte'][$anzahl - 1]['stand'];
            $enden[] = ['y' => $y($letzter), 'wert' => $letzter, 'name' => $reihe['name'], 'farbe' => $reihe['farbe']];

            $svg[] = sprintf(
                '<circle cx="%s" cy="%s" r="4" fill="%s" stroke="%s" stroke-width="2"/>',
                $zahl($x($anzahl - 1)),
                $zahl($y($letzter)),
                self::e($reihe['farbe']),
                self::FLAECHE
            );
        }

        // Endbeschriftung nur, wenn die Enden weit genug auseinander liegen.
        // Uebereinandergeschoben loesten sich die Beschriftungen von ihren
        // Linien; dann tragen Legende, Tooltip und Tabelle.
        $getrennt = count($enden) === 1 || abs($enden[0]['y'] - $enden[1]['y']) >= 16;
        foreach ($enden as $ende) {
            if (!$getrennt) {
                break;
            }
            $svg[] = sprintf(
                '<text x="%s" y="%s" font-size="15" fill="%s" dominant-baseline="middle"><tspan font-weight="700">%d</tspan> %s</text>',
                $zahl($x($anzahl - 1) + 10),
                $zahl($ende['y']),
                self::TEXT,
                $ende['wert'],
                self::e($ende['name'])
            );
        }

        // Fadenkreuz fuer das Skript, unsichtbar bis zur ersten Bewegung.
        $svg[] = sprintf(
            '<line class="verlauf__kreuz" x1="0" x2="0" y1="%d" y2="%d" stroke="%s" stroke-width="1" visibility="hidden"/>',
            self::OBEN,
            self::HOEHE - self::UNTEN,
            self::TEXT_LEISE
        );
        $svg[] = sprintf(
            '<rect class="verlauf__flaeche" x="%d" y="%d" width="%d" height="%d" fill="transparent"/>',
            self::LINKS,
            self::OBEN,
            $innenBreite,
            $innenHoehe
        );

        $svg[] = '</svg>';

        return implode('', $svg);
    }

    private static function kurzdatum(string $datum): string
    {
        $teile = explode('-', $datum);

        return count($teile) === 3 ? $teile[2] . '.' . $teile[1] . '.' : $datum;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
